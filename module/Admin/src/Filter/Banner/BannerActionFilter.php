<?php

declare(strict_types=1);

namespace Admin\Filter\Banner;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 banner (xoá — docs §3.5): id + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class BannerActionFilter extends AppInputFilter
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
