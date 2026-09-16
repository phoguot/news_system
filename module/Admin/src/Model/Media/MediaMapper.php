<?php

declare(strict_types=1);

namespace Admin\Model\Media;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `media` (chuẩn 07 §5 — chỉ đụng đúng bảng này; kiểm tra
 * nội dung nào đang dùng media là việc của các mapper bảng kia, MediaService
 * điều phối — docs §5.14). Method đọc trả MediaModel hydrate qua fromRow() (07 §3).
 */
class MediaMapper
{
    public const TABLE_NAME = 'media';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Toàn bộ media, mới nhất trước (trang thư viện — docs §3.10).
     *
     * @return list<MediaModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->order(['id' => 'DESC']);

        return $this->models($sql, $select);
    }

    /**
     * Chiếu nhẹ id + nhãn + path cho select box ảnh trong form (07 §3 —
     * projection ít cột giữ mảng scalar), mới nhất trước.
     *
     * @return list<array{id: int, label: string, path: string}>
     */
    public function listOptions(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id', 'originalName', 'path']);
        $select->order(['id' => 'DESC']);

        $options = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options[] = [
                'id'    => (int) $row['id'],
                'label' => (string) $row['originalName'],
                'path'  => (string) $row['path'],
            ];
        }

        return $options;
    }

    public function findById(int $id): ?MediaModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? MediaModel::fromRow($row) : null;
    }

    /**
     * Thông tin hiển thị thẻ ảnh (FR-32 trang chủ · thumb danh sách admin) —
     * 1 query IN, tránh N+1 (07 §5): id => ['path' => đường tương đối uploads,
     * 'alt' => altText, 'thumb'/'large' => biến thể nếu có, không thì path].
     *
     * @param list<int> $ids
     *
     * @return array<int, array{path: string, alt: string, thumb: string, large: string}>
     */
    public function mapCardsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $where = new Where();
        $where->in('id', $ids);

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id', 'path', 'altText', 'variants']);
        $select->where($where);

        $cards = [];
        foreach ($this->rows($sql, $select) as $row) {
            $model   = MediaModel::fromRow($row);
            $variant = $model->variantsArray();

            $cards[$model->id] = [
                'path'  => $model->path,
                'alt'   => $model->altText ?? '',
                'thumb' => $variant['thumb'] ?? $model->path,
                'large' => $variant['large'] ?? $model->path,
            ];
        }

        return $cards;
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
     * Đổi altText từ thư viện — hàm riêng cho luồng đổi 1 cột (07 §3).
     * Không còn `update(array $values)` chung: mọi ghi trên media đi qua
     * đường có ràng buộc kiểu rõ ràng.
     */
    public function updateAltText(int $id, ?string $altText): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set(['altText' => $altText]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /** Xoá cứng 1 dòng (Service đã dọn file + kiểm tra ràng buộc trước khi gọi). */
    public function delete(int $id): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
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
     * @return list<MediaModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = MediaModel::fromRow($row);
        }

        return $models;
    }
}
