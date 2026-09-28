<?php

declare(strict_types=1);

namespace Admin\Model\PostViewDaily;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `post_view_daily` (docs §4.4.3, §5.8).
 * FR-41: upsert lượt xem hàng ngày + dọn khi xoá bài cứng.
 */
class PostViewDailyMapper
{
    public const TABLE_NAME = 'post_view_daily';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Tăng lượt xem ngày UTC hiện tại cho một bài — insert nếu chưa có, +1 nếu đã có.
     * Dùng ON DUPLICATE KEY UPDATE với alias newRow (MySQL 8.0.19+ syntax).
     */
    public function increment(int $postId): void
    {
        $tbl = $this->db->getPlatform()->quoteIdentifier(self::TABLE_NAME);
        $sql = 'INSERT INTO ' . $tbl . ' (postId, viewDate, views) VALUES (?, UTC_DATE(), 1) AS newRow'
            . ' ON DUPLICATE KEY UPDATE views = ' . $tbl . '.views + newRow.views';
        $stmt = $this->db->createStatement($sql);
        $stmt->prepare();
        $stmt->execute([$postId]);
    }

    public function deleteByPostId(int $postId): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['postId' => $postId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /** Tổng lượt xem đã ghi của toàn bộ bài viết. */
    public function countAll(): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COALESCE(SUM(views), 0)')]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /** Đếm lượt xem trong khoảng ngày UTC [fromDate, toDate). */
    public function countBetween(string $fromDate, string $toDate): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COALESCE(SUM(views), 0)')]);

        $where = new Where();
        $where->greaterThanOrEqualTo('viewDate', $fromDate);
        $where->lessThan('viewDate', $toDate);
        $select->where($where);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Tổng lượt xem từng ngày UTC trong khoảng [fromDate, toDate).
     * Ngày không có dữ liệu được DashboardService điền 0 để biểu đồ liền mạch.
     *
     * @return array<string, int> map Y-m-d => views
     */
    public function countDailyBetween(string $fromDate, string $toDate): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns([
            'viewDate',
            'total' => new Expression('SUM(views)'),
        ]);

        $where = new Where();
        $where->greaterThanOrEqualTo('viewDate', $fromDate);
        $where->lessThan('viewDate', $toDate);
        $select->where($where);
        $select->group('viewDate');
        $select->order(['viewDate' => 'ASC']);

        $counts = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = (string) ($row['viewDate'] ?? '');
            if ($date !== '') {
                $counts[$date] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }
}
