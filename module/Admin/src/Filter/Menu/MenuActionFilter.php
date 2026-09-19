<?php

declare(strict_types=1);

namespace Admin\Filter\Menu;

use Application\Filter\AppInputFilter;

/**
 * Validate form xoá menu (id + CSRF).
 */
final class MenuActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField('id');

        parent::__construct($withCsrf);
    }

    public function idValue(): int
    {
        return (int) $this->positiveIdValue('id');
    }
}
