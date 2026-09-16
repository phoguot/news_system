<?php

declare(strict_types=1);

namespace Frontend\Model\Pricing;

/**
 * Hằng entity `pricing_items` (bảng giá — Medlatec-style, docs §3.5 mở rộng).
 * Bảng do Frontend sở hữu mapper — Admin CRUD qua cùng mapper (07 §5).
 */
final class PricingConst
{
    public const ACTIVE   = 1;
    public const INACTIVE = 0;

    public const GROUP_GENERAL  = 'general';
    public const GROUP_HOSPITAL = 'hospital';
    public const GROUP_HOME     = 'home';

    /** @var array<string, string> groupCode => nhãn */
    public const GROUP_LABELS = [
        self::GROUP_GENERAL  => 'Chung',
        self::GROUP_HOSPITAL => 'Tại viện',
        self::GROUP_HOME     => 'Tại nhà',
    ];

    public const MAX_LENGTH_NAME = 255;
    public const MAX_LENGTH_SLUG = 255;
    public const MAX_LENGTH_UNIT = 100;
    public const MAX_LENGTH_NOTE = 500;

    public const FLAG_CREATED = 'created';
    public const FLAG_UPDATED = 'updated';
    public const FLAG_DELETED = 'deleted';

    public const ERROR_NOT_FOUND = 'Không tìm thấy mục bảng giá.';
}
