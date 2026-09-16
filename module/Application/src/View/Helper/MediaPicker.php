<?php

declare(strict_types=1);

namespace Application\View\Helper;

/**
 * Field "chọn ảnh từ thư viện media" trong form Admin — không còn select box
 * cũng không còn nút chữ (user feedback 14/09/2026: "bỏ hết selectbox đi"; sau
 * đó "bỏ luôn nút chọn ảnh từ thư viện đi"). Khung preview là đường chọn duy
 * nhất; giá trị submit vẫn là id qua HIDDEN INPUT → luồng Filter/Service giữ
 * nguyên.
 *
 * Markup mỗi field (polish 14/09 theo feedback "ẩn chưa chọn ảnh đi, chọn xong
 * tự hiển thị, nhìn thoáng"): khung preview cố định `.media-pick-frame`
 * (role=button, mở popup khi click/Enter — admin.js) — rỗng thì hiện icon
 * camera nhạt (KHÔNG có dòng chữ trạng thái "— Chưa chọn ảnh —"), chọn xong ảnh
 * tự hiện lấp đầy khung không nhảy layout; bỏ chọn bằng dấu × nhỏ overlay
 * `.media-pick-clear` góc phải-trên của khung, chỉ hiện khi đang có ảnh chọn
 * (handler khung bỏ qua click vào × nên hai việc không xung đột). Dòng đỏ
 * `.media-pick-error` chỉ
 * hiện khi id đang lưu không còn trong thư viện. Danh sách ảnh nhúng vào MỘT
 * `<script type="application/json" class="media-lib">` mỗi danh sách trùng nội
 * dung (dedupe theo hash trong request) — admin.js đọc node này cho cả popup
 * field media lẫn nút 📷 chèn ảnh của editor, không call API.
 *
 * Không extends AbstractHelper (lớp nền deprecated — khuôn SelectField); escape
 * htmlspecialchars trực tiếp.
 */
final class MediaPicker
{
    /** @var array<string, bool> md5(json thư viện) đã render trong request hiện tại */
    private static array $emittedLibs = [];

    private MediaUrl $mediaUrl;

    public function __construct()
    {
        $this->mediaUrl = new MediaUrl();
    }

    /**
     * @param list<array{id: int|string, label: string, path?: string}> $options
     * @param string $current id đang chọn (chuỗi rỗng = chưa chọn)
     */
    public function __invoke(string $name, array $options, string $current): string
    {
        $e     = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $src   = '';
        foreach ($options as $opt) {
            if ((string) ($opt['id'] ?? '') !== $current) {
                continue;
            }
            $path = $opt['path'] ?? '';
            if ($path !== '') {
                $src = ($this->mediaUrl)($path);
            }
            break;
        }

        $html = '<input type="hidden" class="media-id" id="f-' . $e($name) . '" name="' . $e($name) . '"'
            . ' value="' . $e($current) . '">';

        /* Khung preview: rỗng → icon camera; chọn xong → ảnh tự hiện (admin.js).
           img + span empty LUÔN render (kèm hidden đúng chiều) để sau khi bấm ×
           JS trả lại được trạng thái rỗng. × = bỏ chọn, overlay góc phải-trên. */
        $html .= '<div class="media-pick-frame" role="button" tabindex="0"'
            . ' title="Chọn ảnh từ thư viện">';
        $imgAttrs = $src === '' ? ' src="" alt="" hidden' : ' src="' . $e($src) . '" alt=""';
        $html .= '<img class="media-pick-preview"' . $imgAttrs . '>';
        $html .= '<span class="media-pick-empty" aria-hidden="true"'
            . ($src !== '' ? ' hidden' : '')
            . '><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9'
            . 'a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg></span>';
        $html .= '<button type="button" class="media-pick-clear" title="Bỏ chọn ảnh"'
            . ' aria-label="Bỏ chọn ảnh"' . ($current === '' ? ' hidden' : '') . '>×</button>';
        $html .= '</div>';

        if ($current !== '' && $src === '') {
            $html .= '<div class="media-pick-error">Ảnh đã chọn không còn trong thư viện — hãy chọn lại.</div>';
        }

        // Nguồn dữ liệu popup: nhúng danh sách ảnh (id + nhãn + URL) dạng JSON —
        // một node cho mỗi danh sách trùng (form 2–3 field media không nhân bản).
        $items = [];
        foreach ($options as $opt) {
            $path = $opt['path'] ?? '';
            if ($path === '') {
                continue;
            }
            $items[] = [
                'id'    => (string) ($opt['id'] ?? ''),
                'label' => $opt['label'],
                'src'   => ($this->mediaUrl)($path),
            ];
        }
        $json = json_encode(
            $items,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        if (is_string($json) && ! isset(self::$emittedLibs[md5($json)])) {
            self::$emittedLibs[md5($json)] = true;
            $html .= '<script type="application/json" class="media-lib">' . $json . '</script>';
        }

        return $html;
    }
}
