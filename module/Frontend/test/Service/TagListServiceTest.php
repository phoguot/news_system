<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagMapper;
use Admin\Model\Tag\TagModel;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\PostListService;
use Frontend\Service\TagListService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc /tag/{slug} (FR-06): slug lạ/rỗng → null (404, không chạm
 * post_tags); tag tồn tại nhưng chưa có bài → 200 rỗng và KHÔNG query bài;
 * tập id từ post_tags đưa NGUYÊN VIÊN vào count + page của PostMapper
 * (scope công khai là việc của mapper — service không lọc lại); phân trang
 * clamp theo khuôn FR-02/FR-05. PostListService là mock — buildCards chỉ
 * được kiểm "service gọi và dùng kết quả".
 */
final class TagListServiceTest extends TestCase
{
    private TagMapper&MockObject $tags;
    private PostTagMapper&MockObject $postTags;
    private PostMapper&MockObject $posts;
    private PostListService&MockObject $postList;
    private TagListService $service;

    protected function setUp(): void
    {
        $this->tags     = $this->createMock(TagMapper::class);
        $this->postTags = $this->createMock(PostTagMapper::class);
        $this->posts    = $this->createMock(PostMapper::class);
        $this->postList = $this->createMock(PostListService::class);

        $this->service = (new TagListService())->setContainer(new TestContainer([
            TagMapper::class        => $this->tags,
            PostTagMapper::class    => $this->postTags,
            PostMapper::class       => $this->posts,
            PostListService::class  => $this->postList,
        ]));

        $this->postList->method('buildCards')
            ->willReturnCallback(
                /** @param list<PostModel> $models @return list<array<int, int>> */
                static fn (array $models): array => array_map(
                    static fn (PostModel $m): array => ['id' => $m->id],
                    $models
                )
            );
    }

    private function tag(int $id = 4, string $slug = 'dinh-duong'): TagModel
    {
        return TagModel::fromRow([
            'id'   => $id,
            'name' => 'Dinh duong',
            'slug' => $slug,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function post(int $id, array $overrides = []): PostModel
    {
        return PostModel::fromRow($overrides + [
            'id'             => $id,
            'categoryId'     => 7,
            'title'          => 'Tieu de ' . $id,
            'slug'           => 'tieu-de-' . $id,
            'publishedAt'    => '2026-09-12 03:00:00',
            'readingMinutes' => 3,
        ]);
    }

    public function testUnknownSlugReturnsNullWithoutPostQueries(): void
    {
        $this->tags->method('findBySlug')->willReturn(null);
        $this->postTags->expects(self::never())->method('postIdsByTag');
        $this->posts->expects(self::never())->method('countPublishedByIds');

        self::assertNull($this->service->page('khong-ton-tai', 1));
    }

    public function testEmptySlugShortCircuitsToNull(): void
    {
        $this->tags->expects(self::never())->method('findBySlug');

        self::assertNull($this->service->page('', 1));
    }

    public function testTagWithoutPostsIsSuccessWithEmptyList(): void
    {
        $this->tags->method('findBySlug')->willReturn($this->tag());
        $this->postTags->method('postIdsByTag')->with(4)->willReturn([]);
        // countPublishedByIds([]) guard trả 0 — service không gọi list.
        $this->posts->method('countPublishedByIds')->with([])->willReturn(0);
        $this->posts->expects(self::never())->method('listPublishedPageByIds');

        $payload = $this->service->page('dinh-duong', 1);

        self::assertIsArray($payload);
        self::assertSame(['id' => 4, 'name' => 'Dinh duong', 'slug' => 'dinh-duong'], $payload['tag']);
        self::assertSame([], $payload['posts']);
        self::assertSame(0, $payload['total']);
        self::assertSame(0, $payload['pages']);
    }

    public function testIdSetFromPostTagsFlowsIntoCountAndPage(): void
    {
        $this->tags->method('findBySlug')->willReturn($this->tag());
        $this->postTags->method('postIdsByTag')->willReturn([9, 12, 15]);
        $this->posts->method('countPublishedByIds')->with([9, 12, 15])->willReturn(2);
        $this->posts->method('listPublishedPageByIds')
            ->with([9, 12, 15], 12, 0)
            ->willReturn([$this->post(15), $this->post(12)]);

        $payload = $this->service->page('dinh-duong', 1);

        self::assertIsArray($payload);
        self::assertSame([['id' => 15], ['id' => 12]], $payload['posts']);
        self::assertSame(2, $payload['total']);
        self::assertSame(1, $payload['pages']);
    }

    public function testRequestedPageBeyondLastClampsToLast(): void
    {
        $this->tags->method('findBySlug')->willReturn($this->tag());
        $this->postTags->method('postIdsByTag')->willReturn([1, 2, 3]);
        $this->posts->method('countPublishedByIds')->willReturn(30);
        $this->posts->method('listPublishedPageByIds')
            ->with([1, 2, 3], 12, 24)
            ->willReturn([$this->post(7)]);

        $payload = $this->service->page('dinh-duong', 99);

        self::assertIsArray($payload);
        self::assertSame(3, $payload['pages']);
        self::assertSame(3, $payload['page']);
    }

    public function testSecondPageUsesOffsetTwelve(): void
    {
        $this->tags->method('findBySlug')->willReturn($this->tag());
        $this->postTags->method('postIdsByTag')->willReturn([1, 2, 3]);
        $this->posts->method('countPublishedByIds')->willReturn(13);
        $this->posts->method('listPublishedPageByIds')
            ->with([1, 2, 3], 12, 12)
            ->willReturn([$this->post(4)]);

        $payload = $this->service->page('dinh-duong', 2);

        self::assertIsArray($payload);
        self::assertSame(2, $payload['page']);
        self::assertSame(2, $payload['pages']);
    }
}
