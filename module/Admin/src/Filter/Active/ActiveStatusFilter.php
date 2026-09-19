<?php

declare(strict_types=1);

namespace Admin\Filter\Active;

use Application\Filter\AppInputFilter;

/**
 * Form nhỏ ở cột "Hiển thị" của các danh sách Admin: id + isActive + CSRF.
 * Service dùng chung để đổi nhanh trạng thái bật/tắt mà không phải mở form sửa.
 */
final class ActiveStatusFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();
        $this->addRawField('isActive', true);
        parent::__construct($withCsrf);
    }

    public function idValue(): int
    {
        return (int) $this->positiveIdValue('id');
    }

    public function activeValue(): int
    {
        return $this->flagValue('isActive') ? 1 : 0;
    }
}
