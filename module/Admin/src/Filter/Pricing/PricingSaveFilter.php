<?php

declare(strict_types=1);

namespace Admin\Filter\Pricing;

use Application\Filter\AppInputFilter;
use Frontend\Model\Pricing\PricingConst;

/** Validate phần cấu hình giá của một dịch vụ con. */
final class PricingSaveFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField('serviceId');
        $this->addIntCastField('price', required: false);
        $this->addStringField('unit', required: false, maxLength: PricingConst::MAX_LENGTH_UNIT);
        $this->addStringField('note', required: false, maxLength: PricingConst::MAX_LENGTH_NOTE);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');
        parent::__construct($withCsrf);
    }

    public function serviceIdValue(): int
    {
        return (int) $this->positiveIdValue('serviceId');
    }
}
