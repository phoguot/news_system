<?php

declare(strict_types=1);

namespace Admin\Filter\Menu;

use Application\Filter\AppInputFilter;
use Frontend\Model\Menu\MenuConst;
use Laminas\InputFilter\Input;
use Laminas\Validator\InArray;
use Laminas\Validator\Regex;
use LogicException;

/**
 * Validate tạo/sửa menu công khai.
 */
final class MenuSaveFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addStringField('label', required: true, maxLength: MenuConst::MAX_LENGTH_LABEL);
        $this->addStringField('url', required: true, maxLength: MenuConst::MAX_LENGTH_URL);
        $url = $this->get('url');
        if (! $url instanceof Input) {
            throw new LogicException('Input url không đúng kiểu mong đợi.');
        }
        $url->getValidatorChain()->attach(new Regex([
            'pattern' => '~^(?:/|https?://|mailto:|tel:)~i',
            'message' => 'URL phải bắt đầu bằng /, http(s)://, mailto: hoặc tel:.',
        ]));

        $target = new Input('target');
        $target->setRequired(true);
        $target->getValidatorChain()->attach(new InArray([
            'haystack' => array_keys(MenuConst::TARGET_LABELS),
            'strict' => true,
            'message' => 'Kiểu mở menu không hợp lệ.',
        ]));
        $this->add($target);

        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');

        parent::__construct($withCsrf);
    }
}
