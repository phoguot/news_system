<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Banner\BannerConst;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use DateTimeImmutable;
use DateTimeZone;
use Frontend\Constant\FrontendConst;

/**
 * Tầng đọc trang danh sách tin (FR-02, docs §5.1/§5.3): bài CÔNG KHAI
 * (status=1 AND publishedAt<=UTC_TIMESTAMP() — scope gom trong PostMapper),
 * phân trang `?page=` mỗi trang NEWS_PAGE_SIZE bài, lọc qua `?danh-muc={slug}`.
 *
 * Precedent batch 9 (05-cau-truc §4): Frontend ĐỌC bảng của Admin qua mapper
 * chủ nhờ container gộp — select thuần, không ghi.
 *
 * Chọn xử lý (spec không chốt tên query param): bộ lọc dùng slug để đồng bộ
 * style URL tiếng Việt của route. Slug không tồn tại/danh mục tắt → BỎ lọc
 * (trang vẫn là "tất cả tin", không 404 — chế 404 danh mục tắt thuộc FR-05
 * vì `/danh-muc/{slug}` là route đích của nó).
 *
 * Cache: riêng MENU DANH MỤC (NFR §7.2 "cache menu danh mục") qua key
 * `category-menu-v1` TTL 60s — Admin CategoryService forget sau mọi ghi.
 * Danh sách bài KHÔNG cache: key sẽ nhân theo (page × category) trong khi
 * mọi write của bài đều phải forget; index `(status, publishedAt)` đã phục vụ
 * LIMIT/OFFSET — TTL 60s của menu đủ cho yêu cầu "đọc nhanh" của trang này.
 */
class PostListService extends AppServiceFactory
{
    /** Múi giờ hiển thị công khai — docs §4.1 (khuôn riêng của HomeService, batch 9). */
    private const VIEW_TIMEZONE = 'Asia/Ho_Chi_Minh';

    /**
     * Một trang bài + thông tin phân trang, payload MẢNG THUẦN cho view.
     *
     * @return array{
     *     posts: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     category: ?array{id: int, name: string, slug: string}
     * }
     */
    public function paginate(?string $categorySlug, int $requestedPage): array
    {
        $category = $this->resolveFilter($categorySlug);
        $ids      = $category === null ? null : [$category['id']];

        $total = $this->posts()->countPublished($ids);
        $pages = (int) ceil($total / FrontendConst::NEWS_PAGE_SIZE);
        // Trang ngoài phạm vi bị kẹp về trang cuối — URL tay/crawler không lạc vào khoảng trống
        $page = min(max(1, $requestedPage), max(1, $pages));

        $models = $total === 0
            ? []
            : $this->posts()->listPublishedPage(
                $ids,
                FrontendConst::NEWS_PAGE_SIZE,
                ($page - 1) * FrontendConst::NEWS_PAGE_SIZE
            );

        return [
            'posts'    => $this->buildCards($models),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'category' => $category,
        ];
    }

    /**
     * Danh mục BẬT cho dropdown lọc — cache `category-menu-v1` (docs §7.2).
     * listAll() đã sort cấp 1 trước rồi con → view thụt đầu dòng theo `depth`.
     *
     * @return list<array{id: int, name: string, slug: string, depth: int}>
     */
    public function filterCategories(): array
    {
        $cache = $this->pageCache();
        if ($cache === null) {
            return $this->buildCategoryMenu();
        }

        /** @var list<array{id: int, name: string, slug: string, depth: int}> */
        return $cache->remember(CacheConst::KEY_CATEGORY_MENU, fn (): array => $this->buildCategoryMenu());
    }

    /**
     * @return list<array{id: int, name: string, slug: string, depth: int}>
     */
    private function buildCategoryMenu(): array
    {
        $menu = [];
        foreach ($this->categories()->listAll() as $category) {
            if ($category->isActive !== CategoryConst::ACTIVE) {
                continue;
            }

            $menu[] = [
                'id'    => $category->id,
                'name'  => $category->name,
                'slug'  => $category->slug,
                'depth' => $category->parentId === null ? 0 : 1,
            ];
        }

        return $menu;
    }

