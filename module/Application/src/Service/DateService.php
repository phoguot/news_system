<?php

declare(strict_types=1);

namespace Application\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Nguồn duy nhất về mốc thời gian UTC (áp dụng pattern `getTimeStampsCurrent`
 * của webapp-be — docs-dev/01-quy-chuan/08-vi-du-sai-dung.md §5). DB lưu chuỗi
 * UTC 'Y-m-d H:i:s' (docs §4.1); ranh giới API dùng ISO 8601 chuyển qua đây.
 * Helper tĩnh, không dependency — không đăng ký container.
 */
final class DateService
{
    /** Định dạng chuỗi thời gian lưu trong DB (UTC). */
    public const FORMAT_DB = 'Y-m-d H:i:s';

    /** Hiện tại UTC theo định dạng lưu DB. */
    public static function nowUtc(): string
    {
        return gmdate(self::FORMAT_DB);
    }

    /**
     * Chuỗi 'Y-m-d H:i:s' (UTC ngầm định) → unix timestamp; null nếu sai format.
     */
    public static function timestampUtc(string $utc): ?int
    {
        $ts = strtotime($utc . ' UTC');

        return $ts === false ? null : $ts;
    }

    /**
     * Cộng N phút vào mốc UTC 'Y-m-d H:i:s'; null nếu mốc đầu vào sai format.
     * Dùng cho khoá tài khoản (AdminAuthService, docs §3.11).
     */
    public static function plusMinutesUtc(string $utc, int $minutes): ?string
    {
        $ts = self::timestampUtc($utc);
        if ($ts === null) {
            return null;
        }

        return gmdate(self::FORMAT_DB, $ts + $minutes * 60);
    }

    /**
     * ISO 8601 khai báo ở API (docs-dev/01-quy-chuan/05 §1) → chuỗi UTC DB,
     * ví dụ '2026-09-12T03:00:00Z' hoặc '2026-09-12 03:00:00' (coi là UTC sẵn).
     * NULL/rỗng → null; SAI định dạng → null (lõi không biết tên field —
     * caller quyết định thông báo validate của mình).
     */
    public static function isoToUtc(mixed $input): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        $value = trim($input);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            return null;
        }

        if (str_ends_with($value, 'Z')) {
            return substr(str_replace('T', ' ', $value), 0, 19);
        }

        $dt = new DateTimeImmutable(str_replace(' ', 'T', $value));

        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT_DB);
    }
}
