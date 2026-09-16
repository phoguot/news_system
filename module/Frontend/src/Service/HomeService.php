<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Banner\BannerMapper;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\HomeSection\HomeSectionConst;
use Admin\Model\HomeSection\HomeSectionMapper;
use Admin\Model\HomeSection\HomeSectionModel;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\TeamMember\TeamMemberModel;
use Application\Constant\CacheConst;
use Application\Constant\ContentConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use DateTimeImmutable;
use DateTimeZone;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;

/**
 * Tầng đọc trang chủ công khai (FR-32 render + FR-39 cache, docs §3.7):
 * dựng payload list<array> THEO THỨ TỰ `home_sections` — mỗi section bật là
 * một block phẳng (mảng thuần, không object — điều kiện để payload đi qua
 * `PageCacheService` vốn `unserialize(..., allowed_classes: false)`).
 *
 * Nguồn mapper: MỘT phần đã có trong module Admin (`PostMapper`,
 * `BannerMapper`, `TeamMemberMapper`, `HomeSectionMapper`,
 * `HomeSectionItemMapper`, `CategoryMapper`, `MediaMapper`) — Frontend ĐỌC
 * bảng qua mapper chủ của nó nhờ container gộp (precedent 05-cau-truc §4:
 * 1 bảng 1 mapper, chiều dùng cross-module đã duyệt; rule "Frontend chỉ đọc,
 * không ghi" vẫn giữ — mọi method gọi ở đây là select thuần).
 *
 * Cache: key `CacheConst::KEY_HOME`, TTL 60s (hẹn giờ `publishedAt` tự lộ
 * diện ≤ 60s, không cronjob) + invalidate ngay khi Admin ghi (các service
 * Admin `forget(KEY_HOME)`). Storage thiếu → producer chạy thẳng (degrade).
 */
class HomeService extends AppServiceFactory
{
    /** Múi giờ hiển thị công khai — docs §4.1: lưu UTC, đổi giờ VN ở tầng hiển thị. */
    private const VIEW_TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Mặc định `limit` khi config thiếu (đúng ví dụ docs §3.7; trần/sàn ở configError Admin). */
    private const DEFAULT_LIMITS = [
        HomeSectionConst::TYPE_FEATURED_POSTS => 5,
        HomeSectionConst::TYPE_LATEST_POSTS   => 6,
        HomeSectionConst::TYPE_CATEGORY_POSTS => 4,
        HomeSectionConst::TYPE_SERVICES       => 6,
        HomeSectionConst::TYPE_TEAM           => 4,
    ];

