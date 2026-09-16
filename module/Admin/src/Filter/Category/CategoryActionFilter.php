<?php

declare(strict_types=1);

namespace Admin\Filter\Category;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 danh mục (xoá — docs §3.2): id + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class CategoryActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();
        parent::__construct($withCsrf);
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }
}
