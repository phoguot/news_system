<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Service\SlugService;
use PHPUnit\Framework\TestCase;

final class SlugServiceTest extends TestCase
{
    private SlugService $service;

    protected function setUp(): void
    {
        $this->service = new SlugService();
    }

    public function testSlugifyVietnameseDiacritics(): void
    {
        self::assertSame(
            'ha-noi-dia-diem-hot',
            $this->service->slugify('Hà Nội địa điểm HOT')
        );
    }

    public function testSlugifyHandlesDAndUnderscores(): void
    {
        self::assertSame('dai-dang-xa-hoi', $this->service->slugify('Đại_Đặng xã hội'));
        self::assertSame('du-doi', $this->service->slugify('Đủ đôi'));
    }

    public function testSlugifyStripsPunctuationAndSqueezesDashes(): void
    {
        self::assertSame('tin-nong-2026', $this->service->slugify('  Tin nóng!!!  2026  '));
    }

    public function testSlugifyTruncatesToMaxLength(): void
    {
        $slug = $this->service->slugify(str_repeat('abcdef ', 50), 40);

        self::assertLessThanOrEqual(40, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public function testSlugifyEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->service->slugify('!!! ??? ###'));
    }

    public function testUniqueAppendsSuffixUntilFree(): void
    {
        $taken = ['tin-a', 'tin-a-2'];
        $slug  = $this->service->unique(
            'tin-a',
            static fn (string $candidate): bool => in_array($candidate, $taken, true)
        );

        self::assertSame('tin-a-3', $slug);
    }

    public function testUniqueReturnsBaseWhenFree(): void
    {
        self::assertSame(
            'slug-dep',
            $this->service->unique('slug-dep', static fn (): bool => false)
        );
    }
}
