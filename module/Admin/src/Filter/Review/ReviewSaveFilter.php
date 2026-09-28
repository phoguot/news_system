<?php

declare(strict_types=1);

namespace Admin\Filter\Review;

use Admin\Model\Media\MediaMapper;
use Admin\Model\Review\ReviewConst;
use Application\Filter\AppInputFilter;
use Laminas\Validator\Callback;

final class ReviewSaveFilter extends AppInputFilter
{
    public function __construct(MediaMapper $media, bool $withCsrf = true)
    {
        $this->addIntCastField('imageMediaId', required: true);
        /** @var \Laminas\InputFilter\Input $input */
        $input = $this->get('imageMediaId');
        $input->getValidatorChain()->attach(new Callback([
            'callback' => static fn (mixed $value): bool => is_int($value)
                && $value > 0
                && $media->findById($value) !== null,
            'messages' => [Callback::INVALID_VALUE => ReviewConst::ERROR_MEDIA],
        ]));
        $this->addStringField('altText', required: false, maxLength: ReviewConst::MAX_LENGTH_ALT);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');
        parent::__construct($withCsrf);
    }
}
