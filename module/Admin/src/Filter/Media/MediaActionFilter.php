<?php

declare(strict_types=1);

namespace Admin\Filter\Media;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 media (xoá — docs §3.10): id + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class MediaActionFilter extends AppInputFilter
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
