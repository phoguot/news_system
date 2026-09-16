<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagConst;
use Admin\Model\Tag\TagMapper;
use Admin\Model\Tag\TagModel;
use Admin\Service\TagService;
use Application\Service\DbService;
use Application\Service\SlugService;
use ApplicationTest\Helper\TestContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TagServiceTest extends TestCase
{
    private TagMapper&MockObject $tags;
    private PostTagMapper&MockObject $postTags;
    private SlugService&MockObject $slugs;
    private DbService&MockObject $db;
    private TagService $service;

    protected function setUp(): void
    {
        $this->tags     = $this->createMock(TagMapper::class);
        $this->postTags = $this->createMock(PostTagMapper::class);
        $this->slugs    = $this->createMock(SlugService::class);
        $this->db       = $this->createMock(DbService::class);

        $this->slugs->method('slugify')->willReturnMap([
            ['Đà Lạt', TagConst::MAX_LENGTH_SLUG, 'da-lat'],
            ['Ẩm thực', TagConst::MAX_LENGTH_SLUG, 'am-thuc'],
            ['Hà Nội', TagConst::MAX_LENGTH_SLUG, 'ha-noi'],
        ]);
        $this->slugs->method('unique')
            ->willReturnCallback(static fn (string $base): string => $base);

        // transactional chạy callback ngay (không có DB thật trong unit test)
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );

        $this->service = (new TagService())->setContainer(new TestContainer([
            TagMapper::class     => $this->tags,
            PostTagMapper::class => $this->postTags,
            SlugService::class   => $this->slugs,
            DbService::class     => $this->db,
        ]));
    }

    public function testListWithCountsMergesCountsWithoutJoin(): void
    {
        $this->tags->method('listAll')->willReturn([
            TagModel::fromRow(['id' => 1, 'name' => 'Hà Nội', 'slug' => 'ha-noi']),
            TagModel::fromRow(['id' => 2, 'name' => 'Ăn uống', 'slug' => 'an-uong']),
        ]);
        $this->postTags->method('countsAll')->willReturn([1 => 7]);

        $rows = $this->service->listWithCounts();

        self::assertSame([7, 0], array_map(static fn (TagModel $r): int => (int) $r->postCount, $rows));
    }

    public function testCreateInsertsNameAndSlug(): void
    {
        $this->tags->method('existsSlug')->willReturn(false);
        $this->tags->expects(self::once())->method('insert')->with('Đà Lạt', 'da-lat')->willReturn(5);

        self::assertSame(5, $this->service->create('Đà Lạt'));
    }

    public function testDeleteRemovesRelationsInsideTransaction(): void
    {
        $this->tags->method('findById')->with(3)->willReturn(TagModel::fromRow(['id' => 3]));
        $this->tags->expects(self::once())->method('delete')->with(3);
        $this->postTags->expects(self::once())->method('deleteByTagId')->with(3);
        $this->db->expects(self::once())->method('transactional');

        $this->service->delete(3);
    }

    public function testMergeSameIdIsValidationFailure(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->merge(4, 4);
    }

    public function testMergeMissingTargetThrowsNotFound(): void
    {
        $this->tags->method('findById')->willReturnCallback(
            static fn (int $id): ?TagModel => $id === 1 ? TagModel::fromRow(['id' => 1]) : null
        );

        $this->expectException(NotFoundException::class);

        $this->service->merge(1, 2);
    }

    public function testMergeReassignsThenDeletesSource(): void
    {
        $this->tags->method('findById')->willReturnCallback(
            static fn (int $id): TagModel => TagModel::fromRow(['id' => $id])
        );
        $this->postTags->expects(self::once())->method('reassignTag')->with(1, 2);
        $this->postTags->expects(self::once())->method('deleteByTagId')->with(1);
        $this->tags->expects(self::once())->method('delete')->with(1);

        $this->service->merge(1, 2);
    }

    public function testEnsureByNameReturnsExistingTagId(): void
    {
        $this->tags->expects(self::once())->method('findBySlug')
            ->with('ha-noi')->willReturn(TagModel::fromRow(['id' => 9]));
        $this->tags->expects(self::never())->method('insert');

        self::assertSame(9, $this->service->ensureByName('Hà Nội'));
    }

    public function testEnsureByNameCreatesWhenSlugMissing(): void
    {
        $this->tags->method('findBySlug')->willReturn(null);
        $this->tags->expects(self::once())->method('insert')
            ->with('Ẩm thực', 'am-thuc')->willReturn(12);

        self::assertSame(12, $this->service->ensureByName(' Ẩm thực '));
    }
}
