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
 * Regression SQL cho nhánh đọc công khai FR-02 (`listPublishedPage` +
 * `countPublished`): scope `status` đã xuất bản + `publishedAt` <=
 * UTC_TIMESTAMP(), lọc `categoryId` bằng Where::in() THẬT (không phải key
 * mảng kết hợp '… IN' hỏng — xem CategoryMapperSqlTest), LIMIT/OFFSET phân
 * trang. Builder `public` để render không cần DB (khuôn 3 file SQL
 * test batch 10).
 */
final class PostMapperPublicReadSqlTest extends TestCase
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

    public function testSecondPageWithCategoryFilter(): void
    {
        $rendered = $this->render($this->mapper()->publicSelect([4], false, [], 12, 12));

        self::assertStringContainsString('FROM `posts`', $rendered);
        // Scope công khai: status đã xuất bản + không hẹn tương lai.
        self::assertStringContainsString("`status` = '1'", $rendered);
        self::assertStringContainsString('`publishedAt` <= UTC_TIMESTAMP()', $rendered);
        // Lọc danh mục bằng IN thật, không doubled.
        self::assertStringContainsString('`categoryId` IN (', $rendered);
        self::assertStringNotContainsString('`IN` IN', $rendered);
        // Phân trang + order newest-first.
        self::assertStringContainsString('LIMIT 12', $rendered);
        self::assertStringContainsString('OFFSET 12', $rendered);
        self::assertStringContainsString('ORDER BY `publishedAt` DESC, `id` DESC', $rendered);
    }

    public function testFirstPageWithoutFilterHasNoOffsetNoCategoryPredicate(): void
    {
        $rendered = $this->render($this->mapper()->publicSelect(null, false, [], 12, 0));

        self::assertStringContainsString('LIMIT 12', $rendered);
        self::assertStringNotContainsString('OFFSET', $rendered);
        self::assertStringNotContainsString('`categoryId` IN', $rendered);
    }

    public function testFeaturedVariantAddsFlagPredicate(): void
    {
        $rendered = $this->render($this->mapper()->publicSelect(null, true, [], 6, 0));

        self::assertStringContainsString("`isFeatured` = '1'", $rendered);
    }

    public function testCountPublishedMatchesScopeAndSkipsPagingClauses(): void
    {
        $filtered = $this->render($this->mapper()->countPublishedSelect([4, 5]));

        self::assertStringContainsString('COUNT(*)', $filtered);
        self::assertStringContainsString("`status` = '1'", $filtered);
        self::assertStringContainsString('`categoryId` IN (', $filtered);
        // Đếm cho phân trang: không LIMIT/OFFSET/ORDER.
        self::assertStringNotContainsString('LIMIT', $filtered);
        self::assertStringNotContainsString('OFFSET', $filtered);

        $all = $this->render($this->mapper()->countPublishedSelect(null));

        self::assertStringContainsString('COUNT(*)', $all);
        self::assertStringNotContainsString('`categoryId` IN', $all);
    }
}
