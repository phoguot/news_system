<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Banner\BannerMapper;
use Admin\Model\Banner\BannerModel;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\HomeSection\HomeSectionConst;
use Admin\Model\HomeSection\HomeSectionMapper;
use Admin\Model\HomeSection\HomeSectionModel;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\HomeSectionItem\HomeSectionItemModel;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\TeamMember\TeamMemberMapper;
use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Service\HomeService;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc trang chủ (FR-32 render + FR-39 cache `home-v1`): payload mảng
 * thuần theo thứ tự section; hit bỏ qua DB, miss tính rồi ghi đúng key;
 * manual giữ thứ tự id và bỏ mục chết; khối rỗng bị ẩn; thiếu cache vẫn chạy.
 */
final class HomeServiceTest extends TestCase
{
    private HomeSectionMapper&MockObject $sections;
    private HomeSectionItemMapper&MockObject $items;
    private PostMapper&MockObject $posts;
    private BannerMapper&MockObject $banners;
    private ServiceMapper&MockObject $services;
    private TeamMemberMapper&MockObject $team;
    private CategoryMapper&MockObject $categories;
    private MediaMapper&MockObject $media;
    private StorageInterface&MockObject $storage;
    private HomeService $service;

    protected function setUp(): void
    {
        $this->sections   = $this->createMock(HomeSectionMapper::class);
        $this->items      = $this->createMock(HomeSectionItemMapper::class);
        $this->posts      = $this->createMock(PostMapper::class);
        $this->banners    = $this->createMock(BannerMapper::class);
        $this->services   = $this->createMock(ServiceMapper::class);
        $this->team       = $this->createMock(TeamMemberMapper::class);
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->media      = $this->createMock(MediaMapper::class);
        $this->storage    = $this->createMock(StorageInterface::class);

        $pageCache = (new PageCacheService())->setContainer(
            new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $this->storage])
        );
        $this->service = $this->containerWith($pageCache);
    }

    private function containerWith(?PageCacheService $pageCache): HomeService
    {
        $entries = [
            HomeSectionMapper::class     => $this->sections,
            HomeSectionItemMapper::class => $this->items,
            PostMapper::class            => $this->posts,
            BannerMapper::class          => $this->banners,
            ServiceMapper::class         => $this->services,
            TeamMemberMapper::class      => $this->team,
            CategoryMapper::class        => $this->categories,
            MediaMapper::class           => $this->media,
        ];
        if ($pageCache !== null) {
            $entries[PageCacheService::class] = $pageCache;
        }

        return (new HomeService())->setContainer(new TestContainer($entries));
    }

    /** @param array<string, mixed>|null $config */
    private function section(int $id, int $type, ?string $title = null, ?array $config = null): HomeSectionModel
    {
        return HomeSectionModel::fromRow([
            'id'     => $id,
            'type'   => $type,
            'title'  => $title,
            'config' => $config === null ? null : json_encode($config),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function post(int $id, array $overrides = []): PostModel
    {
        return PostModel::fromRow($overrides + [
            'id'           => $id,
            'title'        => 'Bai ' . $id,
            'slug'         => 'bai-' . $id,
            'publishedAt'  => '2026-09-12 18:00:00',
            'readingMinutes' => 4,
        ]);
    }

    private function banner(int $id, ?int $imageId): BannerModel
    {
        return BannerModel::fromRow([
            'id'            => $id,
            'title'         => 'Banner ' . $id,
            'imageMediaId'  => $imageId,
            'buttonText'    => 'Xem',
            'openNewTab'    => 1,
        ]);
    }

    /** @param list<int> $itemIds */
    private function manualItems(int $sectionId, int $itemType, array $itemIds): void
    {
        $this->items->method('listBySectionId')->willReturnCallback(
            /** @return list<HomeSectionItemModel> */
            static function (int $sid) use ($itemIds, $itemType, $sectionId): array {
                if ($sid !== $sectionId) {
                    return [];
                }

                $rows = [];
                foreach ($itemIds as $index => $itemId) {
                    $rows[] = HomeSectionItemModel::fromRow([
                        'id'        => $index + 1,
                        'sectionId' => $sid,
                        'itemType'  => $itemType,
                        'itemId'    => $itemId,
                    ]);
                }

                return $rows;
            }
        );
    }

    public function testMissBuildsPayloadAndWritesHomeKey(): void
    {
        $this->sections->method('listActiveOrdered')->willReturn([
            $this->section(1, HomeSectionConst::TYPE_HERO_BANNER, 'Chao', ['autoplay' => false, 'interval_ms' => 7000]),
        ]);
        $this->banners->method('listActiveHomeHero')->willReturn([$this->banner(5, null)]);
        $this->storage
            ->expects(self::once())
            ->method('setItem')
            ->with(CacheConst::KEY_HOME, self::callback('is_string'));

        $blocks = $this->service->sections();

        /** @var list<array<string, mixed>> $heroItems */
        $heroItems = $blocks[0]['items'];
        self::assertCount(1, $blocks);
        self::assertSame('hero', $blocks[0]['kind']);
        self::assertSame('Chao', $blocks[0]['title']);
        self::assertFalse($blocks[0]['autoplay']);
        self::assertSame(7000, $blocks[0]['intervalMs']);
        self::assertSame('Banner 5', $heroItems[0]['title']);
        self::assertTrue($heroItems[0]['newTab']);
        // imageMediaId = 0 → không có ảnh, resolveImages không thêm key 'image'.
        self::assertArrayNotHasKey('image', $heroItems[0]);
    }

    public function testHitSkipsDbEntirely(): void
    {
        $cached = [['kind' => 'cta', 'title' => 'T', 'href' => '/lien-he', 'button' => 'Go', 'items' => []]];
        $this->sections->expects(self::never())->method('listActiveOrdered');
        $this->storage->method('getItem')->willReturnCallback(
            /** @param bool|null $success */
            static function (string $key, ?bool &$success = null) use ($cached) {
                $success = true;

                return serialize([$cached]);
            }
        );

        self::assertSame($cached, $this->service->sections());
    }

    public function testWorksWithoutCacheEntry(): void
    {
        // TestContainer thiếu PageCacheService → producer chạy thẳng, không nổ.
        $service = $this->containerWith(null);
        $this->sections->method('listActiveOrdered')->willReturn([]);
        $this->sections->expects(self::once())->method('listActiveOrdered');

        self::assertSame([], $service->sections());
    }

    public function testManualFeaturedKeepsIdOrderAndSkipsDeadItems(): void
    {
        $this->sections->method('listActiveOrdered')->willReturn([
            $this->section(2, HomeSectionConst::TYPE_FEATURED_POSTS, 'Nổi bật', ['mode' => 'manual']),
        ]);
        $this->manualItems(2, 1, [30, 10, 99]);
        // 99 đã bị ẩn/xoá → mapper công khai không trả về.
        $this->posts->method('listPublishedByIds')
            ->with(self::callback(
                /** @param list<int> $ids */
                static fn (array $ids): bool => $ids === [30, 10, 99]
            ))
            ->willReturn([$this->post(10), $this->post(30)]);

        $blocks = $this->service->sections();

        /** @var list<array<string, mixed>> $postItems */
        $postItems = $blocks[0]['items'];
        self::assertSame(['Bai 30', 'Bai 10'], array_column($postItems, 'title'));
        self::assertSame('/tin-tuc/bai-30', $postItems[0]['href']);
        // 12/09 18:00 UTC = 13/09 01:00 VN → mốc đổi ngày phải hiển thị theo VN.
        self::assertSame('13/09/2026', $postItems[0]['date']);
    }

    public function testEmptyBlocksAreDroppedSilently(): void
    {
        $this->sections->method('listActiveOrdered')->willReturn([
            $this->section(1, HomeSectionConst::TYPE_HERO_BANNER),            // 0 banner
            $this->section(2, HomeSectionConst::TYPE_LATEST_POSTS),           // 0 bài
            $this->section(3, HomeSectionConst::TYPE_CATEGORY_POSTS, null, ['category_id' => 9]),
            // ^ category 9 không tồn tại
            $this->section(4, HomeSectionConst::TYPE_CONTACT_CTA, 'CTA'),     // thiếu button
        ]);
        $this->banners->method('listActiveHomeHero')->willReturn([]);
        $this->posts->method('listLatestPublished')->willReturn([]);
        $this->categories->method('findById')->willReturn(null);

        self::assertSame([], $this->service->sections());
    }

    public function testCategoryBlockFallsBackToCategoryNameAndResolvesImages(): void
    {
        $this->sections->method('listActiveOrdered')->willReturn([
            $this->section(3, HomeSectionConst::TYPE_CATEGORY_POSTS, null, ['category_id' => 4, 'limit' => 2]),
        ]);
        $this->categories->method('findById')->with(4)->willReturn(
            CategoryModel::fromRow(['id' => 4, 'name' => 'Công nghệ', 'slug' => 'cong-nghe'])
        );
        // category_id phải đổ vào scope bài theo danh mục, không phải featured.
        $this->posts->method('listPublishedByCategory')->with(4, 2)
            ->willReturn([$this->post(7, ['thumbnailMediaId' => 21])]);
        $this->media->method('mapCardsByIds')->with([21])
            ->willReturn([21 => ['path' => '/m/7.jpg', 'alt' => 'A', 'thumb' => '/m/7-thumb.jpg']]);

        $blocks = $this->service->sections();

        self::assertSame('Công nghệ', $blocks[0]['title']);
        self::assertSame('/danh-muc/cong-nghe', $blocks[0]['headingHref']);
        /** @var list<array<string, mixed>> $catItems */
        $catItems = $blocks[0]['items'];
        /** @var array{path: string, alt: string} $image */
        $image = $catItems[0]['image'];
        self::assertSame('/m/7-thumb.jpg', $image['path']);
        self::assertSame('A', $image['alt']);
    }
}
