<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Media\MediaMapper;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Service\ServiceConst;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;
use Frontend\Service\ServiceViewService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test cho ServiceViewService (FR-08):
 * - paginate(): cha active làm filter, chỉ con active được render thành card.
 * - list(): trả về danh sách dịch vụ active, map icon/image media.
 * - detail(slug):
 *   - slug rỗng hoặc không tồn tại: null
 *   - dịch vụ active: trả về đầy đủ payload, fallback metaTitle/metaDescription.
 */
final class ServiceViewServiceTest extends TestCase
{
    private ServiceMapper&MockObject $services;
    private MediaMapper&MockObject $media;
    private ServiceViewService $service;

    protected function setUp(): void
    {
        $this->services = $this->createMock(ServiceMapper::class);
        $this->media    = $this->createMock(MediaMapper::class);

        $this->service = (new ServiceViewService())->setContainer(new TestContainer([
            ServiceMapper::class => $this->services,
            MediaMapper::class   => $this->media,
        ]));
    }

    private function model(int $id, string $slug, array $overrides = []): ServiceModel
    {
        return ServiceModel::fromRow($overrides + [
            'id'               => $id,
            'name'             => 'Dịch vụ ' . $id,
            'slug'             => $slug,
            'shortDescription' => 'Mô tả ngắn ' . $id,
            'content'          => '<p>Nội dung ' . $id . '</p>',
            'iconMediaId'      => null,
            'imageMediaId'     => null,
            'sortOrder'        => 0,
            'isActive'         => ServiceConst::ACTIVE,
            'metaTitle'        => null,
            'metaDescription'  => null,
        ]);
    }

    public function testListEmptyServicesReturnsEmptyArray(): void
    {
        $this->services->method('listActiveAll')->willReturn([]);
        $this->media->expects(self::never())->method('mapCardsByIds');

        $result = $this->service->list();

        self::assertSame([], $result['services']);
        self::assertSame(0, $result['total']);
    }

    public function testListWithServicesResolvesMediaAndReturnsItems(): void
    {
        $models = [
            $this->model(1, 'dich-vu-1', ['iconMediaId' => 10, 'imageMediaId' => 20]),
            $this->model(2, 'dich-vu-2', ['iconMediaId' => 10, 'imageMediaId' => null]),
        ];

        $this->services->method('listActiveAll')->willReturn($models);
        $this->media->expects(self::once())
            ->method('mapCardsByIds')
            ->with([10, 20])
            ->willReturn([
                10 => ['path' => 'icon.png', 'alt' => 'Icon', 'thumb' => 'icon.png'],
                20 => ['path' => 'img.jpg', 'alt' => 'Banner', 'thumb' => 'img_thumb.webp'],
            ]);

        $result = $this->service->list();

        self::assertSame(2, $result['total']);
        self::assertSame('/dich-vu/dich-vu-1', $result['services'][0]['href']);
        self::assertSame('icon.png', $result['services'][0]['icon']['path'] ?? null);
        self::assertSame('img.jpg', $result['services'][0]['image']['path'] ?? null);
        self::assertNull($result['services'][1]['image']);
    }

    public function testPaginateShowsOnlyChildrenOfActiveParents(): void
    {
        $parents = [
            $this->model(1, 'cham-soc-tai-nha', ['name' => 'Chăm sóc tại nhà', 'parentId' => null]),
            $this->model(2, 'cham-soc-tai-vien', ['name' => 'Chăm sóc tại viện', 'parentId' => null]),
        ];
        $children = [
            $this->model(11, 'dieu-duong-tai-nha', ['parentId' => 1]),
            $this->model(21, 'kham-tong-quat', ['parentId' => 2]),
        ];
        $this->services->method('listActiveParents')->willReturn($parents);
        $this->services->expects(self::once())->method('countActiveChildren')->with([1, 2])->willReturn(2);
        $this->services->expects(self::once())
            ->method('listActiveChildrenPage')
            ->with([1, 2], 12, 0)
            ->willReturn($children);
        $result = $this->service->paginate(null, 1);
        self::assertSame(2, $result['total']);
        self::assertSame([11, 21], array_column($result['services'], 'id'));
        self::assertSame(['cham-soc-tai-nha', 'cham-soc-tai-vien'], array_column($result['parentFilters'], 'slug'));
        self::assertNull($result['selectedParent']);
    }

