<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\Pricing\PricingModel;
use Frontend\Model\PricingGroup\PricingGroupMapper;
use Frontend\Model\PricingGroup\PricingGroupModel;
use Frontend\Model\Service\ServiceConst;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;
use Frontend\Service\PricingViewService;
use PHPUnit\Framework\TestCase;

final class PricingViewServiceTest extends TestCase
{
    public function testListUsesIndependentGroupAndChildPricingOrder(): void
    {
        $serviceMapper = $this->createMock(ServiceMapper::class);
        $serviceMapper->method('listActiveAll')->willReturn([
            $this->service(10, null, 'Tại nhà', 'tai-nha'),
            $this->service(11, 10, 'Truyền dịch', 'truyen-dich'),
            $this->service(20, null, 'Tại viện', 'tai-vien'),
            $this->service(21, 20, 'Chăm sóc theo giờ', 'cham-soc-theo-gio'),
        ]);
        $groupMapper = $this->createMock(PricingGroupMapper::class);
        $groupMapper->method('listActive')->willReturn([
            PricingGroupModel::fromRow(['id' => 2, 'serviceId' => 20, 'sortOrder' => 0, 'isActive' => 1]),
            PricingGroupModel::fromRow(['id' => 1, 'serviceId' => 10, 'sortOrder' => 1, 'isActive' => 1]),
        ]);
        $pricingMapper = $this->createMock(PricingMapper::class);
        $pricingMapper->method('listActive')->willReturn([
            $this->price(11, 1, 200000),
            $this->price(21, 0, 100000),
        ]);
        $service = (new PricingViewService())->setContainer(new TestContainer([
            ServiceMapper::class => $serviceMapper,
            PricingGroupMapper::class => $groupMapper,
            PricingMapper::class => $pricingMapper,
        ]));

        $payload = $service->list();

        self::assertSame(['Tại viện', 'Tại nhà'], array_column($payload['groupBlocks'], 'name'));
        self::assertSame('Chăm sóc theo giờ', $payload['groupBlocks'][0]['items'][0]['name']);
        self::assertSame(['tai-vien' => 'Tại viện', 'tai-nha' => 'Tại nhà'], $payload['groups']);
        self::assertSame(2, $payload['total']);
    }

    public function testListFiltersByParentSlugAndChildName(): void
    {
        $serviceMapper = $this->createMock(ServiceMapper::class);
        $serviceMapper->method('listActiveAll')->willReturn([
            $this->service(10, null, 'Tại nhà', 'tai-nha'),
            $this->service(11, 10, 'Truyền dịch', 'truyen-dich'),
            $this->service(12, 10, 'Thay băng', 'thay-bang'),
        ]);
        $groupMapper = $this->createMock(PricingGroupMapper::class);
        $groupMapper->method('listActive')->willReturn([
            PricingGroupModel::fromRow(['id' => 1, 'serviceId' => 10, 'sortOrder' => 0, 'isActive' => 1]),
        ]);
        $pricingMapper = $this->createMock(PricingMapper::class);
        $pricingMapper->method('listActive')->willReturn([
            $this->price(11, 0, 200000),
            $this->price(12, 1, 80000),
        ]);
        $service = (new PricingViewService())->setContainer(new TestContainer([
            ServiceMapper::class => $serviceMapper,
            PricingGroupMapper::class => $groupMapper,
            PricingMapper::class => $pricingMapper,
        ]));

        $payload = $service->list('tai-nha', 'băng', 99);

        self::assertSame(1, $payload['total']);
        self::assertSame('Thay băng', $payload['items'][0]['name']);
        self::assertSame(1, $payload['page']);
        self::assertSame(1, $payload['pages']);
    }

    private function service(int $id, ?int $parentId, string $name, string $slug): ServiceModel
    {
        return ServiceModel::fromRow([
            'id' => $id,
            'parentId' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'isActive' => ServiceConst::ACTIVE,
        ]);
    }

    private function price(int $serviceId, int $sortOrder, int $price): PricingModel
    {
        return PricingModel::fromRow([
            'id' => $serviceId,
            'serviceId' => $serviceId,
            'price' => $price,
            'sortOrder' => $sortOrder,
            'isActive' => PricingConst::ACTIVE,
        ]);
    }
}
