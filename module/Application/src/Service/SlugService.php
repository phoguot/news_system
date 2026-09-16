<?php

declare(strict_types=1);

namespace Application\Service;

/**
 * Sinh slug tiếng Việt không dấu (docs §3.2, FR-40): bỏ dấu, `đ→d`, ký tự
 * không phải a-z0-9 → `-`, gộp `-` lặp, cắt đầu/cuối. Không đụng DB — việc
 * "trùng thì thêm `-2`, `-3`" cần biết bảng nên ở tầng Service (chuẩn 07 §5).
 */
class SlugService
{
    /** Bảng bỏ dấu Unicode tiếng Việt tường minh (không phụ thuộc iconv/ICU). */
    private const DIACRITICS = [
        'à' => 'a', 'ả' => 'a', 'ã' => 'a', 'á' => 'a', 'ạ' => 'a',
        'ă' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ắ' => 'a', 'ặ' => 'a',
        'â' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ấ' => 'a', 'ậ' => 'a',
        'è' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'é' => 'e', 'ẹ' => 'e',
        'ê' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ế' => 'e', 'ệ' => 'e',
        'ì' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'í' => 'i', 'ị' => 'i',
        'ò' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ó' => 'o', 'ọ' => 'o',
        'ô' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ố' => 'o', 'ộ' => 'o',
        'ơ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ớ' => 'o', 'ợ' => 'o',
        'ù' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ú' => 'u', 'ụ' => 'u',
        'ư' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ứ' => 'u', 'ự' => 'u',
        'ỳ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ý' => 'y', 'ỵ' => 'y',
        'đ' => 'd',
        // Bản in hoa (slugify đã hạ ký tự ASCII trước, nhưng UTF-8 hoa chưa đổi case với strtolower đơn byte)
        'À' => 'a', 'Ả' => 'a', 'Ã' => 'a', 'Á' => 'a', 'Ạ' => 'a',
        'Ă' => 'a', 'Ằ' => 'a', 'Ẳ' => 'a', 'Ẵ' => 'a', 'Ắ' => 'a', 'Ặ' => 'a',
        'Â' => 'a', 'Ầ' => 'a', 'Ẩ' => 'a', 'Ẫ' => 'a', 'Ấ' => 'a', 'Ậ' => 'a',
        'È' => 'e', 'Ẻ' => 'e', 'Ẽ' => 'e', 'É' => 'e', 'Ẹ' => 'e',
        'Ê' => 'e', 'Ề' => 'e', 'Ể' => 'e', 'Ễ' => 'e', 'Ế' => 'e', 'Ệ' => 'e',
        'Ì' => 'i', 'Ỉ' => 'i', 'Ĩ' => 'i', 'Í' => 'i', 'Ị' => 'i',
        'Ò' => 'o', 'Ỏ' => 'o', 'Õ' => 'o', 'Ó' => 'o', 'Ọ' => 'o',
        'Ô' => 'o', 'Ồ' => 'o', 'Ổ' => 'o', 'Ỗ' => 'o', 'Ố' => 'o', 'Ộ' => 'o',
        'Ơ' => 'o', 'Ờ' => 'o', 'Ở' => 'o', 'Ỡ' => 'o', 'Ớ' => 'o', 'Ợ' => 'o',
        'Ù' => 'u', 'Ủ' => 'u', 'Ũ' => 'u', 'Ú' => 'u', 'Ụ' => 'u',
        'Ư' => 'u', 'Ừ' => 'u', 'Ử' => 'u', 'Ữ' => 'u', 'Ứ' => 'u', 'Ự' => 'u',
        'Ỳ' => 'y', 'Ỷ' => 'y', 'Ỹ' => 'y', 'Ý' => 'y', 'Ỵ' => 'y',
        'Đ' => 'd',
    ];

    /**
     * @param int $maxLength giới hạn độ dài theo cột (VARCHAR slug từng bảng)
     */
    public function slugify(string $text, int $maxLength = 255): string
    {
        $slug = strtolower($this->stripDiacritics($text));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim((string) preg_replace('/-{2,}/', '-', $slug), '-');

        if (strlen($slug) > $maxLength) {
            $slug = rtrim(substr($slug, 0, $maxLength), '-');
        }

        return $slug;
    }

    /**
     * Thêm hậu tố `-2`, `-3`… cho đến khi $exists() trả false (docs §3.2).
     *
     * @param callable(string): bool $exists kiểm tra trùng ở tầng Mapper/Service
     */
    public function unique(string $base, callable $exists, int $maxTries = 50): string
    {
        $base = $base === '' ? 'item' : $base;
        if (! $exists($base)) {
            return $base;
        }

        $suffix = 2;
        while ($suffix <= $maxTries && $exists($base . '-' . $suffix)) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /** Bỏ dấu Unicode tiếng Việt theo bảng ký tự tường minh (không phụ thuộc iconv/ICU). */
    private function stripDiacritics(string $text): string
    {
        return strtr($text, self::DIACRITICS);
    }
}
