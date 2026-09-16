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
 * Regression SQL FR-06 (batch 14) — nhánh "/tag/{slug}": đếm theo tập id từ
 * post_tags phải dùng `Where::in('id', …)` THẬT (bug latent `['id IN' => …]`
 * của batch 10 không được quay lại) và giữ nguyên scope công khai; tập id
 * rỗng phải chặn TRƯỚC mọi query (IN () hỏng SQL trên MySQL).
 */
final class PostMapperPublishedByIdsSqlTest extends TestCase
{
    private function mapper(): PostMapper
    {
        // Mock adapter chỉ để thỏa constructor — builder không đụng DB.
        return new PostMapper($this->createMock(AdapterInterface::class));
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

    public function testCountByIdsRendersRealInPlusPublicScope(): void
    {
        $rendered = $this->render($this->mapper()->countPublishedByIdsSelect([3, 5]));

        self::assertStringContainsString('FROM `posts`', $rendered);
        self::assertStringContainsString('`id` IN (', $rendered);
        self::assertStringContainsString('COUNT(*)', $rendered);
        // Scope công khai phải còn (status + cửa sổ hẹn giờ) — FR-03/FR-04 đã chốt.
        self::assertStringContainsString('`status`', $rendered);
        self::assertStringContainsString('UTC_TIMESTAMP()', $rendered);
        // Dấu hiệu bug cũ: `IN` bị doubled như giá trị cột.
        self::assertStringNotContainsString('`IN` IN', $rendered);
        // Không được lẫn bộ lọc danh mục khi gọi theo id.
        self::assertStringNotContainsString('`categoryId`', $rendered);
    }

    public function testEmptyIdSetsNeverTouchDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        // Chuỗi execute thật bắt đầu bằng getDriver() — never() = không có query.
        $adapter->expects(self::never())->method('getDriver');
        $mapper = new PostMapper($adapter);

        self::assertSame(0, $mapper->countPublishedByIds([]));
        self::assertSame([], $mapper->listPublishedPageByIds([], 12, 0));
    }
}
