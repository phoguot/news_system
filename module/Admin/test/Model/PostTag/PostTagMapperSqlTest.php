<?php

declare(strict_types=1);

namespace AdminTest\Model\PostTag;

use Admin\Model\PostTag\PostTagMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL (FR-03, batch 11) — `sharedTagCounts` là bước 1 của bài liên
 * quan docs §5.4, chạy single-table trên `post_tags` (luật 1 mapper = 1 bảng,
 * không JOIN posts/tags). Builder-render theo khuôn batch 10: public
 * `sharedTagCountsSelect()` render không cần DB.
 */
final class PostTagMapperSqlTest extends TestCase
{
    private function mapper(): PostTagMapper
    {
        return new PostTagMapper($this->createMock(AdapterInterface::class));
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

    public function testSharedTagCountsRendersInNotEqualGroupCount(): void
    {
        $rendered = $this->render($this->mapper()->sharedTagCountsSelect(1, [11, 22]));

        self::assertStringContainsString('FROM `post_tags`', $rendered);
        self::assertStringContainsString('`tagId` IN (', $rendered);
        self::assertStringContainsString('`postId` != ', $rendered);
        self::assertStringContainsString('COUNT(*)', $rendered);
        self::assertStringContainsString('AS `sharedCount`', $rendered);
        self::assertStringContainsString('GROUP BY', $rendered);
        // Không được sinh JOIN chéo — chỉ đúng bảng post_tags xuất hiện một lần.
        self::assertStringNotContainsString('JOIN', $rendered);
    }

    public function testEmptyTagIdsNeverTouchesDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('getDriver');

        self::assertSame([], (new PostTagMapper($adapter))->sharedTagCounts(1, []));
    }
}
