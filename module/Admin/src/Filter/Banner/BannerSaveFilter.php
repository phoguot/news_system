<?php

declare(strict_types=1);

namespace Admin\Filter\Banner;

use Application\Filter\AppInputFilter;
use Admin\Model\Banner\BannerConst;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;
use Laminas\Validator\Digits;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa banner (docs §3.5) — chuẩn 07 §6: InputFilter thuần,
 * Service `new` mỗi request (stateful), dùng chung create + update.
 * startAt/endAt là giờ tường minh theo giờ VN (định dạng datetime-local hoặc
 * 'Y-m-d H:i[:s]') — kiểm format ở đây (addDateTimeField nền tảng), quy đổi
 * UTC ở BannerService.
 */
final class BannerSaveFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $position = new Input('position');
        $position->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $position->getValidatorChain()
            ->attach(new StringLength(['max' => BannerConst::MAX_LENGTH_POSITION]))
            ->attach(new Callback([
                'callback' => static fn (mixed $value): bool => is_string($value)
                    && isset(BannerConst::POSITION_LABELS[$value]),
                'message' => BannerConst::ERROR_POSITION,
            ]));
        $this->add($position);

        $this->addStringField('title', required: false, maxLength: BannerConst::MAX_LENGTH_TITLE);
        $this->addStringField('subtitle', required: false, maxLength: BannerConst::MAX_LENGTH_SUBTITLE);

        $image = new Input('imageMediaId');
        $image->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $image->getValidatorChain()
            ->attach(new Digits(['message' => 'Phải chọn ảnh banner (media id).']))
            ->attach(new Callback([
                'callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
                'message'  => 'Ảnh banner chưa hợp lệ (media id > 0).',
            ]));
        $this->add($image);

        $this->addIdField('mobileImageMediaId', required: false);

        $link = new Input('linkUrl');
        $link->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $link->getValidatorChain()
            ->attach(new StringLength(['max' => BannerConst::MAX_LENGTH_LINK]))
            ->attach(new Callback([
                'callback' => static function (mixed $value): bool {
                    if (! is_string($value) || $value === '') {
                        return true;
                    }

                    return preg_match('#^(https?://|/)#', $value) === 1;
                },
                'message' => BannerConst::ERROR_LINK,
            ]));
        $this->add($link);

        $this->addRawField('openNewTab');
        $this->addStringField('buttonText', required: false, maxLength: BannerConst::MAX_LENGTH_BUTTON);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');

        foreach (['startAt', 'endAt'] as $timeField) {
            $this->addDateTimeField($timeField, BannerConst::ERROR_INVALID_TIME);
        }

        parent::__construct($withCsrf);
    }
}
