<?php

declare(strict_types=1);

namespace Admin\Model\User;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Mapper cho bảng `users` — mapper DUY NHẤT sở hữu bảng users
 * (chuẩn docs-dev/01-quy-chuan/07-crud-convention.md §5, docs §4.4, §3.11).
 * Mọi mốc thời gian truyền vào đã là chuỗi UTC 'Y-m-d H:i:s' (docs §4.1) —
 * nguồn mốc giờ tập trung ở Application\Service\DateService.
 */
class UserMapper
{
    public const TABLE_NAME = 'users';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Định danh đăng nhập (docs §2.6 — hỗ trợ từ 13/09/2026): chuỗi chứa `@`
     * → tra theo `email`, ngược lại tra theo `username`. Regex của LoginFilter
     * đảm bảo username không bao giờ có `@` nên hai nhánh không chồng nhau.
     */
    public function getUserByIdentifier(string $identifier): ?UserModel
    {
        $column = str_contains($identifier, '@') ? 'email' : 'username';

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where([$column => $identifier]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? UserModel::fromRow($row) : null;
    }

    /**
     * Kiểm unique username khi sửa hồ sơ (FR-14): có dòng khác `exceptUserId`
     * đang giữ username này không.
     */
    public function isUsernameTaken(string $username, int $exceptUserId): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['cnt' => new Expression('COUNT(*)')]);
        $select->where(['username' => $username, 'id != ?' => $exceptUserId]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) && (int) ($row['cnt'] ?? 0) > 0;
    }

    public function findById(int $id): ?UserModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? UserModel::fromRow($row) : null;
    }

    /**
     * Ghi hồ sơ (FR-14). `updatedAt` do DDL tự đẩy
     * (ON UPDATE CURRENT_TIMESTAMP) — không truyền tay.
     *
     * @param array<array-key, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set($values);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Đổi mật khẩu (FR-14) — hàm riêng cho luồng đổi 1 cột (07 §3):
     * không cho phép đường nào khác ghi passwordHash qua mảng values tự do.
     */
    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set(['passwordHash' => $passwordHash]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Ghi nhận một lần đăng nhập sai.
     *
     * @param string|null $lockedUntilUtc NULL = chưa khoá; chuỗi UTC = thời điểm mở khoá
     */
    public function registerFailedLogin(int $userId, int $failedCount, ?string $lockedUntilUtc): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set([
            'failedLoginCount' => $failedCount,
            'lockedUntil'      => $lockedUntilUtc,
        ]);
        $update->where(['id' => $userId]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Đăng nhập thành công: xoá khoá + đếm lỗi, cập nhật lastLoginAt.
     */
    public function registerSuccessfulLogin(int $userId, string $nowUtc): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set([
            'failedLoginCount' => 0,
            'lockedUntil'      => null,
            'lastLoginAt'      => $nowUtc,
        ]);
        $update->where(['id' => $userId]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Số tài khoản đang lấy avatar từ một media (FR-38, docs §5.14 — bảng users
     * chỉ 1 dòng nên đếm là đủ, không cần trả id).
     */
    public function countByMedia(int $mediaId): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['mediaCount' => new Expression('COUNT(*)')]);
        $select->where(['avatarMediaId' => $mediaId]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['mediaCount'] ?? 0) : 0;
    }
}
