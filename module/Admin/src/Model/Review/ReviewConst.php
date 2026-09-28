<?php

declare(strict_types=1);

namespace Admin\Model\Review;

final class ReviewConst
{
    public const ACTIVE = 1;
    public const INACTIVE = 0;

    public const MAX_LENGTH_ALT = 255;

    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    public const ERROR_NOT_FOUND = 'Không tìm thấy ảnh đánh giá.';
    public const ERROR_MEDIA = 'Vui lòng chọn một ảnh hợp lệ từ thư viện.';
}
