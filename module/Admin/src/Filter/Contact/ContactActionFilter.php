<?php

declare(strict_types=1);

namespace Admin\Filter\Contact;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 lượt liên hệ (xoá — docs §3.9): id + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class ContactActionFilter extends AppInputFilter
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
