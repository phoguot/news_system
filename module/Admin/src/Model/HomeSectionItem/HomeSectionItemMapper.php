<?php

declare(strict_types=1);

namespace Admin\Model\HomeSectionItem;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Sql;

/**
 * Mapper sở hữu bảng `home_section_items` (docs §4.4.4).
 * `deleteByItem` dọn tham chiếu đa hình khi xoá cứng bài (docs §4.6 —
 * itemType = ContentConst::SECTION_ITEM_*); `deleteBySectionId` cascade khi
 * HomeSectionService xoá section (gói trong transaction của Service).
 * CRUD mục chọn tay (FR-33): `listBySectionId` hydrate model theo
 * `sortOrder`, `insert`/`deleteByIdAndSection`/`updateSortOrder` cho Service.
 */
class HomeSectionItemMapper
{
    public const TABLE_NAME = 'home_section_items';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Mục của một section theo thứ tự hiển thị (docs §5.2: manual đọc
     * `home_section_items ORDER BY sortOrder`).
     *
     * @return list<HomeSectionItemModel>
     */
    public function listBySectionId(int $sectionId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['sectionId' => $sectionId]);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        $models = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $models[] = HomeSectionItemModel::fromRow($row);
        }

        return $models;
    }

    /**
     * @param array<array-key, mixed> $values cột => giá trị (sectionId, itemType, itemId, sortOrder)
     *
     * @psalm-suppress PossiblyUnusedReturnValue luồng PRG FR-33 không dùng id mới — giữ khuôn insert trả id.
     */
    public function insert(array $values): int
    {
        $sql = new Sql($this->db);

        return (int) $sql->prepareStatementForSqlObject(
            $sql->insert(self::TABLE_NAME)->values($values)
        )->execute()->getGeneratedValue();
    }

    /** Chặn trùng cặp (sectionId, itemType, itemId) — UNIQUE uq_home_section_items. */
    public function existsItem(int $sectionId, int $itemType, int $itemId): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id']);
        $select->where(['sectionId' => $sectionId, 'itemType' => $itemType, 'itemId' => $itemId]);
        $select->limit(1);

        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** Xoá theo CẢ id dòng + sectionId — chặn thao tác chéo section từ raw bị sửa. */
    public function deleteByIdAndSection(int $id, int $sectionId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['id' => $id, 'sectionId' => $sectionId])
        )->execute();
    }

    public function updateSortOrder(int $id, int $sortOrder): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->update(self::TABLE_NAME)->set(['sortOrder' => $sortOrder])->where(['id' => $id])
        )->execute();
    }

    public function deleteByItem(int $itemType, int $itemId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['itemType' => $itemType, 'itemId' => $itemId])
        )->execute();
    }

    public function deleteBySectionId(int $sectionId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['sectionId' => $sectionId])
        )->execute();
    }
}
