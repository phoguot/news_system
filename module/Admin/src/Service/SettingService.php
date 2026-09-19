<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\ValidationException;
use Admin\Filter\Setting\SettingSaveFilter;
use Admin\Model\Media\MediaMapper;
use Application\Factory\AppServiceFactory;
use Frontend\Model\Setting\SettingConst;
use Frontend\Model\Setting\SettingMapper;
use Frontend\Model\Setting\SettingModel;
use Frontend\Service\SettingService as FrontendSettingService;

/**
 * Trang /admin/settings (docs §3.12 FR-39): xem + sửa hàng loạt giá trị theo
 * 4 nhóm đã seed. KHÔNG thêm/xoá key — danh mục cài đặt do seed.sql định nghĩa;
 * chỉ settingValue được ghi. Bảng `settings` do mapper của Frontend sở hữu
 * (05-cau-truc §4) — Admin dùng lại cùng class qua container gộp.
 * DI nền 07 §4 (13/09/2026): kế thừa AppServiceFactory, dependency lấy từ
 * container bằng getContainerEntry() đúng lúc dùng — constructor không nhận gì.
 *
 * Cache (FR-39 đã đóng 13/09/2026): luồng đọc công khai đi qua
 * Frontend\SettingService + PageCacheService TTL 60s; saveForm() invalidate
 * ngay sau khi ghi để giá trị mới xuất hiện tức thì.
 */
class SettingService extends AppServiceFactory
{
    private function settingMapper(): SettingMapper
    {
        /** @var SettingMapper */
        return $this->getContainerEntry(SettingMapper::class);
    }

    /** Tầng đọc + cache settings của Frontend — chỉ để invalidate sau khi ghi. */
    private function frontendSettings(): FrontendSettingService
    {
        /** @var FrontendSettingService */
        return $this->getContainerEntry(FrontendSettingService::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    /**
     * Ảnh trong thư viện media cho ô cài đặt `valueType=media` (07 §9.2 —
     * khung preview MediaPicker, cấm bắt người dùng gõ id; 07 §5 — Service
     * điều phối thêm mapper bảng khác, không join).
     *
     * @return list<array{id: int, label: string, path: string}>
     */
    public function mediaOptions(): array
    {
        return $this->mediaMapper()->listOptions();
    }

    /**
     * Nhóm theo groupCode giữ nguyên thứ tự groupCode + sortOrder của mapper.
     *
     * @return array<string, list<SettingModel>>
     */
    public function listGrouped(): array
    {
        $grouped = [];
        foreach ($this->settingMapper()->listAll() as $setting) {
            if ($setting->settingKey === SettingConst::KEY_MAP_EMBED_URL) {
                continue;
            }
            if ($setting->settingKey === SettingConst::KEY_MAP_ADDRESS) {
                $setting->label = 'Địa chỉ Google Map';
            }
            $grouped[$setting->groupCode][] = $setting;
        }

        return $grouped;
    }

    /**
     * Entry point form trang: chạy SettingSaveFilter (kèm CSRF) trên POST thô,
     * ghi từng dòng đổi giá trị. Rỗng → NULL; boolean rỗng → '0'.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException lỗi từng trường (fieldErrors của filter)
     */
    public function saveForm(array $raw, ?int $updatedBy): void
    {
        $settings = $this->settingMapper()->listAll();
        $filter   = new SettingSaveFilter($settings);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $filter->valuesByInputName();

        foreach ($settings as $setting) {
            $key   = 'setting_' . $setting->id;
            $input = $values[$key] ?? '';

            if ($input === '' && $setting->valueType !== SettingConst::VALUE_TYPE_BOOLEAN) {
                $new = null;
            } elseif ($setting->valueType === SettingConst::VALUE_TYPE_BOOLEAN) {
                $new = $input === '1' ? '1' : '0';
            } else {
                $new = $input;
            }

            if ($new === $setting->settingValue) {
                continue; // chỉ ghi dòng thực sự đổi
            }

            $this->settingMapper()->updateValue($setting->id, $new, $updatedBy);
        }

        // FR-39: mọi lần lưu (kể cả 0 dòng đổi — invalidate rẻ) đều ép tính lại
        // map settings cho luồng đọc công khai ở request kế.
        $this->frontendSettings()->invalidate();
    }

    /** Hash CSRF cho form cài đặt. */
    public function saveFormCsrfHash(): string
    {
        return (new SettingSaveFilter($this->settingMapper()->listAll()))->csrfHash();
    }
}
