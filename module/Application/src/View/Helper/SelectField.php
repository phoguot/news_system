<?php

declare(strict_types=1);

namespace Application\View\Helper;

/**
 * Select box cho các field "chọn bản ghi" PHI-MEDIA trong form Admin
 * (hiện chỉ còn `itemId` form thêm mục home-section) — thay cho ô nhập ID số.
 * Giá trị submit vẫn là id, filter/Service giữ nguyên luồng validate.
 *
 * Từ 14/09/2026: field ảnh media KHÔNG còn dùng helper này — chuyển sang
 * `MediaPicker` (nút mở popup chọn ảnh, bỏ select box theo feedback user).
 *
 * Không extends AbstractHelper (lớp nền bị đánh dấu deprecated trong
 * laminas-view, và getView() trả RendererInterface|null nên plugin()/escapeHtml
 * không có type an toàn). Đăng ký trong view_helpers vẫn dùng được qua
 * `$this->selectField(...)` vì HelperPluginManager chỉ cần một callable.
 */
final class SelectField
{
    /**
     * @param list<array{id: int|string, label: string}> $options
     * @param string $current giá trị đang chọn (chuỗi id, rỗng = chưa chọn)
     */
    public function __invoke(
        string $name,
        array $options,
        string $current,
        bool $required = false,
        string $placeholder = '— Chọn trong danh sách —',
    ): string {
        $e      = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $select = '<select class="form-select" id="f-' . $e($name) . '" name="' . $e($name) . '"'
            . ($required ? ' required' : '') . '>';
        $select .= '<option value="">' . $e($placeholder) . '</option>';

        $found = false;
        foreach ($options as $opt) {
            $id       = (string) ($opt['id'] ?? '');
            $selected = $id !== '' && $id === $current ? ' selected' : '';
            if ($selected !== '') {
                $found = true;
            }
            $select .= '<option value="' . $e($id) . '"' . $selected . '>'
                . '#' . $e($id) . ' · ' . $e($opt['label'] ?? '') . '</option>';
        }

        if (! $found && $current !== '') {
            $select .= '<option value="' . $e($current) . '" selected>#' . $e($current)
                . ' · (không còn trong danh sách)</option>';
        }

        $select .= '</select>';

        return $select;
    }
}
