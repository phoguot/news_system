<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Media\MediaMapper;
use Admin\Model\TeamMember\TeamMemberConst;
use Admin\Model\TeamMember\TeamMemberMapper;
use Application\Factory\AppServiceFactory;

/**
 * Tầng đọc trang đội ngũ công khai (FR-09, docs §3.8 / §5.6):
 * - list(): toàn bộ thành viên active (isActive = 1), sắp xếp sortOrder ASC, id ASC.
 *   Ẩn email / SĐT khi showContact = 0 (cột `team_members.showContact` — docs §3.8).
 *
 * Resolve avatar qua MediaMapper::mapCardsByIds chống N+1 (1 mapper 1 bảng, 07 §5).
 * Không cache (bảng đội ngũ ít bản ghi, thay đổi chậm — không thuộc danh mục cache §7.2).
 */
class TeamViewService extends AppServiceFactory
{
    /**
     * Payload cho trang đội ngũ /doi-ngu.
     *
     * @return array{
     *     members: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function list(): array
    {
        $models = $this->team()->listAllActive();
        if ($models === []) {
            return [
                'members' => [],
                'total'   => 0,
            ];
        }

        /* --- Collect avatar media ids (batch, chống N+1) --- */
        $avatarIds = [];
        foreach ($models as $member) {
            if ($member->avatarMediaId !== null) {
                $avatarIds[] = $member->avatarMediaId;
            }
        }

        $mediaMap = $avatarIds === []
            ? []
            : $this->media()->mapCardsByIds(array_values(array_unique($avatarIds)));

        /* --- Dựng payload — ẩn email/SĐT khi showContact = 0 (§3.8) --- */
        $members = [];
        foreach ($models as $m) {
            $avatar = $m->avatarMediaId !== null ? ($mediaMap[$m->avatarMediaId] ?? null) : null;

            $showContact = $m->showContact === TeamMemberConst::SHOW_CONTACT;

            $members[] = [
                'id'            => $m->id,
                'fullName'      => $m->fullName,
                'positionTitle' => $m->positionTitle,
                'bio'           => $m->bio,
                'avatar'        => $avatar,
                'email'         => $showContact ? $m->email : null,
                'phone'         => $showContact ? $m->phone : null,
                'socialLinks'   => $m->socialLinks,
                'isFeatured'    => $m->isFeatured === TeamMemberConst::FEATURED,
            ];
        }

        return [
            'members' => $members,
            'total'   => count($members),
        ];
    }

    private function team(): TeamMemberMapper
    {
        /** @var TeamMemberMapper */
        return $this->getContainerEntry(TeamMemberMapper::class);
    }

    private function media(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }
}
