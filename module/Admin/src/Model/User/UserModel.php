<?php

declare(strict_types=1);

namespace Admin\Model\User;

/**
 * POPO entity `users` (bảng chỉ 1 dòng admin — docs §2.6): UserMapper fill
 * row qua `fromRow()` — model không query DB, không biết HTTP.
 *
 * @psalm-suppress PossiblyUnusedProperty các cột schema hydrate đủ, chưa dùng hết trong code.
 */
class UserModel
{
    public int $id = 0;
    public string $fullName = '';
    public string $email = '';
    public ?string $username = null;
    public string $passwordHash = '';
    public ?string $phone = null;
    public ?int $avatarMediaId = null;
    public int $failedLoginCount = 0;
    public ?string $lockedUntil = null;
    public ?string $lastLoginAt = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $m                  = new self();
        $m->id              = (int) ($row['id'] ?? 0);
        $m->fullName        = (string) ($row['fullName'] ?? '');
        $m->email           = (string) ($row['email'] ?? '');
        $m->username        = self::strOrNull($row['username'] ?? null);
        $m->passwordHash    = (string) ($row['passwordHash'] ?? '');
        $m->phone           = self::strOrNull($row['phone'] ?? null);
        $m->avatarMediaId   = self::intOrNull($row['avatarMediaId'] ?? null);
        $m->failedLoginCount = (int) ($row['failedLoginCount'] ?? 0);
        $m->lockedUntil     = self::strOrNull($row['lockedUntil'] ?? null);
        $m->lastLoginAt     = self::strOrNull($row['lastLoginAt'] ?? null);
        $m->createdAt       = (string) ($row['createdAt'] ?? '');
        $m->updatedAt       = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    private static function strOrNull(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private static function intOrNull(mixed $raw): ?int
    {
        return $raw === null || $raw === '' ? null : (int) $raw;
    }
}
