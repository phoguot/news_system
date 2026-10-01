<?php

declare(strict_types=1);

namespace FrontendTest\Model\SiteVisitDaily;

use Frontend\Model\SiteVisitDaily\SiteVisitDailyMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\DriverInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Platform\PlatformInterface;
use PHPUnit\Framework\TestCase;

final class SiteVisitDailyMapperSqlTest extends TestCase
{
    public function testIncrementUsesMariaDbCompatibleUpsert(): void
    {
        $adapter   = $this->createMock(AdapterInterface::class);
        $driver    = $this->createMock(DriverInterface::class);
        $platform  = $this->createMock(PlatformInterface::class);
        $statement = $this->createMock(StatementInterface::class);
        $result    = $this->createMock(ResultInterface::class);

        $adapter->method('getPlatform')->willReturn($platform);
        $adapter->method('getDriver')->willReturn($driver);
        $platform->method('quoteIdentifier')->with('site_visit_daily')->willReturn('`site_visit_daily`');
        $driver->expects(self::once())->method('createStatement')->with(self::callback(
            static function (string $sql): bool {
                self::assertStringNotContainsString('AS newRow', $sql);
                self::assertStringContainsString(
                    'ON DUPLICATE KEY UPDATE visitors = `site_visit_daily`.visitors + 1',
                    $sql
                );

                return true;
            }
        ))->willReturn($statement);
        $statement->expects(self::once())->method('prepare');
        $statement->expects(self::once())->method('execute')->willReturn($result);

        (new SiteVisitDailyMapper($adapter))->incrementToday();
    }
}
