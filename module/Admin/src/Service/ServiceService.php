<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Active\ActiveStatusFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Admin\Filter\Service\ServiceActionFilter;
use Admin\Filter\Service\ServiceSaveFilter;
use Admin\Model\Media\MediaMapper;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceConst;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;

/**
 * Nghiệp vụ dịch vụ (docs §3.4). Bảng `services` do mapper của Frontend sở hữu
 * (05-cau-truc §4) — Admin dùng qua cùng class, container gộp (07 §4-5).
 * Luồng chuẩn 07 §2: Service chạy Filter trên raw, Mapper trả ServiceModel.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class ServiceService extends AppServiceFactory
{
    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function contactMapper(): ContactMapper
    {
        /** @var ContactMapper */
        return $this->getContainerEntry(ContactMapper::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    /**
     * Ảnh trong thư viện media cho select box của form (07 §5 — Service điều
     * phối thêm mapper bảng khác, không join).
     *
     * @return list<array{id: int, label: string, path: string}>
     */
    /** @return array<int, string> */
    public function parentOptions(?int $excludeId = null): array
    {
        return $this->serviceMapper()->listParentOptions($excludeId);
    }

    public function mediaOptions(): array
    {
        return $this->mediaMapper()->listOptions();
    }

    private function slugService(): SlugService
    {
        /** @var SlugService */
        return $this->getContainerEntry(SlugService::class);
    }

    /**
     * @return list<ServiceModel>
     */
    public function listAll(): array
    {
        return $this->serviceMapper()->listAll();
    }

    public function findOrFail(int $id): ServiceModel
    {
        $service = $this->serviceMapper()->findById($id);
        if ($service === null) {
            throw NotFoundException::forEntity('dịch vụ', $id);
        }

        return $service;
    }

    /**
     * Entry point form trang: chạy ServiceSaveFilter (kèm CSRF) trên POST thô.
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
     * @param array<array-key, mixed> $raw
     */
    private function saveValidated(?int $id, array $raw, bool $withCsrf): void
    {
        $filter = new ServiceSaveFilter($this->serviceMapper(), $id, $withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $filter->getValues();

        if ($id === null) {
            $this->create($values);

            return;
        }

        $this->update($id, $values);
    }

    /**
     * Xoá từ form danh sách: chạy ServiceActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new ServiceActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->delete((int) $filter->idValue());

            return ServiceConst::FLAG_DELETED;
        } catch (ConflictException) {
            return ServiceConst::FLAG_DELETE_BLOCKED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** Hash CSRF cho form tạo/sửa dịch vụ. */
    public function saveFormCsrfHash(?int $id): string
    {
        return (new ServiceSaveFilter($this->serviceMapper(), $id))->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new ServiceActionFilter())->csrfHash();
    }

    /** Hash CSRF cho lệnh kéo-thả đổi thứ tự (ReorderFilter). */
    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    /** Hash CSRF cho select bật/tắt nhanh trên danh sách. */
    public function activeFormCsrfHash(): string
    {
        return (new ActiveStatusFilter())->csrfHash();
    }

    /**
     * Đổi nhanh cột isActive từ danh sách — chỉ chạm đúng cờ hiển thị.
     *
     * @param array<array-key, mixed> $raw id + isActive + csrf
     */
    public function activeForm(array $raw): string
    {
        $filter = new ActiveStatusFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $id = $filter->idValue();
            $this->findOrFail($id);
            $this->serviceMapper()->update($id, ['isActive' => $filter->activeValue()]);
            $this->invalidatePublicCaches();

            return 'active-updated';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /**
     * Kéo-thả thứ tự dịch vụ từ trang danh sách (FR-29). Id nêu trong payload
     * nhận sortOrder 0..n-1 theo thứ tự mới; dòng không nêu giữ nguyên thứ tự
     * hiển thị và được đánh số tiếp ở cuối — không mất dòng. Chỉ ghi dòng thực
     * đổi giá trị, trong một transaction; invalidate cache công khai.
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

        $sequence = $this->reorderSequence($filter->idList());
        $mapper   = $this->serviceMapper();

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
     * Thứ tự dòng sau reorder: id được nêu trước (theo payload, bỏ id lạ),
     * phần còn lại giữ nguyên thứ tự listAll.
     *
     * @param list<int> $requested
     *
     * @return list<ServiceModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows   = $this->serviceMapper()->listAll();
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
     * Tạo dịch vụ — nhận data ĐÃ qua ServiceSaveFilter. Trả về id mới.
     *
     * @param array<array-key, mixed> $data
     */
    public function create(array $data): int
    {
        $id = $this->serviceMapper()->insert($this->buildValues($data, null));
        $this->invalidatePublicCaches();

        return $id;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws NotFoundException khi id không tồn tại
     */
    public function update(int $id, array $data): void
    {
        $this->findOrFail($id);
        $this->serviceMapper()->update($id, $this->buildValues($data, $id));
        $this->invalidatePublicCaches();
    }

    /**
     * Xoá cứng — chặn khi còn lượt liên hệ tham chiếu serviceId (docs §3.4).
     *
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);

        if ($this->contactMapper()->countByServiceId($id) > 0) {
            throw new ConflictException([ServiceConst::ERROR_DELETE_CONTACTS]);
        }

        $this->serviceMapper()->delete($id);
        $this->invalidatePublicCaches();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> values cho Mapper
     */
    private function buildValues(array $data, ?int $excludeId): array
    {
        $slugs    = $this->slugService();
        $services = $this->serviceMapper();

        $name      = trim((string) $data['name']);
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug      = $slugInput !== ''
            ? $slugs->slugify($slugInput, ServiceConst::MAX_LENGTH_SLUG)
            : $slugs->slugify($name, ServiceConst::MAX_LENGTH_SLUG);

        $parentId = $this->nullableInt($data['parentId'] ?? null);
        if ($parentId !== null) {
            if ($excludeId !== null && $parentId === $excludeId) {
                throw new \Admin\Exception\ValidationException(['parentId' => ServiceConst::ERROR_PARENT_SELF]);
            }
            if ($this->serviceMapper()->findById($parentId) === null) {
                throw new \Admin\Exception\ValidationException(['parentId' => ServiceConst::ERROR_PARENT_INVALID]);
            }
        }

        return [
            'parentId'         => $parentId,
            'name'             => $name,
            'slug'             => $slugs->unique(
                $slug,
                static fn (string $candidate): bool => $services->existsSlug($candidate, $excludeId)
            ),
            'shortDescription' => $this->nullableTrim($data['shortDescription'] ?? null),
            'content'          => $this->nullableTrim($data['content'] ?? null),
            'iconMediaId'      => $this->nullableInt($data['iconMediaId'] ?? null),
            'imageMediaId'     => $this->nullableInt($data['imageMediaId'] ?? null),
            'sortOrder'        => (int) ($data['sortOrder'] ?? 0),
            'isActive'         => $this->flag($data['isActive'] ?? null),
            'metaTitle'        => $this->nullableTrim($data['metaTitle'] ?? null),
            'metaDescription'  => $this->nullableTrim($data['metaDescription'] ?? null),
        ];
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? ServiceConst::INACTIVE
            : ServiceConst::ACTIVE;
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

    /** PageCacheService là dependency mềm (FR-39): test container thiếu key → null. */
    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /** FR-32/FR-11: khối dịch vụ trang chủ + URL /dich-vu/{slug} trong sitemap — đổi gì cũng forget 'home-v1' + 'sitemap-v1'. */
    private function invalidatePublicCaches(): void
    {
        $cache = $this->pageCache();
        $cache?->forget(CacheConst::KEY_HOME);
        $cache?->forget(CacheConst::KEY_SITEMAP);
    }
}
