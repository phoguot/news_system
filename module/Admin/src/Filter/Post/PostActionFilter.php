<?php

declare(strict_types=1);

namespace Admin\Filter\Post;

use Admin\Model\Post\PostConst;
use Application\Filter\AppInputFilter;

/**
 * Validate form thao tác trên 1 bài (publish/archive/draft/delete — docs §3.3.3):
 * id bắt buộc, publishedAt tuỳ chọn (chỉ nhánh publish hẹn giờ dùng), CSRF.
 * Service chạy filter này thay cho controller (chuẩn 07 §2).
 */
final class PostActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField(digitsMessage: PostConst::ERROR_NOT_FOUND);
        $this->addDateTimeField('publishedAt', PostConst::ERROR_INVALID_TIME);
        parent::__construct($withCsrf);
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }

    /** @return string|null giờ VN tường minh cho nhánh publish hẹn giờ */
    public function publishedAtRaw(): ?string
    {
        return $this->nullableStringValue('publishedAt');
    }
}
