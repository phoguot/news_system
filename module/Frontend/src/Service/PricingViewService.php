<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use Frontend\Constant\FrontendConst;
use Frontend\Model\Pricing\PricingMapper;

/**
 * Tầng đọc bảng giá công khai /bang-gia — cache 60s như home, filter theo nhóm + tìm kiếm.
 * Trang công khai phân trang trên mảng đã cache (20 dòng/trang) để Admin chỉ
 * cần forget một key `pricing-v1` sau khi ghi.
 *
 * Cache **một** khóa `pricing-v1` (mảng thuần toàn bộ mục active) — Admin\PricingService
 * forget đúng khóa này là đủ; bộ lọc `nhom/q` áp dụng bằng PHP sau khi lấy cache,
 * tránh phình khóa theo `md5(group|q)` khiến invalidate không bao phủ hết biến thể.
 */
class PricingViewService extends AppServiceFactory
{
    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     groups: array<string, string>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     perPage: int
     * }
     */
    public function list(?string $groupCode = null, ?string $q = null, int $requestedPage = 1): array
    {
        $filtered = $this->applyFilter($this->allCached(), $groupCode, $q);
        $total    = count($filtered);
        $pages    = (int) ceil($total / FrontendConst::PRICING_PAGE_SIZE);
        $page     = min(max(1, $requestedPage), max(1, $pages));
        $items    = array_slice(
            $filtered,
            ($page - 1) * FrontendConst::PRICING_PAGE_SIZE,
            FrontendConst::PRICING_PAGE_SIZE
        );

        return [
            'items'   => $items,
            'groups'  => \Frontend\Model\Pricing\PricingConst::GROUP_LABELS,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => FrontendConst::PRICING_PAGE_SIZE,
        ];
    }

    /** @return list<array<string, mixed>> toàn bộ mục active (đã cache `pricing-v1`). */
    private function allCached(): array
    {
        $cache    = $this->pageCache();
        $producer = fn (): array => $this->buildAll();

        if ($cache === null) {
            return $producer();
        }

        /** @var list<array<string, mixed>> */
        return $cache->remember(CacheConst::KEY_PRICING, $producer);
    }

    /** @return list<array<string, mixed>> */
    private function buildAll(): array
    {
        $models = $this->pricing()->listActive(null, null);
        $out = [];
        foreach ($models as $m) {
            $out[] = [
                'id'        => $m->id,
                'groupCode' => $m->groupCode,
                'name'      => $m->name,
                'slug'      => $m->slug,
                'price'     => $m->price,
                'priceText' => $m->price !== null ? number_format($m->price, 0, ',', '.') . ' đ' : 'Liên hệ',
                'unit'      => $m->unit ?? '',
                'note'      => $m->note ?? '',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $all
     *
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $all, ?string $groupCode, ?string $q): array
    {
        $groupCode = $groupCode !== null ? trim($groupCode) : null;
        $q         = $q !== null ? trim($q) : null;

        if (($groupCode === null || $groupCode === '') && ($q === null || $q === '')) {
            return $all;
        }

        $out = [];
        foreach ($all as $row) {
            if ($groupCode !== null && $groupCode !== '' && (string) ($row['groupCode'] ?? '') !== $groupCode) {
                continue;
            }
            if ($q !== null && $q !== '' && mb_stripos((string) ($row['name'] ?? ''), $q, 0, 'UTF-8') === false) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function pricing(): PricingMapper
    {
        /** @var PricingMapper */
        return $this->getContainerEntry(PricingMapper::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }
}
