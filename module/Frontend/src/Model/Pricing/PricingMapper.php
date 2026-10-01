<?php

declare(strict_types=1);

namespace Frontend\Model\Pricing;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `pricing_items` (chuẩn 07 §5 — 1 bảng 1 mapper).
 * Frontend đọc qua `listActive*`; Admin CRUD dùng cùng mapper (container gộp).
 */
class PricingMapper
{
    public const TABLE_NAME = 'pricing_items';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /** @return list<PricingModel> */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /** @return list<PricingModel> */
    public function listActive(?string $groupCode = null, ?string $q = null): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);
        $where = new Where();
        $where->equalTo('isActive', PricingConst::ACTIVE);
        if ($groupCode !== null && $groupCode !== '') {
            $where->equalTo('groupCode', $groupCode);
        }
        if ($q !== null && trim($q) !== '') {
            $where->like('name', '%' . trim($q) . '%');
        }
        $select->where($where);

        return $this->models($sql, $select);
    }

    public function findByServiceId(int $serviceId): ?PricingModel
    {
        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->where(['serviceId' => $serviceId])->limit(1);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();
        return is_array($row) ? PricingModel::fromRow($row) : null;
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
        $delete = $sql->delete(self::TABLE_NAME)->where(['serviceId' => $serviceId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
    /** @return list<array<array-key, mixed>> */
    private function rows(Sql $sql, Select $select): array
    {
        $rows = [];
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<PricingModel> */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = PricingModel::fromRow($row);
        }

        return $models;
    }
}
