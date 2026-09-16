<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingMapper;
use Frontend\Model\Setting\SettingModel;
use Frontend\Service\SettingService;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc settings + cache 60s (FR-39, NFR-PERF-1): map `settingKey =>
 * settingValue` tính một lần từ SettingMapper::listAll rồi nằm trong
 * page_cache; hit không đụng DB; invalidate xoá đúng KEY_SETTINGS.
 */
final class SettingServiceTest extends TestCase
{
    private SettingMapper&MockObject $mapper;
    private StorageInterface&MockObject $storage;
    private SettingService $service;

    protected function setUp(): void
    {
        $this->mapper  = $this->createMock(SettingMapper::class);
        $this->storage = $this->createMock(StorageInterface::class);

        $pageCache = (new PageCacheService())->setContainer(
            new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $this->storage])
        );
        $this->service = (new SettingService())->setContainer(new TestContainer([
            SettingMapper::class  => $this->mapper,
            PageCacheService::class => $pageCache,
        ]));
    }

    /** @return list<SettingModel> */
    private function rows(): array
    {
        return [
            SettingModel::fromRow([
                'id' => 1, 'groupCode' => 'general', 'settingKey' => 'site_name',
                'settingValue' => 'Vạn Lang', 'valueType' => SettingConst::VALUE_TYPE_STRING,
                'label' => 'Tên trang', 'sortOrder' => 0,
            ]),
            SettingModel::fromRow([
                'id' => 2, 'groupCode' => 'contact', 'settingKey' => SettingConst::KEY_NOTIFY_EMAILS,
                'settingValue' => null, 'valueType' => SettingConst::VALUE_TYPE_STRING,
                'label' => 'Email nhận thông báo', 'sortOrder' => 0,
            ]),
        ];
    }

    public function testMissLoadsFromDbAndWritesCache(): void
    {
        $expectedMap = ['site_name' => 'Vạn Lang', SettingConst::KEY_NOTIFY_EMAILS => null];
        $this->mapper->method('listAll')->willReturn($this->rows());
        // PageCacheService ghi payload dạng serialize([map]) (FR-39).
        $this->storage
            ->expects(self::once())
            ->method('setItem')
            ->with(CacheConst::KEY_SETTINGS, serialize([$expectedMap]));

        self::assertSame($expectedMap, $this->service->all());
    }

    public function testHitSkipsDb(): void
    {
        $this->mapper->expects(self::never())->method('listAll');
        $this->storage
            ->method('getItem')
            ->willReturnCallback(
                /** @param bool|null $success */
                static function (string $key, ?bool &$success = null) {
                    $success = true;

                    return serialize([['site_name' => 'Từ cache']]);
                }
            );

        self::assertSame(['site_name' => 'Từ cache'], $this->service->all());
    }

    public function testStringOrNullNormalisesEmptyAndMissing(): void
    {
        $this->mapper->method('listAll')->willReturn($this->rows());

        self::assertSame('Vạn Lang', $this->service->stringOrNull('site_name'));
        // NULL trong DB và key không tồn tại → null
        self::assertNull($this->service->stringOrNull(SettingConst::KEY_NOTIFY_EMAILS));
        self::assertNull($this->service->stringOrNull('khong_co'));
    }

    public function testInvalidateForgetsSettingsKey(): void
    {
        $this->storage
            ->expects(self::once())
            ->method('removeItem')
            ->with(CacheConst::KEY_SETTINGS);

        $this->service->invalidate();
    }

    public function testWorksWithoutCacheService(): void
    {
        // Container thiếu PageCacheService (unit test nhẹ ký) → đọc thẳng DB,
        // invalidate là no-op thay vì nổ.
        $service = (new SettingService())->setContainer(
            new TestContainer([SettingMapper::class => $this->mapper])
        );
        $this->mapper->method('listAll')->willReturn($this->rows());

        self::assertSame('Vạn Lang', $service->stringOrNull('site_name'));
        $service->invalidate();
    }

    public function testMapEmbedUrlPrefersMapAddressOverManualEmbedUrl(): void
    {
        $service = (new SettingService())->setContainer(
            new TestContainer([SettingMapper::class => $this->mapper])
        );
        $this->mapper->method('listAll')->willReturn([
            $this->setting(1, SettingConst::KEY_MAP_EMBED_URL, 'https://www.google.com/maps/embed?pb=old'),
            $this->setting(2, SettingConst::KEY_MAP_ADDRESS, '10 Nguyễn Huệ, Quận 1, TP.HCM'),
        ]);

        $expected = 'https://www.google.com/maps?q='
            . rawurlencode('10 Nguyễn Huệ, Quận 1, TP.HCM') . '&output=embed';

        self::assertSame($expected, $service->mapEmbedUrl());
    }

    public function testMapEmbedUrlFallsBackToPublicAddressBeforeManualEmbedUrl(): void
    {
        $service = (new SettingService())->setContainer(
            new TestContainer([SettingMapper::class => $this->mapper])
        );
        $this->mapper->method('listAll')->willReturn([
            $this->setting(1, SettingConst::KEY_MAP_EMBED_URL, 'https://www.google.com/maps/embed?pb=old'),
            $this->setting(2, SettingConst::KEY_MAP_ADDRESS, null),
            $this->setting(3, SettingConst::KEY_ADDRESS, '123 Hoàng Văn Ca, Long Biên, Hà Nội'),
        ]);

        $expected = 'https://www.google.com/maps?q='
            . rawurlencode('123 Hoàng Văn Ca, Long Biên, Hà Nội') . '&output=embed';

        self::assertSame($expected, $service->mapEmbedUrl());
    }

    public function testMapEmbedUrlUsesManualEmbedUrlWhenNoAddressExists(): void
    {
        $service = (new SettingService())->setContainer(
            new TestContainer([SettingMapper::class => $this->mapper])
        );
        $this->mapper->method('listAll')->willReturn([
            $this->setting(1, SettingConst::KEY_MAP_EMBED_URL, 'https://www.google.com/maps/embed?pb=manual'),
            $this->setting(2, SettingConst::KEY_MAP_ADDRESS, ''),
            $this->setting(3, SettingConst::KEY_ADDRESS, null),
        ]);

        self::assertSame('https://www.google.com/maps/embed?pb=manual', $service->mapEmbedUrl());
    }

    private function setting(int $id, string $key, ?string $value): SettingModel
    {
        return SettingModel::fromRow([
            'id' => $id, 'groupCode' => 'contact', 'settingKey' => $key,
            'settingValue' => $value, 'valueType' => SettingConst::VALUE_TYPE_STRING,
            'label' => $key, 'sortOrder' => $id,
        ]);
    }
}
