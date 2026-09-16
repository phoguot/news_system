<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Banner\BannerConst;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Banner\BannerModel;
use Admin\Service\BannerService;
use Application\Constant\CacheConst;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BannerServiceTest extends TestCase
{
    private BannerMapper&MockObject $banners;
    private DbService&MockObject $db;
    private BannerService $service;

    protected function setUp(): void
    {
        $this->banners = $this->createMock(BannerMapper::class);
        $this->db      = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );
        $this->service = (new BannerService())->setContainer(new TestContainer([
            BannerMapper::class => $this->banners,
            DbService::class    => $this->db,
        ]));
    }

    /**
     * POST thô hợp lệ tối thiểu (giờ VN 20:00 ngày 12/09 = UTC 13:00).
     *
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function raw(array $overrides = []): array
    {
        return $overrides + [
            'position'     => BannerConst::POSITION_HOME_HERO,
            'title'        => 'Khuyen mai he',
            'imageMediaId' => '7',
            'startAt'      => '2026-09-12T20:00',
            'csrf'         => $this->service->saveFormCsrfHash(),
        ];
    }

    public function testSaveFormCreatesWithVnTimeConvertedToUtc(): void
    {
        $captured = null;
        $this->banners->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 21;
            });

        $this->service->saveForm(null, $this->raw());

        self::assertIsArray($captured);
        self::assertSame(BannerConst::POSITION_HOME_HERO, $captured['position']);
        self::assertSame(7, $captured['imageMediaId']);
        self::assertSame('2026-09-12 13:00:00', $captured['startAt']);
        self::assertNull($captured['endAt']);
        self::assertSame(BannerConst::INACTIVE, $captured['isActive']);
    }

    public function testSaveFormRequiresImageMediaId(): void
    {
        $this->banners->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['imageMediaId' => '']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('imageMediaId', $e->getErrors());
        }
    }

    public function testSaveFormRejectsUnknownPosition(): void
    {
        $this->banners->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['position' => 'khong_ton_tai']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                ['position' => BannerConst::ERROR_POSITION],
                array_intersect_key($e->getErrors(), ['position' => true])
            );
        }
    }

    /**
     * Regex của filter chặn trước mọi chuỗi sai dáng — nhánh ERROR_INVALID_TIME
     * trong Service chỉ là phòng vệ (createFromFormat của PHP "roll" ngày
     * vượt giới hạn thay vì trả false, giống PostService).
     */
    public function testSaveFormRejectsBadDateTimeShape(): void
    {
        $this->banners->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['startAt' => '12/09/2026 10:00']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('startAt', $e->getErrors());
        }
    }

    public function testSaveFormRejectsEndBeforeStart(): void
    {
        $this->banners->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw([
                'startAt' => '2026-09-12T20:00',
                'endAt'   => '2026-09-12T18:00',
            ]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                ['endAt' => BannerConst::ERROR_TIME_RANGE],
                array_intersect_key($e->getErrors(), ['endAt' => true])
            );
        }
    }

    public function testSaveFormUpdateCallsMapperUpdate(): void
    {
        $this->banners->method('findById')->willReturn(BannerModel::fromRow(['id' => 5]));
        $this->banners->expects(self::once())->method('update')->with(5, self::isType('array'));
        $this->banners->expects(self::never())->method('insert');

        $this->service->saveForm(5, $this->raw());
    }

    public function testSaveFormUpdateMissingRowThrowsNotFound(): void
    {
        $this->banners->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->saveForm(5, $this->raw());
    }

    public function testFormValuesConvertsUtcBackToVnWallInput(): void
    {
        $values = $this->service->formValues(BannerModel::fromRow([
            'id'           => 5,
            'position'     => BannerConst::POSITION_HOME_HERO,
            'imageMediaId' => 7,
            'startAt'      => '2026-09-12 13:00:00',
            'endAt'        => null,
        ]));

        self::assertSame('2026-09-12T20:00', $values['startAt']);
        self::assertSame('', $values['endAt']);
    }

    public function testDeleteFormRejectsBadCsrf(): void
    {
        $this->banners->expects(self::never())->method('delete');

        self::assertSame('csrf', $this->service->deleteForm(['id' => '4']));
    }

    public function testDeleteFormDeletesAndReturnsFlag(): void
    {
        $this->banners->method('findById')->willReturn(BannerModel::fromRow(['id' => 4]));
        $this->banners->expects(self::once())->method('delete')->with(4);

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(BannerConst::FLAG_DELETED, $flag);
    }

    public function testDeleteFormMissingRowReturnsNotfound(): void
    {
        $this->banners->method('findById')->willReturn(null);
        $this->banners->expects(self::never())->method('delete');

        $flag = $this->service->deleteForm(['id' => '99', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame('notfound', $flag);
    }

    /** FR-32/FR-39: create/update/delete banner đều forget 'home-v1'. */
    public function testWritesForgetHomeCache(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::exactly(3))->method('removeItem')->with(CacheConst::KEY_HOME);
        $this->banners->method('insert')->willReturn(9);
        $this->banners->method('findById')->willReturn(BannerModel::fromRow(['id' => 9]));
        $service = (new BannerService())->setContainer(new TestContainer([
            BannerMapper::class     => $this->banners,
            PageCacheService::class => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $service->create(['position' => BannerConst::POSITION_HOME_HERO, 'imageMediaId' => 3]);
        $service->update(9, ['position' => BannerConst::POSITION_HOME_HERO, 'imageMediaId' => 3]);
        $service->delete(9);
    }

    /* ---- FR-30: kéo-thả đổi thứ tự (formReorder) ---- */

    public function testFormReorderAppliesNewSequenceAndRenumbers(): void
    {
        $this->banners->method('listAll')->willReturn([
            BannerModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            BannerModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            BannerModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->banners->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '3,1,2',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(3, $result['applied']);
        self::assertSame([3 => 0, 1 => 1, 2 => 2], $writes);
    }

    public function testFormReorderIgnoresUnknownIdsAndAppendsMissingRows(): void
    {
        $this->banners->method('listAll')->willReturn([
            BannerModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            BannerModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            BannerModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->banners->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => [2, 99, 1],
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(2, $result['applied']);
        self::assertSame([2 => 0, 1 => 1], $writes);
    }

    public function testFormReorderSameOrderWritesNothing(): void
    {
        $this->banners->method('listAll')->willReturn([
            BannerModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            BannerModel::fromRow(['id' => 2, 'sortOrder' => 1]),
        ]);
        $this->banners->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '1,2',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(0, $result['applied']);
    }

    public function testFormReorderRejectsBadCsrf(): void
    {
        $this->banners->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '2,1',
            'csrf' => 'sai-token',
        ]);

        self::assertSame('csrf', $result['flag']);
        self::assertSame(0, $result['applied']);
    }
}
