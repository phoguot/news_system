<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Banner\BannerConst;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Banner\BannerModel;
use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\PostListService;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc /tin-tuc (FR-02): scope công khai do mapper lo, service chốt
 * phân trang (kẹp trang · đếm trước rồi mới query), lọc `?danh-muc={slug}`
 * chỉ nhận danh mục BẬT, menu danh mục cache 'category-menu-v1', card hydrate
 * 2 batch query (tên danh mục + ảnh) không N+1.
 */
final class PostListServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private CategoryMapper&MockObject $categories;
    private MediaMapper&MockObject $media;
    private BannerMapper&MockObject $banners;
    private StorageInterface&MockObject $storage;
    private PostListService $service;

    protected function setUp(): void
    {
        $this->posts      = $this->createMock(PostMapper::class);
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->media      = $this->createMock(MediaMapper::class);
        $this->banners    = $this->createMock(BannerMapper::class);
        $this->storage    = $this->createMock(StorageInterface::class);

        $this->service = $this->containerWith(
            (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $this->storage])
            )
        );
    }

    private function containerWith(?PageCacheService $pageCache): PostListService
    {
        $entries = [
            PostMapper::class      => $this->posts,
            CategoryMapper::class  => $this->categories,
            MediaMapper::class     => $this->media,
            BannerMapper::class    => $this->banners,
        ];
        if ($pageCache !== null) {
            $entries[PageCacheService::class] = $pageCache;
        }

        return (new PostListService())->setContainer(new TestContainer($entries));
    }

    /** @param array<string, mixed> $overrides */
    private function post(int $id, array $overrides = []): PostModel
    {
        return PostModel::fromRow($overrides + [
            'id'             => $id,
            'categoryId'     => 3,
            'title'          => 'Bai ' . $id,
            'slug'           => 'bai-' . $id,
            'excerpt'        => 'Tom tat ' . $id,
            'publishedAt'    => '2026-09-12 18:00:00',
            'readingMinutes' => 4,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function category(array $overrides = []): CategoryModel
    {
        return CategoryModel::fromRow($overrides + [
            'id'       => 3,
            'name'     => 'Thi truong',
            'slug'     => 'thi-truong',
            'isActive' => CategoryConst::ACTIVE,
        ]);
    }

    public function testPageTwoUsesOffsetTwelveAndHydratesCard(): void
    {
        $this->posts->expects(self::once())->method('countPublished')->with(null)->willReturn(30);
        $this->posts->expects(self::once())->method('listPublishedPage')
            ->with(null, 12, 12)->willReturn([$this->post(9, ['thumbnailMediaId' => 7])]);
        $this->categories->expects(self::once())->method('getNamesByIds')->with([3])
            ->willReturn([3 => 'Thi truong']);
        $this->media->expects(self::once())->method('mapCardsByIds')->with([7])
            ->willReturn([7 => ['path' => '2026/09/a.png', 'alt' => 'Minh hoa', 'thumb' => '2026/09/a-thumb.webp']]);

        $result = $this->service->paginate(null, 2);

        self::assertSame(30, $result['total']);
        self::assertSame(3, $result['pages']);
        self::assertSame(2, $result['page']);
        self::assertNull($result['category']);
        self::assertSame('Bai 9', $result['posts'][0]['title']);
        self::assertSame('/tin-tuc/bai-9', $result['posts'][0]['href']);
        self::assertSame('13/09/2026', $result['posts'][0]['date']);
        self::assertSame(4, $result['posts'][0]['minutes']);
        self::assertSame('Thi truong', $result['posts'][0]['categoryName']);
        $image = $result['posts'][0]['image'];
        self::assertIsArray($image);
        /** @var array<string, mixed> $image */
        // FR-19: card lấy BIẾN THỂ thumb WebP, không phải ảnh gốc.
        self::assertSame('2026/09/a-thumb.webp', (string) $image['path']);
        self::assertSame('Minh hoa', (string) $image['alt']);
    }

    public function testThumbnailMissingFallsBackToBannerThumbVariant(): void
    {
        // FR-19: bài chỉ có banner → thumbnail fallback chính biến thể thumb của banner.
        $this->posts->expects(self::once())->method('countPublished')->with(null)->willReturn(1);
        $this->posts->expects(self::once())->method('listPublishedPage')
            ->with(null, 12, 0)->willReturn([$this->post(5, ['thumbnailMediaId' => null, 'bannerMediaId' => 8])]);
        $this->categories->method('getNamesByIds')->willReturn([3 => 'Thi truong']);
        $this->media->expects(self::once())->method('mapCardsByIds')->with([8])
            ->willReturn([8 => ['path' => '2026/09/b.jpg', 'alt' => 'Banner', 'thumb' => '2026/09/b-thumb.webp']]);

        $image = $this->service->paginate(null, 1)['posts'][0]['image'];
        self::assertIsArray($image);
        /** @var array<string, mixed> $image */
        self::assertSame('2026/09/b-thumb.webp', (string) $image['path']);
    }

    public function testActiveSlugFiltersByItsId(): void
    {
        $this->categories->method('findBySlug')->with('thi-truong')
            ->willReturn($this->category(['id' => 4]));
        $this->posts->expects(self::once())->method('countPublished')->with([4])->willReturn(1);
        $this->posts->expects(self::once())->method('listPublishedPage')
            ->with([4], 12, 0)->willReturn([$this->post(1, ['categoryId' => 4])]);
        $this->categories->method('getNamesByIds')->willReturn([4 => 'Thi truong']);

        $result = $this->service->paginate('thi-truong', 1);

        self::assertSame(['id' => 4, 'name' => 'Thi truong', 'slug' => 'thi-truong'], $result['category']);
    }

    public function testUnknownOrInactiveSlugDropsFilterSilently(): void
    {
        $this->categories->method('findBySlug')->willReturnCallback(
            fn (string $slug): ?CategoryModel => $slug === 'tam'
                ? $this->category(['id' => 8, 'isActive' => CategoryConst::INACTIVE])
                : null
        );
        $this->posts->expects(self::exactly(2))->method('countPublished')->with(null)->willReturn(0);
        $this->posts->expects(self::never())->method('listPublishedPage');

        self::assertNull($this->service->paginate('tam', 1)['category']);
        self::assertNull($this->service->paginate('sai', 1)['category']);
    }

    public function testPageBeyondLastIsClamped(): void
    {
        $this->posts->method('countPublished')->willReturn(5);
        $this->posts->expects(self::once())->method('listPublishedPage')
            ->with(null, 12, 0)->willReturn([$this->post(1)]);
        $this->categories->method('getNamesByIds')->willReturn([3 => 'Thi truong']);

        $result = $this->service->paginate(null, 99);

        self::assertSame(1, $result['page']);
        self::assertSame(1, $result['pages']);
    }

    public function testEmptyResultSkipsListQuery(): void
    {
        $this->posts->method('countPublished')->willReturn(0);
        $this->posts->expects(self::never())->method('listPublishedPage');
        $this->media->expects(self::never())->method('mapCardsByIds');

        $result = $this->service->paginate(null, 1);

        self::assertSame([], $result['posts']);
        self::assertSame(0, $result['pages']);
        self::assertSame(1, $result['page']);
    }

    public function testMenuMissBuildsActiveOnlyWithDepthAndWritesKey(): void
    {
        $this->categories->method('listAll')->willReturn([
            $this->category(['id' => 1, 'parentId' => null]),
            $this->category(['id' => 2, 'parentId' => 1, 'slug' => 'con', 'name' => 'Con']),
            $this->category(['id' => 3, 'parentId' => null, 'slug' => 'tat', 'isActive' => CategoryConst::INACTIVE]),
        ]);
        $this->storage->expects(self::once())->method('setItem')
            ->with(CacheConst::KEY_CATEGORY_MENU, self::callback('is_string'));

        $menu = $this->service->filterCategories();

        self::assertSame(
            [
                ['id' => 1, 'name' => 'Thi truong', 'slug' => 'thi-truong', 'depth' => 0],
                ['id' => 2, 'name' => 'Con', 'slug' => 'con', 'depth' => 1],
            ],
            $menu
        );
    }

    public function testMenuHitSkipsMapper(): void
    {
        $cached = [['id' => 1, 'name' => 'X', 'slug' => 'x', 'depth' => 0]];
        $this->categories->expects(self::never())->method('listAll');
        $this->storage->method('getItem')->willReturnCallback(
            /** @param bool|null $success */
            static function (string $key, ?bool &$success = null) use ($cached) {
                $success = true;

                return serialize([$cached]);
            }
        );

        self::assertSame($cached, $this->service->filterCategories());
    }

    public function testMenuWorksWithoutCacheEntry(): void
    {
        $service = $this->containerWith(null);
        $this->categories->expects(self::once())->method('listAll')
            ->willReturn([$this->category()]);

        self::assertCount(1, $service->filterCategories());
    }

    /* ---- FR-31: banner news_top đầu /tin-tuc ---- */

    public function testTopBannerHydratesFirstActiveNewsTop(): void
    {
        $this->banners->method('listActiveByPosition')
            ->with(BannerConst::POSITION_NEWS_TOP, 1)
            ->willReturn([BannerModel::fromRow([
                'id'           => 4,
                'position'     => BannerConst::POSITION_NEWS_TOP,
                'title'        => 'Khuyen mai thu',
                'subtitle'     => 'Uu dai 20%',
                'imageMediaId' => 44,
                'linkUrl'      => '/tin-tuc/km',
                'buttonText'   => 'Xem ngay',
                'openNewTab'   => 1,
            ])]);
        $this->media->method('mapCardsByIds')->with([44])->willReturn([
            44 => ['path' => '2026/09/top.jpg', 'alt' => 'Top', 'thumb' => '2026/09/top-thumb.webp'],
        ]);

        $banner = $this->service->topBanner();
        self::assertIsArray($banner);
        /** @var array<string, mixed> $banner */
        self::assertSame('Khuyen mai thu', (string) $banner['title']);
        self::assertSame('Xem ngay', (string) $banner['button']);
        // Banner dau trang dung Anh GOC — khong phai bien the thumb (khac card FR-19).
        self::assertSame('2026/09/top.jpg', (string) $banner['image']);
        self::assertSame('/tin-tuc/km', (string) $banner['href']);
        self::assertTrue((bool) $banner['newTab']);
    }

    public function testTopBannerNullWhenNoActiveNewsTop(): void
    {
        $this->banners->method('listActiveByPosition')->willReturn([]);
        $this->media->expects(self::never())->method('mapCardsByIds');

        self::assertNull($this->service->topBanner());
    }

    public function testTopBannerWithoutImageSkipsMediaQuery(): void
    {
        $this->banners->method('listActiveByPosition')
            ->willReturn([BannerModel::fromRow(['id' => 9, 'title' => 'Khong anh'])]);
        $this->media->expects(self::never())->method('mapCardsByIds');

        $banner = $this->service->topBanner();
        self::assertIsArray($banner);
        /** @var array<string, mixed> $banner */
        self::assertSame('', (string) $banner['image']);
        self::assertFalse((bool) $banner['newTab']);
    }
}
