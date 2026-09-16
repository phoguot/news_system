<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Tag\TagActionFilter;
use Admin\Filter\Tag\TagSaveFilter;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagConst;
use Admin\Model\Tag\TagMapper;
use Admin\Model\Tag\TagModel;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\Api\ApiResultModel;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\SlugService;

/**
 * Nghiệp vụ tag (docs §3.4): CRUD + đếm bài (ghép từ PostTagMapper countsAll —
 * không join chéo bảng) + GỘP tag A→B trong MỘT transaction (chuyển post_tags
 * rồi xoá A; URL /tag/a cũ thành 404 — hệ không có redirect, đã ghi docs §3.4).
 * Luồng chuẩn 07 §2 + DI nền 07 §4 (13/09/2026): kế thừa AppServiceFactory,
 * dependency lấy từ container bằng getContainerEntry() đúng lúc dùng —
 * constructor không nhận gì cả. Service chạy Filter trên raw, Mapper trả
 * TagModel đã hydrate.
 */
class TagService extends AppServiceFactory
{
    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function tagMapper(): TagMapper
    {
        /** @var TagMapper */
        return $this->getContainerEntry(TagMapper::class);
    }

    private function postTagMapper(): PostTagMapper
    {
        /** @var PostTagMapper */
        return $this->getContainerEntry(PostTagMapper::class);
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

    /**
     * Danh sách tag kèm số bài sử dụng (docs §3.4 — 2 batch query, không N+1).
     * `postCount` là trường hiển thị ghép thêm, không phải cột DB.
     *
     * @return list<TagModel>
     */
    public function listWithCounts(): array
    {
        $counts = $this->postTagMapper()->countsAll();
        $rows   = [];
        foreach ($this->tagMapper()->listAll() as $tag) {
            $tag->postCount = $counts[$tag->id] ?? 0;
            $rows[]         = $tag;
        }

        return $rows;
    }

    public function findOrFail(int $id): TagModel
    {
        $tag = $this->tagMapper()->findById($id);
        if ($tag === null) {
            throw NotFoundException::forEntity('tag', $id);
        }

        return $tag;
    }

    /**
     * Entry point form trang: chạy TagSaveFilter (kèm CSRF) trên POST thô.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException mang lỗi từng trường (fieldErrors của filter)
     * @throws NotFoundException   khi sửa tag không còn
     */
    public function saveForm(?int $id, array $raw): void
    {
        $this->saveValidated($id, $raw, true);
    }

    /**
     * Entry point API JSON (không CSRF — SameSite=Lax, 07 §6). Trả trọn
     * envelope — chuẩn 08 §5 (luồng API trả response từ Service).
     *
     * @param array<array-key, mixed> $raw
     */
    public function saveApi(?int $id, array $raw): ApiResponseModel
    {
        $savedId = $this->saveValidated($id, $raw, false);

        return ApiResultModel::ok(ApiResultModel::serializeModel($this->findOrFail($savedId)));
    }

    /** GET /api/admin/tags?q= — mỗi tag kèm postCount (ghép batch, 07 §5). */
    public function listApi(string $q = ''): ApiResponseModel
    {
        $needle = strtolower(trim($q));

        $data = [];
        foreach ($this->listWithCounts() as $tag) {
            if (
                $needle !== ''
                && ! str_contains(strtolower($tag->name), $needle)
                && ! str_contains(strtolower($tag->slug), $needle)
            ) {
                continue;
            }

            $data[] = ApiResultModel::serializeModel($tag);
        }

        return ApiResultModel::ok($data);
    }

    public function readApi(int $id): ApiResponseModel
    {
        return ApiResultModel::ok(ApiResultModel::serializeModel($this->findOrFail($id)));
    }

    /** DELETE /tags/{id}. */
    public function deleteApi(int $id): ApiResponseModel
    {
        $this->delete($id);

        return ApiResultModel::ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function saveValidated(?int $id, array $raw, bool $withCsrf): int
    {
        $filter = new TagSaveFilter($this->tagMapper(), $id, $withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $filter->getValues();
        $name   = (string) ($values['name'] ?? '');
        $slug   = isset($values['slug']) && is_string($values['slug']) && $values['slug'] !== ''
            ? $values['slug']
            : null;

        if ($id === null) {
            return $this->create($name, $slug);
        }

        $this->update($id, $name, $slug);

        return $id;
    }

    /**
     * Xoá từ form danh sách: TagActionFilter(id) → query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new TagActionFilter(false);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : TagConst::FLAG_NOT_FOUND;
        }

        try {
            $this->delete((int) $filter->idValue());

            return TagConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return TagConst::FLAG_NOT_FOUND;
        }
    }

    /**
     * Gộp từ form danh sách: TagActionFilter(merge) → query flag PRG.
     *
     * @param array<array-key, mixed> $raw
     */
    public function mergeForm(array $raw): string
    {
        $filter = new TagActionFilter(true);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : TagConst::FLAG_INVALID;
        }

        try {
            $this->merge((int) $filter->sourceIdValue(), (int) $filter->targetIdValue());

            return TagConst::FLAG_MERGED;
        } catch (ValidationException | NotFoundException) {
            return TagConst::FLAG_INVALID;
        }
    }

    /**
     * Gộp qua API JSON (không CSRF): sourceId/targetId trong body.
     *
     * @param array<array-key, mixed> $body
     */
    public function mergeApi(array $body): ApiResponseModel
    {
        $filter = new TagActionFilter(true, false);
        $filter->setData($body);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $sourceId = (int) $filter->sourceIdValue();
        $targetId = (int) $filter->targetIdValue();
        $this->merge($sourceId, $targetId);

        return ApiResultModel::ok(['mergedInto' => $targetId]);
    }

    /** Hash CSRF cho form tạo/sửa tag. */
    public function saveFormCsrfHash(?int $id): string
    {
        return (new TagSaveFilter($this->tagMapper(), $id))->csrfHash();
    }

    /** Hash CSRF chung cho form xoá + gộp trên trang danh sách. */
    public function actionCsrfHash(): string
    {
        return (new TagActionFilter())->csrfHash();
    }

    /**
     * Tạo tag — nhận name/slug ĐÃ qua TagSaveFilter; slug tự sinh + unique
     * (chuẩn hoá "Hà Nội" ≡ "ha noi", docs §3.4).
     */
    public function create(string $name, ?string $slugInput = null): int
    {
        $name = trim($name);
        $slug = $this->resolveSlug($name, $slugInput, null);

        return $this->tagMapper()->insert($name, $slug);
    }

    public function update(int $id, string $name, ?string $slugInput = null): void
    {
        $this->findOrFail($id);
        $name = trim($name);
        $slug = $this->resolveSlug($name, $slugInput, $id);
        $this->tagMapper()->update($id, $name, $slug);
    }

    /**
     * Xoá tag + mọi quan hệ post_tags của nó trong một transaction (docs §4.6:
     * tags không bị chặn ràng buộc — bài chỉ mất tag).
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $postTags = $this->postTagMapper();
        $tags     = $this->tagMapper();
        $this->db()->transactional(function () use ($id, $postTags, $tags): void {
            $postTags->deleteByTagId($id);
            $tags->delete($id);
        });
    }

    /**
     * Gộp sourceId → targetId rồi xoá source (docs §3.4). Bài đã có cả hai tag
     * tự suy ra bản ghi trùng nhờ unique key (postId, tagId).
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new ValidationException(['sourceId' => TagConst::ERROR_MERGE_SAME]);
        }

        $this->findOrFail($sourceId);
        try {
            $this->findOrFail($targetId);
        } catch (NotFoundException) {
            throw new NotFoundException(TagConst::ERROR_MERGE_TARGET);
        }

        $postTags = $this->postTagMapper();
        $tags     = $this->tagMapper();
        $this->db()->transactional(function () use ($sourceId, $targetId, $postTags, $tags): void {
            $postTags->reassignTag($sourceId, $targetId);
            $postTags->deleteByTagId($sourceId);
            $tags->delete($sourceId);
        });
    }

    /**
     * Đảm bảo tag theo TÊN tồn tại, trả id — dùng khi lưu bài có gõ tag mới
     * (docs §3.4 "gõ để tìm, chưa có thì tạo mới"). Không tự do chạy ngoài
     * transaction của PostService: insert đơn bảng nên an toàn commit lẻ.
     */
    public function ensureByName(string $name): int
    {
        $name  = trim($name);
        $slugs = $this->slugService();
        $tags  = $this->tagMapper();
        $slug  = $slugs->slugify($name, TagConst::MAX_LENGTH_SLUG);
        if ($slug === '') {
            throw new ValidationException(['tags' => 'Tên tag không hợp lệ.']);
        }

        $existing = $tags->findBySlug($slug);
        if ($existing !== null) {
            return $existing->id;
        }

        $unique = $slugs->unique(
            $slug,
            static fn (string $candidate): bool => $tags->findBySlug($candidate) !== null
        );

        return $tags->insert($name, $unique);
    }

    private function resolveSlug(string $name, ?string $slugInput, ?int $excludeId): string
    {
        $slugs = $this->slugService();
        $tags  = $this->tagMapper();

        $slugInput = trim((string) $slugInput);
        $base      = $slugInput !== ''
            ? $slugs->slugify($slugInput, TagConst::MAX_LENGTH_SLUG)
            : $slugs->slugify($name, TagConst::MAX_LENGTH_SLUG);

        return $slugs->unique(
            $base,
            static fn (string $candidate): bool => $tags->existsSlug($candidate, $excludeId)
        );
    }
}
