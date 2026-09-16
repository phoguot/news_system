<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Media\MediaMapper;
use Admin\Model\User\UserConst;
use Admin\Model\User\UserMapper;
use Admin\Model\User\UserModel;
use Admin\Service\AccountService;
use Admin\Service\AdminAuthService;
use ApplicationTest\Helper\TestContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Tài khoản của tôi" (FR-14): validate hồ sơ + đổi mật khẩu trên dòng users
 * duy nhất. UserMapper/AdminAuthService/MediaMapper đều mock; CSRF hash thật
 * (validator chạy được trong CLI như các test service khác).
 */
final class AccountServiceTest extends TestCase
{
    private UserMapper&MockObject $users;
    private AdminAuthService&MockObject $auth;
    private MediaMapper&MockObject $media;
    private AccountService $service;

    protected function setUp(): void
    {
        $this->users  = $this->createMock(UserMapper::class);
        $this->auth   = $this->createMock(AdminAuthService::class);
        $this->media  = $this->createMock(MediaMapper::class);
        $this->service = (new AccountService())->setContainer(new TestContainer([
            UserMapper::class        => $this->users,
            AdminAuthService::class  => $this->auth,
            MediaMapper::class       => $this->media,
        ]));

        $this->users->method('findById')->willReturnCallback(
            fn (int $id): ?UserModel => $id === 1 ? $this->makeUser() : null
        );
    }

    /**
     * @param array<array-key, mixed> $overrides
     */
    private function makeUser(array $overrides = []): UserModel
    {
        return UserModel::fromRow([
            'id'           => 1,
            'fullName'     => 'Quản Trị',
            'email'        => 'admin@examples.com',
            'passwordHash' => password_hash('mat-khau-cu-123', PASSWORD_DEFAULT),
            'phone'        => '0900000000',
            'createdAt'    => '2026-09-01 00:00:00',
            'updatedAt'    => '2026-09-01 00:00:00',
        ] + $overrides);
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function profileRaw(array $overrides = []): array
    {
        return $overrides + [
            'fullName'      => 'Nguyễn Văn A',
            'email'         => 'a@examples.com',
            'phone'         => '0912345678',
            'avatarMediaId' => '',
            'csrf'          => $this->service->profileFormCsrfHash(),
        ];
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function passwordRaw(array $overrides = []): array
    {
        return $overrides + [
            'currentPassword' => 'mat-khau-cu-123',
            'newPassword'     => 'mat-khau-moi-456',
            'confirmPassword' => 'mat-khau-moi-456',
            'csrf'            => $this->service->passwordFormCsrfHash(),
        ];
    }

    public function testProfileFormUpdatesValuesAndRefreshesSession(): void
    {
        $captured = null;
        $this->users->method('update')->willReturnCallback(
            static function (int $id, array $values) use (&$captured): void {
                $captured = ['id' => $id, 'values' => $values];
            }
        );
        $this->auth->expects(self::once())->method('refreshIdentity')->with('a@examples.com', 'Nguyễn Văn A', null);

        $this->service->profileForm(1, $this->profileRaw(['fullName' => '  Nguyễn Văn A ']));

        self::assertIsArray($captured);
        self::assertSame(1, $captured['id']);
        self::assertSame(
            [
                'fullName' => 'Nguyễn Văn A',
                'email' => 'a@examples.com',
                'username' => null,
                'phone' => '0912345678',
                'avatarMediaId' => null,
            ],
            $captured['values']
        );
    }

    public function testProfileFormStoresValidUsernameAndChecksUniqueness(): void
    {
        $captured = [];
        $this->users->method('update')->willReturnCallback(
            static function (int $id, array $values) use (&$captured): void {
                $captured = $values;
            }
        );
        $this->users->expects(self::once())->method('isUsernameTaken')
            ->with('quantri', 1)->willReturn(false);

        $this->service->profileForm(1, $this->profileRaw(['username' => ' quantri ']));

        self::assertSame('quantri', $captured['username']);
    }

    public function testProfileFormRejectsUsernameTakenByAnotherRow(): void
    {
        $this->users->method('isUsernameTaken')->willReturn(true);
        $this->users->expects(self::never())->method('update');

        try {
            $this->service->profileForm(1, $this->profileRaw(['username' => 'admin']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                UserConst::ERROR_USERNAME_TAKEN,
                $e->getErrors()['username'] ?? null
            );
        }
    }

    public function testProfileFormRejectsMalformedUsername(): void
    {
        $this->users->expects(self::never())->method('update');

        try {
            $this->service->profileForm(1, $this->profileRaw(['username' => 'Admin 1']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('username', $e->getErrors());
        }
    }

    public function testProfileFormStoresEmptyPhoneAsNullAndAvatarIdAsInt(): void
    {
        $captured = [];
        $this->users->method('update')->willReturnCallback(
            static function (int $id, array $values) use (&$captured): void {
                $captured = $values;
            }
        );

        $this->service->profileForm(1, $this->profileRaw(['phone' => '  ', 'avatarMediaId' => '42']));

        self::assertNull($captured['phone']);
        self::assertSame(42, $captured['avatarMediaId']);
    }

    public function testProfileFormRejectsInvalidEmailAndPhone(): void
    {
        $this->users->expects(self::never())->method('update');

        try {
            $this->service->profileForm(1, $this->profileRaw(['email' => 'khong-phai-email', 'phone' => '09ab']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            self::assertArrayHasKey('email', $errors);
            self::assertArrayHasKey('phone', $errors);
        }
    }

    public function testProfileFormRequiresCsrf(): void
    {
        try {
            $this->service->profileForm(1, $this->profileRaw(['csrf' => 'sai-token']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }

    public function testProfileFormThrowsNotFoundWhenRowMissing(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->profileForm(99, $this->profileRaw());
    }

    public function testPasswordFormRejectsWrongCurrentPassword(): void
    {
        $this->users->expects(self::never())->method('updatePasswordHash');

        try {
            $this->service->passwordForm(1, $this->passwordRaw(['currentPassword' => 'sai-mat-khau']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                UserConst::ERROR_PASSWORD_CURRENT,
                $e->getErrors()['currentPassword'] ?? null
            );
        }
    }

    public function testPasswordFormRejectsMismatchedConfirmation(): void
    {
        try {
            $this->service->passwordForm(1, $this->passwordRaw(['confirmPassword' => 'khac-rroi-nha']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                UserConst::ERROR_PASSWORD_MISMATCH,
                $e->getErrors()['confirmPassword'] ?? null
            );
        }
    }

    public function testPasswordFormEnforcesMinimumLength(): void
    {
        try {
            $this->service->passwordForm(1, $this->passwordRaw([
                'newPassword'     => 'ngan',
                'confirmPassword' => 'ngan',
            ]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('newPassword', $e->getErrors());
        }
    }

    public function testPasswordFormStoresVerifiableHash(): void
    {
        $capturedHash = null;
        $this->users->method('updatePasswordHash')->willReturnCallback(
            static function (int $id, string $hash) use (&$capturedHash): void {
                $capturedHash = $hash;
            }
        );

        $this->service->passwordForm(1, $this->passwordRaw());

        self::assertIsString($capturedHash);
        self::assertTrue(password_verify('mat-khau-moi-456', $capturedHash));
        self::assertFalse(password_verify('mat-khau-cu-123', $capturedHash));
    }

    public function testAvatarMediaDelegatesToMediaMapper(): void
    {
        self::assertNull($this->service->avatarMedia(null));
        $this->media->expects(self::never())->method('findById');
    }
}
