<?php

declare(strict_types=1);

namespace Admin\Model\HomeSection;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

/**
 * Mapper sở hữu bảng `home_sections` (docs §3.7, chuẩn 07 §5 — chỉ đụng đúng
 * bảng này; `home_section_items` do HomeSectionItemMapper riêng). Method đọc
 * trả HomeSectionModel hydrate qua fromRow() (07 §3).
 */
class HomeSectionMapper
{
    public const TABLE_NAME = 'home_sections';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * @return list<HomeSectionModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * Section BẬT cho trang chủ công khai, theo đúng thứ tự hiển thị
     * (FR-32, docs §3.7 — tắt section là ẩn khối ngay, TTL cache ≤ 60s).
     *
     * @return list<HomeSectionModel>
     */
    public function listActiveOrdered(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['isActive' => HomeSectionConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?HomeSectionModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['id' => $id])
            ->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? HomeSectionModel::fromRow($row) : null;
    }

    /**
     * @param array<array-key, mixed> $values cột => giá trị (đã chuẩn hoá ở Service)
     */
    public function insert(array $values): int
    {
        $sql = new Sql($this->db);

        return (int) $sql->prepareStatementForSqlObject(
            $sql->insert(self::TABLE_NAME)->values($values)
        )->execute()->getGeneratedValue();
    }

    /**
     * @param array<array-key, mixed> $values
     */
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

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(Sql $sql, Select $select): array
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

    /**
     * @return list<HomeSectionModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = HomeSectionModel::fromRow($row);
        }

        return $models;
    }
}
