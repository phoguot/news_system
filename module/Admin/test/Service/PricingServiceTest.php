<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Service\PricingService;
use Application\Service\DbService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Pricing\PricingMapper;
use Frontend\Model\Pricing\PricingModel;
use Frontend\Model\PricingGroup\PricingGroupMapper;
use Frontend\Model\PricingGroup\PricingGroupModel;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private PricingMapper&MockObject $pricing;
    private PricingGroupMapper&MockObject $groups;
    private ServiceMapper&MockObject $services;
    private DbService&MockObject $db;
    private PricingService $service;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(dirname(__DIR__, 4) . '/data/cache');
        }
        $this->pricing = $this->createMock(PricingMapper::class);
        $this->groups = $this->createMock(PricingGroupMapper::class);
        $this->services = $this->createMock(ServiceMapper::class);
        $this->db = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $callback): mixed => $callback()
        );
        $this->service = (new PricingService())->setContainer(new TestContainer([
            PricingMapper::class => $this->pricing,
            PricingGroupMapper::class => $this->groups,
            ServiceMapper::class => $this->services,
            DbService::class => $this->db,
        ]));
    }

    public function testActiveFormCanHideParentServiceFromPricing(): void
    {
        $parent = ServiceModel::fromRow([
            'id' => 1,
            'parentId' => null,
            'name' => 'Dịch vụ tại viện',
            'slug' => 'dich-vu-tai-vien',
        ]);
        $config = PricingGroupModel::fromRow([
            'id' => 7,
            'serviceId' => 1,
            'sortOrder' => 0,
            'isActive' => 1,
        ]);
        $this->services->method('findById')->with(1)->willReturn($parent);
        $this->groups->method('findByServiceId')->with(1)->willReturn($config);
        $this->groups->expects(self::once())->method('update')->with(
            7,
            self::callback(static fn (array $values): bool => $values['isActive'] === 0),
        );

        $flag = $this->service->activeForm([
            'id' => '1',
            'isActive' => '0',
            'csrf' => $this->service->activeFormCsrfHash(),
        ]);

        self::assertSame('active-updated', $flag);
    }

    public function testActiveFormCanHideChildServiceFromPricing(): void
    {
        $parent = ServiceModel::fromRow([
            'id' => 1,
            'parentId' => null,
            'name' => 'Dịch vụ tại viện',
            'slug' => 'dich-vu-tai-vien',
        ]);
        $child = ServiceModel::fromRow([
            'id' => 10,
            'parentId' => 1,
            'name' => 'Chăm sóc theo giờ',
            'slug' => 'cham-soc-theo-gio',
        ]);
        $config = PricingModel::fromRow([
            'id' => 21,
            'serviceId' => 10,
            'groupCode' => $parent->slug,
            'name' => $child->name,
            'slug' => $child->slug,
            'isActive' => 1,
        ]);
        $this->services->method('findById')->willReturnMap([
            [10, $child],
            [1, $parent],
        ]);
        $this->pricing->method('findByServiceId')->with(10)->willReturn($config);
        $this->pricing->expects(self::once())->method('update')->with(
            21,
            self::callback(static fn (array $values): bool => $values['isActive'] === 0),
        );

        $flag = $this->service->activeForm([
            'id' => '10',
            'isActive' => '0',
            'csrf' => $this->service->activeFormCsrfHash(),
        ]);

        self::assertSame('active-updated', $flag);
    }

    public function testPositionFormMovesParentAndRenumbersEveryGroup(): void
    {
        $first = ServiceModel::fromRow([
            'id' => 1,
            'parentId' => null,
            'name' => 'Tại viện',
            'slug' => 'tai-vien',
        ]);
        $second = ServiceModel::fromRow([
            'id' => 2,
            'parentId' => null,
            'name' => 'Tại nhà',
            'slug' => 'tai-nha',
        ]);
        $firstConfig = PricingGroupModel::fromRow([
            'id' => 11,
            'serviceId' => 1,
            'sortOrder' => 0,
            'isActive' => 1,
        ]);
        $secondConfig = PricingGroupModel::fromRow([
            'id' => 12,
            'serviceId' => 2,
            'sortOrder' => 1,
            'isActive' => 1,
        ]);
        $this->services->method('listAll')->willReturn([$first, $second]);
        $this->groups->method('listAll')->willReturn([$firstConfig, $secondConfig]);
        $this->groups->method('findByServiceId')->willReturnMap([
            [1, $firstConfig],
            [2, $secondConfig],
        ]);
        $this->pricing->method('listAll')->willReturn([]);
        $updated = [];
        $this->groups->expects(self::exactly(2))->method('update')->willReturnCallback(
            static function (int $id, array $values) use (&$updated): void {
                $updated[$id] = $values['sortOrder'];
            }
        );

        $flag = $this->service->positionForm([
            'id' => '2',
            'position' => '1',
            'csrf' => $this->service->positionFormCsrfHash(),
        ]);

        self::assertSame('position-updated', $flag);
        self::assertSame([12 => 0, 11 => 1], $updated);
    }

    public function testPositionFormMovesChildOnlyInsideItsParent(): void
    {
        $parent = ServiceModel::fromRow([
            'id' => 1,
            'parentId' => null,
            'name' => 'Tại nhà',
            'slug' => 'tai-nha',
        ]);
        $first = ServiceModel::fromRow([
            'id' => 10,
            'parentId' => 1,
            'name' => 'Tiêm truyền',
            'slug' => 'tiem-truyen',
        ]);
        $second = ServiceModel::fromRow([
            'id' => 11,
            'parentId' => 1,
            'name' => 'Thay băng',
            'slug' => 'thay-bang',
        ]);
        $groupConfig = PricingGroupModel::fromRow([
            'id' => 7,
            'serviceId' => 1,
            'sortOrder' => 0,
            'isActive' => 1,
        ]);
        $firstConfig = PricingModel::fromRow([
            'id' => 21,
            'serviceId' => 10,
            'groupCode' => 'tai-nha',
            'name' => 'Tiêm truyền',
            'slug' => 'tiem-truyen',
            'sortOrder' => 0,
            'isActive' => 1,
        ]);
        $secondConfig = PricingModel::fromRow([
            'id' => 22,
            'serviceId' => 11,
            'groupCode' => 'tai-nha',
            'name' => 'Thay băng',
            'slug' => 'thay-bang',
            'sortOrder' => 1,
            'isActive' => 1,
        ]);
        $this->services->method('listAll')->willReturn([$parent, $first, $second]);
        $this->groups->method('listAll')->willReturn([$groupConfig]);
        $this->pricing->method('listAll')->willReturn([$firstConfig, $secondConfig]);
        $this->pricing->method('findByServiceId')->willReturnMap([
            [10, $firstConfig],
            [11, $secondConfig],
        ]);
        $updated = [];
        $this->pricing->expects(self::exactly(2))->method('update')->willReturnCallback(
            static function (int $id, array $values) use (&$updated): void {
                $updated[$id] = $values['sortOrder'];
            }
        );

        $flag = $this->service->positionForm([
            'id' => '11',
            'position' => '1',
            'csrf' => $this->service->positionFormCsrfHash(),
        ]);

        self::assertSame('position-updated', $flag);
        self::assertSame([22 => 0, 21 => 1], $updated);
    }
}
