<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Factory\AppServiceFactory;

/**
 * Xác minh captcha phía server — hỗ trợ cả Cloudflare Turnstile lẫn reCAPTCHA v3
 * (docs §3.9). Endpoint siteverify đặt qua config 'recaptcha.verify_url';
 * secret_key rỗng (môi trường dev chưa cấu hình) → verify() trả true để không chặn phát triển.
 *
 * DI nền 07 §4 (batch 7 — 13/09/2026): không constructor — key `recaptcha` đọc
 * lazy qua `getContainerEntry('Config')`, đăng ký bằng `AppInvokableFactory`.
 */
class CaptchaService extends AppServiceFactory
{
    private const VERIFY_TIMEOUT_SECONDS = 5;
    private const DEFAULT_VERIFY_URL     = 'https://www.google.com/recaptcha/api/siteverify';

    public function isEnabled(): bool
    {
        return $this->recaptchaConfig()['secret_key'] !== '';
    }

    public function siteKey(): string
    {
        return $this->recaptchaConfig()['site_key'];
    }

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        $recaptcha = $this->recaptchaConfig();
        $fields    = ['secret' => $recaptcha['secret_key'], 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query($fields),
                'timeout'       => self::VERIFY_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($recaptcha['verify_url'], false, $context);
        if (! is_string($raw)) {
            return false;
        }

        /** @var array<array-key, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }

    /**
     * Key `recaptcha` trong config — 3 field đã ép string (mặc định như closure cũ).
     *
     * @return array{secret_key: string, site_key: string, verify_url: string}
     */
    private function recaptchaConfig(): array
    {
        /** @var array<array-key, mixed>|\Laminas\Config\Config $config */
        $config = $this->getContainer()->get('Config');
        $all   = is_array($config) ? $config : $config->toArray();
        /** @var array<array-key, mixed> $recaptcha */
        $recaptcha = is_array($all['recaptcha'] ?? null) ? $all['recaptcha'] : [];

        return [
            'secret_key' => (string) ($recaptcha['secret_key'] ?? ''),
            'site_key'   => (string) ($recaptcha['site_key'] ?? ''),
            'verify_url' => (string) ($recaptcha['verify_url'] ?? self::DEFAULT_VERIFY_URL),
        ];
    }
}
