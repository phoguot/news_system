<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Frontend\Model\Setting\SettingConst;
use Frontend\Service\SettingService;
use Throwable;

/**
 * Render các bubble liên hệ nổi (Messenger, Zalo, Hotline) ở góc phải dưới
 * layout frontend. Dữ liệu đọc từ bảng `settings` qua SettingService (cache
 * 60s) — quản trị viên sửa ở /admin/settings thì bubble cập nhật theo.
 *
 * Bubble nào thiếu URL/số điện thoại trong cài đặt → ẩn tự động.
 * Không extends AbstractHelper (deprecated trong laminas-view); escape bằng
 * htmlspecialchars trực tiếp — cùng pattern SelectField/FrontendMenu.
 */
final class ContactBubbles
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function __invoke(): string
    {
        try {
            $all = $this->settings->all();
        } catch (Throwable) {
            return '';
        }

        $hotline  = $this->val($all, SettingConst::KEY_HOTLINE);
        $facebook = $this->val($all, SettingConst::KEY_FACEBOOK_URL);
        $zalo     = $this->val($all, SettingConst::KEY_ZALO_URL);

        // Không có gì để hiện
        if ($hotline === null && $facebook === null && $zalo === null) {
            return '';
        }

        $html = '<div class="contact-bubbles">';

        // --- Messenger ---
        if ($facebook !== null) {
            $href = $this->e($this->messengerUrl($facebook));
            $html .= '<a class="bubble bubble-messenger" href="' . $href . '"'
                . ' target="_blank" rel="noopener" aria-label="Chat Messenger">'
                . '<span class="bubble-icon">' . $this->svgMessenger() . '</span>'
                . '<span class="bubble-label">Chat Messenger</span>'
                . '</a>';
        }

        // --- Zalo ---
        if ($zalo !== null) {
            $href = $this->e($zalo);
            $html .= '<a class="bubble bubble-zalo" href="' . $href . '"'
                . ' target="_blank" rel="noopener" aria-label="Liên hệ Zalo">'
                . '<span class="bubble-icon">' . $this->svgZalo() . '</span>'
                . '<span class="bubble-label">Liên hệ Zalo</span>'
                . '</a>';
        }

        // --- Hotline ---
        if ($hotline !== null) {
            $tel = preg_replace('/[^0-9+]/', '', $hotline);
            $html .= '<a class="bubble bubble-hotline" href="tel:' . $this->e($tel) . '"'
                . ' aria-label="Hotline">'
                . '<span class="bubble-icon">' . $this->svgPhone() . '</span>'
                . '<span class="bubble-label">Hotline: ' . $this->e($hotline) . '</span>'
                . '</a>';
        }

        $html .= '</div>';

        return $html;
    }

    /** Biến URL Facebook page/profile thành link Messenger chat. */
    private function messengerUrl(string $fbUrl): string
    {
        // Nếu đã là link m.me hoặc messenger → giữ nguyên
        if (str_contains($fbUrl, 'm.me/') || str_contains($fbUrl, 'messenger.com')) {
            return $fbUrl;
        }

        // Rút username/id từ URL facebook.com
        $path = trim(parse_url($fbUrl, PHP_URL_PATH) ?? '', '/');
        if ($path !== '') {
            return 'https://m.me/' . $path;
        }

        // Fallback: trả link Facebook gốc
        return $fbUrl;
    }

    private function val(array $all, string $key): ?string
    {
        $v = $all[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- Inline SVG icons (nhẹ, không phụ thuộc font-awesome/CDN) ----

    private function svgMessenger(): string
    {
        return '<svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28">'
            . '<path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.2 5.42 3.15 7.2.16.15.26.36.27.58'
            . 'l.05 1.82c.02.64.68 1.05 1.27.8l2.04-.9c.17-.08.37-.1.55-.06.92.25 1.9.39 2.92'
            . '.39 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm5.95 7.57l-2.92 4.63c-.47.74-1.44.93'
            . '-2.13.41l-2.32-1.74a.75.75 0 00-.9 0l-3.13 2.37c-.42.32-.96-.18-.68-.63l2.92'
            . '-4.63c.47-.74 1.44-.93 2.13-.41l2.32 1.74a.75.75 0 00.9 0l3.13-2.37c.42-.32'
            . '.96.18.68.63z"/></svg>';
    }

    private function svgZalo(): string
    {
        return '<svg viewBox="0 0 48 48" width="28" height="28">'
            . '<circle cx="24" cy="24" r="22" fill="#0068FF"/>'
            . '<text x="24" y="31" text-anchor="middle" fill="#fff" '
            . 'font-family="Arial,sans-serif" font-weight="700" font-size="18">Zalo</text>'
            . '</svg>';
    }

    private function svgPhone(): string
    {
        return '<svg viewBox="0 0 24 24" fill="currentColor" width="26" height="26">'
            . '<path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.01-.24'
            . ' 11.36 11.36 0 003.54.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0'
            . ' 011-1h3.5a1 1 0 011 1 11.36 11.36 0 00.57 3.54 1 1 0 01-.25 1.02l-2.2 2.23z"/>'
            . '</svg>';
    }
}
