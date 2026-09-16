<?php

declare(strict_types=1);

namespace AdminTest\Model\Post;

use Admin\Model\Post\PostMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL FR-11 (sitemap, batch 18) — `sitemapPublishedSelect` phải
 * mang ĐÚNG publicScope (nháp · hẹn giờ tương lai · archived loại — go-live
 * checklist §SEO) và chỉ chiếu `slug`/`updatedAt`.
 *
 * Khuôn render builder driverless (batch 10/14/15): không mock
 * Driver/Statement/ResultSet.
 */
final class PostMapperSitemapSqlTest extends TestCase
{
    private function mapper(): PostMapper
    {
        return new PostMapper($this->createMock(AdapterInterface::class));
    }

    private function render(Select $select): string
    {
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

    public function testSitemapSelectCarriesPublicScopeAndSlugProjection(): void
    {
        $rendered = $this->render($this->mapper()->sitemapPublishedSelect());

        self::assertStringContainsString('FROM `posts`', $rendered);
        // publicScope: status=PUBLISHED + publishedAt <= UTC_TIMESTAMP() (hẹn giờ tương lai loại).
        self::assertStringContainsString("`status` = '1'", $rendered);
        self::assertStringContainsString('`publishedAt` <= UTC_TIMESTAMP()', $rendered);
        // Chiếu sitemap: đúng 2 cột, không kéo `content`.
        self::assertStringContainsString('`slug`', $rendered);
        self::assertStringContainsString('`updatedAt`', $rendered);
        self::assertStringNotContainsString('`content`', $rendered);
        self::assertStringNotContainsString('JOIN', $rendered);
    }
}
