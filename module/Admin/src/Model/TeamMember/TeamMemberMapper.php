<?php

declare(strict_types=1);

namespace Admin\Model\TeamMember;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `team_members` (docs §3.6, chuẩn 07 §5 — chỉ đụng đúng
 * bảng này). Method đọc trả TeamMemberModel hydrate qua fromRow() (07 §3).
 */
class TeamMemberMapper
{
    public const TABLE_NAME = 'team_members';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Danh sách thành viên đang hoạt động (isActive = 1) cho trang công khai /doi-ngu
     * (FR-09, docs §3.8). Sắp xếp theo sortOrder ASC, id ASC.
     *
     * @return list<TeamMemberModel>
     */
    public function listAllActive(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['isActive' => TeamMemberConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * @return list<TeamMemberModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * Chiếu nhẹ id + nhãn cho select box nhân sự (07 §3 — projection ít cột
     * giữ mảng scalar), theo sortOrder/id như listAll.
     *
     * @return list<array{id: int, label: string}>
     */
    public function listOptions(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id', 'fullName']);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        $options = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options[] = [
                'id'    => (int) $row['id'],
                'label' => (string) $row['fullName'],
            ];
        }

        return $options;
    }

    /**
     * Nhân sự NỔI BẬT đang hoạt động cho khối trang chủ auto (FR-32,
     * docs §3.7 type=6 mode=auto), theo sortOrder/id.
     *
     * @return list<TeamMemberModel>
     */
    public function listFeaturedActive(int $limit): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where([
                'isActive'   => TeamMemberConst::ACTIVE,
                'isFeatured' => TeamMemberConst::FEATURED,
            ])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->limit($limit);

        return $this->models($sql, $select);
    }

    /**
     * Nhân sự active theo tập id (type=6 mode=manual — Service giữ thứ tự
     * `home_section_items`; nhân sự ẩn/xoá tự vắng mặt).
     *
     * @param list<int> $ids
     *
     * @return list<TeamMemberModel>
     */
    public function listActiveByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $where = new Where();
        $where->in('id', $ids);
        $where->equalTo('isActive', TeamMemberConst::ACTIVE);

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where($where)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->limit(count($ids));

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?TeamMemberModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['id' => $id])
            ->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? TeamMemberModel::fromRow($row) : null;
    }

    public function findByUserId(int $userId): ?TeamMemberModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['userId' => $userId])
            ->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? TeamMemberModel::fromRow($row) : null;
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
     * id thành viên đang dùng ảnh đại diện là media này (FR-38, docs §5.14).
     *
     * @return list<int>
     */
    public function findIdsByMedia(int $mediaId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['id'])
            ->where(['avatarMediaId' => $mediaId]);

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
     * @return list<TeamMemberModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = TeamMemberModel::fromRow($row);
        }

        return $models;
    }
}
