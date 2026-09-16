<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Media\MediaMapper;
use Admin\Model\TeamMember\TeamMemberConst;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\TeamMember\TeamMemberModel;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\TeamViewService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test cho TeamViewService (FR-09, docs §3.8 / §5.6):
 * - list(): trả về danh sách thành viên active, resolve avatar, ẩn contact khi showContact=0.
 */
final class TeamViewServiceTest extends TestCase
{
    private TeamMemberMapper&MockObject $team;
    private MediaMapper&MockObject $media;
    private TeamViewService $service;

    protected function setUp(): void
    {
        $this->team  = $this->createMock(TeamMemberMapper::class);
        $this->media = $this->createMock(MediaMapper::class);

        $this->service = (new TeamViewService())->setContainer(new TestContainer([
            TeamMemberMapper::class => $this->team,
            MediaMapper::class      => $this->media,
        ]));
    }

    private function member(int $id, array $overrides = []): TeamMemberModel
    {
        return TeamMemberModel::fromRow($overrides + [
            'id'            => $id,
            'fullName'      => 'Thành viên ' . $id,
            'positionTitle' => 'Chức danh ' . $id,
            'avatarMediaId' => null,
            'bio'           => null,
            'email'         => 'user' . $id . '@example.com',
            'phone'         => '09000000' . $id,
            'showContact'   => TeamMemberConst::SHOW_CONTACT,
            'socialLinks'   => null,
            'sortOrder'     => $id,
            'isFeatured'    => TeamMemberConst::NOT_FEATURED,
            'isActive'      => TeamMemberConst::ACTIVE,
        ]);
    }

    public function testListEmptyActiveReturnsEmptyPayload(): void
    {
        $this->team->method('listAllActive')->willReturn([]);
        $this->media->expects(self::never())->method('mapCardsByIds');

        $result = $this->service->list();

        self::assertSame([], $result['members']);
        self::assertSame(0, $result['total']);
    }

    public function testListWithMembersResolvesAvatar(): void
    {
        $m1 = $this->member(1, ['avatarMediaId' => 10]);
        $m2 = $this->member(2, ['avatarMediaId' => null]);

        $this->team->method('listAllActive')->willReturn([$m1, $m2]);
        $this->media->expects(self::once())
            ->method('mapCardsByIds')
            ->with([10])
            ->willReturn([
                10 => ['path' => 'av1.jpg', 'alt' => 'Avatar 1', 'thumb' => 'av1_t.webp'],
            ]);

        $result = $this->service->list();

        self::assertSame(2, $result['total']);
        self::assertSame('av1_t.webp', $result['members'][0]['avatar']['thumb'] ?? null);
        self::assertNull($result['members'][1]['avatar']);
    }

    public function testListShowsContactWhenFlagEnabled(): void
    {
        $m = $this->member(3, [
            'email'       => 'contact@example.com',
            'phone'       => '0901234567',
            'showContact' => TeamMemberConst::SHOW_CONTACT,
        ]);

        $this->team->method('listAllActive')->willReturn([$m]);
        $this->media->method('mapCardsByIds')->willReturn([]);

        $result  = $this->service->list();
        $payload = $result['members'][0];

        self::assertSame('contact@example.com', $payload['email']);
        self::assertSame('0901234567', $payload['phone']);
    }

    public function testListHidesContactWhenFlagDisabled(): void
    {
        $m = $this->member(4, [
            'email'       => 'hidden@example.com',
            'phone'       => '0909999999',
            'showContact' => TeamMemberConst::HIDE_CONTACT,
        ]);

        $this->team->method('listAllActive')->willReturn([$m]);
        $this->media->method('mapCardsByIds')->willReturn([]);

        $result  = $this->service->list();
        $payload = $result['members'][0];

        /* email và phone phải bị ẩn (null) khi showContact = 0 (§3.8) */
        self::assertNull($payload['email']);
        self::assertNull($payload['phone']);
    }

    public function testListDeduplicatesAvatarMediaIds(): void
    {
        /* 2 thành viên cùng media id → mapCardsByIds chỉ gọi 1 lần với unique id */
        $m1 = $this->member(5, ['avatarMediaId' => 99]);
        $m2 = $this->member(6, ['avatarMediaId' => 99]);

        $this->team->method('listAllActive')->willReturn([$m1, $m2]);
        $this->media->expects(self::once())
            ->method('mapCardsByIds')
            ->with([99])
            ->willReturn([
                99 => ['path' => 'shared.jpg', 'alt' => 'Shared', 'thumb' => 'shared_t.webp'],
            ]);

        $result = $this->service->list();

        self::assertSame(2, $result['total']);
        self::assertSame('shared.jpg', $result['members'][0]['avatar']['path'] ?? null);
        self::assertSame('shared.jpg', $result['members'][1]['avatar']['path'] ?? null);
    }

    public function testListReturnsIsFeaturedFlag(): void
    {
        $m = $this->member(7, ['isFeatured' => TeamMemberConst::FEATURED]);

        $this->team->method('listAllActive')->willReturn([$m]);
        $this->media->method('mapCardsByIds')->willReturn([]);

        $result = $this->service->list();

        self::assertTrue($result['members'][0]['isFeatured']);
    }
}
