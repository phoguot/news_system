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
 * Regression SQL FR-07 — tìm kiếm toàn văn tiếng Việt ("/tim-kiem?q="):
 * SELECT phải chứa MATCH(title, excerpt) AGAINST (:keyword IN NATURAL LANGUAGE MODE),
 * giữ nguyên scope công khai (status=1 AND publishedAt <= UTC_TIMESTAMP()),
 * order theo score DESC, publishedAt DESC, id DESC, LIMIT 20;
 * từ khoá rỗng hoặc < 2 ký tự phải chặn TRƯỚC mọi query.
 */
final class PostMapperSearchSqlTest extends TestCase
{
    private function mapper(): PostMapper
    {
        return new PostMapper($this->createMock(AdapterInterface::class));
    }

    /** Dựng SQL string bằng platform Mysql thật, adapter mock không đụng driver. */
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

    public function testSearchRendersMatchAgainstPlusPublicScopeAndOrder(): void
    {
        $rendered = $this->render($this->mapper()->searchPublishedSelect('tuyển sinh', 20));

        self::assertStringContainsString('FROM `posts`', $rendered);
        self::assertStringContainsString('MATCH(title, excerpt) AGAINST (', $rendered);
        self::assertStringContainsString('NATURAL LANGUAGE MODE', $rendered);
        self::assertStringContainsString('score', $rendered);
        self::assertStringContainsString('`status`', $rendered);
        self::assertStringContainsString('UTC_TIMESTAMP()', $rendered);
        self::assertStringContainsString('score DESC', $rendered);
        self::assertStringContainsString('`publishedAt` DESC', $rendered);
        self::assertStringContainsString('LIMIT 20', $rendered);
    }

    public function testEmptyOrShortKeywordNeverTouchesDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('getDriver');
        $mapper = new PostMapper($adapter);

        self::assertSame([], $mapper->searchPublished(''));
        self::assertSame([], $mapper->searchPublished('   '));
        self::assertSame([], $mapper->searchPublished('a'));
    }
}
