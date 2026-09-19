<?php

declare(strict_types=1);

namespace Frontend\Model\Menu;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

/**
 * Mapper sở hữu bảng `menu_items` — Frontend đọc, Admin CRUD dùng lại.
 */
class MenuMapper
{
    public const TABLE_NAME = 'menu_items';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /** @return list<MenuModel> */
    public function listAll(): array
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /** @return list<MenuModel> */
    public function listActive(): array
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['isActive' => MenuConst::ACTIVE]);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?MenuModel
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed. */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? MenuModel::fromRow($row) : null;
    }

    /** @param array<array-key, mixed> $values */
    /** @psalm-suppress PossiblyUnusedReturnValue id phục vụ luồng CRUD tương lai. */
    public function insert(array $values): int
    {
        $sql = new Sql($this->db);
        $insert = $sql->insert(self::TABLE_NAME);
        $insert->values($values);

        return (int) $sql->prepareStatementForSqlObject($insert)->execute()->getGeneratedValue();
    }

    /** @param array<array-key, mixed> $values */
    public function update(int $id, array $values): void
    {
        $sql = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set($values);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function delete(int $id): void
    {
        $sql = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(Sql $sql, Select $select): array
    {
        $rows = [];
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed. */
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<MenuModel> */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = MenuModel::fromRow($row);
        }

        return $models;
    }
}
