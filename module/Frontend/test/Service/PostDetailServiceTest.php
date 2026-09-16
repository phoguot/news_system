<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagMapper;
use Admin\Model\Tag\TagModel;
use Admin\Model\User\UserMapper;
use Admin\Model\User\UserModel;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\PostDetailService;
use Frontend\Service\PostListService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc /tin-tuc/{slug} (FR-03): slug lạ/nháp → null (controller 404);
 * payload đủ banner/tác giả (CHỈ tên)/tag/meta fallback; bài liên quan theo
 * bản đồ §5.4 — điểm tag chung DESC rồi pool danh mục, loại chính nó, cắt 4.
 * PostListService là mock: buildCards chỉ được kiểm "service gọi và dùng kết quả".
 */
final class PostDetailServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private CategoryMapper&MockObject $categories;
    private UserMapper&MockObject $users;
    private PostTagMapper&MockObject $postTags;
    private TagMapper&MockObject $tags;
    private MediaMapper&MockObject $media;
    private PostListService&MockObject $postList;
    private PostDetailService $service;

    protected function setUp(): void
    {
        $this->posts      = $this->createMock(PostMapper::class);
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->users      = $this->createMock(UserMapper::class);
        $this->postTags   = $this->createMock(PostTagMapper::class);
        $this->tags       = $this->createMock(TagMapper::class);
        $this->media      = $this->createMock(MediaMapper::class);
        $this->postList   = $this->createMock(PostListService::class);

        $this->service = (new PostDetailService())->setContainer(new TestContainer([
            PostMapper::class       => $this->posts,
            CategoryMapper::class   => $this->categories,
            UserMapper::class       => $this->users,
            PostTagMapper::class    => $this->postTags,
            TagMapper::class        => $this->tags,
            MediaMapper::class      => $this->media,
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

    /** @param array<string, mixed> $overrides */
    private function post(int $id, array $overrides = []): PostModel
    {
        return PostModel::fromRow($overrides + [
            'id'             => $id,
            'categoryId'     => 3,
            'authorId'       => 5,
            'title'          => 'Tieu de ' . $id,
            'slug'           => 'tieu-de-' . $id,
            'excerpt'        => 'Tom tat ' . $id,
            'content'        => '<p>Noi dung ' . $id . '</p>',
            'publishedAt'    => '2026-09-12 18:00:00',
            'readingMinutes' => 4,
        ]);
    }

    public function testUnknownOrDraftSlugReturnsNullWithoutOtherQueries(): void
    {
        $this->posts->method('findPublishedBySlug')->with('la')->willReturn(null);
        $this->postTags->expects(self::never())->method('tagIdsForPost');
        $this->categories->expects(self::never())->method('findById');

        self::assertNull($this->service->detail('la'));
    }

    public function testFullPayloadHydratesBannerAuthorTagsAndMetaFallback(): void
    {
        $this->posts->method('findPublishedBySlug')->with('tieu-de-1')
            ->willReturn($this->post(1, ['bannerMediaId' => 9]));
        $this->postTags->method('tagIdsForPost')->with(1)->willReturn([11, 12]);
        $this->categories->method('findById')->with(3)->willReturn(CategoryModel::fromRow([
            'id' => 3, 'name' => 'Thi truong', 'slug' => 'thi-truong',
            'isActive' => CategoryConst::ACTIVE,
        ]));
        $this->users->method('findById')->with(5)->willReturn(UserModel::fromRow([
            'id' => 5, 'fullName' => 'BS. Tran Van A', 'passwordHash' => 'SECRET-HASH',
        ]));
        $this->tags->method('listByIds')->with([11, 12])->willReturn([
            TagModel::fromRow(['id' => 11, 'name' => 'Suc khoe', 'slug' => 'suc-khoe']),
            TagModel::fromRow(['id' => 12, 'name' => 'Dinh duong', 'slug' => 'dinh-duong']),
        ]);
        $this->media->method('findById')->with(9)->willReturn(MediaModel::fromRow([
            'id' => 9, 'path' => '2026/09/banner.jpg', 'altText' => 'Banner bai viet',
        ]));
        $this->postTags->method('sharedTagCounts')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        self::assertSame('Tieu de 1', $payload['title']);
        self::assertSame('/tin-tuc/tieu-de-1', $payload['href']);
        self::assertSame('<p>Noi dung 1</p>', $payload['content']);
        self::assertSame('13/09/2026', $payload['date']);
        self::assertSame(4, $payload['minutes']);
        self::assertSame('BS. Tran Van A', $payload['authorName']);
        self::assertStringNotContainsString('SECRET', json_encode($payload));
        self::assertSame(['name' => 'Thi truong', 'slug' => 'thi-truong'], $payload['category']);
        self::assertSame(['path' => '2026/09/banner.jpg', 'alt' => 'Banner bai viet'], $payload['banner']);
        self::assertSame(
            [
                ['name' => 'Suc khoe', 'href' => '/tag/suc-khoe'],
                ['name' => 'Dinh duong', 'href' => '/tag/dinh-duong'],
            ],
            $payload['tags']
        );
        self::assertSame('Tieu de 1', $payload['metaTitle']);
        self::assertSame('Tom tat 1', $payload['metaDescription']);
    }

    public function testSeoMetaOverrideFallbacks(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1, [
            'metaTitle'       => 'Meta ngan',
            'metaDescription' => 'Meta mo ta',
        ]));
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->postTags->method('sharedTagCounts')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        self::assertSame('Meta ngan', $payload['metaTitle']);
        self::assertSame('Meta mo ta', $payload['metaDescription']);
    }

    public function testRelatedScoresSharedTagsFirstThenCategoryFillExcludingSelf(): void
    {
        $self = $this->post(1);
        $this->posts->method('findPublishedBySlug')->willReturn($self);
        $this->postTags->method('tagIdsForPost')->with(1)->willReturn([11]);
        // 8 chung 2 tag, 7 chung 1 tag — 7 cũng có mặt ở pool danh mục (phải giữ điểm tag)
        $this->postTags->method('sharedTagCounts')->with(1, [11])->willReturn([8 => 2, 7 => 1]);
        $this->posts->method('listPublishedByIds')->with([8, 7])
            ->willReturn([$this->post(7), $this->post(8)]);
        // Pool cùng danh mục: trả cả chính bài 1 (phải bị loại) + bài 9/10 không tag chung
        $this->posts->method('listPublishedByCategory')->with(3, 8)
            ->willReturn([$self, $this->post(9), $this->post(10)]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        $related = $payload['related'];
        self::assertIsArray($related);
        $ids = array_column($related, 'id');
        // 8 (2 tag chung) > 7 (1 tag) > pool danh mục: 9/10 bằng ngày → id DESC
        self::assertSame([8, 7, 10, 9], $ids);
    }

    public function testRelatedCutsAtFourAndTiesFallBackOnDateThenId(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1));
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->postTags->method('sharedTagCounts')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([
            $this->post(6, ['publishedAt' => '2026-09-10 00:00:00']),
            $this->post(5, ['publishedAt' => '2026-09-11 00:00:00']),
            $this->post(4),
            $this->post(3, ['publishedAt' => '2026-09-11 00:00:00']),
            $this->post(2, ['publishedAt' => null]),
        ]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        $related = $payload['related'];
        self::assertIsArray($related);
        $ids = array_column($related, 'id');
        self::assertCount(4, $ids);
        // Cùng mốc 09/11 → id DESC (5 trước 3); rồi 09/12 (4) dẫn đầu, 09/10 (6);
        // bài null date rớt chót và bị cắt
        self::assertSame([4, 5, 3, 6], $ids);
    }

    public function testNoTagsSkipsTagAndSharedQueries(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1));
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->tags->expects(self::never())->method('listByIds');
        $this->postTags->expects(self::never())->method('sharedTagCounts');
        $this->posts->expects(self::never())->method('listPublishedByIds');
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        self::assertSame([], $payload['tags']);
        self::assertNull($payload['banner']);
        self::assertSame('', $payload['authorName']);
    }

    public function testInactiveOrMissingCategoryHidesRefOnly(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1));
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->postTags->method('sharedTagCounts')->willReturn([]);
        $this->categories->method('findById')->willReturnCallback(
            fn (int $id): ?CategoryModel => $id === 3
                ? CategoryModel::fromRow([
                    'id' => 3, 'name' => 'An', 'slug' => 'an',
                    'isActive' => CategoryConst::INACTIVE,
                ])
                : null
        );
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $off = $this->service->detail('tieu-de-1');

        self::assertIsArray($off);
        self::assertNull($off['category']);
        self::assertSame('Tieu de 1', $off['title']);
    }

    /* ---------------- FR-04: previewToken + noindex (NFR-SEO-5) ---------------- */

    public function testDraftVisibleOnlyWhenTokenMatchesExactly(): void
    {
        $draft = $this->post(1, ['status' => 0, 'publishedAt' => null]);
        $this->posts->method('findPublishedBySlug')->willReturn(null);
        $this->posts->method('findBySlugPreviewToken')
            ->willReturnCallback(
                fn (string $s, string $t): ?PostModel => $t === 'tok-dung-32-hex' ? $draft : null
            );
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1', 'tok-dung-32-hex');
        self::assertIsArray($payload);
        self::assertTrue($payload['noindex']);
        self::assertSame('', $payload['date']); // bài nháp chưa có mốc đăng

        self::assertNull($this->service->detail('tieu-de-1', 'tok-sai'));
    }

    public function testDraftWithoutTokenNeverChecksMapper(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn(null);
        $this->posts->expects(self::never())->method('findBySlugPreviewToken');

        self::assertNull($this->service->detail('tieu-de-1', ''));
    }

    public function testTokenOnPublishedPostServesCanonicalButNoindex(): void
    {
        // URL kèm ?previewToken= là bản sao preview → vẫn là bài công khai,
        // không cần tra token, nhưng phải noindex (NFR-SEO-5 chốt theo URL).
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1));
        $this->posts->expects(self::never())->method('findBySlugPreviewToken');
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1', 'bat-ky-token');

        self::assertIsArray($payload);
        self::assertTrue($payload['noindex']);
        self::assertSame('Tieu de 1', $payload['title']);
    }

    public function testNormalVisitHasNoNoindexFlag(): void
    {
        $this->posts->method('findPublishedBySlug')->willReturn($this->post(1));
        $this->postTags->method('tagIdsForPost')->willReturn([]);
        $this->posts->method('listPublishedByCategory')->willReturn([]);

        $payload = $this->service->detail('tieu-de-1');

        self::assertIsArray($payload);
        self::assertFalse($payload['noindex']);
    }
}
