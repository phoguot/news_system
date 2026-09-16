<?php

declare(strict_types=1);

namespace Admin\Model\Tag;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `tags` (docs §4.4.3, chuẩn 07 §5).
 * Đếm số bài dùng tag là việc của PostTagMapper (bảng post_tags) — Service ghép.
 */
class TagMapper
{
    public const TABLE_NAME = 'tags';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * @return list<TagModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->order(['name' => 'ASC']);

        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = TagModel::fromRow($row);
        }

        return $models;
    }

    public function findById(int $id): ?TagModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->where(['id' => $id])->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? TagModel::fromRow($row) : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> id => name (batch, chống N+1 — chuẩn 07 §5)
     */
    public function getNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = new Sql($this->db);

        $names = [];
        foreach ($this->rows($sql, $this->namesSelect($ids)) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Projection id/name theo `id IN (...)` — Where::in() thật, KHÔNG phải key
     * mảng kết hợp 'id IN' (laminas-db không parse ra toán tử, sinh SQL hỏng —
     * bug latent lộ khi PostListService FR-02 là caller đầu tiên có dữ liệu).
     *
     * Public vì TagMapperSqlTest cần render SQL không cần DB (batch 10);
     * caller nghiệp vụ chỉ có getNamesByIds.
     *
     * @param list<int> $ids
     */
    public function namesSelect(array $ids): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['id', 'name']);

        $where = new Where();
        $where->in('id', $ids);
        $select->where($where);

        return $select;
    }

    public function findBySlug(string $slug): ?TagModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->where(['slug' => $slug])->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? TagModel::fromRow($row) : null;
    }

    public function existsSlug(string $slug, ?int $excludeId = null): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['idCount' => new Expression('COUNT(*)')])
            ->where(['slug' => $slug]);

        if ($excludeId !== null) {
            $select->where->notEqualTo('id', $excludeId);
        }

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) && (int) ($row['idCount'] ?? 0) > 0;
    }

    public function insert(string $name, string $slug): int
    {
        $sql = new Sql($this->db);

        return (int) $sql->prepareStatementForSqlObject(
            $sql->insert(self::TABLE_NAME)->values(['name' => $name, 'slug' => $slug])
        )->execute()->getGeneratedValue();
    }

    public function update(int $id, string $name, string $slug): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->update(self::TABLE_NAME)->set(['name' => $name, 'slug' => $slug])->where(['id' => $id])
        )->execute();
    }

    /**
     * Tag theo tập id (FR-03 — chip tag trang chi tiết cần cả name lẫn slug để
     * liên kết /tag/{slug}; thứ tự hiển thị do Service quyết định). Where::in()
     * thật — cùng khuôn fix batch 10, không dùng key mảng kết hợp 'id IN'.
     *
     * @param list<int> $ids
     *
     * @return list<TagModel>
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->columns(['id', 'name', 'slug']);
        $where  = new Where();
        $where->in('id', $ids);
        $select->where($where);

        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = TagModel::fromRow($row);
        }

        return $models;
    }

    public function delete(int $id): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['id' => $id])
        )->execute();
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(Sql $sql, \Laminas\Db\Sql\Select $select): array
    {
        $rows   = [];
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
