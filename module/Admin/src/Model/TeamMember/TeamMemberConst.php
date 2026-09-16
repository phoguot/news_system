<?php

declare(strict_types=1);

namespace Admin\Model\TeamMember;

/**
 * Hằng entity `team_members` (docs §3.6; quy ước 06-quy-uoc-const).
 */
final class TeamMemberConst
{
    /** Giá trị cột isActive. */
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    /** Giá trị cột isFeatured. */
    public const FEATURED     = 1;
    public const NOT_FEATURED = 0;

    /** Giá trị cột showContact (ẩn/hiện email + SĐT ở trang công khai — FR-09, §3.8). */
    public const SHOW_CONTACT = 1;
    public const HIDE_CONTACT = 0;

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_FULL_NAME   = 150;
    public const MAX_LENGTH_POSITION    = 150;
    public const MAX_LENGTH_EMAIL       = 255;
    public const MAX_LENGTH_PHONE       = 20;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND     = 'Không tìm thấy thành viên.';
    public const ERROR_USER_TAKEN    = 'Tài khoản đã được liên kết với thành viên khác.';
    public const ERROR_USER_NOT_FOUND = 'Tài khoản liên kết không tồn tại.';
    public const ERROR_SOCIAL_LINKS  = 'Mạng xã hội phải là JSON hợp lệ (vd: {"facebook":"https://..."})';
}
