<?php

declare(strict_types=1);

namespace Admin\Filter\Tag;

use Admin\Model\Tag\TagConst;
use Admin\Model\Tag\TagMapper;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\Input;
use Laminas\Validator\StringLength;

/**
 * Validate tạo/sửa tag (docs §3.4) — InputFilter theo chuẩn 07 §6, `new` mỗi
 * request. Slug optional: trống thì Service tự sinh từ tên và tự hậu tố khi trùng.
 */
final class TagSaveFilter extends AppInputFilter
{
    public function __construct(
        TagMapper $tags,
        ?int $excludeId = null,
        bool $withCsrf = true,
    ) {
        $name = new Input('name');
        $name->setRequired(true)->getFilterChain()->attach(new StringTrim());
        $name->getValidatorChain()->attach(new StringLength([
            'min' => 2,
            'max' => TagConst::MAX_LENGTH_NAME,
        ]));
        $this->add($name);

        $this->addSlugField(
            TagConst::MAX_LENGTH_SLUG,
            static fn (string $value): bool => $tags->existsSlug($value, $excludeId),
        );

        parent::__construct($withCsrf);
    }
}
