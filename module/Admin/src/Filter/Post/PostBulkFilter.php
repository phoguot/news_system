<?php

declare(strict_types=1);

namespace Admin\Filter\Post;

use Admin\Model\Post\PostConst;
use Application\Filter\AppInputFilter;

/**
 * Validate thao tác hàng loạt trên bài viết (FR-22 — docs §3.3.1, API §6.2
 * `POST /posts/bulk`): action whitelist, ids (mảng từ form `ids[]`/JSON hoặc
 * CSV), categoryId cho nhánh chuyển danh mục, confirmCount cho nhánh xoá
 * (spec bắt buộc gõ đúng số bài). Service chạy filter này (chuẩn 07 §2);
 * whitelist action + các ràng buộc chéo kiểm ở Service vì phụ thuộc lẫn nhau.
 */
final class PostBulkFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addStringField('action', true, 20);
        $this->addRawField('ids');
        $this->addIdField('categoryId', false);
        $this->addIdField('confirmCount', false);
        parent::__construct($withCsrf);
    }

    public function actionValue(): string
    {
        return $this->stringValue('action');
    }

    /** Giá trị `ids` gốc từ request (mảng `ids[]`/JSON hoặc CSV) — mixed có chủ đích. */
    public function idsRaw(): mixed
    {
        return $this->getValue('ids');
    }

    public function categoryIdValue(): ?int
    {
        return $this->positiveIdValue('categoryId');
    }

    public function confirmCountValue(): ?int
    {
        return $this->positiveIdValue('confirmCount');
    }
}
