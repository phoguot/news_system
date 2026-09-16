<?php

declare(strict_types=1);

namespace Admin\Model\PasswordResetToken;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Sql;

/**
 * Mapper sở hữu bảng password_reset_tokens (docs §4.4.1, chuẩn 07 §5).
 * Chỉ đụng bảng này — mọi mốc thời gian là chuỗi UTC 'Y-m-d H:i:s'.
 */
final class PasswordResetTokenMapper
{
    public const TABLE_NAME = 'password_reset_tokens';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    public function findByHash(string $tokenHash): ?PasswordResetTokenModel
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['tokenHash' => $tokenHash]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? PasswordResetTokenModel::fromRow($row) : null;
    }

    /**
     * @param array{userId: int, tokenHash: string, expiresAt: string} $values
     */
    public function insert(array $values): int
    {
        $sql = new Sql($this->db);
        $insert = $sql->insert(self::TABLE_NAME);
        $insert->values($values);

        return (int) $sql->prepareStatementForSqlObject($insert)->execute()->getGeneratedValue();
    }

    public function markUsed(int $id, string $usedAtUtc): void
    {
        $sql = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set(['usedAt' => $usedAtUtc]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function deleteByUserId(int $userId): void
    {
        $sql = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['userId' => $userId]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
