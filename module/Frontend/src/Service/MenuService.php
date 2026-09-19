<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use Frontend\Model\Menu\MenuMapper;

/**
 * Tầng đọc menu công khai cho layout FE, cache một key TTL 60s.
 */
class MenuService extends AppServiceFactory
{
    private function menuMapper(): MenuMapper
    {
        /** @var MenuMapper */
        return $this->getContainerEntry(MenuMapper::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /** @return list<array{label: string, url: string, target: string}> */
    public function publicItems(): array
    {
        $cache = $this->pageCache();
        if ($cache === null) {
            return $this->load();
        }

        return $cache->remember(CacheConst::KEY_MENU, fn (): array => $this->load());
    }

    public function invalidate(): void
    {
        $this->pageCache()?->forget(CacheConst::KEY_MENU);
    }

    /** @return list<array{label: string, url: string, target: string}> */
    private function load(): array
    {
        $items = [];
        foreach ($this->menuMapper()->listActive() as $row) {
            $items[] = [
                'label' => $row->label,
                'url' => $row->url,
                'target' => $row->target,
            ];
        }

        return $items;
    }
}
