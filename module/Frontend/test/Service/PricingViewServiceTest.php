<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use ApplicationTest\Helper\TestContainer;
use Frontend\Constant\FrontendConst;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\Pricing\PricingModel;
use Frontend\Service\PricingViewService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * /bang-gia đọc toàn bộ bảng giá active qua một cache key, rồi filter + phân
 * trang trên mảng thuần để URL `?page=` không làm nở key cache.
 */
final class PricingViewServiceTest extends TestCase
{
    private PricingMapper&MockObject $pricing;
    private PricingViewService $service;

    protected function setUp(): void
    {
        $this->pricing = $this->createMock(PricingMapper::class);
        $this->service = (new PricingViewService())->setContainer(new TestContainer([
            PricingMapper::class => $this->pricing,
        ]));
    }

    public function testListPaginatesAfterLoadingAllActiveRows(): void
    {
        $this->pricing->method('listActive')->willReturn($this->rows(45));

        $payload = $this->service->list(null, null, 3);

        self::assertSame(45, $payload['total']);
        self::assertSame(3, $payload['page']);
        self::assertSame(3, $payload['pages']);
        self::assertSame(FrontendConst::PRICING_PAGE_SIZE, $payload['perPage']);
        self::assertCount(5, $payload['items']);
        self::assertSame(41, $payload['items'][0]['id']);
    }

    public function testListFiltersBeforePaginatingAndClampsPage(): void
    {
        $rows = array_merge(
            $this->rows(23, PricingConst::GROUP_HOSPITAL, 'Siêu âm'),
            $this->rows(5, PricingConst::GROUP_HOME, 'Chăm sóc')
        );
        $this->pricing->method('listActive')->willReturn($rows);

        $payload = $this->service->list(PricingConst::GROUP_HOSPITAL, 'Siêu âm', 99);

        self::assertSame(23, $payload['total']);
        self::assertSame(2, $payload['page']);
        self::assertSame(2, $payload['pages']);
        self::assertCount(3, $payload['items']);
        self::assertSame(PricingConst::GROUP_HOSPITAL, $payload['items'][0]['groupCode']);
    }

    /** @return list<PricingModel> */
    private function rows(int $count, string $group = PricingConst::GROUP_GENERAL, string $prefix = 'Dịch vụ'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = PricingModel::fromRow([
                'id'        => $i,
                'groupCode' => $group,
                'name'      => $prefix . ' ' . $i,
                'slug'      => 'pricing-' . $group . '-' . $i,
                'price'     => 100000 + $i,
                'unit'      => 'lần',
                'note'      => null,
                'sortOrder' => $i,
                'isActive'  => PricingConst::ACTIVE,
            ]);
        }

        return $rows;
    }
}
