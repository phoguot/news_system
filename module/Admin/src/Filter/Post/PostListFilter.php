<?php

declare(strict_types=1);

namespace Admin\Filter\Post;

use Admin\Model\Post\PostConst;
use Application\Filter\AppInputFilter;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\Input;
use Laminas\Validator\Digits;
use Laminas\Validator\InArray;

/**
 * Validate bộ lọc/trang danh sách bài quản trị + API (docs §3.3.1, §5.12).
 * Giá trị sai rơi về mặc định an toàn (chưa required + fallback ở các getter
 * tabValue()/pageValue()/perPageValue()) nên chỉ kiểm tra kiểu, không chặn request.
 * API có thể gửi `status` (0 draft / 1 published / 2 archived) thay cho `tab`.
 * Query danh sách không có form → CSRF tắt (withCsrf = false ở nền tảng).
 */
final class PostListFilter extends AppInputFilter
{
    public const int PER_PAGE_DEFAULT = 20;
    public const int PER_PAGE_MAX     = 100;

    /** status kiểu API (int) => tab quản trị. */
    private const array STATUS_TO_TAB = [
        0 => PostConst::TAB_DRAFT,
        1 => PostConst::TAB_PUBLISHED,
        2 => PostConst::TAB_ARCHIVED,
    ];

    public function __construct()
    {
        $tab = new Input('tab');
        $tab->setRequired(false)->getFilterChain()->attach(new StringTrim());
        $tab->getValidatorChain()->attach(new InArray([
            'haystack' => array_keys(PostConst::TAB_LABELS),
        ]));
        $this->add($tab);

        foreach (['status', 'page', 'perPage'] as $field) {
            $input = new Input($field);
            $input->setRequired(false)->getFilterChain()->attach(new ToInt());
            $input->getValidatorChain()->attach(new Digits());
            $this->add($input);
        }

        $this->addIdField('categoryId', required: false);
        $this->addIdField('tagId', required: false);
        $this->addStringField('q', required: false);
        $this->addRawField('featured');
        $this->addStringField('dateFrom', required: false);
        $this->addStringField('dateTo', required: false);

        parent::__construct(withCsrf: false);
    }

    /** Tab hiệu lực (ưu tiên `tab`; sai/thiếu suy từ `status` kiểu API; mặc định all). */
    public function tabValue(): string
    {
        /** @var mixed $tab */
        $tab = $this->getValue('tab');
        if (is_string($tab) && array_key_exists($tab, PostConst::TAB_LABELS)) {
            return $tab;
        }

        /** @var mixed $status */
        $status = $this->getValue('status');

        return self::STATUS_TO_TAB[(int) $status] ?? PostConst::TAB_ALL;
    }

    public function categoryIdValue(): ?int
    {
        return $this->positiveIdValue('categoryId');
    }

    public function tagIdValue(): ?int
    {
        return $this->positiveIdValue('tagId');
    }

    public function qValue(): string
    {
        return $this->stringValue('q');
    }

    public function featuredValue(): bool
    {
        return $this->flagValue('featured');
    }

    /** Ngày VN 'Y-m-d' (input type=date) — sai format/rỗng → null (không lọc). */
    public function dateFromValue(): ?string
    {
        $raw = trim($this->stringValue('dateFrom'));
        if ($raw === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    public function dateToValue(): ?string
    {
        $raw = trim($this->stringValue('dateTo'));
        if ($raw === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    public function pageValue(): int
    {
        /** @var mixed $raw */
        $raw = $this->getValue('page');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 1;
    }

    public function perPageValue(): int
    {
        /** @var mixed $raw */
        $raw = $this->getValue('perPage');
        if (! is_numeric($raw) || (int) $raw < 1) {
            return self::PER_PAGE_DEFAULT;
        }

        return min(self::PER_PAGE_MAX, (int) $raw);
    }
}
