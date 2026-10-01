<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Active\ActiveStatusFilter;
use Admin\Filter\Pricing\PricingSaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\Pricing\PricingModel;
use Frontend\Model\PricingGroup\PricingGroupMapper;
use Frontend\Model\PricingGroup\PricingGroupModel;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;

/** Quản trị giá theo cây dịch vụ, với thứ tự riêng cho cả cha và con. */
class PricingService extends AppServiceFactory
{
    private function pricingMapper(): PricingMapper
    {
        /** @var PricingMapper */
        return $this->getContainerEntry(PricingMapper::class);
    }

    private function groupMapper(): PricingGroupMapper
    {
        /** @var PricingGroupMapper */
        return $this->getContainerEntry(PricingGroupMapper::class);
    }

    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    /**
     * @return list<array{service: ServiceModel, config: PricingGroupModel|null,
     *   children: list<array{service: ServiceModel, config: PricingModel|null}>}>
     */
    public function tree(): array
    {
        $groupsByService = [];
        foreach ($this->groupMapper()->listAll() as $group) {
            $groupsByService[$group->serviceId] = $group;
        }
        $itemsByService = [];
        foreach ($this->pricingMapper()->listAll() as $item) {
            if ($item->serviceId !== null) {
                $itemsByService[$item->serviceId] = $item;
            }
        }

        $parents = [];
        $children = [];
        foreach ($this->serviceMapper()->listAll() as $service) {
            if ($service->parentId === null) {
                $parents[$service->id] = $service;
            } else {
                $children[$service->parentId][] = $service;
            }
        }
        uasort($parents, static function (ServiceModel $a, ServiceModel $b) use ($groupsByService): int {
            $aOrder = $groupsByService[$a->id]->sortOrder ?? $a->sortOrder;
            $bOrder = $groupsByService[$b->id]->sortOrder ?? $b->sortOrder;
            return [$aOrder, $a->id] <=> [$bOrder, $b->id];
        });

        $tree = [];
        foreach ($parents as $parent) {
            $childRows = $children[$parent->id] ?? [];
            usort($childRows, static function (ServiceModel $a, ServiceModel $b) use ($itemsByService): int {
                $aOrder = $itemsByService[$a->id]->sortOrder ?? $a->sortOrder;
                $bOrder = $itemsByService[$b->id]->sortOrder ?? $b->sortOrder;
                return [$aOrder, $a->id] <=> [$bOrder, $b->id];
            });
            $configured = [];
            foreach ($childRows as $child) {
                $configured[] = ['service' => $child, 'config' => $itemsByService[$child->id] ?? null];
            }
            $tree[] = [
                'service' => $parent,
                'config' => $groupsByService[$parent->id] ?? null,
                'children' => $configured,
            ];
        }

        return $tree;
    }

    /** @return array{service: ServiceModel, parent: ServiceModel, config: PricingModel|null} */
    public function configurationForService(int $serviceId): array
    {
        $service = $this->serviceMapper()->findById($serviceId);
        if ($service === null || $service->parentId === null) {
            throw NotFoundException::forEntity('dịch vụ con', $serviceId);
        }
        $parent = $this->serviceMapper()->findById($service->parentId);
        if ($parent === null) {
            throw NotFoundException::forEntity('dịch vụ cha', $service->parentId);
        }

        return [
            'service' => $service,
            'parent' => $parent,
            'config' => $this->pricingMapper()->findByServiceId($serviceId),
        ];
    }

