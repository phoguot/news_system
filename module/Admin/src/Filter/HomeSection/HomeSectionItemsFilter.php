<?php

declare(strict_types=1);

namespace Admin\Filter\HomeSection;

use Application\Filter\AppInputFilter;

/**
 * Validate một thao tác trên danh sách mục `mode=manual` của section
 * (FR-33, docs §4.4.4): op (add|remove|up|down — giá trị kiểm ở Service vì
 * mỗi op cần cặp field khác nhau) + id (dòng item) / itemType / itemId.
 * Nền CSRF + fieldErrors theo 07 §6.
 */
final class HomeSectionItemsFilter extends AppInputFilter
{
    public const OPS = ['add', 'remove', 'up', 'down'];

    public function __construct(bool $withCsrf = true)
    {
        $this->addRawField('op', true);
        $this->addIdField('id', false);
        $this->addIdField('itemType', false);
        $this->addIdField('itemId', false);
        parent::__construct($withCsrf);
    }

    public function opValue(): string
    {
        return strtolower($this->stringValue('op'));
    }

    /** Số nguyên dương hoặc 0 khi thiếu — caller kiểm theo op. */
    public function intOf(string $field): int
    {
        return (int) $this->getValue($field);
    }
}
