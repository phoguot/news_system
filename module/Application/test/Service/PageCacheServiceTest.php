<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Constant\CacheConst;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PageCacheService (FR-39, NFR-PERF-1): remember = đọc storage (payload là
 * mảng serialize bọc 1 phần tử), miss thì tính + ghi; forget = removeItem.
 * Không có storage (test/CLI) hoặc getItem fail (exceptionhandler nuốt →
 * success=false) thì luôn tính thẳng — cache không được thành dependency cứng.
 */
final class PageCacheServiceTest extends TestCase
{
    private StorageInterface&MockObject $storage;
    private PageCacheService $service;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageInterface::class);
        $this->service = (new PageCacheService())->setContainer(
            new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $this->storage])
        );
    }

    public function testMissComputesAndWritesSerializedPayload(): void
    {
        // Mock trả null, không đụng $success → coi như miss.
        $this->storage
            ->expects(self::once())
            ->method('setItem')
            ->with(CacheConst::KEY_SETTINGS, serialize([['site_name' => 'Vạn Lang']]));

        $value = $this->service->remember(
            CacheConst::KEY_SETTINGS,
            static fn (): array => ['site_name' => 'Vạn Lang']
        );

        self::assertSame(['site_name' => 'Vạn Lang'], $value);
    }

    public function testHitSkipsProducer(): void
    {
        $this->storage
            ->method('getItem')
            ->willReturnCallback(
                /** @param bool|null $success */
                static function (string $key, ?bool &$success = null) {
                    $success = true;

                    return serialize(['tu-cache']);
                }
            );
        $this->storage->expects(self::never())->method('setItem');

        $producerCalls = 0;
        $producer      = static function () use (&$producerCalls): string {
            $producerCalls++;

            return 'tu-producer';
        };

        self::assertSame('tu-cache', $this->service->remember('k', $producer));
        self::assertSame(0, $producerCalls);
    }

    public function testCachedFalseIsHitNotMiss(): void
    {
        // serialize bọc 1 phần tử phân biệt được "cache = false" với "key trống".
        $this->storage
            ->method('getItem')
            ->willReturnCallback(
                /** @param bool|null $success */
                static function (string $key, ?bool &$success = null) {
                    $success = true;

                    return serialize([false]);
                }
            );

        self::assertFalse($this->service->remember('k', static fn (): bool => true));
    }

    public function testEmptyRawCountsAsMiss(): void
    {
        $this->storage
            ->method('getItem')
            ->willReturnCallback(
                /** @param bool|null $success */
                static function (string $key, ?bool &$success = null) {
                    $success = true;

                    return '';
                }
            );

        self::assertSame('tinh-lai', $this->service->remember('k', static fn (): string => 'tinh-lai'));
    }

    public function testCorruptPayloadCountsAsMiss(): void
    {
        $this->storage
            ->method('getItem')
            ->willReturnCallback(
                /** @param bool|null $success */
                static function (string $key, ?bool &$success = null) {
                    $success = true;

                    return 'not-a-serialized-payload';
                }
            );

        self::assertSame(42, $this->service->remember('k', static fn (): int => 42));
    }

    public function testForgetRemovesKey(): void
    {
        $this->storage
            ->expects(self::once())
            ->method('removeItem')
            ->with(CacheConst::KEY_SETTINGS);

        $this->service->forget(CacheConst::KEY_SETTINGS);
    }

    public function testDegradesWithoutStorageEntry(): void
    {
        // Container không có 'page_cache' (unit test service khác dùng nền):
        // mỗi lần remember là một lần tính, forget là no-op an toàn.
        $service = (new PageCacheService())->setContainer(new TestContainer([]));

        $calls = 0;
        $producer = static function () use (&$calls): int {
            $calls++;

            return $calls;
        };

        self::assertSame(1, $service->remember('k', $producer));
        self::assertSame(2, $service->remember('k', $producer));
        $service->forget('k');
    }
}
