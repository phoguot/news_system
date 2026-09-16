<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\PostListService;
use Frontend\Service\SearchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test cho SearchService (FR-07):
 * - Từ khoá rỗng hoặc < 2 ký tự: trả rỗng không chạm PostMapper.
 * - Từ khoá hợp lệ: gọi PostMapper::searchPublished và PostListService::buildCards.
 * - Cờ noindex luôn bằng true (NFR-SEO-5).
 */
final class SearchServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private PostListService&MockObject $postList;
    private SearchService $service;

    protected function setUp(): void
    {
        $this->posts    = $this->createMock(PostMapper::class);
        $this->postList = $this->createMock(PostListService::class);

        $this->service = (new SearchService())->setContainer(new TestContainer([
            PostMapper::class      => $this->posts,
            PostListService::class => $this->postList,
        ]));
    }

    private function post(int $id, string $title = 'Bài viết'): PostModel
    {
        return PostModel::fromRow([
            'id'             => $id,
            'categoryId'     => 1,
            'title'          => $title,
            'slug'           => 'bai-viet-' . $id,
            'publishedAt'    => '2026-09-13 10:00:00',
            'readingMinutes' => 2,
        ]);
    }

    public function testEmptyQueryReturnsEmptyResultWithoutMapperQuery(): void
    {
        $this->posts->expects(self::never())->method('searchPublished');
        $this->postList->expects(self::never())->method('buildCards');

        $result = $this->service->search('');

        self::assertSame('', $result['q']);
        self::assertSame([], $result['posts']);
        self::assertSame(0, $result['total']);
        self::assertTrue($result['noindex']);
    }

    public function testShortQueryUnderTwoCharsReturnsEmptyResultWithoutMapperQuery(): void
    {
        $this->posts->expects(self::never())->method('searchPublished');
        $this->postList->expects(self::never())->method('buildCards');

        $result = $this->service->search(' a ');

        self::assertSame('a', $result['q']);
        self::assertSame([], $result['posts']);
        self::assertSame(0, $result['total']);
        self::assertTrue($result['noindex']);
    }

    public function testValidQueryCallsMapperAndBuildCards(): void
    {
        $models = [$this->post(1, 'Học phí Văn Lang'), $this->post(2, 'Tuyển sinh Văn Lang')];
        $cards  = [
            ['title' => 'Học phí Văn Lang', 'href' => '/tin-tuc/hoc-phi-van-lang'],
            ['title' => 'Tuyển sinh Văn Lang', 'href' => '/tin-tuc/tuyen-sinh-van-lang'],
        ];

        $this->posts->expects(self::once())
            ->method('searchPublished')
            ->with('Văn Lang', 20)
            ->willReturn($models);

        $this->postList->expects(self::once())
            ->method('buildCards')
            ->with($models)
            ->willReturn($cards);

        $result = $this->service->search('  Văn Lang  ');

        self::assertSame('Văn Lang', $result['q']);
        self::assertSame($cards, $result['posts']);
        self::assertSame(2, $result['total']);
        self::assertTrue($result['noindex']);
    }
}
