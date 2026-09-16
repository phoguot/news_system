<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Filter\Auth\ForgotPasswordFilter;
use Admin\Filter\Auth\ResetPasswordFilter;
use Admin\Model\PasswordResetToken\PasswordResetTokenConst;
use Admin\Model\PasswordResetToken\PasswordResetTokenMapper;
use Admin\Model\User\UserMapper;
use Application\Factory\AppServiceFactory;
use Application\Service\DateService;
use Application\Service\MailService;

/**
 * Nghiệp vụ quên / đặt lại mật khẩu (FR-13, docs §3.11 + §4.4.1 + §6.2):
 * - chỉ lưu hash SHA-256 token (CHAR 64), hạn 60 phút, dùng 1 lần
 * - tạo token mới thì xoá token cũ cùng userId (docs §4.4.1)
 * DI nền 07 §4: extends AppServiceFactory, dependency qua getContainerEntry().
 */
final class PasswordResetService extends AppServiceFactory
{
    private function userMapper(): UserMapper
    {
        /** @var UserMapper */
        return $this->getContainerEntry(UserMapper::class);
    }

    private function tokenMapper(): PasswordResetTokenMapper
    {
        /** @var PasswordResetTokenMapper */
        return $this->getContainerEntry(PasswordResetTokenMapper::class);
    }

    private function mail(): MailService
    {
        /** @var MailService */
        return $this->getContainerEntry(MailService::class);
    }

    /**
     * @return array{ok: bool, errors: array<string, string>}
     *
     * @param array<array-key, mixed> $raw
     */
    public function requestReset(array $raw, bool $withCsrf = true): array
    {
        $filter = new ForgotPasswordFilter($withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return ['ok' => false, 'errors' => $filter->fieldErrors()];
        }

        $email = $filter->emailValue();
        $user = $this->userMapper()->getUserByIdentifier($email);
        if ($user === null) {
            // Chống dò account: vẫn trả ok nhưng không gửi mail
            return ['ok' => true, 'errors' => []];
        }

        // Xoá token cũ cùng user trước khi tạo mới (docs §4.4.1)
        $this->tokenMapper()->deleteByUserId($user->id);

        $token = bin2hex(random_bytes(PasswordResetTokenConst::TOKEN_BYTES));
        $hash = hash('sha256', $token);
        $expiresAt = DateService::plusMinutesUtc(DateService::nowUtc(), PasswordResetTokenConst::EXPIRY_MINUTES);
        if ($expiresAt === null) {
            $expiresAt = DateService::nowUtc();
        }

        $this->tokenMapper()->insert([
            'userId' => $user->id,
            'tokenHash' => $hash,
            'expiresAt' => $expiresAt,
        ]);

        $baseUrl = trim((string) ($this->mailConfig()['base_url'] ?? ''));
        if ($baseUrl === '') {
            $cfg = $this->getContainer()->get('Config');
            $all = is_array($cfg) ? $cfg : $cfg->toArray();
            $appCfg = is_array($all['app'] ?? null) ? $all['app'] : [];
            $baseUrl = trim((string) ($appCfg['site_url'] ?? ''));
        }
        $baseUrl = rtrim($baseUrl, '/');
        $resetPath = '/admin/reset-password?token=' . urlencode($token);
        $resetUrl = $baseUrl !== '' ? $baseUrl . $resetPath : $resetPath;
        $subject = '[Vạn Lang] Đặt lại mật khẩu';
        $body = "Bạn đã yêu cầu đặt lại mật khẩu.\n\n"
            . "Liên kết đặt lại (hết hạn sau 60 phút, chỉ dùng 1 lần):\n"
            . $resetUrl . "\n\n"
            . "Nếu bạn không yêu cầu, hãy bỏ qua email này.\n";

        $sent = $this->mail()->send($user->email, $subject, $body);
        if (! $sent) {
            error_log('password reset email failed for ' . $user->email);
        }

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function resetPassword(array $raw, bool $withCsrf = true): array
    {
        $filter = new ResetPasswordFilter($withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return ['ok' => false, 'errors' => $filter->fieldErrors()];
        }

        $token = $filter->tokenValue();
        $password = $filter->passwordValue();
        $confirm = $filter->confirmPasswordValue();
        if ($password !== $confirm) {
            return ['ok' => false, 'errors' => ['confirmPassword' => PasswordResetTokenConst::ERROR_PASSWORD_MISMATCH]];
        }

        $hash = hash('sha256', $token);
        $record = $this->tokenMapper()->findByHash($hash);
        if ($record === null) {
            return ['ok' => false, 'errors' => ['token' => PasswordResetTokenConst::ERROR_TOKEN_INVALID]];
        }

        if ($record->usedAt !== null) {
            return ['ok' => false, 'errors' => ['token' => PasswordResetTokenConst::ERROR_TOKEN_USED]];
        }

        $nowUtc = DateService::nowUtc();
        if ($record->expiresAt <= $nowUtc) {
            return ['ok' => false, 'errors' => ['token' => PasswordResetTokenConst::ERROR_TOKEN_EXPIRED]];
        }

        $user = $this->userMapper()->findById($record->userId);
        if ($user === null) {
            return ['ok' => false, 'errors' => ['token' => PasswordResetTokenConst::ERROR_TOKEN_INVALID]];
        }

        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $this->userMapper()->updatePasswordHash($user->id, $newHash);
        $this->tokenMapper()->markUsed($record->id, $nowUtc);
        $this->tokenMapper()->deleteByUserId($user->id);

        return ['ok' => true, 'errors' => []];
    }

    public function forgotCsrfHash(): string
    {
        return (new ForgotPasswordFilter())->csrfHash();
    }

    public function resetCsrfHash(): string
    {
        return (new ResetPasswordFilter())->csrfHash();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function mailConfig(): array
    {
        /** @var array<array-key, mixed>|\Laminas\Config\Config $config */
        $config = $this->getContainer()->get('Config');
        $all = is_array($config) ? $config : $config->toArray();
        /** @var array<array-key, mixed> $mail */
        $mail = is_array($all['mail'] ?? null) ? $all['mail'] : [];

        return $mail;
    }
}
