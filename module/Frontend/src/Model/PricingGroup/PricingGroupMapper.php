<?php

declare(strict_types=1);

namespace Frontend\Model\PricingGroup;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

/** Mapper duy nhất sở hữu bảng `pricing_groups`. */
class PricingGroupMapper
{
    public const TABLE_NAME = 'pricing_groups';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /** @return list<PricingGroupModel> */
    public function listAll(): array
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /** @return list<PricingGroupModel> */
    public function listActive(): array
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['isActive' => 1])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    public function findByServiceId(int $serviceId): ?PricingGroupModel
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->where(['serviceId' => $serviceId])->limit(1);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? PricingGroupModel::fromRow($row) : null;
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

    public function deleteByServiceId(int $serviceId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['serviceId' => $serviceId])
        )->execute();
    }

    /** @return list<PricingGroupModel> */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $models[] = PricingGroupModel::fromRow($row);
            }
        }

        return $models;
    }
}
