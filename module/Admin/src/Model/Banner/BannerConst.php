<?php

declare(strict_types=1);

namespace Admin\Model\Banner;

/**
 * Hằng entity `banners` (docs §3.5, §4.4.6; quy ước 06-quy-uoc-const).
 */
final class BannerConst
{
    /** Giá trị cột isActive. */
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    /** Vị trí hiển thị đã biết (docs §3.5 — thêm vị trí mới tại đây + select view). */
    public const POSITION_HOME_HERO = 'home_hero';
    public const POSITION_NEWS_TOP  = 'news_top';

    /** @var array<string, string> position => nhãn */
    public const POSITION_LABELS = [
        self::POSITION_HOME_HERO => 'Hero trang chủ',
        self::POSITION_NEWS_TOP  => 'Đầu trang tin tức',
    ];

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_POSITION   = 50;
    public const MAX_LENGTH_TITLE      = 255;
    public const MAX_LENGTH_SUBTITLE   = 500;
    public const MAX_LENGTH_LINK       = 500;
    public const MAX_LENGTH_BUTTON     = 50;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND     = 'Không tìm thấy banner.';
    public const ERROR_INVALID_TIME  = 'Định dạng thời gian không hợp lệ (YYYY-MM-DD HH:MM giờ VN).';
    public const ERROR_TIME_RANGE    = 'Ngày kết thúc phải sau ngày bắt đầu.';
    public const ERROR_POSITION      = 'Vị trí banner không hợp lệ.';
    public const ERROR_LINK          = 'Link phải bắt đầu bằng http://, https:// hoặc /.';
}
