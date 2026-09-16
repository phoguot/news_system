<?php

declare(strict_types=1);

namespace Admin\Filter\HomeSection;

use Admin\Model\HomeSection\HomeSectionConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\Validator\Callback;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa section trang chủ (docs §3.7) — chuẩn 07 §6:
 * InputFilter thuần, Service `new` mỗi request (stateful), dùng chung create
 * + update. `config` chỉ kiểm là JSON object hợp lệ ở đây; ràng buộc theo
 * `type` (mode/limit/category_id/…) do HomeSectionService validate vì cần
 * mapper đối chiếu.
 */
final class HomeSectionSaveFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $type = new Input('type');
        $type->setRequired(true)->getFilterChain()->attach(new ToInt());
        $type->getValidatorChain()->attach(new Callback([
            'callback' => static fn (mixed $value): bool => is_int($value)
                && isset(HomeSectionConst::TYPE_LABELS[$value]),
            'message' => HomeSectionConst::ERROR_TYPE,
        ]));
        $this->add($type);

        $this->addStringField('title', required: false, maxLength: HomeSectionConst::MAX_LENGTH_TITLE);
        $this->addStringField('subtitle', required: false, maxLength: HomeSectionConst::MAX_LENGTH_SUBTITLE);

        $config = new Input('config');
        $config->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $config->getValidatorChain()
            ->attach(new StringLength(['max' => HomeSectionConst::MAX_LENGTH_CONFIG]))
            ->attach(new Callback([
                'callback' => static fn (mixed $value): bool => self::isJsonObject($value),
                'message'  => HomeSectionConst::ERROR_CONFIG,
            ]));
        $this->add($config);

        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');

        parent::__construct($withCsrf);
    }

    /** Chuỗi rỗng = bỏ trống config; ngược lại phải decode ra JSON object. */
    public static function isJsonObject(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return true;
        }

        /** @psalm-suppress MixedAssignment */
        $decoded = json_decode($value);

        return $decoded instanceof \stdClass;
    }
}
