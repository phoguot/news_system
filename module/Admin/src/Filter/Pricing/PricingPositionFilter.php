<?php

declare(strict_types=1);

namespace Admin\Filter\Pricing;

use Application\Filter\AppInputFilter;

/** Validate form đổi vị trí một nhóm cha hoặc dịch vụ con trong bảng giá. */
final class PricingPositionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();
        $this->addIdField('position');
        parent::__construct($withCsrf);
    }

    public function idValue(): int
    {
        return (int) $this->positiveIdValue('id');
    }

    public function positionValue(): int
    {
        return (int) $this->positiveIdValue('position');
    }
}
