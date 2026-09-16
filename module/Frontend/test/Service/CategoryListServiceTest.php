<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use ApplicationTest\Helper\TestContainer;
use Frontend\Service\CategoryListService;
use Frontend\Service\PostListService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tầng đọc /danh-muc/{slug} (FR-05): slug lạ hoặc danh mục TẮT → null cho
 * controller 404 (khác semantics bỏ-lọc-im-lặng của /tin-tuc FR-02); tập bài
 * = chính nó + con ĐANG BẬT (nhánh parentId+isActive — §5.3), phân trang +
 * clamp theo khuôn FR-02, meta fallback tên/mô tả (§3.2). PostListService là
 * mock — buildCards chỉ được kiểm "service gọi và dùng kết quả".
 */
final class CategoryListServiceTest extends TestCase
{
    private CategoryMapper&MockObject $categories;
    private PostMapper&MockObject $posts;
    private PostListService&MockObject $postList;
    private CategoryListService $service;

    protected function setUp(): void
    {
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->posts      = $this->createMock(PostMapper::class);
        $this->postList   = $this->createMock(PostListService::class);

        $this->service = (new CategoryListService())->setContainer(new TestContainer([
            CategoryMapper::class  => $this->categories,
            PostMapper::class      => $this->posts,
            PostListService::class => $this->postList,
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
    private function category(array $overrides = []): CategoryModel
    {
        return CategoryModel::fromRow($overrides + [
            'id'       => 7,
            'parentId' => null,
            'name'     => 'Suc khoe',
            'slug'     => 'suc-khoe',
            'isActive' => CategoryConst::ACTIVE,
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

    public function testUnknownSlugReturnsNullWithoutTouchingPostMapper(): void
    {
        $this->categories->method('findBySlug')->willReturn(null);
        $this->posts->expects(self::never())->method('countPublished');

        self::assertNull($this->service->page('khong-ton-tai', 1));
    }

    public function testEmptySlugShortCircuitsToNull(): void
    {
        $this->categories->expects(self::never())->method('findBySlug');

        self::assertNull($this->service->page('', 1));
    }

    public function testInactiveCategory404sBeforeAnyPostQuery(): void
    {
        $this->categories->method('findBySlug')
            ->willReturn($this->category(['isActive' => CategoryConst::INACTIVE]));
        $this->posts->expects(self::never())->method('countPublished');
        $this->categories->expects(self::never())->method('activeChildIds');

        self::assertNull($this->service->page('suc-khoe', 1));
    }

    public function testScopeIsSelfPlusActiveChildrenOnly(): void
    {
        // §5.3: con TẮT đã bị loại ngay trong activeChildIds (WHERE isActive=1),
        // service chỉ việc gộp [cha, ...con-bat].
        $this->categories->method('findBySlug')->willReturn($this->category());
        $this->categories->method('activeChildIds')->with(7)->willReturn([8, 9]);
        $this->posts->method('countPublished')->with([7, 8, 9])->willReturn(2);
        $this->posts->method('listPublishedPage')
            ->with([7, 8, 9], 12, 0)
            ->willReturn([$this->post(21), $this->post(22)]);

        $payload = $this->service->page('suc-khoe', 1);

        self::assertIsArray($payload);
        self::assertSame(
            ['id' => 7, 'name' => 'Suc khoe', 'slug' => 'suc-khoe', 'description' => ''],
            $payload['category']
        );
        self::assertSame([['id' => 21], ['id' => 22]], $payload['posts']);
        self::assertSame(2, $payload['total']);
        self::assertSame(1, $payload['pages']);
    }

    public function testChildlessCategoryUsesSingleId(): void
    {
        $this->categories->method('findBySlug')->willReturn($this->category());
        $this->categories->method('activeChildIds')->willReturn([]);
        $this->posts->method('countPublished')->with([7])->willReturn(0);
        $this->posts->expects(self::never())->method('listPublishedPage');

        $payload = $this->service->page('suc-khoe', 1);

        self::assertIsArray($payload);
        self::assertSame([], $payload['posts']);
        self::assertSame(0, $payload['pages']);
    }

    public function testRequestedPageBeyondLastClampsToLast(): void
    {
        $this->categories->method('findBySlug')->willReturn($this->category());
        $this->categories->method('activeChildIds')->willReturn([8]);
        $this->posts->method('countPublished')->willReturn(30);
        $this->posts->method('listPublishedPage')
            ->with([7, 8], 12, 24)
            ->willReturn([$this->post(31)]);

        $payload = $this->service->page('suc-khoe', 99);

        self::assertIsArray($payload);
        self::assertSame(3, $payload['pages']);
        self::assertSame(3, $payload['page']);
    }

    public function testMetaFallbacksToNameAndDescription(): void
    {
        $this->categories->method('findBySlug')->willReturn($this->category([
            'description' => 'Mô tả danh mục dùng làm meta.',
        ]));
        $this->categories->method('activeChildIds')->willReturn([]);
        $this->posts->method('countPublished')->willReturn(0);

        $payload = $this->service->page('suc-khoe', 1);

        self::assertIsArray($payload);
        self::assertSame('Suc khoe', $payload['metaTitle']);
        self::assertSame('Mô tả danh mục dùng làm meta.', $payload['metaDescription']);
    }

    public function testExplicitMetaWinOverFallback(): void
    {
        $this->categories->method('findBySlug')->willReturn($this->category([
            'description'     => 'Mô tả gốc',
            'metaTitle'       => 'Meta title riêng',
            'metaDescription' => 'Meta desc riêng',
        ]));
        $this->categories->method('activeChildIds')->willReturn([]);
        $this->posts->method('countPublished')->willReturn(0);

        $payload = $this->service->page('suc-khoe', 1);

        self::assertIsArray($payload);
        self::assertSame('Meta title riêng', $payload['metaTitle']);
        self::assertSame('Meta desc riêng', $payload['metaDescription']);
    }
}
