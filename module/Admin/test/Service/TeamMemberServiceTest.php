<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\TeamMember\TeamMemberConst;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\TeamMember\TeamMemberModel;
use Admin\Service\TeamMemberService;
use Application\Constant\CacheConst;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TeamMemberServiceTest extends TestCase
{
    private TeamMemberMapper&MockObject $team;
    private DbService&MockObject $db;
    private TeamMemberService $service;

    protected function setUp(): void
    {
        $this->team    = $this->createMock(TeamMemberMapper::class);
        $this->db      = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );
        $this->service = (new TeamMemberService())->setContainer(new TestContainer([
            TeamMemberMapper::class => $this->team,
            DbService::class        => $this->db,
        ]));
    }

    /**
     * POST thô hợp lệ tối thiểu + CSRF thật (validator Laminas chạy được trong CLI PHPUnit).
     *
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function raw(array $overrides = []): array
    {
        return $overrides + [
            'fullName'      => '  Tran Van B  ',
            'positionTitle' => 'Editor',
            'csrf'          => $this->service->saveFormCsrfHash(),
        ];
    }

    public function testSaveFormCreatesTrimmedValues(): void
    {
        $captured = null;
        $this->team->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 31;
            });

        $this->service->saveForm(null, $this->raw([
            'email'       => 'b@example.com',
            'phone'       => '0901 234 567',
            'isFeatured'  => '1',
            'isActive'    => '1',
        ]));

        self::assertIsArray($captured);
        self::assertSame('Tran Van B', $captured['fullName']);
        self::assertSame('Editor', $captured['positionTitle']);
        self::assertSame(TeamMemberConst::FEATURED, $captured['isFeatured']);
        self::assertSame(TeamMemberConst::ACTIVE, $captured['isActive']);
        self::assertSame('b@example.com', $captured['email']);
        // FR-34: buildValues luôn chứa userId/socialLinks (null khi trống) để đồng bộ cột DB
        self::assertArrayHasKey('userId', $captured);
        self::assertSame(null, $captured['userId']);
        self::assertArrayHasKey('socialLinks', $captured);
        self::assertSame(null, $captured['socialLinks']);
    }

    public function testSaveFormMissingFullNameRejected(): void
    {
        $this->team->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['fullName' => '']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('fullName', $e->getErrors());
        }
    }

    public function testSaveFormInvalidEmailRejected(): void
    {
        $this->team->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['email' => 'not-an-email']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->getErrors());
        }
    }

    public function testSaveFormInvalidPhoneRejected(): void
    {
        $this->team->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['phone' => 'abc!']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('phone', $e->getErrors());
        }
    }

    public function testSaveFormUpdateCallsMapperUpdate(): void
    {
        $this->team->method('findById')->willReturn(TeamMemberModel::fromRow(['id' => 6]));
        $this->team->expects(self::once())->method('update')->with(6, self::isType('array'));
        $this->team->expects(self::never())->method('insert');

        $this->service->saveForm(6, $this->raw());
    }

    public function testSaveFormUpdateMissingRowThrowsNotFound(): void
    {
        $this->team->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->saveForm(6, $this->raw());
    }

    public function testDeleteFormRejectsBadCsrf(): void
    {
        $this->team->expects(self::never())->method('delete');

        self::assertSame('csrf', $this->service->deleteForm(['id' => '4']));
    }

    public function testDeleteFormDeletesAndReturnsFlag(): void
    {
        $this->team->method('findById')->willReturn(TeamMemberModel::fromRow(['id' => 4]));
        $this->team->expects(self::once())->method('delete')->with(4);

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(TeamMemberConst::FLAG_DELETED, $flag);
    }

    public function testDeleteFormMissingRowReturnsNotfound(): void
    {
        $this->team->method('findById')->willReturn(null);
        $this->team->expects(self::never())->method('delete');

        $flag = $this->service->deleteForm(['id' => '99', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame('notfound', $flag);
    }

    public function testSaveFormValidSocialLinksStoredCompact(): void
    {
        $captured = null;
        $this->team->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;
                return 32;
            });
        $this->service->saveForm(null, $this->raw([
            'socialLinks' => '{"facebook":"https://facebook.com/vanlang","x":"https://x.com/vl"}',
        ]));
        self::assertIsArray($captured);
        self::assertSame(
            '{"facebook":"https://facebook.com/vanlang","x":"https://x.com/vl"}',
            $captured['socialLinks']
        );
    }

    public function testSaveFormSocialLinksFiltersNonHttpUrls(): void
    {
        $captured = null;
        $this->team->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;
                return 33;
            });
        $this->service->saveForm(null, $this->raw([
            'socialLinks' => '{"ok":"https://example.com","bad":"javascript:alert(1)","ftp":"ftp://example.com"}',
        ]));
        self::assertIsArray($captured);
        self::assertSame('{"ok":"https://example.com"}', $captured['socialLinks']);
    }

    public function testSaveFormInvalidSocialLinksRejected(): void
    {
        $this->team->expects(self::never())->method('insert');
        try {
            $this->service->saveForm(null, $this->raw(['socialLinks' => 'not-json']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('socialLinks', $e->getErrors());
        }
    }

    public function testSaveFormEmptySocialLinksBecomesNull(): void
    {
        $captured = null;
        $this->team->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;
                return 34;
            });
        $this->service->saveForm(null, $this->raw(['socialLinks' => '  ']));
        self::assertIsArray($captured);
        self::assertSame(null, $captured['socialLinks']);
    }

    public function testSaveFormUserIdNotFoundRejected(): void
    {
        $userMapper = $this->createMock(\Admin\Model\User\UserMapper::class);
        $userMapper->method('findById')->willReturn(null);
        $service = (new TeamMemberService())->setContainer(new \ApplicationTest\Helper\TestContainer([
            \Admin\Model\TeamMember\TeamMemberMapper::class => $this->team,
            \Admin\Model\User\UserMapper::class => $userMapper,
        ]));
        $this->team->expects(self::never())->method('insert');
        try {
            $service->saveForm(null, $this->raw(['userId' => '5', 'csrf' => $service->saveFormCsrfHash()]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('userId', $e->getErrors());
            self::assertSame(TeamMemberConst::ERROR_USER_NOT_FOUND, $e->getErrors()['userId']);
        }
    }

    public function testSaveFormUserIdTakenRejected(): void
    {
        $userMapper = $this->createMock(\Admin\Model\User\UserMapper::class);
        $userMapper->method('findById')->willReturn(\Admin\Model\User\UserModel::fromRow(['id' => 7]));
        $taken = \Admin\Model\TeamMember\TeamMemberModel::fromRow(['id' => 99, 'userId' => 7]);
        $this->team->method('findByUserId')->willReturn($taken);
        $service = (new TeamMemberService())->setContainer(new \ApplicationTest\Helper\TestContainer([
            \Admin\Model\TeamMember\TeamMemberMapper::class => $this->team,
            \Admin\Model\User\UserMapper::class => $userMapper,
        ]));
        $this->team->expects(self::never())->method('insert');
        try {
            $service->saveForm(null, $this->raw(['userId' => '7', 'csrf' => $service->saveFormCsrfHash()]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('userId', $e->getErrors());
            self::assertSame(TeamMemberConst::ERROR_USER_TAKEN, $e->getErrors()['userId']);
        }
    }

    public function testSaveFormUserIdSameRowOnUpdateAllowed(): void
    {
        $userMapper = $this->createMock(\Admin\Model\User\UserMapper::class);
        $userMapper->method('findById')->willReturn(\Admin\Model\User\UserModel::fromRow(['id' => 7]));
        $taken = \Admin\Model\TeamMember\TeamMemberModel::fromRow(['id' => 8, 'userId' => 7]);
        $this->team->method('findByUserId')->willReturn($taken);
        $this->team->method('findById')->willReturn(
            \Admin\Model\TeamMember\TeamMemberModel::fromRow(['id' => 8])
        );
        $this->team->expects(self::once())->method('update')->with(8, self::isType('array'));
        $service = (new TeamMemberService())->setContainer(new \ApplicationTest\Helper\TestContainer([
            \Admin\Model\TeamMember\TeamMemberMapper::class => $this->team,
            \Admin\Model\User\UserMapper::class => $userMapper,
        ]));
        $service->saveForm(8, $this->raw(['userId' => '7', 'csrf' => $service->saveFormCsrfHash()]));
        self::assertTrue(true);
    }

    public function testSaveFormValidUserIdStored(): void
    {
        $userMapper = $this->createMock(\Admin\Model\User\UserMapper::class);
        $userMapper->method('findById')->willReturn(\Admin\Model\User\UserModel::fromRow(['id' => 9]));
        $this->team->method('findByUserId')->willReturn(null);
        $captured = null;
        $this->team->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;
                return 35;
            });
        $service = (new TeamMemberService())->setContainer(new \ApplicationTest\Helper\TestContainer([
            \Admin\Model\TeamMember\TeamMemberMapper::class => $this->team,
            \Admin\Model\User\UserMapper::class => $userMapper,
        ]));
        $service->saveForm(null, $this->raw(['userId' => '9', 'csrf' => $service->saveFormCsrfHash()]));
        self::assertIsArray($captured);
        self::assertSame(9, $captured['userId']);
    }

    /** FR-32/FR-39: create/update/delete nhân sự đều forget 'home-v1'. */
    public function testWritesForgetHomeCache(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::exactly(3))->method('removeItem')->with(CacheConst::KEY_HOME);
        $this->team->method('insert')->willReturn(9);
        $this->team->method('findById')->willReturn(TeamMemberModel::fromRow(['id' => 9]));
        $service = (new TeamMemberService())->setContainer(new TestContainer([
            TeamMemberMapper::class => $this->team,
            PageCacheService::class => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $service->create(['fullName' => 'A', 'positionTitle' => 'B']);
        $service->update(9, ['fullName' => 'A', 'positionTitle' => 'B']);
        $service->delete(9);
    }

    /* ---- FR-34: kéo-thả đổi thứ tự (formReorder) ---- */

    public function testFormReorderAppliesNewSequenceAndRenumbers(): void
    {
        $this->team->method('listAll')->willReturn([
            TeamMemberModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            TeamMemberModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            TeamMemberModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->team->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '3,1,2',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(3, $result['applied']);
        self::assertSame([3 => 0, 1 => 1, 2 => 2], $writes);
    }

    public function testFormReorderIgnoresUnknownAndAppendsMissing(): void
    {
        $this->team->method('listAll')->willReturn([
            TeamMemberModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            TeamMemberModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            TeamMemberModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->team->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '2,99,1',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(2, $result['applied']);
        self::assertSame([2 => 0, 1 => 1], $writes);
    }

    public function testFormReorderRejectsBadCsrf(): void
    {
        $this->team->expects(self::never())->method('update');

        $result = $this->service->formReorder(['ids' => '2,1', 'csrf' => 'sai-token']);

        self::assertSame('csrf', $result['flag']);
        self::assertSame(0, $result['applied']);
    }

    /* ---- Cột "Ảnh" danh sách: avatarCardsFor (thumb + lightbox) ---- */

    public function testAvatarCardsForDedupesIdsIntoOneMediaQuery(): void
    {
        $media = $this->createMock(\Admin\Model\Media\MediaMapper::class);
        $media->expects(self::once())->method('mapCardsByIds')->with([7, 5])->willReturn([
            5 => ['path' => 'a.jpg', 'alt' => 'A', 'thumb' => 'thumb/a.jpg', 'large' => 'large/a.jpg'],
            7 => ['path' => 'b.jpg', 'alt' => '', 'thumb' => 'b.jpg', 'large' => 'b.jpg'],
        ]);
        $service = (new TeamMemberService())->setContainer(new TestContainer([
            \Admin\Model\Media\MediaMapper::class => $media,
        ]));

        $cards = $service->avatarCardsFor([
            TeamMemberModel::fromRow(['id' => 1, 'avatarMediaId' => 7]),
            TeamMemberModel::fromRow(['id' => 2, 'avatarMediaId' => 5]),
            TeamMemberModel::fromRow(['id' => 3, 'avatarMediaId' => 7]),
            TeamMemberModel::fromRow(['id' => 4]),
        ]);

        self::assertSame([5, 7], array_keys($cards));
        self::assertSame('thumb/a.jpg', $cards[5]['thumb']);
        self::assertSame('large/a.jpg', $cards[5]['large']);
    }

    public function testAvatarCardsForWithoutAvatarsSendsEmptyIds(): void
    {
        $media = $this->createMock(\Admin\Model\Media\MediaMapper::class);
        $media->expects(self::once())->method('mapCardsByIds')->with([])->willReturn([]);
        $service = (new TeamMemberService())->setContainer(new TestContainer([
            \Admin\Model\Media\MediaMapper::class => $media,
        ]));

        self::assertSame([], $service->avatarCardsFor([
            TeamMemberModel::fromRow(['id' => 1]),
        ]));
    }
}
