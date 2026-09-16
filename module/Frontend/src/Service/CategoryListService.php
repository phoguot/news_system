<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostMapper;
use Application\Factory\AppServiceFactory;
use Frontend\Constant\FrontendConst;

/**
 * Tầng đọc trang danh mục `/danh-muc/{slug}` (FR-05, docs §3.2/§5.3):
 * resolve slug → danh mục, SEMANTICS KHÁC trang /tin-tuc (PostListService
 * bỏ lọc im lặng khi slug lạ/ẩn): ở đây slug lạ HOẶC danh mục TẮT đều
 * null → controller 404 (dòng FR-05 "404 nếu danh mục tắt").
 *
 * Bài hiển thị = của chính danh mục + các danh mục CON ĐANG BẬT (docs §5.3
 * `id = :cat OR (parentId = :cat AND isActive = 1)`; cây bị Admin chặn tối đa
 * 2 cấp ở FR-26 nên đúng một lớp `activeChildIds` là đủ — không JOIN chéo,
 * mỗi query một bảng). Danh mục cha TẮT thì 404 trước cả khi chạm PostMapper;
 * con TẮT chỉ bị loại khỏi tập id (bài nó vẫn đọc được qua URL trực tiếp —
 * §3.2 "Tắt danh mục").
 *
 * Phân trang 12 bài/trang + clamp trang cuối theo đúng khuôn FR-02
 * (`FrontendConst::NEWS_PAGE_SIZE`); card reuse `PostListService::buildCards`
 * — cùng hình hài với /tin-tuc và khối bài liên quan FR-03.
 *
 * Cache: KHÔNG — NFR §7.2 chỉ liệt kê home/menu/settings; key theo
 * (slug × trang) sẽ nổ như đã ghi ở PostListService (danh sách bài không
 * cache). Menu danh mục (dropdown) đã có `category-menu-v1` riêng.
 *
 * SEO meta theo §3.2: metaTitle/metaDescription trống → fallback tên/mô tả.
 */
class CategoryListService extends AppServiceFactory
{
    /**
     * Toàn bộ payload trang danh mục; null = slug lạ hoặc danh mục tắt
     * (controller 404).
     *
     * @return ?array<string, mixed>
     */
    public function page(string $slug, int $requestedPage): ?array
    {
        $category = $slug === '' ? null : $this->categories()->findBySlug($slug);
        if ($category === null || $category->isActive !== CategoryConst::ACTIVE) {
            return null;
        }

        // §5.3: chính nó + con ĐANG BẬT — thứ tự giữ cha trước để đọc/debug dễ.
        $ids = [$category->id, ...$this->categories()->activeChildIds($category->id)];

        $total = $this->posts()->countPublished($ids);
        $pages = (int) ceil($total / FrontendConst::NEWS_PAGE_SIZE);
        // Trang ngoài phạm vi kẹp về trang cuối — khuôn FR-02.
        $page  = min(max(1, $requestedPage), max(1, $pages));

        $models = $total === 0
            ? []
            : $this->posts()->listPublishedPage(
                $ids,
                FrontendConst::NEWS_PAGE_SIZE,
                ($page - 1) * FrontendConst::NEWS_PAGE_SIZE
            );

        return [
            'category' => [
                'id'          => $category->id,
                'name'        => $category->name,
                'slug'        => $category->slug,
                'description' => $category->description ?? '',
            ],
            'posts'           => $this->postList()->buildCards($models),
            'total'           => $total,
            'page'            => $page,
            'pages'           => $pages,
            'metaTitle'       => $category->metaTitle !== null && $category->metaTitle !== ''
                ? $category->metaTitle
                : $category->name,
            'metaDescription' => $category->metaDescription !== null && $category->metaDescription !== ''
                ? $category->metaDescription
                : ($category->description ?? ''),
        ];
    }

    private function categories(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function postList(): PostListService
    {
        /** @var PostListService */
        return $this->getContainerEntry(PostListService::class);
    }
}
