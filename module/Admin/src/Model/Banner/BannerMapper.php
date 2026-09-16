<?php

declare(strict_types=1);

namespace Admin\Model\Banner;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `banners` (docs §3.5, chuẩn 07 §5 — chỉ đụng đúng bảng này).
 * Method đọc trả BannerModel hydrate qua fromRow() (07 §3).
 */
class BannerMapper
{
    public const TABLE_NAME = 'banners';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * @return list<BannerModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->order(['position' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * Banner ĐANG hiển thị tại một vị trí (FR-31, docs §3.6/§5.5):
     * isActive + position + cửa sổ startAt/endAt so với UTC_TIMESTAMP() của
     * DB (connection đã SET time_zone='+00:00') — null = không chặn mốc đó.
     *
     * @return list<BannerModel>
     */
    public function listActiveByPosition(string $position, ?int $limit = null): array
    {
        $sql = new Sql($this->db);

        return $this->models($sql, $this->activeByPositionSelect($position, $limit));
    }

    /**
     * Builder tách public cho SQL regression test (khuôn batch 10) — xem
     * docblock `listActiveByPosition()` về nghiệp vụ.
     */
    public function activeByPositionSelect(string $position, ?int $limit = null): Select
    {
        $where = new Where();
        $where->equalTo('isActive', BannerConst::ACTIVE);
        $where->equalTo('position', $position);

        $startWindow = $where->nest();
        $startWindow->isNull('startAt');
        $startWindow->or->lessThanOrEqualTo('startAt', new Expression('UTC_TIMESTAMP()'));

        $endWindow = $where->nest();
        $endWindow->isNull('endAt');
        $endWindow->or->greaterThanOrEqualTo('endAt', new Expression('UTC_TIMESTAMP()'));

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where($where);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        if ($limit !== null) {
            $select->limit($limit);
        }

        return $select;
    }

    /**
     * Hero trang chủ — toàn bộ banner active đang trong cửa sổ thời gian
     * (slider home đọc hết, không limit).
     *
     * @return list<BannerModel>
     */
    public function listActiveHomeHero(): array
    {
        return $this->listActiveByPosition(BannerConst::POSITION_HOME_HERO);
    }

    public function findById(int $id): ?BannerModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? BannerModel::fromRow($row) : null;
    }

    /**
     * @param array<array-key, mixed> $values cột => giá trị (đã chuẩn hoá ở Service)
     */
    public function insert(array $values): int
    {
        $sql    = new Sql($this->db);
        $insert = $sql->insert(self::TABLE_NAME);
        $insert->values($values);

        return (int) $sql->prepareStatementForSqlObject($insert)->execute()->getGeneratedValue();
    }

    /**
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

    public function delete(int $id): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * id các banner đang dùng một media ở ảnh desktop hoặc ảnh mobile
     * (FR-38, docs §5.14) — OR qua Predicate `or->` (bind trong prepared
     * statement), không JOIN bảng media.
     *
     * @return list<int>
     */
    public function findIdsByMedia(int $mediaId): array
    {
        $where = new Where();
        $where->equalTo('imageMediaId', $mediaId);
        $where->or->equalTo('mobileImageMediaId', $mediaId);

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id']);
        $select->where($where);

        $ids = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
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
     * @return list<BannerModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = BannerModel::fromRow($row);
        }

        return $models;
    }
}
