<?php

declare(strict_types=1);

namespace Admin\Model\TeamMember;

/**
 * POPO entity `team_members` (chuẩn 07 §3): TeamMemberMapper fill row qua
 * `fromRow()` — model không query DB, không biết HTTP.
 */
class TeamMemberModel
{
    public int $id = 0;
    public ?int $userId = null;
    public string $fullName = '';
    public string $positionTitle = '';
    public ?int $avatarMediaId = null;
    public ?string $bio = null;
    public ?string $email = null;
    public ?string $phone = null;
    public int $showContact = 0;
    public ?string $socialLinks = null;
    public int $sortOrder = 0;
    public int $isFeatured = TeamMemberConst::NOT_FEATURED;
    public int $isActive = TeamMemberConst::ACTIVE;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m                 = new static();
        $m->id             = (int) ($row['id'] ?? 0);
        $m->userId         = self::intOrNull($row['userId'] ?? null);
        $m->fullName       = (string) ($row['fullName'] ?? '');
        $m->positionTitle  = (string) ($row['positionTitle'] ?? '');
        $m->avatarMediaId  = self::intOrNull($row['avatarMediaId'] ?? null);
        $m->bio            = self::strOrNull($row['bio'] ?? null);
        $m->email          = self::strOrNull($row['email'] ?? null);
        $m->phone          = self::strOrNull($row['phone'] ?? null);
        $m->showContact    = (int) ($row['showContact'] ?? 0);
        $m->socialLinks    = self::strOrNull($row['socialLinks'] ?? null);
        $m->sortOrder      = (int) ($row['sortOrder'] ?? 0);
        $m->isFeatured     = (int) ($row['isFeatured'] ?? TeamMemberConst::NOT_FEATURED);
        $m->isActive       = (int) ($row['isActive'] ?? TeamMemberConst::ACTIVE);
        $m->createdAt      = (string) ($row['createdAt'] ?? '');
        $m->updatedAt      = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API team chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'userId'        => $this->userId,
            'fullName'      => $this->fullName,
            'positionTitle' => $this->positionTitle,
            'avatarMediaId' => $this->avatarMediaId,
            'bio'           => $this->bio,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'showContact'   => $this->showContact === 1,
            'socialLinks'   => $this->socialLinks,
            'sortOrder'     => $this->sortOrder,
            'isFeatured'    => $this->isFeatured === TeamMemberConst::FEATURED,
            'isActive'      => $this->isActive,
            'createdAt'     => $this->createdAt,
            'updatedAt'     => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form thành viên (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'fullName'      => $this->fullName,
            'positionTitle' => $this->positionTitle,
            'avatarMediaId' => $this->avatarMediaId ?? '',
            'bio'           => $this->bio ?? '',
            'email'         => $this->email ?? '',
            'phone'         => $this->phone ?? '',
            'showContact'   => $this->showContact,
            'socialLinks'   => $this->socialLinks ?? '',
            'userId'        => $this->userId ?? '',
            'sortOrder'     => $this->sortOrder,
            'isFeatured'    => $this->isFeatured,
            'isActive'      => $this->isActive,
        ];
    }

    private static function intOrNull(mixed $raw): ?int
    {
        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    private static function strOrNull(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
