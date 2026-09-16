<?php

declare(strict_types=1);

namespace Frontend\Model\Service;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `services` (chuẩn 07 §5 — 1 bảng 1 mapper, chỉ đụng đúng
 * bảng này). Frontend đọc qua `listActiveOptions()` (dropdown "Dịch vụ quan
 * tâm"); Admin CRUD dùng các method đọc/ghi còn lại qua cùng mapper (DI
 * container gộp, 07 §4).
 */
class ServiceMapper
{
    public const TABLE_NAME = 'services';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Danh sách dịch vụ đang hoạt động cho select: [id => name], theo sortOrder.
     *
     * @return array<int, string>
     */
    public function listActiveOptions(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['id', 'name'])
            ->where(['isActive' => 1])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        $options = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * Toàn bộ dịch vụ cho trang quản trị, theo sortOrder/id.
     *
     * @return list<ServiceModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * Dịch vụ đang hoạt động cho khối trang chủ auto (FR-32, docs §3.7 type=5),
     * theo sortOrder/id.
     *
     * @return list<ServiceModel>
     */
    /** @return list<ServiceModel> */
    public function listParents(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where->isNull('parentId');
        $select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /** @return list<ServiceModel> */
    public function listChildren(int $parentId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['parentId' => $parentId])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /** @return array<int, string> id => name cho select cha. */
    public function listParentOptions(?int $excludeId = null): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['id', 'name'])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);
        $select->where->isNull('parentId');
        if ($excludeId !== null) {
            $select->where->notEqualTo('id', $excludeId);
        }

        $options = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    public function hasChildren(int $id): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['cnt' => new Expression('COUNT(*)')])
            ->where(['parentId' => $id]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) && (int) ($row['cnt'] ?? 0) > 0;
    }

    public function listActive(int $limit): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
        ->where(['isActive' => ServiceConst::ACTIVE])
        ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
        ->limit($limit);

        return $this->models($sql, $select);
    }

    /**
     * Dịch vụ active theo tập id (type=5 mode=manual — Service giữ thứ tự
     * từ `home_section_items`; dịch vụ ẩn/xoá tự vắng mặt).
     *
     * @param list<int> $ids
     *
     * @return list<ServiceModel>
     */
    public function listActiveByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $where = new Where();
        $where->in('id', $ids);
        $where->equalTo('isActive', ServiceConst::ACTIVE);

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where($where)
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->limit(count($ids));

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?ServiceModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['id' => $id])
            ->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? ServiceModel::fromRow($row) : null;
    }

    /**
     * Toàn bộ dịch vụ đang hoạt động cho trang danh sách /dich-vu (FR-08, docs §2.1/§3.5).
     * Giữ lại cho grouped() fallback + home-section — phân trang FE dùng listActivePage().
     *
     * @return list<ServiceModel>
     */
    public function listActiveAll(): array
    {
        $sql = new Sql($this->db);

        return $this->models($sql, $this->listActiveAllSelect());
    }

    /**
     * Select toàn bộ dịch vụ đang hoạt động — tách public cho ServiceMapperSqlTest render không cần DB.
     */
    public function listActiveAllSelect(): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->where(['isActive' => ServiceConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $select;
    }

    /** Tổng số dịch vụ đang hoạt động — phân trang /dich-vu (FR-08). */
    public function countActive(): int
    {
        $sql    = new Sql($this->db);
        $select = $this->countActiveSelect();

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Select đếm dịch vụ active — tách public cho ServiceMapperSqlTest render không cần DB (khuôn batch 10).
     */
    public function countActiveSelect(): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')])
            ->where(['isActive' => ServiceConst::ACTIVE]);

        return $select;
    }

    /**
     * Một trang dịch vụ active cho /dich-vu phân trang (FR-08).
     *
     * @return list<ServiceModel>
     */
    public function listActivePage(int $limit, int $offset): array
    {
        $sql = new Sql($this->db);

        return $this->models($sql, $this->listActivePageSelect($limit, $offset));
    }

    /**
     * Select một trang dịch vụ active — tách public cho ServiceMapperSqlTest render không cần DB (khuôn batch 10).
     */
    public function listActivePageSelect(int $limit, int $offset): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->where(['isActive' => ServiceConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->limit($limit)
            ->offset($offset);

        return $select;
    }

    /**
     * Dịch vụ đang hoạt động theo slug cho trang chi tiết /dich-vu/{slug} (FR-08, docs §2.1/§3.5).
     * Trả về null nếu slug không tồn tại hoặc dịch vụ bị tắt.
     */
    public function findActiveBySlug(string $slug): ?ServiceModel
    {
        if ($slug === '') {
            return null;
        }

        $sql  = new Sql($this->db);
        $rows = $this->models($sql, $this->activeBySlugSelect($slug));

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Select dịch vụ active theo slug — tách public cho ServiceMapperSqlTest render không cần DB.
     */
    public function activeBySlugSelect(string $slug): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->where([
            'slug'     => $slug,
            'isActive' => ServiceConst::ACTIVE,
        ])->limit(1);

        return $select;
    }

    /**
     * Slug dịch vụ ĐANG HOẠT ĐỘNG cho sitemap.xml (FR-11 / NFR-SEO-4 — docs
     * §6.1). Dịch vụ tắt về 404 (FR-08) → không được có mặt trong sitemap.
     * Chiếu 1 cột, giữ mảng scalar (07 §5).
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
     * Select sitemap dịch vụ — tách public cho ServiceMapperSqlTest render
     * không cần DB (khuôn batch 10).
     */
    public function sitemapActiveSlugsSelect(): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['slug'])
            ->where(['isActive' => ServiceConst::ACTIVE])
            ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $select;
    }

    public function existsSlug(string $slug, ?int $excludeId = null): bool
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['idCount' => new Expression('COUNT(*)')])
            ->where(['slug' => $slug]);

        if ($excludeId !== null) {
            $select->where->notEqualTo('id', $excludeId);
        }

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) && (int) ($row['idCount'] ?? 0) > 0;
    }

    /** Tổng số dịch vụ (card "Dịch vụ" dashboard — mockup 13/09/2026). */
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
     * Đếm dịch vụ tạo trong khoảng [fromUtc, toUtc) — mốc so sánh
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

    /** Xoá cứng 1 dòng (Service đã kiểm tra ràng buộc trước khi gọi). */
    public function delete(int $id): void
    {
        $sql = new Sql($this->db);
        $sql->prepareStatementForSqlObject(
            $sql->delete(self::TABLE_NAME)->where(['id' => $id])
        )->execute();
    }

    /**
     * id dịch vụ đang dùng một media ở icon hoặc ảnh đại diện (FR-38,
     * docs §5.14) — OR qua Predicate `or->` (bind trong prepared statement),
     * không JOIN bảng media.
     *
     * @return list<int>
     */
    public function findIdsByMedia(int $mediaId): array
    {
        $where = new Where();
        $where->equalTo('iconMediaId', $mediaId);
        $where->or->equalTo('imageMediaId', $mediaId);

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['id'])
            ->where($where);

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
     * Read đầy đủ bảng services → hydrate Model (chuẩn 07 §3). Riêng projection
     * id => name (listActiveOptions) giữ mảng vì không đủ cột cho Model.
     *
     * @return list<ServiceModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = ServiceModel::fromRow($row);
        }

        return $models;
    }
}