    /**
     * Danh sách block đã dựng, ưu tiên đọc từ cache `home-v1`.
     *
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        $cache = $this->pageCache();
        if ($cache === null) {
            return $this->build();
        }

        /** @var list<array<string, mixed>> */
        return $cache->remember(CacheConst::KEY_HOME, fn (): array => $this->build());
    }

    private function homeSections(): HomeSectionMapper
    {
        /** @var HomeSectionMapper */
        return $this->getContainerEntry(HomeSectionMapper::class);
    }

    private function homeSectionItems(): HomeSectionItemMapper
    {
        /** @var HomeSectionItemMapper */
        return $this->getContainerEntry(HomeSectionItemMapper::class);
    }

    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function banners(): BannerMapper
    {
        /** @var BannerMapper */
        return $this->getContainerEntry(BannerMapper::class);
    }

    private function services(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function team(): TeamMemberMapper
    {
        /** @var TeamMemberMapper */
        return $this->getContainerEntry(TeamMemberMapper::class);
    }

    private function categories(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function media(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /**
     * Dựng toàn bộ payload từ DB (bước producer của cache).
     *
     * @return list<array<string, mixed>>
     */
    private function build(): array
    {
        $mediaIds  = [];
        $sections  = [];

        foreach ($this->homeSections()->listActiveOrdered() as $section) {
            $block = $this->buildSection($section, $mediaIds);
            if ($block !== null) {
                $sections[] = $block;
            }
        }

        return $this->resolveImages($sections, $mediaIds);
    }

    /**
     * Một section → một block phẳng, hoặc null khi khối không có gì hiển thị
     * (section rỗng / category đã xoá / CTA thiếu link) — ẩn thay vì lỗi.
     *
     * @param list<int> $mediaIds gom id ảnh để resolve 1 query cuối (07 §5)
     *
     * @return array<string, mixed>|null
     */
    private function buildSection(HomeSectionModel $section, array &$mediaIds): ?array
    {
        /** @var array<string, mixed> $config */
        $config = $section->configArray() ?? [];

        return match ($section->type) {
            HomeSectionConst::TYPE_HERO_BANNER    => $this->heroBlock($section, $config, $mediaIds),
            HomeSectionConst::TYPE_FEATURED_POSTS => $this->postsBlock(
                $section,
                $this->isManual($config) ? $this->manualPostIds($section) : null,
                $this->limitFor($section->type, $config),
                $mediaIds
            ),
            HomeSectionConst::TYPE_LATEST_POSTS   => $this->postsBlock(
                $section,
                null,
                $this->limitFor($section->type, $config),
                $mediaIds
            ),
            HomeSectionConst::TYPE_CATEGORY_POSTS => $this->categoryBlock($section, $config, $mediaIds),
            HomeSectionConst::TYPE_SERVICES       => $this->servicesBlock($section, $config, $mediaIds),
            HomeSectionConst::TYPE_TEAM           => $this->teamBlock($section, $config, $mediaIds),
            HomeSectionConst::TYPE_CONTACT_CTA    => $this->ctaBlock($section, $config),
            HomeSectionConst::TYPE_PROCESS        => $this->processBlock($section, $config),
            default                              => null,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $mediaIds
     *
     * @return array<string, mixed>|null
     */
    private function heroBlock(HomeSectionModel $section, array $config, array &$mediaIds): ?array
    {
        $banners = $this->banners()->listActiveHomeHero();
        if ($banners === []) {
            return null;
        }

        $items = [];
        foreach ($banners as $banner) {
            $this->collectMedia($mediaIds, $banner->imageMediaId, $banner->mobileImageMediaId);
            $items[] = [
                'kind'          => 'banner',
                'title'         => $banner->title ?? '',
                'subtitle'      => $banner->subtitle ?? '',
                'href'          => $banner->linkUrl ?? '',
                'button'        => $banner->buttonText ?? '',
                'newTab'        => $banner->openNewTab === 1,
                'imageId'       => $banner->imageMediaId,
                'mobileImageId' => $banner->mobileImageMediaId,
            ];
        }

        return [
            'kind'       => 'hero',
            'title'      => $section->title ?? '',
            'autoplay'   => ($config['autoplay'] ?? true) !== false,
            'intervalMs' => (int) ($config['interval_ms'] ?? 5000),
            'items'      => $items,
        ];
    }

    /**
     * featured/latest/category chung một shape 'posts' — bài manual theo id,
     * auto theo scope; bài ẩn/xoá/ngừng publish tự biến mất khi cache làm lại.
     *
     * @param list<int>|null        $ids        null = auto (mới nhất/nổi bật/theo category)
     * @param list<int>             $mediaIds
     * @param array{0: string, 1: string}|null $heading [label, href] cho khối danh mục
     *
     * @return array<string, mixed>|null
     */
    private function postsBlock(
        HomeSectionModel $section,
        ?array $ids,
        int $limit,
        array &$mediaIds,
        ?array $heading = null,
        ?int $categoryId = null
    ): ?array {
        $posts = $ids !== null
            ? $this->orderedByIds(
                $this->posts()->listPublishedByIds($ids),
                $ids,
                static fn (PostModel $p): int => $p->id
            )
            : ($section->type === HomeSectionConst::TYPE_LATEST_POSTS
                ? $this->posts()->listLatestPublished($limit)
                : ($categoryId === null
                    ? $this->posts()->listFeaturedPublished($limit)
                    : $this->posts()->listPublishedByCategory($categoryId, $limit)));

        if ($posts === []) {
            return null;
        }

        $items = [];
        foreach ($posts as $post) {
            $this->collectMedia($mediaIds, $post->thumbnailMediaId, $post->bannerMediaId);
            $items[] = [
                'kind'       => 'post',
                'title'      => $post->title,
                'href'       => '/tin-tuc/' . $post->slug,
                'subtitle'   => $post->excerpt ?? '',
                'date'       => $this->viewDate($post->publishedAt),
                'minutes'    => $post->readingMinutes,
                'imageId'    => $post->thumbnailMediaId ?? $post->bannerMediaId,
            ];
        }

        return [
            'kind'        => 'posts',
            'title'       => $heading[0] ?? ($section->title ?? ''),
            'headingHref' => $heading[1] ?? null,
            'items'       => $items,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $mediaIds
     *
     * @return array<string, mixed>|null
     */
    private function categoryBlock(HomeSectionModel $section, array $config, array &$mediaIds): ?array
    {
        $categoryId = (int) ($config['category_id'] ?? 0);
        $category   = $categoryId > 0 ? $this->categories()->findById($categoryId) : null;
        if ($category === null) {
            return null; // danh mục đã xoá — ẩn khối (docs §3.7: mục biến mất khi render)
        }

        $categoryTitle = $section->title !== null && $section->title !== ''
            ? $section->title
            : $category->name;

        return $this->postsBlock(
            $section,
            null,
            $this->limitFor($section->type, $config),
            $mediaIds,
            [$categoryTitle, '/danh-muc/' . $category->slug],
            $categoryId
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $mediaIds
     *
     * @return array<string, mixed>|null
     */
    private function servicesBlock(HomeSectionModel $section, array $config, array &$mediaIds): ?array
    {
        $ids  = $this->isManual($config)
            ? $this->manualItemIds($section, ContentConst::SECTION_ITEM_SERVICE)
            : null;
        /** @var list<ServiceModel> $rows */
        $rows = $ids === null
            ? $this->services()->listActive($this->limitFor($section->type, $config))
            : $this->orderedByIds(
                $this->services()->listActiveByIds($ids),
                $ids,
                static fn (ServiceModel $s): int => $s->id
            );

        if ($rows === []) {
            return null;
        }

        $items = [];
        foreach ($rows as $service) {
            $this->collectMedia($mediaIds, $service->imageMediaId, $service->iconMediaId);
            $items[] = [
                'kind'     => 'service',
                'title'    => $service->name,
                'href'     => '/dich-vu/' . $service->slug,
                'subtitle' => $service->shortDescription ?? '',
                'imageId'  => $service->imageMediaId,
                'iconId'   => $service->iconMediaId,
            ];
        }

        return [
            'kind'  => 'services',
            'title' => $section->title ?? '',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<int>            $mediaIds
     *
     * @return array<string, mixed>|null
     */
    private function teamBlock(HomeSectionModel $section, array $config, array &$mediaIds): ?array
    {
        $ids  = $this->isManual($config)
            ? $this->manualItemIds($section, ContentConst::SECTION_ITEM_TEAM_MEMBER)
            : null;
        $rows = $ids === null
            ? $this->team()->listFeaturedActive($this->limitFor($section->type, $config))
            : $this->orderedByIds(
                $this->team()->listActiveByIds($ids),
                $ids,
                static fn (TeamMemberModel $t): int => $t->id
            );

        if ($rows === []) {
            return null;
        }

        $items = [];
        foreach ($rows as $member) {
            $this->collectMedia($mediaIds, $member->avatarMediaId);
            $items[] = [
                'kind'     => 'team',
                'title'    => $member->fullName,
                'subtitle' => $member->positionTitle,
                'href'     => '',
                'imageId'  => $member->avatarMediaId,
            ];
        }

        return [
            'kind'  => 'team',
            'title' => $section->title ?? '',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function ctaBlock(HomeSectionModel $section, array $config): ?array
    {
        $url  = trim((string) ($config['button_url'] ?? ''));
        $text = trim((string) ($config['button_text'] ?? ''));
        if ($url === '' || $text === '') {
            return null;
        }

        return [
            'kind'  => 'cta',
            'title' => $section->title ?? '',
            'href'  => $url,
            'button' => $text,
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function processBlock(HomeSectionModel $section, array $config): ?array
    {
        $steps = $config['steps'] ?? null;
        if (! is_array($steps) || count($steps) < 3) {
            return null;
        }
        $items = [];
        foreach (array_values($steps) as $i => $step) {
            if (! is_array($step) || ! isset($step['title']) || ! is_string($step['title'])) {
                continue;
            }
            $items[] = [
                'kind'  => 'step',
                'title' => trim($step['title']),
                'subtitle' => isset($step['desc']) && is_string($step['desc']) ? trim($step['desc']) : '',
                'index' => $i + 1,
            ];
        }
        if (count($items) < 3) {
            return null;
        }

        return [
            'kind'  => 'process',
            'title' => $section->title ?? '',
            'subtitle' => $section->subtitle ?? '',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isManual(array $config): bool
    {
        return ($config['mode'] ?? HomeSectionConst::MODE_AUTO) === HomeSectionConst::MODE_MANUAL;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function limitFor(int $type, array $config): int
    {
        $limit = (int) ($config['limit'] ?? self::DEFAULT_LIMITS[$type] ?? 6);

        return max(HomeSectionConst::LIMIT_MIN, min(HomeSectionConst::LIMIT_MAX, $limit));
    }

    /**
     * Id manual của section bài nổi bật (type=2) — cùng đường với
     * manualItemIds nhưng dùng cho cả hai loại section bài.
     *
     * @return list<int>
     */
    private function manualPostIds(HomeSectionModel $section): array
    {
        return $this->manualItemIds($section, ContentConst::SECTION_ITEM_POST);
    }

    /**
     * `home_section_items` theo đúng thứ tự lưu, lọc itemType khớp loại mục
     * (mục sai kiểu là dữ liệu cũ/thối — bỏ qua, không nổ).
     *
     * @return list<int>
     */
    private function manualItemIds(HomeSectionModel $section, int $expectedType): array
    {
        $ids = [];
        foreach ($this->homeSectionItems()->listBySectionId($section->id) as $item) {
            if ($item->itemType === $expectedType && $item->itemId > 0) {
                $ids[] = $item->itemId;
            }
        }

        return $ids;
    }

    /**
     * Trả model theo ĐÚNG thứ tự id manual (SQL `IN` không đảm bảo thứ tự);
     * id vắng mặt (ẩn/xoá) bị bỏ qua — docs §3.7.
     *
     * @template T of object
     *
     * @param list<T>              $rows
     * @param list<int>            $ids
     * @param callable(T): int     $idOf
     *
     * @return list<T>
     */
    private function orderedByIds(array $rows, array $ids, callable $idOf): array
    {
        /** @var array<int, T> $byId */
        $byId = [];
        foreach ($rows as $row) {
            $byId[$idOf($row)] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Ảnh card: 1 query IN cho TOÀN bộ id gom trong build; id không có trong
     * map (media đã xoá) → card đơn giản không ảnh.
     *
     * @param list<array<string, mixed>> $sections
     * @param list<int>                  $mediaIds
     *
     * @return list<array<string, mixed>>
     */
    private function resolveImages(array $sections, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique($mediaIds));
        $map      = $mediaIds === [] ? [] : $this->media()->mapCardsByIds($mediaIds);

        foreach ($sections as &$section) {
            /** @var list<array<string, mixed>> $items */
            $items = $section['items'] ?? [];
            foreach ($items as &$item) {
                $image = $this->imageOf($map, $item['imageId'] ?? null);
                if ($image !== null) {
                    $item['image'] = $image;
                }
                $mobile = $this->imageOf($map, $item['mobileImageId'] ?? null);
                if ($mobile !== null) {
                    $item['mobileImage'] = $mobile;
                }
                $icon = $this->imageOf($map, $item['iconId'] ?? null);
                if ($icon !== null) {
                    $item['icon'] = $icon;
                }
            }
            unset($item);

            $section['items'] = $items;
        }
        unset($section);

        return $sections;
    }

    /**
     * @param array<int, array{path: string, alt: string, thumb: string, large: string}> $map
     *
     * @return array{path: string, alt: string}|null
     */
    private function imageOf(array $map, mixed $mediaId): ?array
    {
        if (! is_int($mediaId) || $mediaId <= 0) {
            return null;
        }

        $entry = $map[$mediaId] ?? null;
        if ($entry === null) {
            return null;
        }

        return ['path' => $entry['thumb'], 'alt' => $entry['alt']];
    }

    /**
     * @param list<int> $mediaIds
     */
    private function collectMedia(array &$mediaIds, ?int ...$ids): void
    {
        foreach ($ids as $id) {
            if ($id !== null && $id > 0) {
                $mediaIds[] = $id;
            }
        }
    }

    /**
     * Mốc 'Y-m-d H:i:s' UTC → 'd/m/Y' giờ VN cho card hiển thị (docs §4.1).
     */
    private function viewDate(?string $publishedAtUtc): string
    {
        if ($publishedAtUtc === null || $publishedAtUtc === '') {
            return '';
        }

        try {
            $utc = new DateTimeImmutable($publishedAtUtc . ' UTC');
        } catch (\Exception) {
            return '';
        }

        return $utc->setTimezone(new DateTimeZone(self::VIEW_TIMEZONE))->format('d/m/Y');
    }
}
