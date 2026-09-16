<?php

declare(strict_types=1);

namespace Admin\Model\PostTag;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng trung gian `post_tags` (docs §4.4.3).
 * N-1: insert theo batch; merge tag dùng INSERT IGNORE…SELECT + DELETE — tất cả
 * chỉ đụng post_tags, không JOIN bảng tags/posts (chuẩn 07 §5).
 */
class PostTagMapper
{
    public const TABLE_NAME = 'post_tags';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Số bài dùng mỗi tag — GROUP BY trên đúng bảng post_tags (docs §3.4).
     *
     * @return array<int, int> tagId => số bài
     */
    public function countsAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['tagId', 'postCount' => new Expression('COUNT(*)')])
            ->group('tagId');

        $counts = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $counts[(int) $row['tagId']] = (int) $row['postCount'];
            }
        }

        return $counts;
    }

    /**
     * Ids bài viết gắn tag (Service đưa vào PostMapper::paginate dạng IN — 07 §5).
     *
     * @return list<int>
     */
    public function postIdsByTag(int $tagId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->columns(['postId'])->where(['tagId' => $tagId]);

        $ids = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $ids[] = (int) $row['postId'];
            }
        }

        return $ids;
    }

    /**
     * Số tag chung giữa bài hiện tại và từng bài ứng viên (docs §5.4 — bài
     * liên quan). Luật 1 mapper = 1 bảng: chỉ đụng `post_tags`, PostMapper +
     * Service ghép tiếp — KHÔNG JOIN chéo posts/tags.
     *
     * @param list<int> $tagIds
     *
     * @return array<int, int> postId => số tag chung (chỉ bài có ≥ 1 tag chung)
     */
    public function sharedTagCounts(int $excludePostId, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $sql    = new Sql($this->db);
        $result = $sql->prepareStatementForSqlObject(
            $this->sharedTagCountsSelect($excludePostId, $tagIds)
        )->execute();

        $counts = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($result as $row) {
            if (is_array($row)) {
                $counts[(int) $row['postId']] = (int) $row['sharedCount'];
            }
        }

        return $counts;
    }

    /**
     * `SELECT postId, COUNT(*) … WHERE tagId IN (…) AND postId <> … GROUP BY` —
     * tách public cho PostTagMapperSqlTest render không cần DB (khuôn batch 10).
     *
     * @param list<int> $tagIds
     */
    public function sharedTagCountsSelect(int $excludePostId, array $tagIds): Select
    {
        $where = new Where();
        $where->in('tagId', $tagIds);
        $where->notEqualTo('postId', $excludePostId);

        $select = new Select(self::TABLE_NAME);
        $select->columns(['postId', 'sharedCount' => new Expression('COUNT(*)')]);
        $select->where($where);
        $select->group('postId');

        return $select;
    }

    /**
     * @return list<int> tagId của một bài
     */
    public function tagIdsForPost(int $postId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)->columns(['tagId'])->where(['postId' => $postId]);

        $ids = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $ids[] = (int) $row['tagId'];
            }
        }

        return $ids;
    }

    /**
     * Thay toàn bộ tag của một bài (delete + insert trong cùng Service-transaction).
     *
     * @param list<int> $tagIds
     */
    public function replaceForPost(int $postId, array $tagIds): void
    {
        $this->deleteByPostId($postId);

        $sql = new Sql($this->db);
        foreach (array_unique($tagIds) as $tagId) {
            $insert = $sql->insert(self::TABLE_NAME)->values(['postId' => $postId, 'tagId' => $tagId]);
            $sql->prepareStatementForSqlObject($insert)->execute();
        }
    }

    public function deleteByPostId(int $postId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['postId' => $postId])
        )->execute();
    }

    public function deleteByTagId(int $tagId): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['tagId' => $tagId])
        )->execute();
    }

    /**
     * Chuyển mọi cặp (postId, fromTagId) sang (postId, toTagId); bài đã có tag
     * đích bị bỏ qua nhờ UNIQUE KEY (postId, tagId) — merge tag docs §3.4.
     * Raw SQL vì INSERT…SELECT chéo chính nó không đi qua Sql builder; vẫn
     * prepared statement + bind (chỉ tên bảng là hằng số).
     */
    public function reassignTag(int $fromTagId, int $toTagId): void
    {
        /**
         * @psalm-suppress UndefinedInterfaceMethod — query() có ở Adapter, thiếu trên AdapterInterface
         * @psalm-suppress MixedAssignment — kiểu trả về bị gắn với suppress phía trên nên về mixed
         */
        $stmt = $this->db->query(
            'INSERT INTO ' . self::TABLE_NAME . ' (postId, tagId) '
            . 'SELECT postId, :toTag FROM ' . self::TABLE_NAME . ' WHERE tagId = :fromTag '
            . 'ON DUPLICATE KEY UPDATE tagId = VALUES(tagId)',
            Adapter::QUERY_MODE_PREPARE
        );
        /** @psalm-suppress MixedMethodCall — $stmt là Statement từ driver */
        $stmt->execute(['toTag' => $toTagId, 'fromTag' => $fromTagId]);
    }
}
