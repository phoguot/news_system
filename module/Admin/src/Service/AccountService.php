<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Account\AccountPasswordFilter;
use Admin\Filter\Account\AccountProfileFilter;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\User\UserConst;
use Admin\Model\User\UserMapper;
use Admin\Model\User\UserModel;
use Application\Factory\AppServiceFactory;

/**
 * Nghiệp vụ "Tài khoản của tôi" (docs §3.11, FR-14): sửa hồ sơ (họ tên, email
 * đăng nhập, SĐT, avatar) và đổi mật khẩu — trên dòng users duy nhất.
 * Luồng chuẩn 07 §2: Service `new` Filter validate raw, Mapper trả UserModel.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 *
 * Đổi mật khẩu kiểm currentPassword bằng `password_verify()` rồi ghi hash mới
 * `password_hash()` (bcrypt mặc định của PHP — docs §3.11 cho phép bcrypt).
 */
class AccountService extends AppServiceFactory
{
    private function userMapper(): UserMapper
    {
        /** @var UserMapper */
        return $this->getContainerEntry(UserMapper::class);
    }

    private function adminAuthService(): AdminAuthService
    {
        /** @var AdminAuthService */
        return $this->getContainerEntry(AdminAuthService::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    /** Dòng tài khoản của id trong session — 404 an toàn nếu không tồn tại. */
    public function me(int $userId): UserModel
    {
        $user = $this->userMapper()->findById($userId);
        if ($user === null) {
            throw NotFoundException::forEntity('user', $userId);
        }

        return $user;
    }

    /** Media avatar hiện chọn (nullable) — Service điều phối, không JOIN. */
    public function avatarMedia(?int $mediaId): ?MediaModel
    {
        return $mediaId === null ? null : $this->mediaMapper()->findById($mediaId);
    }

    /** @return list<array{id: int, label: string, path: string}> Danh sách media cho selectField avatar. */
    public function mediaOptions(): array
    {
        return $this->mediaMapper()->listOptions();
    }

    /**
     * Form hồ sơ: validate bằng AccountProfileFilter rồi ghi đúng các trường
     * hiển thị; không bao giờ chạm passwordHash/cột kỹ thuật.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException field errors (kèm cả key `csrf`)
     */
    public function profileForm(int $userId, array $raw): void
    {
        $filter = new AccountProfileFilter(
            fn (string $username): bool => ! $this->userMapper()->isUsernameTaken($username, $userId)
        );
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = [
            'fullName'      => $filter->fullNameValue(),
            'email'         => $filter->emailValue(),
            'username'      => $filter->usernameValue(),
            'phone'         => $filter->phoneValue(),
            'avatarMediaId' => $filter->avatarMediaIdValue(),
        ];

        $this->me($userId);
        $this->userMapper()->update($userId, $values);
        $this->adminAuthService()->refreshIdentity($values['email'], $values['fullName'], $values['username']);
    }

    /**
     * Form đổi mật khẩu: filter bắt định dạng, Service kiểm currentPassword
     * khớp hash + newPassword === confirmPassword trước khi ghi hash mới.
     *
     * @param array<array-key, mixed> $raw
     */
    public function passwordForm(int $userId, array $raw): void
    {
        $filter = new AccountPasswordFilter();
        $filter->setData($raw);

        $errors = $filter->isValid() ? [] : $filter->fieldErrors();
        if ($errors === []) {
            $new     = $filter->newPasswordValue();
            $confirm = $filter->confirmPasswordValue();
            if ($new !== $confirm) {
                $errors['confirmPassword'] = UserConst::ERROR_PASSWORD_MISMATCH;
            } else {
                $user = $this->me($userId);
                if ($user->passwordHash === '' || ! password_verify($filter->currentValue(), $user->passwordHash)) {
                    $errors['currentPassword'] = UserConst::ERROR_PASSWORD_CURRENT;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->userMapper()->updatePasswordHash(
            $userId,
            password_hash($filter->newPasswordValue(), PASSWORD_DEFAULT)
        );
    }

    /** Hash CSRF cho form hồ sơ (view render hidden input). */
    public function profileFormCsrfHash(): string
    {
        return (new AccountProfileFilter())->csrfHash();
    }

    /** Hash CSRF cho form đổi mật khẩu. */
    public function passwordFormCsrfHash(): string
    {
        return (new AccountPasswordFilter())->csrfHash();
    }
}