    /** @param array<array-key, mixed> $raw */
    public function saveForm(array $raw): void
    {
        $filter = new PricingSaveFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }
        $values = $filter->getValues();
        $context = $this->configurationForService($filter->serviceIdValue());
        $config = $context['config'];
        $data = $this->itemValues($context['service'], $context['parent'], $values);
        if ($config === null) {
            $this->pricingMapper()->insert($data);
        } else {
            $this->pricingMapper()->update($config->id, $data);
        }
        $this->invalidatePublicCaches();
    }

    public function saveFormCsrfHash(): string
    {
        return (new PricingSaveFilter())->csrfHash();
    }

    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    public function activeFormCsrfHash(): string
    {
        return (new ActiveStatusFilter())->csrfHash();
    }

    /** @param array<array-key, mixed> $raw */
    public function activeForm(array $raw): string
    {
        $filter = new ActiveStatusFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return isset($filter->fieldErrors()['csrf']) ? 'csrf' : 'notfound';
        }
        $service = $this->serviceMapper()->findById($filter->idValue());
        if ($service === null) {
            return 'notfound';
        }
        $active = $filter->activeValue();
        if ($service->parentId === null) {
            $this->saveGroupConfig($service, null, $active);
        } else {
            $parent = $this->serviceMapper()->findById($service->parentId);
            if ($parent === null) {
                return 'notfound';
            }
            $this->saveItemConfig($service, $parent, null, $active);
        }
        $this->invalidatePublicCaches();
        return 'active-updated';
    }

    /** @param array<array-key, mixed> $raw @return array{flag: string, applied: int} */
    public function formReorder(array $raw): array
    {
        $filter = new ReorderFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return ['flag' => 'csrf', 'applied' => 0];
        }
        $byId = [];
        foreach ($this->serviceMapper()->listAll() as $service) {
            $byId[$service->id] = $service;
        }
        $groupOrder = 0;
        $childOrders = [];
        $operations = [];
        foreach ($filter->idList() as $serviceId) {
            $service = $byId[$serviceId] ?? null;
            if ($service === null) {
                continue;
            }
            if ($service->parentId === null) {
                $operations[] = [$service, null, $groupOrder++];
                continue;
            }
            $parent = $byId[$service->parentId] ?? null;
            if ($parent === null) {
                continue;
            }
            $order = $childOrders[$parent->id] ?? 0;
            $childOrders[$parent->id] = $order + 1;
            $operations[] = [$service, $parent, $order];
        }
        $this->db()->transactional(function () use ($operations): void {
            foreach ($operations as [$service, $parent, $order]) {
                if ($parent === null) {
                    $this->saveGroupConfig($service, $order, null);
                } else {
                    $this->saveItemConfig($service, $parent, $order, null);
                }
            }
        });
        $this->invalidatePublicCaches();
        return ['flag' => 'reordered', 'applied' => count($operations)];
    }

    private function saveGroupConfig(ServiceModel $service, ?int $sortOrder, ?int $active): void
    {
        $config = $this->groupMapper()->findByServiceId($service->id);
        $values = [
            'sortOrder' => $sortOrder ?? $config?->sortOrder ?? $service->sortOrder,
            'isActive' => $active ?? $config?->isActive ?? PricingConst::INACTIVE,
        ];
        if ($config === null) {
            $this->groupMapper()->insert(['serviceId' => $service->id] + $values);
        } else {
            $this->groupMapper()->update($config->id, $values);
        }
    }

    private function saveItemConfig(
        ServiceModel $service,
        ServiceModel $parent,
        ?int $sortOrder,
        ?int $active
    ): void {
        $config = $this->pricingMapper()->findByServiceId($service->id);
        $values = $this->itemValues($service, $parent, [
            'price' => $config?->price,
            'unit' => $config?->unit,
            'note' => $config?->note,
            'sortOrder' => $sortOrder ?? $config?->sortOrder ?? $service->sortOrder,
            'isActive' => $active ?? $config?->isActive ?? PricingConst::INACTIVE,
        ]);
        if ($config === null) {
            $this->pricingMapper()->insert($values);
        } else {
            $this->pricingMapper()->update($config->id, $values);
        }
    }

    /** @param array<array-key, mixed> $data @return array<array-key, mixed> */
    private function itemValues(ServiceModel $service, ServiceModel $parent, array $data): array
    {
        return [
            'serviceId' => $service->id,
            'groupCode' => $parent->slug,
            'name' => $service->name,
            'slug' => $service->slug,
            'price' => $this->nullableInt($data['price'] ?? null),
            'unit' => $this->nullableTrim($data['unit'] ?? null),
            'note' => $this->nullableTrim($data['note'] ?? null),
            'sortOrder' => (int) ($data['sortOrder'] ?? 0),
            'isActive' => $this->flag($data['isActive'] ?? null),
        ];
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === 0 || $raw === '0' || $raw === false
            ? PricingConst::INACTIVE : PricingConst::ACTIVE;
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
