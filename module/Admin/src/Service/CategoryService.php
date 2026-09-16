<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Category\CategoryActionFilter;
use Admin\Filter\Category\CategorySaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostMapper;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\Api\ApiResultModel;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;

/**
 * Nghiệp vụ danh mục (docs §3.2, §5.15): cây tối đa 2 cấp, slug tự sinh + unique,
 * chặn xoá khi còn danh mục con HOẶC còn bài (kể cả nháp/lưu trữ).
 * Luồng chuẩn 07 §2 (đã cập nhật): Service chạy Filter trên raw, Mapper trả
 * CategoryModel đã hydrate. Service điều phối 2 Mapper riêng biệt —
 * không JOIN chéo bảng (07 §5).
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class CategoryService extends AppServiceFactory
{
    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function categoryMapper(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function postMapper(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    /**
     * Ảnh trong thư viện media cho select box của form (07 §5 — Service điều
     * phối thêm mapper bảng khác, không join).
     *
     * @return list<array{id: int, label: string, path: string}>
     */
    public function mediaOptions(): array
    {
        return $this->mediaMapper()->listOptions();
    }

    private function slugService(): SlugService
    {
        /** @var SlugService */
        return $this->getContainerEntry(SlugService::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /**
     * FR-39/FR-11 invalidate sau ghi (khuôn batch 9): menu danh mục công khai
     * FR-02 + khối bài theo danh mục trên trang chủ (payload home-v1 đọc
     * tên/slug danh mục qua findById lúc dựng) + URL /danh-muc/{slug} trong
     * sitemap. Dependency mềm — storage thiếu thì producer tự tính lại theo TTL.
     */
    private function invalidatePublicCaches(): void
    {
        $cache = $this->pageCache();
        $cache?->forget(CacheConst::KEY_CATEGORY_MENU);
        $cache?->forget(CacheConst::KEY_HOME);
        $cache?->forget(CacheConst::KEY_SITEMAP);
    }

    /**
     * Danh sách phẳng theo thứ tự cây để view tabel hoá (parent rồi tới con của nó).
     * Danh mục mồ côi (cha đã bị xoá tay trong DB) xếp cuối, vẫn hiển thị cấp 2.
     *
     * @return list<array{category: CategoryModel, depth: int}>
     */
    public function listOrdered(): array
    {
        $all      = $this->categoryMapper()->listAll();
        $byParent = [];
        $topIds   = [];
        foreach ($all as $category) {
            $parentId = $category->parentId ?? 0;
            $byParent[$parentId][] = $category;
            if ($parentId === 0) {
                $topIds[$category->id] = true;
            }
        }

        $ordered = [];
        foreach ($byParent[0] ?? [] as $parent) {
            $ordered[] = ['category' => $parent, 'depth' => 0];
            foreach ($byParent[$parent->id] ?? [] as $child) {
                $ordered[] = ['category' => $child, 'depth' => 1];
            }
        }

        foreach ($all as $category) {
            $parentId = $category->parentId ?? 0;
            if ($parentId !== 0 && ! isset($topIds[$parentId])) {
                $ordered[] = ['category' => $category, 'depth' => 1];
            }
        }

        return $ordered;
    }

    public function findOrFail(int $id): CategoryModel
    {
        $category = $this->categoryMapper()->findById($id);
        if ($category === null) {
            throw NotFoundException::forEntity('danh mục', $id);
        }

        return $category;
    }

    /**
     * Entry point form trang: chạy CategorySaveFilter (kèm CSRF) trên POST thô.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException mang lỗi từng trường (fieldErrors của filter)
     */
    public function saveForm(?int $id, array $raw): void
    {
        $this->saveValidated($id, $raw, true);
    }

    /**
     * Entry point API JSON (không CSRF — SameSite=Lax, 07 §6).
     * Trả trọn envelope qua ApiResponseModel — chuẩn 08 §5 (luồng API trả
     * response từ Service); controller chỉ dispatch + gắn statusCode.
     *
     * @param array<array-key, mixed> $raw
     */
    public function saveApi(?int $id, array $raw): ApiResponseModel
    {
        $savedId = $this->saveValidated($id, $raw, false);

        return ApiResultModel::ok(ApiResultModel::serializeModel($this->findOrFail($savedId)));
    }

    /**
     * GET /api/admin/categories[/{id}|/tree] (docs-dev/05 §5.1).
     * $tree = true → cây 2 cấp, node cấp 1 kèm `children`.
     */
    public function listApi(bool $tree = false): ApiResponseModel
    {
        $ordered = $this->listOrdered();

        if ($tree) {
            return ApiResultModel::ok($this->buildTree($ordered));
        }

        return ApiResultModel::ok(array_map(
            /** @param array{category: CategoryModel, depth: int} $item */
            static fn (array $item): array => ApiResultModel::serializeModel($item['category']),
            $ordered
        ));
    }

    public function readApi(int $id): ApiResponseModel
    {
        return ApiResultModel::ok(ApiResultModel::serializeModel($this->findOrFail($id)));
    }

    /** DELETE /categories/{id} — còn con/bài → ConflictException (controller map 409). */
    public function deleteApi(int $id): ApiResponseModel
    {
        $this->delete($id);

        return ApiResultModel::ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * Cây danh mục tối đa 2 cấp (docs §3.2): nodes cấp 1 kèm `children`.
     * listOrdered() bảo đảm mọi node depth 1 đi ngay sau cha depth 0.
     *
     * @param list<array{category: CategoryModel, depth: int}> $ordered
     *
     * @return list<array<array-key, mixed>>
     */
    private function buildTree(array $ordered): array
    {
        /** @var list<array<array-key, mixed>> $tree */
        $tree = [];
        /** @var list<array<array-key, mixed>> $children */
        $children   = [];
        $lastParent = -1;
        foreach ($ordered as $item) {
            $row = ApiResultModel::serializeModel($item['category']);
            if ($item['depth'] === 0) {
                if ($lastParent >= 0) {
                    $tree[$lastParent]['children'] = $children;
                    $children                      = [];
                }

                $row['children'] = [];
                $tree[]          = $row;
                $lastParent      = count($tree) - 1;
            } elseif ($lastParent >= 0) {
                $children[] = $row;
            }
        }

        if ($lastParent >= 0) {
            $tree[$lastParent]['children'] = $children;
        }

        /** @var list<array<array-key, mixed>> $result */
        $result = $tree;

        return $result;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function saveValidated(?int $id, array $raw, bool $withCsrf): int
    {
        $filter = new CategorySaveFilter($this->categoryMapper(), $id, $withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $filter->getValues();

        if ($id === null) {
            return $this->create($values);
        }

        $this->update($id, $values);

        return $id;
    }

    /**
     * Xoá từ form danh sách: chạy CategoryActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new CategoryActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->delete((int) $filter->idValue());

            return CategoryConst::FLAG_DELETED;
        } catch (ConflictException) {
            return CategoryConst::FLAG_DELETE_BLOCKED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** Hash CSRF cho form tạo/sửa danh mục. */
    public function saveFormCsrfHash(?int $id): string
    {
        return (new CategorySaveFilter($this->categoryMapper(), $id))->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new CategoryActionFilter())->csrfHash();
    }

    /** Hash CSRF cho lệnh kéo-thả đổi thứ tự (ReorderFilter). */
    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    /**
     * Kéo-thả thứ tự danh mục từ trang danh sách (FR-26). Id nêu trong payload
     * nhận sortOrder 0..n-1 theo thứ tự mới; dòng không nêu giữ nguyên thứ tự
     * hiển thị và được đánh số tiếp ở cuối — không bao giờ mất dòng. Chỉ ghi
     * dòng thực đổi giá trị, trong một transaction; invalidate cache công khai.
     *
     * @param array<array-key, mixed> $raw `ids` (CSV/mảng) + `csrf`
     *
     * @return array{flag: string, applied: int}
     */
    public function formReorder(array $raw): array
    {
        $filter = new ReorderFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return ['flag' => 'csrf', 'applied' => 0];
        }

        return $this->applyReorder($filter->idList());
    }

    /**
     * `PUT /api/admin/categories/reorder` (FR-26, §6.2): body JSON
     * `{ids:[..]}` — không CSRF (SameSite=Lax che, luật API §5), trả
     * `{flag, applied}` qua envelope chuẩn.
     *
     * @param array<array-key, mixed> $body
     */
    public function reorderApi(array $body): ApiResponseModel
    {
        $filter = new ReorderFilter(false);
        $filter->setData($body);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        return ApiResultModel::ok($this->applyReorder($filter->idList()));
    }

    /**
     * Lõi reorder dùng chung form + API: dựng thứ tự mới rồi ghi các dòng đổi
     * giá trị trong một transaction và invalidate cache.
     *
     * @param list<int> $requested
     *
     * @return array{flag: string, applied: int}
     */
    private function applyReorder(array $requested): array
    {
        $sequence = $this->reorderSequence($requested);
        $mapper   = $this->categoryMapper();

        $changed = [];
        foreach ($sequence as $index => $row) {
            if ($row->sortOrder !== $index) {
                $changed[$row->id] = $index;
            }
        }
        if ($changed === []) {
            return ['flag' => 'reordered', 'applied' => 0];
        }

        $this->db()->transactional(static function () use ($mapper, $changed): void {
            foreach ($changed as $id => $index) {
                $mapper->update($id, ['sortOrder' => $index]);
            }
        });
        $this->invalidatePublicCaches();

        return ['flag' => 'reordered', 'applied' => count($changed)];
    }

    /**
     * Thứ tự dòng sau reorder: các id được nêu trước (theo đúng thứ tự payload,
     * bỏ id lạ), phần còn lại giữ nguyên thứ tự listAll.
     *
     * @param list<int> $requested
     *
     * @return list<CategoryModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows   = $this->categoryMapper()->listAll();
        $byId   = [];
        $existing = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
            $existing[]     = $row->id;
        }

        /** @var array<int, int> $left */
        $left = array_flip($existing);
        $ordered = [];
        foreach ($requested as $id) {
            if (isset($left[$id])) {
                $ordered[] = $byId[$id];
                unset($left[$id]);
            }
        }
        foreach ($existing as $id) {
            if (isset($left[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Select "danh mục cha" cho form (docs §3.2 — chỉ được chọn cấp 1).
     *
     * @return list<array{id: int, name: string}>
     */
    public function topLevelOptions(): array
    {
        $options = [];
        foreach ($this->categoryMapper()->listAll() as $category) {
            if ($category->parentId === null) {
                $options[] = ['id' => $category->id, 'name' => $category->name];
            }
        }

        return $options;
    }

    /**
     * Tạo danh mục — nhận data ĐÃ qua CategorySaveFilter. Trả về id mới.
     *
     * @param array<array-key, mixed> $data
     */
    public function create(array $data): int
    {
        $parentId = $this->normalizeParentId($data['parentId'] ?? null);
        if ($parentId !== null) {
            $this->assertParentUsable($parentId, null);
        }

        $newId = $this->categoryMapper()->insert($this->buildValues($data, null, $parentId));
        $this->invalidatePublicCaches();

        return $newId;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws NotFoundException khi id không tồn tại
     */
    public function update(int $id, array $data): void
    {
        $current  = $this->findOrFail($id);
        $parentId = $this->normalizeParentId($data['parentId'] ?? null);

        if ($parentId !== null) {
            if ($parentId === $id) {
                throw new ValidationException(['parentId' => CategoryConst::ERROR_PARENT_SELF]);
            }

            // Đổi slug/cha không đổi dữ kiện depth — kiểm theo row hiện tại + cha mới
            if ($parentId !== ($current->parentId ?? 0)) {
                $this->assertParentUsable($parentId, $id);
            }
        }

        $this->categoryMapper()->update($id, $this->buildValues($data, $id, $parentId));
        $this->invalidatePublicCaches();
    }

    /**
     * Xoá cứng — chặn theo docs §5.15: còn con HOẶC còn bài (mọi trạng thái).
     *
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);

        $reasons = [];
        if ($this->categoryMapper()->countChildren($id) > 0) {
            $reasons[] = CategoryConst::ERROR_DELETE_CHILDREN;
        }

        if ($this->postMapper()->countByCategoryId($id) > 0) {
            $reasons[] = CategoryConst::ERROR_DELETE_POSTS;
        }

        if ($reasons !== []) {
            throw new ConflictException($reasons);
        }

        $this->categoryMapper()->delete($id);
        $this->invalidatePublicCaches();
    }

    /**
     * Cha phải tồn tại và đang ở cấp 1 (docs §3.2). $movingId: danh mục đang
     * được chuyển (nếu con cũ của nó còn thì không được tụt xuống cấp 2).
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function assertParentUsable(int $parentId, ?int $movingId): void
    {
        $parent = $this->categoryMapper()->findById($parentId);
        if ($parent === null) {
            throw NotFoundException::forEntity('danh mục cha', $parentId);
        }

        if ($parent->parentId !== null) {
            throw new ValidationException(['parentId' => CategoryConst::ERROR_PARENT_DEPTH]);
        }

        if ($movingId !== null && $this->categoryMapper()->countChildren($movingId) > 0) {
            throw new ValidationException(['parentId' => CategoryConst::ERROR_PARENT_HAS_CHILD]);
        }
    }

    private function normalizeParentId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (is_numeric($raw) && (int) $raw === 0)) {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> values cho Mapper
     */
    private function buildValues(array $data, ?int $excludeId, ?int $parentId): array
    {
        $slugs      = $this->slugService();
        $categories = $this->categoryMapper();

        $name      = trim((string) $data['name']);
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug      = $slugInput !== ''
            ? $slugs->slugify($slugInput, CategoryConst::MAX_LENGTH_SLUG)
            : $slugs->slugify($name, CategoryConst::MAX_LENGTH_SLUG);

        return [
            'parentId'        => $parentId,
            'name'            => $name,
            'slug'            => $slugs->unique(
                $slug,
                static fn (string $candidate): bool => $categories->existsSlug($candidate, $excludeId)
            ),
            'description'     => $this->nullableTrim($data['description'] ?? null),
            'coverMediaId'    => $this->nullableInt($data['coverMediaId'] ?? null),
            'sortOrder'       => (int) ($data['sortOrder'] ?? 0),
            'isActive'        => $this->flag($data['isActive'] ?? null),
            'metaTitle'       => $this->nullableTrim($data['metaTitle'] ?? null),
            'metaDescription' => $this->nullableTrim($data['metaDescription'] ?? null),
        ];
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? CategoryConst::INACTIVE
            : CategoryConst::ACTIVE;
    }

    private function nullableTrim(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }
}
