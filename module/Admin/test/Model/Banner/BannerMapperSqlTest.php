<?php

declare(strict_types=1);

namespace AdminTest\Model\Banner;

use Admin\Model\Banner\BannerConst;
use Admin\Model\Banner\BannerMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL (FR-31, 14/09) — `listActiveByPosition` là khuôn cửa sổ
 * thời gian của MỌI vị trí banner (news_top tái dùng từ home hero): isActive +
 * position + `(startAt IS NULL OR startAt <= UTC_TIMESTAMP())` +
 * `(endAt IS NULL OR endAt >= UTC_TIMESTAMP())`. Test render builder public
 * `activeByPositionSelect()` — không mock Driver/Statement (khuôn batch 10).
 */
final class BannerMapperSqlTest extends TestCase
{
    private function mapper(): BannerMapper
    {
        // Mock adapter chỉ để thỏa constructor — builder không đụng DB.
        return new BannerMapper($this->createMock(AdapterInterface::class));
    }

    /** Dựng SQL string bằng platform Mysql thật, adapter mock không đụng driver. */
    private function render(Select $select): string
    {
        // quoteValue() mặc định trigger notice "without extension/driver support"
        // — driverless render chỉ cần quoting thuần nên chuyển sang quoteTrustedValue.
        /** @psalm-suppress PropertyNotSetInConstructor */
        $platform = new class extends Mysql {
            public function quoteValue($value)
            {
                return $this->quoteTrustedValue($value);
            }
        };
        $adapter  = $this->createMock(AdapterInterface::class);
        $adapter->method('getPlatform')->willReturn($platform);

        return (new Sql($adapter))->buildSqlString($select);
    }

    public function testNewsTopSelectRendersPositionWindowAndLimit(): void
    {
        $rendered = $this->render(
            $this->mapper()->activeByPositionSelect(BannerConst::POSITION_NEWS_TOP, 1)
        );

        self::assertStringContainsString('FROM `banners`', $rendered);
        self::assertStringContainsString('`position` = \'news_top\'', $rendered);
        self::assertStringContainsString('`isActive` = \'1\'', $rendered);
        // Hai vế cửa sổ NULL-mở — nest() phải sinh cặp ngoặc OR riêng.
        self::assertStringContainsString('(`startAt` IS NULL OR `startAt` <= UTC_TIMESTAMP())', $rendered);
        self::assertStringContainsString('(`endAt` IS NULL OR `endAt` >= UTC_TIMESTAMP())', $rendered);
        self::assertStringContainsString('ORDER BY `sortOrder` ASC, `id` ASC', $rendered);
        self::assertStringContainsString('LIMIT 1', $rendered);
    }

    public function testHomeHeroSelectHasNoLimit(): void
    {
        $rendered = $this->render(
            $this->mapper()->activeByPositionSelect(BannerConst::POSITION_HOME_HERO)
        );

        self::assertStringContainsString('`position` = \'home_hero\'', $rendered);
        // Slider home đọc TOÀN BỘ banner trong cửa sổ — không được LIMIT.
        self::assertStringNotContainsString('LIMIT', $rendered);
    }
}
