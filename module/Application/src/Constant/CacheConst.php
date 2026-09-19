<?php

declare(strict_types=1);

namespace Application\Constant;

/**
 * Hằng cho tầng cache hiệu năng `page_cache` (FR-39, NFR-PERF-1 — docs
 * 00-tong-quan/03 §Cache): tên service trong container + khoá cache theo nhóm
 * dữ liệu. Khoá dùng dấu gạch nối (an toàn với Filesystem adapter, không có
 * ký tự phân cách thư mục). Quy ước đặt hằng: docs-dev/01-quy-chuan/06.
 */
final class CacheConst
{
    /** Tên service storage resolve qua StorageCacheAbstractServiceFactory từ key 'caches' trong global.php. */
    public const SERVICE_PAGE_CACHE = 'page_cache';

    /** Map toàn bộ settings `settingKey => settingValue` (Frontend\Service\SettingService). */
    public const KEY_SETTINGS = 'settings-v1';

    /**
     * Payload trang chủ đã dựng (danh sách section + card, mảng thuần —
     * Frontend\Service\HomeService). Invalidate sau MỌI ghi affecting home:
     * bài (save/publish/archive/draft/xoá) · banner · dịch vụ · nhân sự ·
     * home_sections + items (các Admin service forget qua PageCacheService).
     */
    public const KEY_HOME = 'home-v1';

    /**
     * Menu danh mục công khai cho bộ lọc trang tin (FR-02) —
     * Frontend\Service\PostListService::filterCategories(). Danh mục là bảng
     * nhỏ đổi chậm nên đủ với TTL 60s; Admin\Service\CategoryService forget
     * key này + `home-v1` (khối bài theo danh mục trên trang chủ) sau mọi ghi.
     */
    public const KEY_CATEGORY_MENU = 'category-menu-v1';

    /**
     * Danh sách URL đã dựng cho /sitemap.xml (FR-11, NFR-SEO-4 — docs §6.1:
     * "sitemap tự sinh, cache; cập nhật khi nội dung thay đổi") —
     * Frontend\Service\SitemapService. Mảng thuần {path, lastmod} (không host
     * — tuyệt đối hoá lúc render). Forget sau mọi ghi chạm nguồn sitemap:
     * bài (Admin\PostService) · danh mục (Admin\CategoryService) ·
     * dịch vụ (Admin\ServiceService).
     */
    public const KEY_SITEMAP = 'sitemap-v1';

    /** Bảng giá công khai — Frontend\Service\PricingViewService, TTL 60s như home. */
    public const KEY_PRICING = 'pricing-v1';

    /** Menu điều hướng công khai — Frontend\Service\MenuService. */
    public const KEY_MENU = 'menu-v1';
}
