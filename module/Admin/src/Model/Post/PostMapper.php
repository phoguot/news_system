<?php

declare(strict_types=1);

namespace Admin\Model\Post;

use Application\Constant\ContentConst;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\Db\Sql\Predicate\Operator;
use Laminas\Db\Sql\Predicate\Predicate;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper sở hữu bảng `posts` (docs §4.4.3, chuẩn 07 §5).
 * Chỉ đụng bảng posts — liên hệ tag / revision / view là việc của mapper riêng,
 * Service điều phối. Mọi mốc giờ vào/ra là chuỗi UTC 'Y-m-d H:i:s' (docs §4.1);
 * so sánh "hiện tại" trong SQL dùng UTC_TIMESTAMP() vì kết nối SET time_zone='+00:00'.
 */
class PostMapper
{
    public const TABLE_NAME = 'posts';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Đếm bài thuộc một danh mục (mọi trạng thái) — guard xoá danh mục docs §5.15.
     */
    public function countByCategoryId(int $categoryId): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['postCount' => new Expression('COUNT(*)')]);
        $select->where(['categoryId' => $categoryId]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['postCount'] ?? 0) : 0;
    }

    /**
     * Chiếu nhẹ id + nhãn cho select box bài viết (07 §3 — projection ít cột
     * giữ mảng scalar), mới nhất trước.
     *
     * @return list<array{id: int, label: string}>
     */
    public function listOptions(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['id', 'title']);
        $select->order(['id' => 'DESC']);

        $options = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $options[] = [
                'id'    => (int) $row['id'],
                'label' => (string) $row['title'],
            ];
        }

        return $options;
    }

    /**
     * Danh sách quản trị có phân trang + filter (docs §3.3.1 — khoảng ngày xuất bản FR-15).
     *
     * @param array{
     *     tab?: string,
     *     categoryId?: int|null,
     *     postIds?: list<int>|null,
     *     q?: string,
     *     featured?: bool,
     *     dateFromUtc?: string|null,
     *     dateToUtc?: string|null
     * } $filters
     *
     * @return array{rows: list<PostModel>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $sql  = new Sql($this->db);
        $list = $sql->select(self::TABLE_NAME);
        $list->where($this->buildWhere($filters));
        $list->order(['id' => 'DESC']);
        $list->offset(($page - 1) * $perPage);
        $list->limit($perPage);

        $count = $sql->select(self::TABLE_NAME);
        $count->columns(['total' => new Expression('COUNT(*)')]);
        $count->where($this->buildWhere($filters));

        /** @var array<array-key, mixed>|bool|null $totalRow */
        $totalRow = $sql->prepareStatementForSqlObject($count)->execute()->current();

        return [
            'rows'  => $this->rows($sql, $list),
            'total' => is_array($totalRow) ? (int) ($totalRow['total'] ?? 0) : 0,
        ];
    }

    private function buildWhere(array $filters): Where
    {
        $where = new Where();
        $tab   = (string) ($filters['tab'] ?? PostConst::TAB_ALL);

        switch ($tab) {
            case PostConst::TAB_DRAFT:
                $where->equalTo('status', ContentConst::STATUS_DRAFT);
                break;

            case PostConst::TAB_SCHEDULED:
                $where->equalTo('status', ContentConst::STATUS_PUBLISHED);
                $where->greaterThan('publishedAt', new Expression('UTC_TIMESTAMP()'));
                break;

            case PostConst::TAB_PUBLISHED:
                $where->equalTo('status', ContentConst::STATUS_PUBLISHED);
                $where->addPredicate(
                    (new Predicate())->lessThanOrEqualTo('publishedAt', new Expression('UTC_TIMESTAMP()'))
                        ->or->isNull('publishedAt')
                );
                break;

            case PostConst::TAB_ARCHIVED:
                $where->equalTo('status', ContentConst::STATUS_ARCHIVED);
                break;

            default:
                // TAB_ALL — không lọc trạng thái
                break;
        }

        if (isset($filters['categoryId']) && $filters['categoryId'] > 0) {
            $where->equalTo('categoryId', (int) $filters['categoryId']);
        }

        if (isset($filters['postIds'])) {
            /** @var list<int> $postIds — do PostService đưa xuống (bài gắn tag) */
            $postIds = $filters['postIds'];
            $where->in('id', $postIds);
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $where->addPredicate(
                (new Predicate())->like('title', '%' . $q . '%')
                    ->or->like('slug', '%' . $q . '%')
            );
        }

        if (($filters['featured'] ?? false) === true) {
            $where->equalTo('isFeatured', 1);
        }

        if (! empty($filters['dateFromUtc'])) {
            $where->greaterThanOrEqualTo('publishedAt', (string) $filters['dateFromUtc']);
        }

        if (! empty($filters['dateToUtc'])) {
            $where->lessThanOrEqualTo('publishedAt', (string) $filters['dateToUtc']);
        }

        return $where;
    }

    /**
     * Đếm theo tab cho thanh tab danh sách (docs §5.12, một lượt query duy nhất).
     * Expression không nhận bind param — chỉ hằng số nguyên của ContentConst được
     * nội suy qua sprintf('%d') (Ép kiểu số, không có chuỗi động vào SQL).
     *
     * @return array<string, int> map tab => số bài
     */
    public function countTabs(): array
    {
        $draft     = sprintf('%d', ContentConst::STATUS_DRAFT);
        $published = sprintf('%d', ContentConst::STATUS_PUBLISHED);
        $archived  = sprintf('%d', ContentConst::STATUS_ARCHIVED);

        $sql = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns([
            'all' => new Expression('COUNT(*)'),
            'draft' => new Expression('COALESCE(SUM(status = ' . $draft . '), 0)'),
            'scheduled' => new Expression(
                'COALESCE(SUM(status = ' . $published . ' AND publishedAt > UTC_TIMESTAMP()), 0)'
            ),
            'published' => new Expression(
                'COALESCE(SUM(status = ' . $published . ' AND (publishedAt <= UTC_TIMESTAMP()'
                . ' OR publishedAt IS NULL)), 0)'
            ),
            'archived' => new Expression('COALESCE(SUM(status = ' . $archived . '), 0)'),
        ]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();
        if (! is_array($row)) {
            return [
                PostConst::TAB_ALL => 0,
                PostConst::TAB_DRAFT => 0,
                PostConst::TAB_SCHEDULED => 0,
                PostConst::TAB_PUBLISHED => 0,
                PostConst::TAB_ARCHIVED => 0,
            ];
        }

        return [
            PostConst::TAB_ALL       => (int) ($row['all'] ?? 0),
            PostConst::TAB_DRAFT     => (int) ($row['draft'] ?? 0),
            PostConst::TAB_SCHEDULED => (int) ($row['scheduled'] ?? 0),
            PostConst::TAB_PUBLISHED => (int) ($row['published'] ?? 0),
            PostConst::TAB_ARCHIVED  => (int) ($row['archived'] ?? 0),
        ];
    }

    /**
     * Đếm bài tạo trong khoảng [fromUtc, toUtc) (mọi trạng thái) — delta
     * "so với tháng trước" card "Tổng bài viết" dashboard. Where bind tham số.
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
     * Số bài ĐÃ XUẤT BẢN theo từng tháng của một năm dương lịch (biểu đồ
     * "Bài viết theo tháng" dashboard — mockup 13/09/2026). Gom theo cột
     * `publishedAt` (UTC, cùng chuẩn với scope của countTabs); mốc năm đi qua
     * Predicate\Operator bind tham số — không nội suy chuỗi vào SQL.
     *
     * @return list<int> 12 phần tử, index 0 = tháng 1
     */
    public function countPublishedByMonth(int $year): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns([
            'm'     => new Expression('MONTH(publishedAt)'),
            'total' => new Expression('COUNT(*)'),
        ]);

        $where = new Where();
        $where->equalTo('status', ContentConst::STATUS_PUBLISHED);
        $where->addPredicate(
            new Operator('publishedAt', '>=', $year . '-01-01 00:00:00')
        );
        $where->addPredicate(
            new Operator('publishedAt', '<=', $year . '-12-31 23:59:59')
        );
        $select->where($where);
        $select->group('m');

        $counts = array_fill(0, 12, 0);
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $month = (int) ($row['m'] ?? 0);
            if ($month >= 1 && $month <= 12) {
                $counts[$month - 1] = (int) ($row['total'] ?? 0);
            }
        }

        return array_values($counts);
    }

    public function findById(int $id): ?PostModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->where(['id' => $id]);
        $select->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? PostModel::fromRow($row) : null;
    }

    /**
     * Bài ĐÃ PUBLISHED đến hẹn cho trang chủ (FR-32) — scope công khai:
     * status=1 AND publishedAt <= UTC_TIMESTAMP() (bài hẹn giờ tự lộ diện
     * sau ≤ TTL cache, docs §3.7/§7.2 — không cronjob).
     */
    private function publicScope(Where $where): Where
    {
        $where->equalTo('status', ContentConst::STATUS_PUBLISHED);
        $where->lessThanOrEqualTo('publishedAt', new Expression('UTC_TIMESTAMP()'));

        return $where;
    }

    /**
     * Bản dùng chung cho các danh sách công khai — chọn đúng cột card,
     * order publishedAt/id DESC, giới hạn.
     *
     * @param list<int>|null  $categoryIds NULL/rỗng = không lọc danh mục (FR-02/FR-05)
     * @param list<int>       $ids         rỗng = không lọc theo id (danh sách mới nhất…)
     * @param int             $offset      phân trang công khai
     *
     * @return list<PostModel>
     */
    private function publicList(
        ?array $categoryIds,
        bool $featuredOnly,
        array $ids,
        int $limit,
        int $offset = 0
    ): array {
        $sql = new Sql($this->db);

        return $this->publicModels(
            $sql,
            $this->publicSelect($categoryIds, $featuredOnly, $ids, $limit, $offset)
        );
    }

    /**
     * Dựng Select công khai (scope đã xuất bản + bộ lọc optional) — tách khỏi
     * thực thi để PostMapperPublicReadSqlTest render SQL không cần DB.
     *
     * Public vì PostMapperPublicReadSqlTest render SQL không cần DB (batch 10).
     *
     * @param list<int>|null $categoryIds
     * @param list<int>      $ids
     */
    public function publicSelect(
        ?array $categoryIds,
        bool $featuredOnly,
        array $ids,
        int $limit,
        int $offset = 0
    ): Select {
        $where = $this->publicScope(new Where());
        if ($categoryIds !== null && $categoryIds !== []) {
            $where->in('categoryId', $categoryIds);
        }
        if ($featuredOnly) {
            $where->equalTo('isFeatured', 1);
        }
        if ($ids !== []) {
            $where->in('id', $ids);
        }

        $select = new Select(self::TABLE_NAME);
        $select->columns([
            'id', 'categoryId', 'title', 'slug', 'excerpt', 'bannerMediaId',
            'thumbnailMediaId', 'status', 'isFeatured', 'publishedAt',
            'readingMinutes', 'createdAt', 'updatedAt',
        ]);
        $select->where($where);
        $select->order(['publishedAt' => 'DESC', 'id' => 'DESC']);
        $select->limit($limit);

        if ($offset > 0) {
            $select->offset($offset);
        }

        return $select;
    }

    /**
     * Tin mới nhất công khai (docs §3.7 type=3).
     *
     * @return list<PostModel>
     */
    public function listLatestPublished(int $limit): array
    {
        return $this->publicList(null, false, [], $limit);
    }

    /**
     * Tin nổi bật auto — `isFeatured=1` mới nhất (docs §3.7 type=2 mode=auto).
     *
     * @return list<PostModel>
     */
    public function listFeaturedPublished(int $limit): array
    {
        return $this->publicList(null, true, [], $limit);
    }

    /**
     * Tin đã xuất bản của một danh mục (docs §3.7 type=4).
     *
     * @return list<PostModel>
     */
    public function listPublishedByCategory(int $categoryId, int $limit): array
    {
        return $this->publicList([$categoryId], false, [], $limit);
    }

    /**
     * Trang bài công khai cho /tin-tuc + /danh-muc (FR-02, docs §5.3) —
     * `publicList` kèm offset; `$categoryIds` NULL = mọi danh mục, mảng id
     * (danh mục + con) = lọc theo IN.
     *
     * @param list<int>|null $categoryIds
     *
     * @return list<PostModel>
     */
    public function listPublishedPage(?array $categoryIds, int $limit, int $offset): array
    {
        return $this->publicList($categoryIds, false, [], $limit, $offset);
    }

    /**
     * Đếm bài công khai cho phân trang (cùng scope với listPublishedPage).
     *
     * @param list<int>|null $categoryIds
     */
    public function countPublished(?array $categoryIds = null): int
    {
        $sql = new Sql($this->db);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($this->countPublishedSelect($categoryIds))
            ->execute()
            ->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * COUNT(*) cùng scope publicSelect — tách dựng để test không cần DB.
     *
     * Public vì PostMapperPublicReadSqlTest render SQL không cần DB (batch 10).
     *
     * @param list<int>|null $categoryIds
     */
    public function countPublishedSelect(?array $categoryIds = null): Select
    {
        $where = $this->publicScope(new Where());
        if ($categoryIds !== null && $categoryIds !== []) {
            $where->in('categoryId', $categoryIds);
        }

        $select = new Select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')]);
        $select->where($where);

        return $select;
    }

    /**
     * Tin đã xuất bản theo tập id (type=2 mode=manual — Service giữ thứ tự
     * từ `home_section_items`, bài ẩn/xoá/ngừng publish tự vắng mặt).
     *
     * @param list<int> $ids
     *
     * @return list<PostModel>
     */
    public function listPublishedByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->publicList(null, false, $ids, count($ids));
    }

    /**
     * Trang bài công khai theo tập id (FR-06 trang /tag/{slug}: id lấy từ
     * `PostTagMapper::postIdsByTag` — 1 bảng 1 mapper, IN(id) + scope public
     * ghép trong MỘT query, sort publishedAt/id DESC của publicSelect).
     * Tập id rỗng phải được caller chặn trước — ở đây guard lần nữa.
     *
     * @param list<int> $ids
     *
     * @return list<PostModel>
     */
    public function listPublishedPageByIds(array $ids, int $limit, int $offset): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->publicList(null, false, $ids, $limit, $offset);
    }

    /**
     * Đếm bài CÔNG KHAI trong tập id — cùng scope với listPublishedPageByIds
     * (bài nháp/xoá/ngừng publish trong post_tags tự không được tính).
     *
     * @param list<int> $ids
     */
    public function countPublishedByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $sql = new Sql($this->db);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($this->countPublishedByIdsSelect($ids))
            ->execute()
            ->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * COUNT(*) + IN(id) cùng scope — tách public cho SQL test render không cần
     * DB (khuôn batch 10). $ids BẮT BUỘC khác rỗng (IN () hỏng SQL).
     *
     * @param non-empty-list<int> $ids
     */
    public function countPublishedByIdsSelect(array $ids): Select
    {
        $where = $this->publicScope(new Where());
        $where->in('id', $ids);

        $select = new Select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')]);
        $select->where($where);

        return $select;
    }

    /**
     * Tìm kiếm toàn văn bài viết công khai theo từ khoá (FR-07, docs §5.11 / §4):
     * sử dụng MATCH(title, excerpt) AGAINST (:keyword IN NATURAL LANGUAGE MODE)
     * kết hợp scope công khai (status=1 AND publishedAt <= UTC_TIMESTAMP()).
     * Sắp xếp theo score DESC, publishedAt DESC, id DESC. Giới hạn 20 bài.
     * Từ khoá rỗng hoặc < 2 ký tự trả mảng rỗng không chạm DB.
     *
     * @return list<PostModel>
     */
    public function searchPublished(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if ($keyword === '' || mb_strlen($keyword, 'UTF-8') < 2) {
            return [];
        }

        $sql = new Sql($this->db);

        return $this->publicModels(
            $sql,
            $this->searchPublishedSelect($keyword, $limit)
        );
    }

    /**
     * Dựng Select tìm kiếm toàn văn kèm scope công khai — tách public cho
     * PostMapperSearchSqlTest render không cần DB (khuôn batch 10/14).
     */
    public function searchPublishedSelect(string $keyword, int $limit = 20): Select
    {
        $where = $this->publicScope(new Where());
        $where->addPredicate(
            new Expression('MATCH(title, excerpt) AGAINST (? IN NATURAL LANGUAGE MODE)', [$keyword])
        );

        $select = new Select(self::TABLE_NAME);
        $select->columns([
            'id', 'categoryId', 'title', 'slug', 'excerpt', 'bannerMediaId',
            'thumbnailMediaId', 'status', 'isFeatured', 'publishedAt',
            'readingMinutes', 'createdAt', 'updatedAt',
            'score' => new Expression('MATCH(title, excerpt) AGAINST (? IN NATURAL LANGUAGE MODE)', [$keyword]),
        ]);
        $select->where($where);
        $select->order([
            new Expression('score DESC'),
            'publishedAt' => 'DESC',
            'id'          => 'DESC',
        ]);
        $select->limit($limit);

        return $select;
    }

    /**
     * Chiếu `slug` + `updatedAt` của MỌI bài đang công khai cho sitemap.xml
     * (FR-11 / NFR-SEO-4 — docs §6.1/§7.1, go-live §5 checklist): dùng đúng
     * `publicScope` nên nháp · hẹn giờ tương lai · archived tự loại. Không
     * LIMIT — sitemap phải đủ; bảng nhỏ ở quy mô MVP, đã có index status.
     * Phép chiếu 2 cột giữ mảng scalar (07 §5, precedent `activeChildIds`).
     *
     * @return list<array{slug: string, updatedAt: string}>
     */
    public function sitemapPublished(): array
    {
        $sql = new Sql($this->db);

        $out = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($this->sitemapPublishedSelect())->execute() as $row) {
            if (is_array($row) && isset($row['slug']) && is_string($row['slug'])) {
                $updatedAt = $row['updatedAt'] ?? null;
                $out[] = [
                    'slug'      => $row['slug'],
                    'updatedAt' => is_string($updatedAt) ? $updatedAt : '',
                ];
            }
        }

        return $out;
    }

    /**
     * Select sitemap — tách public cho PostMapperSitemapSqlTest render không
     * cần DB (khuôn batch 10).
     */
    public function sitemapPublishedSelect(): Select
    {
        $select = new Select(self::TABLE_NAME);
        $select->columns(['slug', 'updatedAt']);
        $select->where($this->publicScope(new Where()));
        $select->order(['updatedAt' => 'DESC', 'id' => 'DESC']);

        return $select;
    }

    /**
     * Bài công khai theo slug cho trang chi tiết (FR-03, docs §2.1/§6.1) —
     * full cột (kể `content`), cùng scope `publicSelect`: chỉ bài
     * `status=PUBLISHED` và `publishedAt <= UTC_TIMESTAMP()`; nháp/hẹn giờ/slug
     * lạ → null để controller 404. Link `?previewToken=` bỏ qua scope là FR-04.
     */
    public function findPublishedBySlug(string $slug): ?PostModel
    {
        $where = $this->publicScope(new Where());
        $where->equalTo('slug', $slug);

        $sql    = new Sql($this->db);
        $select = new Select(self::TABLE_NAME);
        $select->where($where);
        $select->limit(1);

        $rows = $this->rows($sql, $select);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Bài cho link xem trước `?previewToken=` (FR-04, docs §3.3.2/§5.7 +
     * NFR-SEO-5): BỎ QUA scope status/publishedAt — chỉ cần slug khớp và token
     * không rỗng, đúng bằng cột `previewToken` (unique `uq_posts_preview_token`
     * → tối đa 1 dòng). Token so bằng bind tham số, không nội suy vào SQL.
     */
    public function findBySlugPreviewToken(string $slug, string $token): ?PostModel
    {
        if ($token === '') {
            return null;
        }

        $sql  = new Sql($this->db);
        $rows = $this->rows($sql, $this->slugPreviewTokenSelect($slug, $token));

        return $rows === [] ? null : $rows[0];
    }

    /**
     * `WHERE slug = … AND previewToken = … LIMIT 1` — tách public cho
     * PostMapperPreviewSqlTest render không cần DB (khuôn batch 10).
     */
    public function slugPreviewTokenSelect(string $slug, string $token): Select
    {
        $where = new Where();
        $where->equalTo('slug', $slug);
        $where->equalTo('previewToken', $token);

        $select = new Select(self::TABLE_NAME);
        $select->where($where);
        $select->limit(1);

        return $select;
    }

    /**
     * @return list<PostModel>
     */
    private function publicModels(Sql $sql, Select $select): array
    {
        return $this->rows($sql, $select);
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

    /**
     * @param array<array-key, mixed> $values
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

    /**
     * Xuất bản / hẹn giờ (07 §3 — hàm riêng cho luồng đổi 1–2 cột):
     * status=PUBLISHED + publishedAt (chuỗi UTC hoặc null).
     */
    public function updatePublished(int $id, ?string $publishedAtUtc): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set([
            'status'      => ContentConst::STATUS_PUBLISHED,
            'publishedAt' => $publishedAtUtc,
        ]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /** Gỡ khỏi website — chỉ đổi status (docs §3.3.3). */
    public function updateArchived(int $id): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set(['status' => ContentConst::STATUS_ARCHIVED]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /** Về nháp / huỷ hẹn giờ — xoá cả publishedAt (docs §3.3.3). */
    public function updateDrafted(int $id): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set([
            'status'      => ContentConst::STATUS_DRAFT,
            'publishedAt' => null,
        ]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /** Đổi previewToken (FR-04 — chỉ một cột). */
    public function updatePreviewToken(int $id, string $token): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME);
        $update->set(['previewToken' => $token]);
        $update->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Tăng viewCount (FR-41) — cộng dồn, không ghi đè.
     */
    public function incrementViewCount(int $id): void
    {
        $sql = 'UPDATE ' . $this->db->getPlatform()->quoteIdentifier(self::TABLE_NAME)
            . ' SET viewCount = viewCount + 1 WHERE id = ?';
        $stmt = $this->db->createStatement($sql);
        $stmt->prepare();
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME);
        $delete->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * id các bài đang dùng một media ở ảnh bìa hoặc ảnh thu nhỏ (FR-38,
     * docs §5.14) — kiểm tra trước khi cho xoá file. OR qua Predicate `or->`
     * (giá trị đi vào ValueBinder của prepareStatementForSqlObject, không nội
     * suy chuỗi vào SQL) — không JOIN sang bảng media (chuẩn 07 §5).
     *
     * @return list<int>
     */
    public function findIdsByMedia(int $mediaId): array
    {
        $where = new Where();
        $where->equalTo('bannerMediaId', $mediaId);
        $where->or->equalTo('thumbnailMediaId', $mediaId);

        return $this->pluckIds($where);
    }

    /**
     * id các bài nhúng đường dẫn media trong nội dung HTML (docs §5.14) —
     * `like()` bind giá trị qua ValueBinder, không ghép chuỗi vào SQL.
     *
     * @return list<int>
     */
    public function findIdsByContentPath(string $path): array
    {
        $where = new Where();
        $where->like('content', '%' . $path . '%');

        return $this->pluckIds($where);
    }

    /**
     * @return list<int>
     */
    private function pluckIds(Where $where): array
    {
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
     * Đọc danh sách dòng bảng posts và hydrate thành PostModel (chuẩn 07 §3:
     * Mapper trả Model, không trả mảng thô).
     *
     * @return list<PostModel>
     */
    private function rows(Sql $sql, Select $select): array
    {
        $rows   = [];
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = PostModel::fromRow($row);
            }
        }

        return $rows;
    }
}
