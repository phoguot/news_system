<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Service\DateService;
use PHPUnit\Framework\TestCase;

final class DateServiceTest extends TestCase
{
    public function testNowUtcMatchesDbFormat(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            DateService::nowUtc()
        );
    }

    public function testTimestampUtcRoundTrip(): void
    {
        self::assertIsInt(DateService::timestampUtc('2026-09-12 03:00:00'));
        self::assertNull(DateService::timestampUtc('12/09/2026 9h'));
    }

    public function testPlusMinutesUtcCrossesHourAndDay(): void
    {
        self::assertSame(
            '2026-09-12 04:45:00',
            DateService::plusMinutesUtc('2026-09-12 03:15:00', 90)
        );
        self::assertSame(
            '2026-09-13 00:05:00',
            DateService::plusMinutesUtc('2026-09-12 23:50:00', 15)
        );
        self::assertNull(DateService::plusMinutesUtc('sai', 15));
    }

    public function testIsoToUtcHandlesZOffsetAndPlainForms(): void
    {
        self::assertSame('2026-09-12 03:00:00', DateService::isoToUtc('2026-09-12T03:00:00Z'));
        self::assertSame('2026-09-12 03:00:00', DateService::isoToUtc('2026-09-12 03:00:00'));
        self::assertSame('2026-09-12 02:30:00', DateService::isoToUtc('2026-09-12T09:00:00+06:30'));
    }

    public function testIsoToUtcLenientReturnsNull(): void
    {
        self::assertNull(DateService::isoToUtc(null));
        self::assertNull(DateService::isoToUtc('  '));
        self::assertNull(DateService::isoToUtc('12/09/2026 9h'));
        self::assertNull(DateService::isoToUtc(123));
    }
}
