<?php

declare(strict_types=1);

namespace Frontend\Model\Menu;

/**
 * Hằng entity `menu_items` — menu điều hướng công khai, Admin quản trị thứ tự.
 */
final class MenuConst
{
    public const ACTIVE = 1;
    public const INACTIVE = 0;

    public const TARGET_SELF = '_self';
    public const TARGET_BLANK = '_blank';

    /** @var array<string, string> */
    public const TARGET_LABELS = [
        self::TARGET_SELF => 'Mở cùng tab',
        self::TARGET_BLANK => 'Mở tab mới',
    ];

    public const MAX_LENGTH_LABEL = 120;
    public const MAX_LENGTH_URL = 255;

    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    public const ERROR_NOT_FOUND = 'Không tìm thấy menu.';
}
