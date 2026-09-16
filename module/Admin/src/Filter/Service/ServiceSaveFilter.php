<?php

declare(strict_types=1);

namespace Admin\Filter\Service;

use Application\Filter\AppInputFilter;
use Frontend\Model\Service\ServiceConst;
use Frontend\Model\Service\ServiceMapper;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa dịch vụ (docs §3.4) — chuẩn 07 §6: InputFilter thuần,
 * Service `new` mỗi request (stateful), dùng chung create + update.
 */
final class ServiceSaveFilter extends AppInputFilter
{
    public function __construct(
        ServiceMapper $services,
        ?int $excludeId = null,
        bool $withCsrf = true,
    ) {
        $name = new Input('name');
        $name->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()->attach(new StringLength([
            'min'            => 2,
            'max'            => ServiceConst::MAX_LENGTH_NAME,
            'messageTooLong' => 'Tên dịch vụ tối đa %max% ký tự.',
        ]));
        $this->add($name);

        // Slug optional — trống thì Service tự sinh từ tên; nhập tay phải đúng format + unique
        $this->addSlugField(
            ServiceConst::MAX_LENGTH_SLUG,
            static fn (string $value): bool => $services->existsSlug($value, $excludeId),
        );

        $this->addIdField('parentId', required: false);
        $this->addStringField(
            'shortDescription',
            required: false,
            maxLength: ServiceConst::MAX_LENGTH_SHORT,
        );
        $this->addStringField('content', required: false);
        $this->addIdField('iconMediaId', required: false);
        $this->addIdField('imageMediaId', required: false);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');
        $this->addStringField('metaTitle', required: false, maxLength: ServiceConst::MAX_LENGTH_META_TITLE);
        $this->addStringField(
            'metaDescription',
            required: false,
            maxLength: ServiceConst::MAX_LENGTH_META_DESCRIPTION,
        );

        parent::__construct($withCsrf);
    }
}
