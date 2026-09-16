<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\ValidationException;
use Admin\Service\SettingService;
use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingMapper;
use Frontend\Model\Setting\SettingModel;
use Frontend\Service\SettingService as FrontendSettingService;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Trang /admin/settings (FR-39): validate theo valueType, chỉ ghi dòng đổi,
 * boolean rỗng → '0', string rỗng → NULL._saveForm xong phải invalidate
 * cache settings (PageCacheService::forget — luồng đọc công khai FR-39).
 */
final class SettingServiceTest extends TestCase
{
    private SettingMapper&MockObject $mapper;
    private StorageInterface&MockObject $cacheStorage;
    private SettingService $service;

    protected function setUp(): void
    {
        $this->mapper       = $this->createMock(SettingMapper::class);
        $this->cacheStorage = $this->createMock(StorageInterface::class);

        // Chuỗi thật Admin\SettingService → Frontend\SettingService →
        // PageCacheService → storage mock (chỉ removeItem là đáng quan tâm ở đây).
        $pageCache        = (new PageCacheService())->setContainer(
            new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $this->cacheStorage])
        );
        $frontendSettings = (new FrontendSettingService())->setContainer(
            new TestContainer([PageCacheService::class => $pageCache])
        );

        $this->service = (new SettingService())->setContainer(new TestContainer([
            SettingMapper::class       => $this->mapper,
            FrontendSettingService::class => $frontendSettings,
        ]));
    }

    /** saveForm thành công (kể cả 0 dòng đổi) phải xoá đúng một lần map settings. */
    private function expectCacheInvalidated(): void
    {
        $this->cacheStorage
            ->expects(self::once())
            ->method('removeItem')
            ->with(CacheConst::KEY_SETTINGS);
    }

    /**
     * @param array<array-key, mixed> $overrides
     */
    private function model(array $overrides): SettingModel
    {
        return SettingModel::fromRow($overrides + [
            'id'          => 1,
            'groupCode'   => 'general',
            'settingKey'  => 'site_name',
            'valueType'   => SettingConst::VALUE_TYPE_STRING,
            'label'       => 'Tên trang',
            'sortOrder'   => 0,
        ]);
    }

    /** @param list<SettingModel> $rows */
    private function rows(array $rows): void
    {
        $this->mapper->method('listAll')->willReturn($rows);
    }

    public function testListGroupedPreservesGroupOrder(): void
    {
        $this->rows([
            $this->model(['id' => 1, 'groupCode' => 'general']),
            $this->model(['id' => 2, 'groupCode' => 'contact']),
            $this->model(['id' => 3, 'groupCode' => 'general']),
        ]);

        $grouped = $this->service->listGrouped();

        self::assertSame(['general', 'contact'], array_keys($grouped));
        self::assertCount(2, $grouped['general']);
    }

    public function testSaveFormWritesOnlyChangedRows(): void
    {
        $this->rows([
            $this->model(['id' => 1, 'settingValue' => 'X']),
            $this->model([
                'id' => 2,
                'settingValue' => '1',
                'valueType' => SettingConst::VALUE_TYPE_BOOLEAN,
            ]),
            $this->model([
                'id' => 3,
                'settingValue' => '5',
                'valueType' => SettingConst::VALUE_TYPE_NUMBER,
            ]),
        ]);

        $calls = [];
        $this->mapper->method('updateValue')->willReturnCallback(
            static function (int $id, ?string $value, ?int $by) use (&$calls): void {
                $calls[$id] = [$value, $by];
            }
        );

        $this->expectCacheInvalidated();

        // setting_1 đổi 'X'→'Y'; setting_2 boolean '1'→'0'; setting_3 giữ '5'
        $this->service->saveForm([
            'setting_1' => 'Y',
            'setting_2' => '0',
            'setting_3' => '5',
            'csrf'      => $this->service->saveFormCsrfHash(),
        ], 7);

        self::assertSame([1 => ['Y', 7], 2 => ['0', 7]], $calls);
    }

    public function testSaveFormEmptyNonBooleanStoresNull(): void
    {
        $this->rows([$this->model(['id' => 1, 'settingValue' => 'X'])]);

        $this->mapper->expects(self::once())->method('updateValue')->with(1, null, 7);
        $this->expectCacheInvalidated();

        $this->service->saveForm(
            ['setting_1' => '', 'csrf' => $this->service->saveFormCsrfHash()],
            7
        );
    }

    public function testSaveFormBooleanMissingInputBecomesZero(): void
    {
        $this->rows([
            $this->model([
                'id' => 4,
                'settingKey' => 'consent_enabled',
                'settingValue' => '1',
                'valueType' => SettingConst::VALUE_TYPE_BOOLEAN,
            ]),
        ]);

        // select luôn gửi 0/1; nếu client bỏ sót input → coi như '0'
        $this->mapper->expects(self::once())->method('updateValue')->with(4, '0', null);
        $this->expectCacheInvalidated();

        $this->service->saveForm(['csrf' => $this->service->saveFormCsrfHash()], null);
    }

    public function testSaveFormRejectsInvalidNumberAndJson(): void
    {
        $this->rows([
            $this->model(['id' => 3, 'valueType' => SettingConst::VALUE_TYPE_NUMBER]),
            $this->model(['id' => 6, 'valueType' => SettingConst::VALUE_TYPE_JSON]),
        ]);
        $this->mapper->expects(self::never())->method('updateValue');
        $this->cacheStorage->expects(self::never())->method('removeItem');

        try {
            $this->service->saveForm([
                'setting_3' => 'abc',
                'setting_6' => '{bad json',
                'csrf'      => $this->service->saveFormCsrfHash(),
            ], null);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('setting_3', $e->getErrors());
            self::assertArrayHasKey('setting_6', $e->getErrors());
        }
    }

    public function testSaveFormRejectsNonPositiveMedia(): void
    {
        $this->rows([$this->model(['id' => 9, 'valueType' => SettingConst::VALUE_TYPE_MEDIA])]);
        $this->mapper->expects(self::never())->method('updateValue');
        $this->cacheStorage->expects(self::never())->method('removeItem');

        try {
            $this->service->saveForm([
                'setting_9' => '0',
                'csrf'      => $this->service->saveFormCsrfHash(),
            ], null);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(SettingConst::ERROR_NOT_MEDIA, $e->getErrors()['setting_9']);
        }
    }

    public function testSaveFormRequiresCsrf(): void
    {
        $this->rows([$this->model(['id' => 1, 'settingValue' => 'X'])]);
        $this->mapper->expects(self::never())->method('updateValue');
        $this->cacheStorage->expects(self::never())->method('removeItem');

        try {
            $this->service->saveForm(['setting_1' => 'Y'], null);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }
}
