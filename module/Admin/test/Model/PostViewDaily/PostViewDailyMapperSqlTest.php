<?php

declare(strict_types=1);

namespace AdminTest\Model\PostViewDaily;

use Admin\Model\PostViewDaily\PostViewDailyMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\DriverInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Platform\PlatformInterface;
use PHPUnit\Framework\TestCase;

final class PostViewDailyMapperSqlTest extends TestCase
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
        $platform->method('quoteIdentifier')->with('post_view_daily')->willReturn('`post_view_daily`');
        $driver->expects(self::once())->method('createStatement')->with(self::callback(
            static function (string $sql): bool {
                self::assertStringNotContainsString('AS newRow', $sql);
                self::assertStringContainsString(
                    'ON DUPLICATE KEY UPDATE views = `post_view_daily`.views + 1',
                    $sql
                );

                return true;
            }
        ))->willReturn($statement);
        $statement->expects(self::once())->method('prepare');
        $statement->expects(self::once())->method('execute')->with([42])->willReturn($result);

        (new PostViewDailyMapper($adapter))->increment(42);
    }
}
