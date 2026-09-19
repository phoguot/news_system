<?php

declare(strict_types=1);

namespace Frontend\Service;

use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\PageCacheService;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingMapper;

/**
 * Tầng ĐỌC cài đặt site kèm cache 60s (FR-39, NFR-PERF-1 — docs §3.12,
 * 00-tong-quan/03 §Cache): thay cho việc mỗi request gọi thẳng
 * SettingMapper::getValue. Service công khai (contact notify, sau này là
 * layout/sitemap) lấy giá trị qua đây; toàn bộ map được tính một lần rồi nằm
 * trong `page_cache`.
 *
 * Vô hiệu hoá: `Admin\Service\SettingService::saveForm()` gọi invalidate() sau
 * khi ghi — giá trị mới xuất hiện ngay, không đợi TTL.
 *
 * Bảng `settings` do Frontend sở hữu (05-cau-truc §4). DI nền 07 §4:
 * AppServiceFactory + typed accessor; PageCacheService thiếu trong container
 * (unit test không quan tâm cache) thì đọc thẳng DB.
 */
class SettingService extends AppServiceFactory
{
    private function settingMapper(): SettingMapper
    {
        /** @var SettingMapper */
        return $this->getContainerEntry(SettingMapper::class);
    }

    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /**
     * Toàn bộ cài đặt dạng map `settingKey => settingValue` (NULL = chưa cấu hình).
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        $cache = $this->pageCache();
        if ($cache === null) {
            return $this->loadFromDb();
        }

        return $cache->remember(CacheConst::KEY_SETTINGS, fn (): array => $this->loadFromDb());
    }

    /** Giá trị string của một key (SettingConst::KEY_*); rỗng/NULL → null. */
    public function stringOrNull(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Ép tính lại map từ DB ở request kế (Admin vừa ghi settings). */
    public function invalidate(): void
    {
        $this->pageCache()?->forget(CacheConst::KEY_SETTINGS);
    }

    /**
     * URL nhúng Google Maps: Admin chỉ nhập một ô địa chỉ Google Map
     * (`map_address`); nếu trống thì dùng địa chỉ liên hệ chung. `map_embed_url`
     * chỉ giữ tương thích dữ liệu cũ khi cả hai địa chỉ đều trống.
     */
    public function mapEmbedUrl(): ?string
    {
        $settings = $this->all();
        $valueOf  = static function (string $key) use ($settings): ?string {
            $value = $settings[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $addr = $valueOf(SettingConst::KEY_MAP_ADDRESS) ?? $valueOf(SettingConst::KEY_ADDRESS);
        if ($addr === null) {
            return $valueOf(SettingConst::KEY_MAP_EMBED_URL);
        }

        return 'https://www.google.com/maps?q=' . rawurlencode($addr) . '&output=embed';
    }

    /**
     * @return array<string, string|null>
     */
    private function loadFromDb(): array
    {
        $map = [];
        foreach ($this->settingMapper()->listAll() as $setting) {
            $map[$setting->settingKey] = $setting->settingValue;
        }

        return $map;
    }
}
