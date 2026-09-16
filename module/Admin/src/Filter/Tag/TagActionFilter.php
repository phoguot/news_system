<?php

declare(strict_types=1);

namespace Admin\Filter\Tag;

use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác tag (docs §3.4):
 * - xoá: id + CSRF;
 * - gộp ($merge = true): sourceId + targetId + CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class TagActionFilter extends AppInputFilter
{
    public function __construct(bool $merge = false, bool $withCsrf = true)
    {
        foreach ($merge ? ['sourceId', 'targetId'] : ['id'] as $field) {
            $this->addIdField($field);
        }
        parent::__construct($withCsrf);
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }

    public function sourceIdValue(): ?int
    {
        return $this->positiveIdValue('sourceId');
    }

    public function targetIdValue(): ?int
    {
        return $this->positiveIdValue('targetId');
    }
}
