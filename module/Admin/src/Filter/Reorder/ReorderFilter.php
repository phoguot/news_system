<?php

declare(strict_types=1);

namespace Admin\Filter\Reorder;

use Application\Filter\AppInputFilter;

/**
 * Nền validate cho kéo-thả đổi thứ tự (FR-26/29/30/32 — API page-level
 * POST /admin/{module}/reorder): `ids` là danh sách id theo thứ tự MỚI
 * (mảng `ids[]` từ fetch hoặc CSV); id lạ/trùng bị loại ở getter — Service
 * tự vá phần còn thiếu xuống cuối nên không bao giờ mất dòng.
 *
 * Filter stateful → Service `new` mỗi request (chuẩn 07 §6), CSRF gắn nền.
 */
final class ReorderFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addRawField('ids');
        parent::__construct($withCsrf);
    }

    /**
     * Danh sách id dương mong muốn theo đúng thứ tự payload, đã loại trùng.
     *
     * @return list<int>
     */
    public function idList(): array
    {
        /** @var mixed $raw */
        $raw = $this->getValue('ids');

        $items = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);

        /** @var list<int> $ids */
        $ids = [];
        /** @psalm-suppress MixedAssignment — giá trị thô từ request, kiểm qua is_numeric. */
        foreach ($items as $item) {
            if (is_numeric($item) && (int) $item > 0 && ! in_array((int) $item, $ids, true)) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }
}
