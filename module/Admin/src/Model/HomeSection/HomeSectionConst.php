<?php

declare(strict_types=1);

namespace Admin\Model\HomeSection;

use Application\Constant\ContentConst;

/**
 * Hằng entity `home_sections` (docs §3.7, §4.4.4; quy ước 06-quy-uoc-const).
 * Mã `type` đối chiếu bảng trong 02-quy-chuan-db.md §4 — thêm type mới phải
 * cập nhật cả docs + HomeSectionService::configRules().
 */
final class HomeSectionConst
{
    /** Mã cột `type` (02-quy-chuan-db §4: 1 hero_banner … 7 contact_cta). */
    public const TYPE_HERO_BANNER    = 1;
    public const TYPE_FEATURED_POSTS = 2;
    public const TYPE_LATEST_POSTS   = 3;
    public const TYPE_CATEGORY_POSTS = 4;
    public const TYPE_SERVICES       = 5;
    public const TYPE_TEAM           = 6;
    public const TYPE_CONTACT_CTA    = 7;
    public const TYPE_PROCESS        = 8;

    /** @var array<int, string> type => nhãn hiển thị */
    public const TYPE_LABELS = [
        self::TYPE_HERO_BANNER    => 'Hero banner',
        self::TYPE_FEATURED_POSTS => 'Tin nổi bật',
        self::TYPE_LATEST_POSTS   => 'Tin mới nhất',
        self::TYPE_CATEGORY_POSTS => 'Tin theo danh mục',
        self::TYPE_SERVICES       => 'Dịch vụ',
        self::TYPE_TEAM           => 'Đội ngũ',
        self::TYPE_CONTACT_CTA    => 'CTA liên hệ',
        self::TYPE_PROCESS        => 'Quy trình',
    ];

    /** Giá trị `mode` trong config các section danh sách. */
    public const MODE_AUTO   = 'auto';
    public const MODE_MANUAL = 'manual';

    /** Giá trị cột isActive. */
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    /** Giới hạn kỹ thuật cho config. */
    public const LIMIT_MIN  = 1;
    public const LIMIT_MAX  = 50;
    public const INTERVAL_MS_MIN = 1000;
    public const INTERVAL_MS_MAX = 60000;

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_TITLE    = 255;
    public const MAX_LENGTH_SUBTITLE = 500;
    public const MAX_LENGTH_BUTTON   = 255;
    public const MAX_LENGTH_URL      = 500;
    public const MAX_LENGTH_CONFIG   = 60000;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';
    public const FLAG_ITEM_ADDED   = 'item-added';
    public const FLAG_ITEM_REMOVED = 'item-removed';
    public const FLAG_ITEM_MOVED   = 'item-moved';
    public const FLAG_ITEM_DUPLICATE = 'item-duplicate';
    public const FLAG_ITEM_MISSING   = 'item-missing';
    public const FLAG_ITEM_LIMIT     = 'item-limit';

    /** Số mục tối đa trong một section manual (trần LIMIT_MAX theo docs §3.7). */
    public const ITEM_LIMIT = self::LIMIT_MAX;

    /**
     * Section cho phép `mode=manual` và itemType tương ứng của mục được chọn
     * (docs §4.4.4 — bài cho tin nổi bật, dịch vụ cho khối dịch vụ, nhân sự
     * cho khối đội ngũ; `ContentConst::SECTION_ITEM_*`).
     *
     * @var array<int, int>
     */
    public const MANUAL_ITEM_TYPES = [
        self::TYPE_FEATURED_POSTS => ContentConst::SECTION_ITEM_POST,
        self::TYPE_SERVICES       => ContentConst::SECTION_ITEM_SERVICE,
        self::TYPE_TEAM           => ContentConst::SECTION_ITEM_TEAM_MEMBER,
    ];

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND = 'Không tìm thấy section trang chủ.';
    public const ERROR_TYPE      = 'Loại section không hợp lệ.';
    public const ERROR_CONFIG    = 'Config phải là JSON object hợp lệ.';
    public const ERROR_MODE      = 'mode chỉ nhận giá trị auto hoặc manual.';
    public const ERROR_LIMIT     = 'limit phải là số nguyên từ 1 đến 50.';
    public const ERROR_INTERVAL  = 'interval_ms phải là số nguyên từ 1000 đến 60000.';
    public const ERROR_AUTOPLAY  = 'autoplay phải là true hoặc false.';
    public const ERROR_CATEGORY   = 'category_id là số nguyên dương và phải tồn tại.';
    public const ERROR_BUTTON_URL = 'button_url phải bắt đầu bằng http://, https:// hoặc /.';
    public const ERROR_UNKNOWN_KEY = 'Config chứa khóa không hợp lệ với loại section.';
    public const ERROR_PROCESS_STEPS = 'Quy trình cần mảng steps gồm 3-6 bước, mỗi bước có title (2-80 ký tự).';
}
