<?php

declare(strict_types=1);

namespace AdminTest\Model\Tag;

use Admin\Model\Tag\TagMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL (FR-02, batch 10) — cùng bug latent với CategoryMapper:
 * `['id IN' => $ids]` làm key mảng kết hợp không được laminas-db parse ra
 * toán tử (xem CategoryMapperSqlTest). `namesSelect()` public để
 * render được không cần DB.
 */
final class TagMapperSqlTest extends TestCase
{
    private function mapper(): TagMapper
    {
        return new TagMapper($this->createMock(AdapterInterface::class));
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

    public function testNamesSelectRendersRealInPredicate(): void
    {
        $rendered = $this->render($this->mapper()->namesSelect([7]));

        self::assertStringContainsString('FROM `tags`', $rendered);
        self::assertStringContainsString('`id` IN (', $rendered);
        self::assertStringNotContainsString('`IN` IN', $rendered);
    }

    public function testEmptyIdsNeverTouchesDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('getDriver');

        self::assertSame([], (new TagMapper($adapter))->getNamesByIds([]));
    }
}
