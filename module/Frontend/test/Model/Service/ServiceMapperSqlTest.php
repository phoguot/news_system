<?php

declare(strict_types=1);

namespace FrontendTest\Model\Service;

use Frontend\Model\Service\ServiceMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL FR-08 (Dịch vụ):
 * - listActiveAllSelect: render WHERE isActive = 1, ORDER BY sortOrder ASC, id ASC.
 * - activeBySlugSelect: render WHERE slug = ? AND isActive = 1, LIMIT 1.
 */
final class ServiceMapperSqlTest extends TestCase
{
    private function mapper(): ServiceMapper
    {
        return new ServiceMapper($this->createMock(AdapterInterface::class));
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

    public function testListActiveAllRendersCorrectWhereAndOrder(): void
    {
        $rendered = $this->render($this->mapper()->listActiveAllSelect());

        self::assertStringContainsString('FROM `services`', $rendered);
        self::assertStringContainsString("`isActive` = '1'", $rendered);
        self::assertStringContainsString('ORDER BY `sortOrder` ASC, `id` ASC', $rendered);
    }

    public function testActiveBySlugRendersSlugAndActiveAndLimit(): void
    {
        $rendered = $this->render($this->mapper()->activeBySlugSelect('kham-tong-quat'));

        self::assertStringContainsString('FROM `services`', $rendered);
        self::assertStringContainsString("`slug` = 'kham-tong-quat'", $rendered);
        self::assertStringContainsString("`isActive` = '1'", $rendered);
        self::assertStringContainsString('LIMIT 1', $rendered);
    }

    public function testEmptySlugNeverTouchesDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('getDriver');
        $mapper = new ServiceMapper($adapter);

        self::assertNull($mapper->findActiveBySlug(''));
    }

    /** FR-11 (batch 18): sitemap chỉ lấy dịch vụ BẬT (dịch vụ tắt về 404 — FR-08). */
    public function testSitemapActiveSlugsSelectRendersFlagOnlyProjection(): void
    {
        $rendered = $this->render($this->mapper()->sitemapActiveSlugsSelect());

        self::assertStringContainsString('SELECT `services`.`slug` AS `slug` FROM `services`', $rendered);
        self::assertStringContainsString("`isActive` = '1'", $rendered);
        self::assertStringContainsString('ORDER BY `sortOrder` ASC, `id` ASC', $rendered);
        self::assertStringNotContainsString('JOIN', $rendered);
    }
}
