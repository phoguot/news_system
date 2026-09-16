<?php

declare(strict_types=1);

namespace Admin\Model\Category;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `categories` (docs §4.4.3, chuẩn 07 §5 — chỉ đụng đúng bảng này;
 * đếm bài theo danh mục là việc của PostMapper, Service điều phối).
 */
class CategoryMapper
{
    public const TABLE_NAME = 'categories';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Toàn bộ danh mục cho cây quản trị (cấp 1 trước, rồi theo sortOrder/id).
     *
     * @return list<CategoryModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->order(['parentId' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?CategoryModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? CategoryModel::fromRow($row) : null;
    }

    /**
     * Tra theo slug — Frontend dùng cho bộ lọc `?danh-muc=` (FR-02) và route
     * `/danh-muc/{slug}` (FR-05); caller tự kiểm `isActive` (05-cau-truc §4).
     */
    public function findBySlug(string $slug): ?CategoryModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['slug' => $slug]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? CategoryModel::fromRow($row) : null;
    }

    /**
     * Map id => name theo batch (chống N+1 khi list bài cần tên danh mục — chuẩn 07 §5).
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function getNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = new Sql($this->db);

        $names = [];
        foreach ($this->rows($sql, $this->namesSelect($ids)) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * Projection id/name theo `id IN (...)` — Where::in() thật, KHÔNG phải key
     * mảng kết hợp 'id IN' (laminas-db không parse ra toán tử, sinh SQL hỏng —
     * bug latent lộ khi PostListService FR-02 là caller đầu tiên có dữ liệu).
     *
     * Public vì CategoryMapperSqlTest cần render SQL không cần DB (batch 10);
     * caller nghiệp vụ chỉ có getNamesByIds.
     *
     * @param list<int> $ids
     */
    public function namesSelect(array $ids): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['id', 'name']);

        $where = new Where();
        $where->in('id', $ids);
        $select->where($where);

        return $select;
    }

    public function existsSlug(string $slug, ?int $excludeId = null): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['idCount' => new Expression('COUNT(*)')]);
        $select->where(['slug' => $slug]);

        if ($excludeId !== null) {
            $select->where->notEqualTo('id', $excludeId);
        }

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) && (int) ($row['idCount'] ?? 0) > 0;
    }

    public function countChildren(int $id): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['childCount' => new Expression('COUNT(*)')]);
        $select->where(['parentId' => $id]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['childCount'] ?? 0) : 0;
    }

    /** Tổng số danh mục (card "Danh mục" dashboard — mockup 13/09/2026). */
    public function countAll(): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Đếm danh mục tạo trong khoảng [fromUtc, toUtc) — mốc so sánh
     * "so với tháng trước" cho dashboard. Giá trị đi qua Where (bind tham số).
     */
    public function countCreatedBetween(string $fromUtc, string $toUtc): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')]);

        $where = new Where();
        $where->greaterThanOrEqualTo('createdAt', $fromUtc);
        $where->lessThan('createdAt', $toUtc);
        $select->where($where);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * id danh mục đang dùng ảnh bìa là media này (FR-38, docs §5.14).
     *
     * @return list<int>
     */
    public function findIdsByMedia(int $mediaId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id']);
        $select->where(['coverMediaId' => $mediaId]);

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
     * id danh mục CON ĐANG BẬT của một cha (FR-05, docs §5.3 — nhánh
     * `parentId = :cat AND isActive = 1`). Cây đã bị Admin chặn tối đa 2 cấp
     * (FR-26) nên một lớp con là đủ; thứ tự theo sortOrder/id như cây quản trị.
     *
     * @return list<int>
     */
    public function activeChildIds(int $parentId): array
    {
        $sql    = new Sql($this->db);
        $select = $this->activeChildIdsSelect($parentId);

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
     * SELECT id WHERE parentId + isActive — tách public cho SQL regression
     * test render `buildSqlString` không cần DB (khuôn batch 10).
     */
    public function activeChildIdsSelect(int $parentId): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['id']);

        $where = new Where();
        $where->equalTo('parentId', $parentId);
        $where->equalTo('isActive', CategoryConst::ACTIVE);
        $select->where($where);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $select;
    }

    /**
     * Slug danh mục ĐANG BẬT cho sitemap.xml (FR-11 / NFR-SEO-4 — docs §6.1:
     * "tự sinh từ bài viết, danh mục, dịch vụ"). Danh mục tắt không có trang
     * công khai (FR-05 404) → phải vắng mặt khỏi sitemap.
     *
     * @return list<string>
     */
    public function sitemapActiveSlugs(): array
    {
        $sql = new Sql($this->db);

        $slugs = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($this->sitemapActiveSlugsSelect())->execute() as $row) {
            if (is_array($row) && isset($row['slug']) && is_string($row['slug'])) {
                $slugs[] = $row['slug'];
            }
        }

        return $slugs;
    }

    /**
     * Select sitemap danh mục — tách public cho CategoryMapperSitemapSqlTest
     * render không cần DB (khuôn batch 10).
     */
    public function sitemapActiveSlugsSelect(): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['slug']);
        $select->where(['isActive' => CategoryConst::ACTIVE]);
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $select;
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

    /** Xoá cứng 1 dòng (Service đã kiểm tra ràng buộc §5.15 trước khi gọi). */
    public function delete(int $id): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * @param \Laminas\Db\Sql\Select $select
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(Sql $sql, \Laminas\Db\Sql\Select $select): array
    {
        $rows  = [];
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
     * Read đầy đủ bảng categories → hydrate Model (chuẩn 07 §3).
     * Riêng projection id => name (getNamesByIds) giữ mảng vì không đủ cột cho Model.
     *
     * @param \Laminas\Db\Sql\Select $select
     *
     * @return list<CategoryModel>
     */
    private function models(Sql $sql, \Laminas\Db\Sql\Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = CategoryModel::fromRow($row);
        }

        return $models;
    }
}