    /**
     * Slug lọc → danh mục chẵn (tồn tại + đang BẬT); sai/tắt → null = bỏ lọc.
     *
     * @return ?array{id: int, name: string, slug: string}
     */
    private function resolveFilter(?string $categorySlug): ?array
    {
        if ($categorySlug === null || $categorySlug === '') {
            return null;
        }

        $category = $this->categories()->findBySlug($categorySlug);
        if ($category === null || $category->isActive !== CategoryConst::ACTIVE) {
            return null;
        }

        return [
            'id'    => $category->id,
            'name'  => $category->name,
            'slug'  => $category->slug,
        ];
    }

    /**
     * Card phẳng như trang chủ (title/href/excerpt/date/minutes/image) +
     * categoryName — hai batch query chống N+1: getNamesByIds + mapCardsByIds.
     *
     * PUBLIC vì PostDetailService (FR-03) dựng lại đúng khuôn card này cho
     * khối "bài liên quan" — view dùng chung một template fragment.
     *
     * @param list<PostModel> $models
     *
     * @return list<array<string, mixed>>
     */
    public function buildCards(array $models): array
    {
        if ($models === []) {
            return [];
        }

        $categoryIds = [];
        $mediaIds    = [];
        foreach ($models as $post) {
            $categoryIds[] = $post->categoryId;
            $imageId       = $post->thumbnailMediaId ?? $post->bannerMediaId;
            if ($imageId !== null) {
                $mediaIds[] = $imageId;
            }
        }

        $names = $this->categories()->getNamesByIds(array_values(array_unique($categoryIds)));
        $media = $mediaIds === []
            ? []
            : $this->media()->mapCardsByIds(array_values(array_unique($mediaIds)));

        $cards = [];
        foreach ($models as $post) {
            $imageId = $post->thumbnailMediaId ?? $post->bannerMediaId;
            $image   = $imageId === null ? null : ($media[$imageId] ?? null);

            $cards[] = [
                'title'        => $post->title,
                'href'         => '/tin-tuc/' . $post->slug,
                'excerpt'      => $post->excerpt ?? '',
                'date'         => $this->viewDate($post->publishedAt),
                'minutes'      => $post->readingMinutes,
                'categoryName' => $names[$post->categoryId] ?? '',
                // FR-19: ưu tiên biến thể thumb WebP (khuôn HomeService::imageOf)
                // — fallback thumbnail→banner cũng bóc được biến thể.
                'image'        => $image === null ? null : [
                    'path' => $image['thumb'],
                    'alt'  => $image['alt'],
                ],
            ];
        }

        return $cards;
    }

    /**
     * Mốc 'Y-m-d H:i:s' UTC → 'd/m/Y' giờ VN (docs §4.1).
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

    /**
     * Banner `news_top` cho đầu /tin-tuc (FR-31, docs §3.6/§5.5): banner active
     * ĐẦU TIÊN đúng vị trí và đang trong cửa sổ `[startAt, endAt]` (NULL = bỏ
     * vế — cửa sổ lọc ngay trong một query của mapper, không đọc hết rồi lọc
     * PHP). Ảnh lấy path GỐC (banner rộng — không dùng biến thể thumb).
     * Frontend đọc bảng Admin qua mapper chủ, chỉ SELECT (precedent batch 9).
     *
     * @return array{title: string, subtitle: string, href: string, button: string, image: string, newTab: bool}|null
     */
    public function topBanner(): ?array
    {
        $banners = $this->banners()->listActiveByPosition(BannerConst::POSITION_NEWS_TOP, 1);
        if ($banners === []) {
            return null;
        }

        $banner = $banners[0];
        $media  = $banner->imageMediaId > 0
            ? $this->media()->mapCardsByIds([$banner->imageMediaId])
            : [];

        return [
            'title'    => $banner->title ?? '',
            'subtitle' => $banner->subtitle ?? '',
            'href'     => $banner->linkUrl ?? '',
            'button'   => $banner->buttonText ?? '',
            'image'    => $media[$banner->imageMediaId]['path'] ?? '',
            'newTab'   => $banner->openNewTab === 1,
        ];
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
}
