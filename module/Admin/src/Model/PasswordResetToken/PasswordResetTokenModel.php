<?php

declare(strict_types=1);

namespace Admin\Model\PasswordResetToken;

/**
 * POPO entity password_reset_tokens (docs §3.11, §4.4.1): hash SHA-256 token, expiry, usedAt.
 * Mapper hydrate qua fromRow() — model không query DB.
 */
final class PasswordResetTokenModel
{
    public int $id = 0;
    public int $userId = 0;
    public string $tokenHash = '';
    public string $expiresAt = '';
    public ?string $usedAt = null;
    public string $createdAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int) ($row['id'] ?? 0);
        $m->userId = (int) ($row['userId'] ?? 0);
        $m->tokenHash = (string) ($row['tokenHash'] ?? '');
        $m->expiresAt = (string) ($row['expiresAt'] ?? '');
        $m->usedAt = isset($row['usedAt']) && is_string($row['usedAt']) && $row['usedAt'] !== '' ? $row['usedAt'] : null;
        $m->createdAt = (string) ($row['createdAt'] ?? '');

        return $m;
    }
}