    public function testPaginateFiltersChildrenBySelectedParentSlug(): void
    {
        $parents = [
            $this->model(1, 'tai-nha', ['name' => 'Tại nhà', 'parentId' => null]),
            $this->model(2, 'tai-vien', ['name' => 'Tại viện', 'parentId' => null]),
        ];
        $this->services->method('listActiveParents')->willReturn($parents);
        $this->services->expects(self::once())->method('countActiveChildren')->with([2])->willReturn(1);
        $this->services->expects(self::once())
            ->method('listActiveChildrenPage')
            ->with([2], 12, 0)
            ->willReturn([$this->model(21, 'kham-tong-quat', ['parentId' => 2])]);
        $result = $this->service->paginate('tai-vien', 1);
        self::assertSame('tai-vien', $result['selectedParent']['slug'] ?? null);
        self::assertSame([21], array_column($result['services'], 'id'));
    }

    public function testPaginateUnknownParentSlugFallsBackToAllActiveParents(): void
    {
        $parents = [$this->model(1, 'tai-nha', ['parentId' => null])];
        $this->services->method('listActiveParents')->willReturn($parents);
        $this->services->expects(self::once())->method('countActiveChildren')->with([1])->willReturn(0);
        $this->services->expects(self::never())->method('listActiveChildrenPage');
        $result = $this->service->paginate('khong-ton-tai', 1);
        self::assertNull($result['selectedParent']);
        self::assertSame([], $result['services']);
    }
    public function testDetailEmptySlugReturnsNull(): void
    {
        $this->services->expects(self::never())->method('findActiveBySlug');

        self::assertNull($this->service->detail(''));
        self::assertNull($this->service->detail('   '));
    }

    public function testDetailUnknownSlugReturnsNull(): void
    {
        $this->services->method('findActiveBySlug')->with('khong-co')->willReturn(null);

        self::assertNull($this->service->detail('khong-co'));
    }

    public function testDetailActiveServiceReturnsFullPayloadWithFallbackMeta(): void
    {
        $model = $this->model(5, 'kham-benh', [
            'name'             => 'Khám bệnh chuyên sâu',
            'shortDescription' => 'Mô tả dịch vụ khám',
            'imageMediaId'     => 15,
            'metaTitle'        => null, // fallback name
            'metaDescription'  => null, // fallback shortDescription
        ]);

        $this->services->method('findActiveBySlug')->with('kham-benh')->willReturn($model);
        $this->media->method('mapCardsByIds')->with([15])->willReturn([
            15 => ['path' => 'kham.jpg', 'alt' => 'Khám bệnh', 'thumb' => 'kham.webp'],
        ]);

        $result = $this->service->detail('kham-benh');

        self::assertNotNull($result);
        $item = $result['service'];
        self::assertSame('Khám bệnh chuyên sâu', $item['name']);
        self::assertSame('kham-benh', $item['slug']);
        self::assertSame('Khám bệnh chuyên sâu', $item['metaTitle']);
        self::assertSame('Mô tả dịch vụ khám', $item['metaDescription']);
        self::assertSame('kham.jpg', $item['image']['path'] ?? null);
    }

    public function testDetailChildReturnsNullWhenParentIsInactive(): void
    {
        $child = $this->model(5, 'dich-vu-con', ['parentId' => 2]);
        $parent = $this->model(2, 'dich-vu-cha', [
            'parentId' => null,
            'isActive' => ServiceConst::INACTIVE,
        ]);
        $this->services->method('findActiveBySlug')->with('dich-vu-con')->willReturn($child);
        $this->services->method('findById')->with(2)->willReturn($parent);
        $this->media->expects(self::never())->method('mapCardsByIds');
        self::assertNull($this->service->detail('dich-vu-con'));
    }
}
