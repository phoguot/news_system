<?php

declare(strict_types=1);

namespace Admin\Model\PostRevision;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Predicate\Operator;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `post_revisions` (docs §4.4.3, §5.13).
 * type: 0=manual · 1=autosave · 2=before_publish (docs §4.4.3 chú giải) —
 * giữ 20 bản manual/before_publish mới nhất, cắt ngay khi lưu (không job).
 */
class PostRevisionMapper
{
    public const TABLE_NAME = 'post_revisions';

    public const TYPE_MANUAL         = 0;
    public const TYPE_AUTOSAVE       = 1;
    public const TYPE_BEFORE_PUBLISH = 2;

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Ghi một revision; id không cần thiết cho luồng nào (cắt bản cũ dựa trên
     * createdAt/id mới nhất — docs §5.13) nên không trả về.
     */
    public function insertRevision(
        int $postId,
        ?int $userId,
        int $type,
        string $title,
        ?string $excerpt,
        string $content
    ): void {
        $sql    = new Sql($this->db);
        $insert = $sql->insert(self::TABLE_NAME);
        $insert->values([
            'postId'  => $postId,
            'userId'  => $userId,
            'type'    => $type,
            'title'   => $title,
            'excerpt' => $excerpt,
            'content' => $content,
        ]);
        $sql->prepareStatementForSqlObject($insert)->execute();
    }

    /**
     * id của $keep bản manual/before_publish MỚI NHẤT — bản ngoài danh sách bị cắt.
     *
     * @return list<int>
     */
    public function recentKeptIds(int $postId, int $keep): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id']);
        $select->where(self::notAutosaveWhere($postId));
        $select->order(['createdAt' => 'DESC', 'id' => 'DESC']);
        $select->limit($keep);

        $ids = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Xoá revision manual/before_publish ngoài danh sách giữ (docs §5.13 —
     * chạy 2 truy vấn trên đúng 1 bảng thay cho NOT IN subquery).
     */
    public function deleteOlderThan(int $postId, array $keepIds): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(self::notAutosaveWhere($postId));

        if ($keepIds !== []) {
            $delete->where->notIn('id', $keepIds);
        }

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function findById(int $id): ?PostRevisionModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? PostRevisionModel::fromRow($row) : null;
    }

    /**
     * Danh sách revision cho một bài — mới nhất trước (createdAt DESC, id DESC).
     * Bao gồm cả autosave để UI hiển thị đầy đủ; cap 20 chỉ áp dụng cho
     * manual/before_publish (recentKeptIds/deleteOlderThan), không lọc ở đây.
     *
     * @return list<PostRevisionModel>
     */
    public function listByPostId(int $postId, int $limit = 50): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['postId' => $postId]);
        $select->order(['createdAt' => 'DESC', 'id' => 'DESC']);
        $select->limit($limit);

        $rows = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row)) {
                $rows[] = PostRevisionModel::fromRow($row);
            }
        }

        return $rows;
    }

    /** Xoá bản autosave cũ trước khi ghi bản mới (docs §5.13 — chỉ giữ 1 bản). */
    public function deleteAutosaveByPostId(int $postId): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $where  = new Where();
        $where->equalTo('postId', $postId);
        $where->equalTo('type', self::TYPE_AUTOSAVE);
        $delete->where($where);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function deleteByPostId(int $postId): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['postId' => $postId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * Where "postId = ? AND type != autosave". KHÔNG dùng mảng tắt
     * `['type !=' => …]`: laminas-db 2.22 sinh SQL hỏng trên platform MySQL
     * (`type` `!``=` = … → syntax error 1064, chỉ lộ khi driver Pdo —
     * bản cũ chết ở transientBegin trước khi tới đây). Operator tường minh
     * sinh đúng `type` != ? — vẫn prepared statement (AGENTS §3).
     */
    private static function notAutosaveWhere(int $postId): Where
    {
        $where = new Where();
        $where->equalTo('postId', $postId);
        $where->addPredicate(new Operator('type', '!=', self::TYPE_AUTOSAVE));

        return $where;
    }
}
