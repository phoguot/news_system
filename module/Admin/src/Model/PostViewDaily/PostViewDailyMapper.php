<?php

declare(strict_types=1);

namespace Admin\Model\PostViewDaily;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Sql;

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
}
