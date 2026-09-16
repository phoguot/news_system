<?php

declare(strict_types=1);

namespace Application\View\Helper;

/**
 * Đường dẫn URL công khai của file media (thư mục uploads + path tương đối).
 *
 * FR-19: tham số $variant từng tồn tại nhưng bị BỎ QUA — path biến thể không
 * suy ra an toàn từ path gốc (chỉ sinh khi ảnh gốc đủ rộng; lưu trong cột
 * `variants` JSON). Việc "bóc biến thể" làm ở tầng dữ liệu:
 * `MediaMapper::mapCardsByIds()` trả sẵn `thumb` (biến thể nếu có, không thì
 * path gốc) — service chọn trỏ vào đó trước khi gọi helper này.
 *
 * Không extends AbstractHelper (deprecated trong laminas-view — khuôn
 * SelectField): HelperPluginManager chỉ cần một callable.
 */
final class MediaUrl
{
    public function __invoke(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }
        return '/uploads/' . ltrim($path, '/');
    }
}
