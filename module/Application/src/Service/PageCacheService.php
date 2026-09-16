<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Laminas\Cache\Storage\StorageInterface;

/**
 * Nền tầng cache hiệu năng cho "dữ liệu đọc nhiều, đổi ít" (FR-39, NFR-PERF-1 —
 * docs 00-tong-quan/03 §Cache): bọc storage `page_cache` (Filesystem
 * `data/cache/page`, TTL 60s, plugin exceptionhandler `throw_exceptions=false`
 * — cấu hình trong `global.php`).
 *
 * - `remember($key, $producer)`: đọc cache; miss thì tính từ nguồn rồi ghi lại.
 * - `forget($key)`: xoá khoá khi nguồn dữ liệu vừa thay đổi (service Admin ghi).
 *
 * Giá trị được serialize bọc trong mảng 1 phần tử trước khi ghi vì adapter
 * Filesystem v3 chỉ nhận string (plugin `serializer` của v2 cần package
 * laminas-serializer — không thêm dependency chỉ để làm việc này). Nhờ vậy
 * mọi giá trị PHP serialize được (scalar, array — kể cả false/null) đều
 * phân biệt rõ "hit giá trị đó" với "cache miss". KHÔNG cache object.
 *
 * Cache chỉ là tăng tốc, KHÔNG phải dependency cứng: storage thiếu trong
 * container (test/CLI), cache_dir bị xoá, hay lỗi IO (exceptionhandler nuốt)
 * đều rơi về tính trực tiếp từ nguồn. DI nền 07 §4: kế thừa AppServiceFactory,
 * storage lấy bằng getContainerEntry(CacheConst::SERVICE_PAGE_CACHE).
 */
class PageCacheService extends AppServiceFactory
{
    private function storage(): ?StorageInterface
    {
        /** @var StorageInterface|null */
        return $this->getContainerEntry(CacheConst::SERVICE_PAGE_CACHE);
    }

    /**
     * Trả giá trị cache cho $key; miss → gọi $producer tính từ nguồn và ghi cache.
     *
     * @template T
     *
     * @param callable(): T $producer
     *
     * @return T
     */
    public function remember(string $key, callable $producer)
    {
        $storage = $this->storage();
        if ($storage !== null) {
            $hit = $this->read($storage, $key);
            if ($hit !== null) {
                /** @var T */
                return $hit[0];
            }
        }

        $value = $producer();

        if ($storage !== null) {
            $storage->setItem($key, serialize([$value]));
        }

        return $value;
    }

    /** Xoá một khoá cache — gọi ngay sau khi nguồn dữ liệu được ghi thành công. */
    public function forget(string $key): void
    {
        $this->storage()?->removeItem($key);
    }

    /**
     * Đọc + giải mã payload; null = cache miss (không có key / lỗi giải mã).
     * Mảng 1 phần tử do remember() ghi — cho phép phân biệt hit-giá-trị-null
     * với miss thật.
     *
     * @return array{0: mixed}|null
     */
    private function read(StorageInterface $storage, string $key): ?array
    {
        $success = false;
        /** @psalm-suppress MixedAssignment — storage API trả mixed */
        $raw = $storage->getItem($key, $success);
        if ($success !== true || ! is_string($raw) || ! str_starts_with($raw, 'a:1:')) {
            // 'a:1:' = prefix serialize([value]) do remember() ghi — chuỗi rác
            // hoặc định dạng cũ → coi là miss, không đưa vào unserialize.
            return null;
        }

        /** @var string $raw — đã qua is_string ở guard trên */
        $decoded = unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($decoded) || ! array_key_exists(0, $decoded)) {
            return null;
        }

        /** @var array{0: mixed} */
        return $decoded;
    }
}
