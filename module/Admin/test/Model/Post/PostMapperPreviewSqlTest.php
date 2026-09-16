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
 * Regression SQL (FR-04, batch 12) — `findBySlugPreviewToken` là nhánh xem
 * trước BỎ QUA scope công khai (docs §5.7): WHERE chỉ có 2 equalTo bind +
 * LIMIT 1, tuyệt đối không được rò `status`/`publishedAt` (nếu có, bài hẹn
 * giờ không xem được preview). Builder public render theo khuôn batch 10.
 */
final class PostMapperPreviewSqlTest extends TestCase
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

    public function testPreviewSelectIsSlugPlusTokenWithoutPublicScope(): void
    {
        $rendered = $this->render(
            $this->mapper()->slugPreviewTokenSelect('bai-nhap', 'aabbccddeeff00112233445566778899')
        );

        self::assertStringContainsString('FROM `posts`', $rendered);
        self::assertStringContainsString('`slug` = ', $rendered);
        self::assertStringContainsString('`previewToken` = ', $rendered);
        self::assertStringContainsString('LIMIT 1', $rendered);
        // Điểm phân biệt với findPublishedBySlug: KHÔNG có scope công khai.
        self::assertStringNotContainsString('`status`', $rendered);
        self::assertStringNotContainsString('UTC_TIMESTAMP()', $rendered);
    }

    public function testEmptyTokenShortCircuitsWithoutDatabase(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('getDriver');

        self::assertNull((new PostMapper($adapter))->findBySlugPreviewToken('bai-nhap', ''));
    }
}
