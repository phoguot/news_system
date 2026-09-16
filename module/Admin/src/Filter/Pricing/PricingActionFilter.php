<?php

declare(strict_types=1);

namespace Admin\Filter\Pricing;

use Application\Filter\AppInputFilter;

/**
 * Validate xoá mục bảng giá (id + CSRF) — form danh sách PRG.
 */
final class PricingActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField('id');

        parent::__construct($withCsrf);
    }

    public function idValue(): int
    {
        return $this->positiveIdValue('id');
    }
}
