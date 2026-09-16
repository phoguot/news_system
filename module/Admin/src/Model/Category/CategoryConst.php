<?php

declare(strict_types=1);

namespace Admin\Model\Category;

/**
 * Hằng entity `categories` (docs §3.2; quy ước: docs-dev/01-quy-chuan/06-quy-uoc-const.md).
 */
final class CategoryConst
{
    /** Độ sâu cây tối đa: cấp 1 (parentId NULL) + cấp 2 (docs §3.2). */
    public const MAX_DEPTH = 2;

    /** Giá trị cột isActive. */
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_NAME            = 150;
    public const MAX_LENGTH_SLUG            = 255;
    public const MAX_LENGTH_DESCRIPTION     = 500;
    public const MAX_LENGTH_META_TITLE      = 255;
    public const MAX_LENGTH_META_DESCRIPTION = 500;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_CREATED        = 'created';
    public const FLAG_UPDATED        = 'updated';
    public const FLAG_DELETED        = 'deleted';
    public const FLAG_DELETE_BLOCKED = 'blocked';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND        = 'Không tìm thấy danh mục.';
    public const ERROR_PARENT_SELF      = 'Danh mục không thể là danh mục cha của chính nó.';
    public const ERROR_PARENT_DEPTH     = 'Cây danh mục tối đa 2 cấp — danh mục cha phải ở cấp 1.';
    public const ERROR_PARENT_HAS_CHILD = 'Danh mục đang có danh mục con, không thể chuyển xuống làm danh mục con.';
    public const ERROR_DELETE_CHILDREN  = 'Danh mục còn danh mục con — chuyển/xoá các danh mục con trước.';
    public const ERROR_DELETE_POSTS     = 'Danh mục còn bài viết — chuyển bài sang danh mục khác trước khi xoá.';
}
