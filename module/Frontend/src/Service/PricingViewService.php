<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\PricingGroup\PricingGroupMapper;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;

/** Dựng bảng giá công khai theo cây dịch vụ và thứ tự bảng giá độc lập. */
class PricingViewService extends AppServiceFactory
{
    /**
     * @return array{items: list<array<string, mixed>>, groups: array<string, string>,
     *   groupBlocks: list<array<string, mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function list(?string $groupCode = null, ?string $q = null, int $requestedPage = 1): array
    {
        unset($requestedPage);
        $blocks = $this->applyFilter($this->allCached(), $groupCode, $q);
        $items = [];
        $options = [];
        foreach ($this->allCached() as $block) {
            $options[(string) $block['slug']] = (string) $block['name'];
        }
        foreach ($blocks as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return [
            'items' => $items,
            'groups' => $options,
            'groupBlocks' => $blocks,
            'total' => count($items),
            'page' => 1,
            'pages' => 1,
            'perPage' => max(1, count($items)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function allCached(): array
    {
        $producer = fn (): array => $this->buildAll();
        $cache = $this->pageCache();
        if ($cache === null) {
            return $producer();
        }

        /** @var list<array<string, mixed>> */
        return $cache->remember(CacheConst::KEY_PRICING, $producer);
    }

    /** @return list<array<string, mixed>> */
    private function buildAll(): array
    {
        $services = [];
        foreach ($this->services()->listActiveAll() as $service) {
            $services[$service->id] = $service;
        }
        $itemsByService = [];
        foreach ($this->pricing()->listActive() as $item) {
            if ($item->serviceId !== null) {
                $itemsByService[$item->serviceId] = $item;
            }
        }

        $blocks = [];
        foreach ($this->groups()->listActive() as $groupConfig) {
            $parent = $services[$groupConfig->serviceId] ?? null;
            if (! $parent instanceof ServiceModel || $parent->parentId !== null) {
                continue;
            }
            $children = [];
            foreach ($services as $service) {
                if ($service->parentId !== $parent->id) {
                    continue;
                }
                $item = $itemsByService[$service->id] ?? null;
                if ($item === null) {
                    continue;
                }
                $children[] = [
                    'id' => $item->id,
                    'serviceId' => $service->id,
                    'groupCode' => $parent->slug,
                    'groupName' => $parent->name,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'price' => $item->price,
                    'priceText' => $item->price !== null
                        ? number_format($item->price, 0, ',', '.') . ' đ' : 'Liên hệ',
                    'unit' => $item->unit ?? '',
                    'note' => $item->note ?? '',
                    'sortOrder' => $item->sortOrder,
                ];
            }
            usort($children, static fn (array $a, array $b): int =>
                [(int) $a['sortOrder'], (int) $a['serviceId']]
                <=> [(int) $b['sortOrder'], (int) $b['serviceId']]);
            if ($children !== []) {
                $blocks[] = [
                    'id' => $parent->id,
                    'slug' => $parent->slug,
                    'name' => $parent->name,
                    'description' => $parent->shortDescription ?? '',
                    'sortOrder' => $groupConfig->sortOrder,
                    'items' => $children,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $blocks, ?string $groupCode, ?string $q): array
    {
        $groupCode = trim((string) $groupCode);
        $q = trim((string) $q);
        $filtered = [];
        foreach ($blocks as $block) {
            if ($groupCode !== '' && (string) ($block['slug'] ?? '') !== $groupCode) {
                continue;
            }
            $items = [];
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ($q !== '' && mb_stripos((string) ($item['name'] ?? ''), $q, 0, 'UTF-8') === false) {
                    continue;
                }
                $items[] = $item;
            }
            if ($items !== []) {
                $block['items'] = $items;
                $filtered[] = $block;
            }
        }

        return $filtered;
    }

    private function pricing(): PricingMapper
    {
        /** @var PricingMapper */
        return $this->getContainerEntry(PricingMapper::class);
    }

    private function groups(): PricingGroupMapper
    {
        /** @var PricingGroupMapper */
        return $this->getContainerEntry(PricingGroupMapper::class);
    }

    private function services(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);
        return $entry instanceof PageCacheService ? $entry : null;
    }
}
