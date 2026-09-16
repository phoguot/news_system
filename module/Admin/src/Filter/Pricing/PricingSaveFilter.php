<?php

declare(strict_types=1);

namespace Admin\Filter\Pricing;

use Application\Filter\AppInputFilter;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Pricing\PricingMapper;

/**
 * Validate input tạo/sửa mục bảng giá — chuẩn 07 §6: InputFilter thuần,
 * Service `new` mỗi request (stateful), dùng chung create + update.
 */
final class PricingSaveFilter extends AppInputFilter
{
    public function __construct(
        PricingMapper $pricing,
        ?int $excludeId = null,
        bool $withCsrf = true,
    ) {
        $this->addStringField('groupCode', required: true, maxLength: 50);
        $this->addStringField('name', required: true, maxLength: PricingConst::MAX_LENGTH_NAME);
        $this->addSlugField(
            PricingConst::MAX_LENGTH_SLUG,
            static fn (string $value): bool => $pricing->existsSlug($value, $excludeId),
        );
        $this->addIntCastField('price', required: false);
        $this->addStringField('unit', required: false, maxLength: PricingConst::MAX_LENGTH_UNIT);
        $this->addStringField('note', required: false, maxLength: PricingConst::MAX_LENGTH_NOTE);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');

        parent::__construct($withCsrf);
    }
}
