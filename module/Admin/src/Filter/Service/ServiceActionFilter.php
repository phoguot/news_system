<?php

declare(strict_types=1);

namespace Admin\Filter\Service;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 dịch vụ (xoá — docs §3.4): id + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class ServiceActionFilter extends AppInputFilter
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
