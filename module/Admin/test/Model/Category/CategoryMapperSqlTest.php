<?php

declare(strict_types=1);

namespace AdminTest\Model\Category;

use Admin\Model\Category\CategoryMapper;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Platform\Mysql;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Regression SQL (FR-02, batch 10) — `getNamesByIds` từng dựng WHERE bằng key
 * mảng kết hợp `['id IN' => $ids]`: laminas-db KHÔNG parse ra toán tử mà coi
 * `id IN` là tên cột, sinh `WHERE \`id\` \`IN\` IN (…)` — 500 ngay khi DB có dữ
 * liệu thật (PostListService FR-02 là caller đầu tiên chạm nhánh này; Admin
 * PostService list/detail cũng gọi cùng method).
 *
 * Test render SQL trực tiếp từ builder `namesSelect()` (public) —
 * không mock chuỗi Driver/Statement/ResultSet (prepareStatementForSqlObject
 * nằm ở Sql chứ không phải AdapterInterface, ResultSetInterface extends
 * Iterator nên getIterator không mock được; hướng builder-render là khuôn
 * ổn định cho mapper-read — 3 file SQL test batch 10).
 */
final class CategoryMapperSqlTest extends TestCase
{
    private function mapper(): CategoryMapper
    {
        // Mock adapter chỉ để thỏa constructor — builder không đụng DB.
        return new CategoryMapper($this->createMock(AdapterInterface::class));
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

    public function testNamesSelectRendersRealInPredicate(): void
    {
        $rendered = $this->render($this->mapper()->namesSelect([11, 22]));

        self::assertStringContainsString('FROM `categories`', $rendered);
        self::assertStringContainsString('`id` IN (', $rendered);
        // Dấu hiệu bug cũ: `IN` bị doubled như giá trị cột.
        self::assertStringNotContainsString('`IN` IN', $rendered);
    }

    public function testEmptyIdsNeverTouchesDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        // Chuỗi execute thật bắt đầu bằng getDriver() — never() = không có query.
        $adapter->expects(self::never())->method('getDriver');

        self::assertSame([], (new CategoryMapper($adapter))->getNamesByIds([]));
    }

    /**
     * FR-05 (batch 13): nhánh §5.3 `parentId = :cat AND isActive = 1` — một
     * bảng, không JOIN (luật 07 §5), sort giống cây quản trị.
     */
    public function testActiveChildIdsSelectRendersParentPlusFlagWithoutJoin(): void
    {
        $rendered = $this->render($this->mapper()->activeChildIdsSelect(7));

        self::assertStringContainsString('FROM `categories`', $rendered);
        self::assertStringContainsString('`parentId` = ', $rendered);
        self::assertStringContainsString('`isActive` = ', $rendered);
        self::assertStringContainsString('ORDER BY `sortOrder` ASC', $rendered);
        self::assertStringNotContainsString('JOIN', $rendered);
    }

    /**
     * FR-11 (batch 18): sitemap chỉ lấy danh mục ĐANG BẬT — trang của danh mục
     * tắt trả 404 (FR-05) nên không được lộ trong sitemap.xml.
     */
    public function testSitemapActiveSlugsSelectRendersFlagOnlyProjection(): void
    {
        $rendered = $this->render($this->mapper()->sitemapActiveSlugsSelect());

        self::assertStringContainsString('SELECT `categories`.`slug` AS `slug` FROM `categories`', $rendered);
        self::assertStringContainsString("`isActive` = '1'", $rendered);
        self::assertStringContainsString('ORDER BY `sortOrder` ASC, `id` ASC', $rendered);
        self::assertStringNotContainsString('JOIN', $rendered);
    }
}
