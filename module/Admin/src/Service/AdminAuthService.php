<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Constant\AdminConst;
use Admin\Filter\Auth\LoginFilter;
use Admin\Model\User\UserMapper;
use Application\Factory\AppServiceFactory;
use Application\Service\DateService;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Result;

/**
 * Nghiệp vụ đăng nhập / đăng xuất admin (docs §3.11, docs-dev/05-van-hanh/03).
 * Luồng chuẩn 07 §2 (đã cập nhật): attemptLogin() chạy LoginFilter trên POST
 * thô (kèm CSRF) rồi mới kiểm khoá tài khoản/mật khẩu. Controller mỏng.
 * DI theo service-locator 13/09: không constructor injection — mapper/service
 * lấy từ container qua getContainerEntry() đúng lúc dùng.
 */
class AdminAuthService extends AppServiceFactory
{
    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function authService(): AuthenticationService
    {
        /** @var AuthenticationService */
        return $this->getContainerEntry(AuthenticationService::class);
    }

    private function userMapper(): UserMapper
    {
        /** @var UserMapper */
        return $this->getContainerEntry(UserMapper::class);
    }

    public function hasIdentity(): bool
    {
        return $this->authService()->hasIdentity();
    }

    /**
     * @return array{id: int, email: string, fullName: string, username: string|null}|null
     */
    public function getIdentity(): ?array
    {
        $identity = $this->authService()->getIdentity();
        if (! is_array($identity)) {
            return null;
        }

        /** @var mixed $username */
        $username = $identity['username'] ?? null;

        return [
            'id'       => (int) $identity['id'],
            'email'    => (string) $identity['email'],
            'fullName' => (string) $identity['fullName'],
            'username' => is_string($username) && $username !== '' ? $username : null,
        ];
    }

    /**
     * Đồng bộ session sau khi admin sửa hồ sơ (FR-14): email/fullName/username
     * trong storage khớp dữ liệu vừa ghi DB. Không đổi id phiên — đây là refresh
     * hiển thị, không phải đăng nhập lại.
     */
    public function refreshIdentity(string $email, string $fullName, ?string $username): void
    {
        $identity = $this->getIdentity();
        if ($identity === null) {
            return;
        }

        $identity['email']    = $email;
        $identity['fullName'] = $fullName;
        $identity['username'] = $username;
        $this->authService()->getStorage()->write($identity);
    }

    /**
     * Entry point form đăng nhập: chạy LoginFilter (kèm CSRF) trên POST thô.
     * Form sai/token hết hạn → kết quả FAILURE dùng chung thông báo credential
     * (không tiết lộ chi tiết validation cho kẻ dò).
     *
     * @param array<array-key, mixed> $raw
     */
    public function attemptLogin(array $raw, bool $withCsrf = true): Result
    {
        $filter = new LoginFilter($withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return new Result(Result::FAILURE_CREDENTIAL_INVALID, null, [AdminConst::ERROR_INVALID_CREDENTIALS]);
        }

        $values = $filter->getValues();

        return $this->login(
            (string) ($values['identity'] ?? ''),
            (string) ($values['password'] ?? '')
        );
    }

    /** Giá trị cho <input type="hidden" name="csrf"> của form đăng nhập. */
    public function loginCsrfHash(): string
    {
        return (new LoginFilter())->csrfHash();
    }

    /**
     * Kiểm tra thông tin đăng nhập theo đúng thứ tự: khoá → mật khẩu.
     * `$identifier` là email hoặc username (mapper tự nhận diện qua `@`).
     * Không tiết lộ định danh có tồn tại hay không (chung một thông báo).
     */
    public function login(string $identifier, string $password): Result
    {
        $user = $this->userMapper()->getUserByIdentifier($identifier);
        if ($user === null) {
            return new Result(Result::FAILURE_IDENTITY_NOT_FOUND, null, [AdminConst::ERROR_INVALID_CREDENTIALS]);
        }

        $nowUtc = DateService::nowUtc();
        if ($user->lockedUntil !== null && $user->lockedUntil > $nowUtc) {
            return new Result(
                Result::FAILURE_UNCATEGORIZED,
                null,
                [sprintf(AdminConst::ERROR_ACCOUNT_LOCKED, $this->minutesLeft($user->lockedUntil, $nowUtc))]
            );
        }

        if ($user->passwordHash === '' || ! password_verify($password, $user->passwordHash)) {
            $failedCount = $user->failedLoginCount + 1;
            $shouldLock  = $failedCount >= AdminConst::MAX_FAILED_LOGINS;
            $this->userMapper()->registerFailedLogin(
                $user->id,
                $failedCount,
                $shouldLock
                    ? DateService::plusMinutesUtc($nowUtc, AdminConst::LOCKOUT_MINUTES)
                    : null
            );

            $messages = [AdminConst::ERROR_INVALID_CREDENTIALS];
            if ($shouldLock) {
                $messages[] = sprintf(
                    AdminConst::ERROR_JUST_LOCKED,
                    AdminConst::MAX_FAILED_LOGINS,
                    AdminConst::LOCKOUT_MINUTES
                );
            }

            return new Result(Result::FAILURE_CREDENTIAL_INVALID, null, $messages);
        }

        $this->userMapper()->registerSuccessfulLogin($user->id, $nowUtc);

        $identity = [
            'id'       => $user->id,
            'email'    => $user->email,
            'fullName' => $user->fullName,
            'username' => $user->username,
        ];

        // Chống session fixation: đổi id phiên TRƯỚC khi ghi danh tính
        $this->authService()->clearIdentity();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->authService()->getStorage()->write($identity);

        return new Result(Result::SUCCESS, $identity);
    }

    public function logout(): void
    {
        $this->authService()->clearIdentity();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function minutesLeft(string $lockedUntilUtc, string $nowUtc): int
    {
        $lockedTs = DateService::timestampUtc($lockedUntilUtc);
        $nowTs    = DateService::timestampUtc($nowUtc);
        if ($lockedTs === null || $nowTs === null) {
            return AdminConst::LOCKOUT_MINUTES;
        }

        return max(1, (int) ceil(($lockedTs - $nowTs) / 60));
    }
}
