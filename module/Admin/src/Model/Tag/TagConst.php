<?php

declare(strict_types=1);

namespace Admin\Model\Tag;

/**
 * Hằng entity `tags` (docs §3.4; quy ước 06-quy-uoc-const).
 */
final class TagConst
{
    /** Độ dài VARCHAR theo schema.sql. */
    public const MAX_LENGTH_NAME = 100;
    public const MAX_LENGTH_SLUG = 120;

    /** Cờ flash PRG. */
    public const FLAG_CREATED       = 'created';
    public const FLAG_UPDATED       = 'updated';
    public const FLAG_DELETED       = 'deleted';
    public const FLAG_MERGED        = 'merged';
    public const FLAG_NOT_FOUND     = 'notfound';
    public const FLAG_INVALID       = 'invalid';

    /** Lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND   = 'Không tìm thấy tag.';
    public const ERROR_MERGE_SAME  = 'Tag nguồn và tag đích phải khác nhau.';
    public const ERROR_MERGE_TARGET = 'Tag đích không tồn tại.';
}
