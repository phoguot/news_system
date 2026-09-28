<?php

declare(strict_types=1);

namespace Admin\Model\Review;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

class ReviewMapper
{
    public const TABLE_NAME = 'reviews';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /** @return list<ReviewModel> */
    public function listAll(): array
    {
        $sql = new Sql($this->db);
        return $this->models($sql, $sql->select(self::TABLE_NAME)->order(['sortOrder' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<ReviewModel> */
    public function listActive(int $limit): array
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['isActive' => ReviewConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->limit($limit);

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?ReviewModel
    {
        $sql = new Sql($this->db);
        $row = $sql->prepareStatementForSqlObject(
            $sql->select(self::TABLE_NAME)->where(['id' => $id])->limit(1)
        )->execute()->current();

        return is_array($row) ? ReviewModel::fromRow($row) : null;
    }

    /** @param array<array-key, mixed> $values */
    public function insert(array $values): int
    {
        $sql = new Sql($this->db);
        return (int) $sql->prepareStatementForSqlObject(
            $sql->insert(self::TABLE_NAME)->values($values)
        )->execute()->getGeneratedValue();
    }

    /** @param array<array-key, mixed> $values */
    public function update(int $id, array $values): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->update(self::TABLE_NAME)->set($values)->where(['id' => $id])
        )->execute();
    }

    public function delete(int $id): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['id' => $id])
        )->execute();
    }

    /** @return list<int> */
    public function findIdsByMedia(int $mediaId): array
    {
        $sql = new Sql($this->db);
        $result = $sql->prepareStatementForSqlObject(
            $sql->select(self::TABLE_NAME)->columns(['id'])->where(['imageMediaId' => $mediaId])
        )->execute();
        $ids = [];
        foreach ($result as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** @return list<ReviewModel> */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $models[] = ReviewModel::fromRow($row);
            }
        }
        return $models;
    }
}
