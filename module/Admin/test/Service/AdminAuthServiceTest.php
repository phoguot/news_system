<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Constant\AdminConst;
use Admin\Service\AdminAuthService;
use Admin\Model\User\UserMapper;
use Admin\Model\User\UserModel;
use ApplicationTest\Helper\TestContainer;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Result;
use Laminas\Authentication\Storage\NonPersistent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminAuthServiceTest extends TestCase
{
    private const EMAIL = 'admin@vanlang.vn';
    private const USERNAME = 'admin';
    private const PASSWORD = 'Secret#123';

    private UserMapper&MockObject $userMapper;
    private AuthenticationService $auth;
    private AdminAuthService $service;

    protected function setUp(): void
    {
        $this->userMapper = $this->createMock(UserMapper::class);
        $this->auth      = new AuthenticationService(new NonPersistent());
        $this->service   = (new AdminAuthService())->setContainer(new TestContainer([
            AuthenticationService::class => $this->auth,
            UserMapper::class            => $this->userMapper,
        ]));
    }

    /** @return array<string, mixed> */
    private function userRow(int $failedCount = 0, ?string $lockedUntil = null): array
    {
        return [
            'id'               => 1,
            'fullName'         => 'Quản Trị',
            'email'            => self::EMAIL,
            'username'         => self::USERNAME,
            'passwordHash'     => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'failedLoginCount' => $failedCount,
            'lockedUntil'      => $lockedUntil,
        ];
    }

    /** Mapper trả model theo luồng mới — stub trả UserModel hydrate từ row. */
    private function userModel(int $failedCount = 0, ?string $lockedUntil = null): UserModel
    {
        return UserModel::fromRow($this->userRow($failedCount, $lockedUntil));
    }

    public function testLoginSuccessWritesIdentityAndResetsCounters(): void
    {
        $this->userMapper->expects(self::once())->method('getUserByIdentifier')
            ->with(self::EMAIL)->willReturn($this->userModel(3));
        $this->userMapper->expects(self::once())->method('registerSuccessfulLogin')->with(1, self::isType('string'));

        $result = $this->service->login(self::EMAIL, self::PASSWORD);

        self::assertTrue($result->isValid());
        self::assertSame(Result::SUCCESS, $result->getCode());
        self::assertTrue($this->service->hasIdentity());
        self::assertSame(
            ['id' => 1, 'email' => self::EMAIL, 'fullName' => 'Quản Trị', 'username' => self::USERNAME],
            $this->service->getIdentity()
        );
    }

    public function testLoginByUsernameSuccess(): void
    {
        $this->userMapper->expects(self::once())->method('getUserByIdentifier')
            ->with(self::USERNAME)->willReturn($this->userModel());
        $this->userMapper->expects(self::once())->method('registerSuccessfulLogin')->with(1, self::isType('string'));

        $result = $this->service->login(self::USERNAME, self::PASSWORD);

        self::assertTrue($result->isValid());
        self::assertSame(
            ['id' => 1, 'email' => self::EMAIL, 'fullName' => 'Quản Trị', 'username' => self::USERNAME],
            $this->service->getIdentity()
        );
    }

    public function testWrongPasswordIncrementsFailedCountWithoutLock(): void
    {
        $this->userMapper->method('getUserByIdentifier')->willReturn($this->userModel(1));
        $this->userMapper->expects(self::once())->method('registerFailedLogin')->with(1, 2, null);

        $result = $this->service->login(self::EMAIL, 'wrong-password');

        self::assertFalse($result->isValid());
        self::assertSame(Result::FAILURE_CREDENTIAL_INVALID, $result->getCode());
        self::assertSame([AdminConst::ERROR_INVALID_CREDENTIALS], $result->getMessages());
    }

    public function testFifthFailedAttemptLocksAccount(): void
    {
        $this->userMapper->method('getUserByIdentifier')->willReturn($this->userModel(4));
        $this->userMapper->expects(self::once())->method('registerFailedLogin')->with(1, 5, self::isType('string'));

        $result = $this->service->login(self::EMAIL, 'wrong-password');

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->getMessages());
    }

    public function testLockedAccountRejectedBeforePasswordCheck(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 600);
        $this->userMapper->method('getUserByIdentifier')->willReturn($this->userModel(5, $future));
        $this->userMapper->expects(self::never())->method('registerFailedLogin');

        $result = $this->service->login(self::USERNAME, self::PASSWORD);

        self::assertFalse($result->isValid());
        self::assertSame(Result::FAILURE_UNCATEGORIZED, $result->getCode());
    }

    public function testUnknownEmailReturnsGenericFailure(): void
    {
        $this->userMapper->method('getUserByIdentifier')->willReturn(null);
        $this->userMapper->expects(self::never())->method('registerFailedLogin');

        $result = $this->service->login('ghost@vanlang.vn', 'whatever');

        self::assertFalse($result->isValid());
        self::assertSame([AdminConst::ERROR_INVALID_CREDENTIALS], $result->getMessages());
    }

    public function testUnknownUsernameReturnsSameGenericFailure(): void
    {
        // Chống dò tài khoản: username không tồn tại phải trả ĐÚNG thông điệp
        // chung như email không tồn tại (docs 03-xac-thuc "Luật chống dò").
        $this->userMapper->method('getUserByIdentifier')->willReturn(null);
        $this->userMapper->expects(self::never())->method('registerFailedLogin');

        $result = $this->service->login('ghost', 'whatever');

        self::assertFalse($result->isValid());
        self::assertSame([AdminConst::ERROR_INVALID_CREDENTIALS], $result->getMessages());
    }

    public function testIdentityUsernameNullWhenAccountHasNone(): void
    {
        $row                       = $this->userRow();
        $row['username']           = null;
        $user                      = UserModel::fromRow($row);
        $this->userMapper->method('getUserByIdentifier')->willReturn($user);

        $result = $this->service->login(self::EMAIL, self::PASSWORD);

        self::assertTrue($result->isValid());
        $identity = $this->service->getIdentity();
        self::assertNotNull($identity);
        self::assertNull($identity['username']);
    }

    public function testLogoutClearsIdentity(): void
    {
        $this->userMapper->method('getUserByIdentifier')->willReturn($this->userModel());
        $this->service->login(self::EMAIL, self::PASSWORD);
        self::assertTrue($this->service->hasIdentity());

        $this->service->logout();

        self::assertFalse($this->service->hasIdentity());
        self::assertNull($this->service->getIdentity());
    }

    public function testAttemptLoginDelegatesValidRawToLogin(): void
    {
        $this->userMapper->method('getUserByIdentifier')->willReturn($this->userModel(3));
        $this->userMapper->expects(self::once())->method('registerSuccessfulLogin');

        $result = $this->service->attemptLogin(
            ['identity' => self::EMAIL, 'password' => self::PASSWORD],
            false // không session CSRF trong unit test
        );

        self::assertTrue($result->isValid());
    }

    public function testAttemptLoginUsernameRawAccepted(): void
    {
        $this->userMapper->expects(self::once())->method('getUserByIdentifier')
            ->with(self::USERNAME)->willReturn($this->userModel());
        $this->userMapper->expects(self::once())->method('registerSuccessfulLogin');

        $result = $this->service->attemptLogin(
            ['identity' => self::USERNAME, 'password' => self::PASSWORD],
            false
        );

        self::assertTrue($result->isValid());
    }

    public function testAttemptLoginInvalidRawFailsWithoutTouchingMapper(): void
    {
        $this->userMapper->expects(self::never())->method('getUserByIdentifier');

        // Dấu cách + '!' nằm ngoài tập ký tự identity hợp lệ → filter chặn, mapper không gọi
        $result = $this->service->attemptLogin(['identity' => 'khong hop le!!', 'password' => ''], false);

        self::assertFalse($result->isValid());
        self::assertSame(Result::FAILURE_CREDENTIAL_INVALID, $result->getCode());
    }
}
