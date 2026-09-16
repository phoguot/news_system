<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostMapper;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use Frontend\Model\Service\ServiceMapper;

/**
 * Tầng dựng sitemap.xml (FR-11 / NFR-SEO-4 — docs §6.1 + §7.1):
 * "sitemap tự sinh từ bài viết, danh mục, dịch vụ; cập nhật khi nội dung thay đổi" + cache.
 *
 * Nguồn URL (go-live checklist §SEO):
 * - Trang tĩnh §6.1: `/`, `/tin-tuc`, `/dich-vu`, `/doi-ngu`, `/lien-he`.
 *   `KHÔNG` có `/tim-kiem` (§7.1: trang tìm kiếm noindex → không được index).
 * - Bài: `PostMapper::sitemapPublished()` — publicScope nên nháp · hẹn giờ
 *   tương lai · archived tự loại; `lastmod` = phần NGÀY của `updatedAt` (W3C
 *   date, UTC — docs §5.8 quy ước cột giờ lưu UTC).
 * - Danh mục: `CategoryMapper::sitemapActiveSlugs()` — danh mục TẮT về 404
 *   (FR-05) nên phải vắng mặt.
 * - Dịch vụ: `ServiceMapper::sitemapActiveSlugs()` — dịch vụ tắt về 404 (FR-08).
 *
 * Đọc PostMapper/CategoryMapper (Admin-sở-hữu) + ServiceMapper (Frontend
 * sở hữu) qua container gộp — chỉ SELECT, precedent batch 9 (05-cau-truc §4).
 *
 * Cache nguyên khối key `sitemap-v1` TTL 60s theo §7.2 — payload là **mảng
 * thuần {path, lastmod} không chứa host** để tuyệt đối hoá lúc render theo
 * request (mọi host trỏ vào cùng một cache). PageCacheService là dependency
 * MỀM (khuôn batch 9): test container thiếu key → tính thẳng.
 * Invalidate: Admin\PostService / CategoryService / ServiceService forget
 * `sitemap-v1` sau mọi ghi chạm nguồn (docs §7.2 "xoá cache theo sự kiện").
 */
class SitemapService extends AppServiceFactory
{
    /** Trang tĩnh public theo docs §6.1 — thứ tự ưu tiên hiển thị trong XML. */
    private const STATIC_PATHS = [
        '/',
        '/tin-tuc',
        '/dich-vu',
        '/doi-ngu',
        '/lien-he',
    ];

    /**
     * Toàn bộ entry sitemap (đường dẫn TƯƠNG ĐỐI — host do view serverUrl()).
     *
     * @return list<array{path: string, lastmod: ?string}>
     */
    public function urls(): array
    {
        $cache = $this->pageCache();
        if ($cache === null) {
            return $this->build();
        }

        /** @var list<array{path: string, lastmod: ?string}> */
        return $cache->remember(CacheConst::KEY_SITEMAP, fn (): array => $this->build());
    }

    /**
     * Dựng payload từ DB (bước producer của cache).
     *
     * @return list<array{path: string, lastmod: ?string}>
     */
    private function build(): array
    {
        $urls = [];
        foreach (self::STATIC_PATHS as $path) {
            $urls[] = ['path' => $path, 'lastmod' => null];
        }

        foreach ($this->posts()->sitemapPublished() as $post) {
            $lastmod = $post['updatedAt'] !== '' ? substr($post['updatedAt'], 0, 10) : null;
            $urls[]  = ['path' => '/tin-tuc/' . $post['slug'], 'lastmod' => $lastmod];
        }

        foreach ($this->categories()->sitemapActiveSlugs() as $slug) {
            $urls[] = ['path' => '/danh-muc/' . $slug, 'lastmod' => null];
        }

        foreach ($this->services()->sitemapActiveSlugs() as $slug) {
            $urls[] = ['path' => '/dich-vu/' . $slug, 'lastmod' => null];
        }

        return $urls;
    }

    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function categories(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function services(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    /** PageCacheService là dependency mềm (FR-39): test container thiếu key → null. */
    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }
}
