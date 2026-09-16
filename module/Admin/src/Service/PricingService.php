<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Pricing\PricingActionFilter;
use Admin\Filter\Pricing\PricingSaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\Pricing\PricingModel;

/**
 * Nghiệp vụ bảng giá (pricing_items). Bảng do mapper Frontend sở hữu
 * (05-cau-truc §4) — Admin dùng qua cùng class, container gộp (07 §4-5).
 * Luồng chuẩn 07 §2: Service chạy Filter trên raw, Mapper trả Model.
 */
class PricingService extends AppServiceFactory
{
    private function pricingMapper(): PricingMapper
    {
        /** @var PricingMapper */
        return $this->getContainerEntry(PricingMapper::class);
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

    /** @return list<PricingModel> */
    public function listAll(): array
    {
        return $this->pricingMapper()->listAll();
    }

    public function findOrFail(int $id): PricingModel
    {
        $model = $this->pricingMapper()->findById($id);
        if ($model === null) {
            throw NotFoundException::forEntity('mục bảng giá', $id);
        }

        return $model;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException
     */
    public function saveForm(?int $id, array $raw): void
    {
        $this->saveValidated($id, $raw, true);
    }

    /** @param array<array-key, mixed> $raw */
    private function saveValidated(?int $id, array $raw, bool $withCsrf): void
    {
        $filter = new PricingSaveFilter($this->pricingMapper(), $id, $withCsrf);
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

    /** @param array<array-key, mixed> $raw */
    public function deleteForm(array $raw): string
    {
        $filter = new PricingActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }
        try {
            $this->delete((int) $filter->idValue());

            return PricingConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    public function saveFormCsrfHash(?int $id): string
    {
        return (new PricingSaveFilter($this->pricingMapper(), $id))->csrfHash();
    }

    public function deleteFormCsrfHash(): string
    {
        return (new PricingActionFilter())->csrfHash();
    }

    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    /**
     * Kéo-thả thứ tự bảng giá — giống ServiceService::formReorder.
     *
     * @param array<array-key, mixed> $raw
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
        $mapper   = $this->pricingMapper();
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
     * @param list<int> $requested
     *
     * @return list<PricingModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows = $this->pricingMapper()->listAll();
        $byId = [];
        $existing = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
            $existing[]     = $row->id;
        }
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

    /** @param array<array-key, mixed> $data */
    public function create(array $data): int
    {
        $id = $this->pricingMapper()->insert($this->buildValues($data, null));
        $this->invalidatePublicCaches();

        return $id;
    }

    /** @param array<array-key, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->findOrFail($id);
        $this->pricingMapper()->update($id, $this->buildValues($data, $id));
        $this->invalidatePublicCaches();
    }

    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $this->pricingMapper()->delete($id);
        $this->invalidatePublicCaches();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function buildValues(array $data, ?int $excludeId): array
    {
        $slugs   = $this->slugService();
        $pricing = $this->pricingMapper();
        $name      = trim((string) $data['name']);
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug      = $slugInput !== ''
            ? $slugs->slugify($slugInput, PricingConst::MAX_LENGTH_SLUG)
            : $slugs->slugify($name, PricingConst::MAX_LENGTH_SLUG);

        return [
            'groupCode' => trim((string) ($data['groupCode'] ?? PricingConst::GROUP_GENERAL)) ?: PricingConst::GROUP_GENERAL,
            'name'      => $name,
            'slug'      => $slugs->unique(
                $slug,
                static fn (string $candidate): bool => $pricing->existsSlug($candidate, $excludeId)
            ),
            'price'     => $this->nullableInt($data['price'] ?? null),
            'unit'      => $this->nullableTrim($data['unit'] ?? null),
            'note'      => $this->nullableTrim($data['note'] ?? null),
            'sortOrder' => (int) ($data['sortOrder'] ?? 0),
            'isActive'  => $this->flag($data['isActive'] ?? null),
        ];
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? PricingConst::INACTIVE
            : PricingConst::ACTIVE;
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

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    private function invalidatePublicCaches(): void
    {
        $cache = $this->pageCache();
        $cache?->forget(CacheConst::KEY_PRICING);
        $cache?->forget(CacheConst::KEY_HOME);
    }
}
