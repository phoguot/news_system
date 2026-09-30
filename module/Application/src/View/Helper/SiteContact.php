<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Frontend\Model\Setting\SettingConst;
use Frontend\Service\SettingService;
use Throwable;

/**
 * Dữ liệu liên hệ dùng chung cho header/footer frontend.
 *
 * Giá trị được đọc qua SettingService để dùng chung cache settings và được
 * cập nhật ngay sau khi Admin\Service\SettingService invalidate cache.
 */
final class SiteContact
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * @return array{
     *     companyName: string,
     *     address: string,
     *     hotline: string,
     *     hotlineHref: string,
     *     facebookUrl: string|null,
     *     zaloUrl: string|null
     * }
     */
    public function __invoke(): array
    {
        try {
            $all = $this->settings->all();
        } catch (Throwable) {
            $all = [];
        }

        $companyName = $this->value($all, SettingConst::KEY_COMPANY_NAME)
            ?? 'Trung tâm Chăm sóc Sức khỏe Văn Lang';
        $address = $this->value($all, SettingConst::KEY_ADDRESS)
            ?? '123 Hoàng Văn Ca, Long Biên, Hà Nội';
        $hotline = $this->value($all, SettingConst::KEY_HOTLINE) ?? '1900 1234';

        return [
            'companyName' => $companyName,
            'address' => $address,
            'hotline' => $hotline,
            'hotlineHref' => 'tel:' . preg_replace('/[^0-9+]/', '', $hotline),
            'facebookUrl' => $this->httpUrl($this->value($all, SettingConst::KEY_FACEBOOK_URL)),
            'zaloUrl' => $this->httpUrl($this->value($all, SettingConst::KEY_ZALO_URL)),
        ];
    }

    /** @param array<string, string|null> $all */
    private function value(array $all, string $key): ?string
    {
        $value = $all[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** Chỉ cho phép liên kết HTTP(S), tự bổ sung https:// khi Admin bỏ trống scheme. */
    private function httpUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
