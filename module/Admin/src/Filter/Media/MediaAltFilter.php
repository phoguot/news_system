<?php

declare(strict_types=1);

namespace Admin\Filter\Media;

use Admin\Model\Media\MediaConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate form sửa alt text của media (docs §3.10): id + altText + CSRF.
 */
final class MediaAltFilter extends AppInputFilter
{
    public function __construct()
    {
        $this->addIdField();

        $alt = new Input('altText');
        $alt->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $alt->getValidatorChain()->attach(new StringLength([
            'max'        => MediaConst::MAX_LENGTH_ALT,
            'messageMax' => 'Alt text tối đa ' . MediaConst::MAX_LENGTH_ALT . ' ký tự.',
        ]));
        $this->add($alt);

        parent::__construct();
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }

    public function altValue(): ?string
    {
        return $this->nullableStringValue('altText');
    }
}
