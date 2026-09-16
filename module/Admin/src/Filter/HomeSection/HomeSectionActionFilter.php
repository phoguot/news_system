<?php

declare(strict_types=1);

namespace Admin\Filter\HomeSection;

use Application\Filter\AppInputFilter;

/**
 * Validate input xoá section trang chủ — chuẩn 07 §6 (id + csrf).
 */
final class HomeSectionActionFilter extends AppInputFilter
{
    public function __construct()
    {
        $this->addIdField();
        parent::__construct();
    }

    public function idValue(): mixed
    {
        return $this->getValue('id');
    }
}
