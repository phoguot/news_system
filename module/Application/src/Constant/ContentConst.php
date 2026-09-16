<?php

declare(strict_types=1);

namespace Application\Constant;

/**
 * Hằng dùng chung giữa Admin và Frontend cho hệ nội dung (bảng posts + tham chiếu
 * đa hình home_section_items). Nguồn: docs §3.3.3, §4.4.4; quy ước đặt hằng:
 * docs-dev/01-quy-chuan/06-quy-uoc-const.md §2.
 */
final class ContentConst
{
    /** posts.status (TINYINT) — docs §3.3.3. "Hẹn giờ" KHÔNG phải trạng thái riêng:
     *  status=1 + publishedAt ở tương lai (docs §5.7). */
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;
    public const STATUS_ARCHIVED  = 2;

    /** Nhãn hiển thị cho tab/danh sách admin (docs §3.3.1). */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT     => 'Nháp',
        self::STATUS_PUBLISHED => 'Đã xuất bản',
        self::STATUS_ARCHIVED  => 'Lưu trữ',
    ];

    /** home_section_items.itemType — docs §4.4.4. */
    public const SECTION_ITEM_POST        = 1;
    public const SECTION_ITEM_SERVICE     = 2;
    public const SECTION_ITEM_TEAM_MEMBER = 3;

    /** Nhãn theo itemType — màn quản mục FR-33 (Admin) và render (Frontend) dùng chung. */
    public const SECTION_ITEM_LABELS = [
        self::SECTION_ITEM_POST        => 'Bài viết',
        self::SECTION_ITEM_SERVICE     => 'Dịch vụ',
        self::SECTION_ITEM_TEAM_MEMBER => 'Nhân sự',
    ];
}
