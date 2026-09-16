<?php

declare(strict_types=1);

namespace Admin\Filter\Category;

use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate input tạo/sửa danh mục (docs §3.2) — chuẩn 07 §6: InputFilter thuần,
 * controller `new` mỗi request (stateful), dùng chung create + update.
 * $withCsrf: form trang có CSRF (mặc định); API JSON dựa vào cookie SameSite=Lax.
 */
final class CategorySaveFilter extends AppInputFilter
{
    public function __construct(
        CategoryMapper $categories,
        ?int $excludeId = null,
        bool $withCsrf = true,
    ) {
        $name = new Input('name');
        $name->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()->attach(new StringLength([
            'min'            => 2,
            'max'            => CategoryConst::MAX_LENGTH_NAME,
            'messageTooLong' => 'Tên danh mục tối đa %max% ký tự.',
        ]));
        $this->add($name);

        // Slug optional — trống thì Service tự sinh từ tên; nhập tay phải đúng format + unique
        $this->addSlugField(
            CategoryConst::MAX_LENGTH_SLUG,
            static fn (string $value): bool => $categories->existsSlug($value, $excludeId),
        );

        $this->addIdField('parentId', required: false);
        $this->addStringField('description', required: false, maxLength: CategoryConst::MAX_LENGTH_DESCRIPTION);
        $this->addIdField('coverMediaId', required: false);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');
        $this->addStringField('metaTitle', required: false, maxLength: CategoryConst::MAX_LENGTH_META_TITLE);
        $this->addStringField(
            'metaDescription',
            required: false,
            maxLength: CategoryConst::MAX_LENGTH_META_DESCRIPTION,
        );

        parent::__construct($withCsrf);
    }
}
