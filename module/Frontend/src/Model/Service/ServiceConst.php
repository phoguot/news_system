<?php

declare(strict_types=1);

namespace Frontend\Model\Service;

/**
 * Hằng entity `services` (docs §3.4; quy ước: docs-dev/01-quy-chuan/06-quy-uoc-const.md).
 * Bảng do Frontend sở hữu mapper — Admin CRUD ghi qua cùng mapper này (07 §5).
 */
final class ServiceConst
{
    /** Giá trị cột isActive. */
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_NAME             = 150;
    public const MAX_LENGTH_SLUG             = 255;
    public const MAX_LENGTH_SHORT            = 500;
    public const MAX_LENGTH_META_TITLE       = 255;
    public const MAX_LENGTH_META_DESCRIPTION = 500;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';
    public const FLAG_DELETE_BLOCKED = 'blocked';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND       = 'Không tìm thấy dịch vụ.';
    public const ERROR_DELETE_CONTACTS = 'Dịch vụ còn lượt liên hệ tham chiếu — không thể xoá.';
    public const ERROR_PARENT_INVALID  = 'Dịch vụ cha không hợp lệ.';
    public const ERROR_PARENT_SELF     = 'Dịch vụ không thể là cha của chính nó.';
}
