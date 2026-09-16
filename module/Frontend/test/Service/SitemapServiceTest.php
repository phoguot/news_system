<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostMapper;
use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Service\SitemapService;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test cho SitemapService (FR-11 / NFR-SEO-4 — docs §6.1/§7.1):
 * - Đủ nhóm URL: trang tĩnh trước, rồi bài → danh mục → dịch vụ.
 * - `/tim-kiem` không bao giờ xuất hiện (§7.1 noindex).
 * - lastmod = phần NGÀY của updatedAt (W3C date); updatedAt rỗng → null.
 * - Cache key 'sitemap-v1': hit không chạm mapper (producer chạy 1 lần).
 */
final class SitemapServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private CategoryMapper&MockObject $categories;
    private ServiceMapper&MockObject $services;

    protected function setUp(): void
    {
        $this->posts      = $this->createMock(PostMapper::class);
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->services   = $this->createMock(ServiceMapper::class);
    }

    /** Service KHÔNG gắn PageCacheService → dependency mềm, build thẳng (khuôn batch 9). */
    private function serviceNoCache(): SitemapService
    {
        return (new SitemapService())->setContainer(new TestContainer([
            PostMapper::class      => $this->posts,
            CategoryMapper::class  => $this->categories,
            ServiceMapper::class   => $this->services,
        ]));
    }

    public function testStaticPagesLeadAndSearchNeverAppears(): void
    {
        $this->posts->method('sitemapPublished')->willReturn([]);
        $this->categories->method('sitemapActiveSlugs')->willReturn([]);
        $this->services->method('sitemapActiveSlugs')->willReturn([]);

        $urls = $this->serviceNoCache()->urls();

        $paths = array_map(static fn (array $u): string => $u['path'], $urls);
        self::assertSame(['/', '/tin-tuc', '/dich-vu', '/doi-ngu', '/lien-he'], $paths);
        self::assertNotContains('/tim-kiem', $paths);
    }

    public function testPostsMappedWithW3cDateLastmodOnly(): void
    {
        $this->posts->method('sitemapPublished')->willReturn([
            ['slug' => 'bai-moi', 'updatedAt' => '2026-09-13 03:04:05'],
            ['slug' => 'bai-thieu-moc', 'updatedAt' => ''],
        ]);
        $this->categories->method('sitemapActiveSlugs')->willReturn([]);
        $this->services->method('sitemapActiveSlugs')->willReturn([]);

        $urls = $this->serviceNoCache()->urls();

        self::assertSame(
            ['path' => '/tin-tuc/bai-moi', 'lastmod' => '2026-09-13'],
            $urls[5]
        );
        self::assertSame(
            ['path' => '/tin-tuc/bai-thieu-moc', 'lastmod' => null],
            $urls[6]
        );
    }

    public function testCategoryAndServiceSlugsAppended(): void
    {
        $this->posts->method('sitemapPublished')->willReturn([]);
        $this->categories->method('sitemapActiveSlugs')->willReturn(['the-gioi', 'khoa-hoc']);
        $this->services->method('sitemapActiveSlugs')->willReturn(['kham-tong-quat']);

        $urls  = $this->serviceNoCache()->urls();
        $paths = array_map(static fn (array $u): string => $u['path'], $urls);

        self::assertContains('/danh-muc/the-gioi', $paths);
        self::assertContains('/danh-muc/khoa-hoc', $paths);
        self::assertContains('/dich-vu/kham-tong-quat', $paths);
        self::assertCount(8, $urls);
    }

    public function testCacheHitSkipsMappersOnSecondCall(): void
    {
        /** Storage giả hướng bộ nhớ — đủ cho chu trình miss→hit của remember(). */
        /** @var array<string, string> $store */
        $store = [];
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('getItem')->willReturnCallback(
            /** @param bool|null $success */
            static function (string $key, ?bool &$success = null) use (&$store) {
                $success = isset($store[$key]);

                return $store[$key] ?? null;
            }
        );
        $storage->method('setItem')->willReturnCallback(
            /** @param string $value */
            static function (string $key, $value) use (&$store): bool {
                $store[$key] = $value;

                return true;
            }
        );

        // expects(once): nếu lần 2 đọc thẳng cache thì mapper không chạy thêm.
        $this->posts->expects(self::once())->method('sitemapPublished')->willReturn(
            [['slug' => 'mot-bai', 'updatedAt' => '2026-09-01 00:00:00']]
        );
        $this->categories->expects(self::once())->method('sitemapActiveSlugs')->willReturn([]);
        $this->services->expects(self::once())->method('sitemapActiveSlugs')->willReturn([]);

        $service = (new SitemapService())->setContainer(new TestContainer([
            PostMapper::class      => $this->posts,
            CategoryMapper::class  => $this->categories,
            ServiceMapper::class   => $this->services,
            PageCacheService::class => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $first  = $service->urls();
        $second = $service->urls();

        self::assertSame($first, $second);
        self::assertArrayHasKey(CacheConst::KEY_SITEMAP, $store);
    }
}
