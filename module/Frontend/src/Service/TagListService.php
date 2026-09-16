<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Post\PostMapper;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagMapper;
use Application\Factory\AppServiceFactory;
use Frontend\Constant\FrontendConst;

/**
 * Tầng đọc trang tag `/tag/{slug}` (FR-06, docs §2.1/§6.1): tag lạ → null
 * → controller 404; tag tồn tại nhưng chưa có bài → trang 200 rỗng (tag là
 * từ khóa tự do, không có trạng thái bật/tắt như danh mục).
 *
 * Luật 1 mapper = 1 bảng (07 §5): id bài lấy từ `post_tags`
 * (`PostTagMapper::postIdsByTag` — docblock của chính method này đã dự
 * phòng sẵn luồng "Service đưa vào PostMapper dạng IN"), rồi MỘT query
 * `IN(id) + scope public` của PostMapper quyết định bài nào được hiển thị
 * (nháp/xoá/ngừng publish tự loại) + phân trang 12 + clamp theo khuôn
 * FR-02/FR-05; card reuse `PostListService::buildCards`.
 *
 * Cache: KHÔNG — cùng lý do với hai trang danh sách trước (key nổ theo
 * slug×trang; §7.2 không liệt kê).
 */
class TagListService extends AppServiceFactory
{
    /**
     * Toàn bộ payload trang tag; null = slug tag không tồn tại (404).
     *
     * @return ?array<string, mixed>
     */
    public function page(string $slug, int $requestedPage): ?array
    {
        $tag = $slug === '' ? null : $this->tags()->findBySlug($slug);
        if ($tag === null) {
            return null;
        }

        $postIds = $this->postTags()->postIdsByTag($tag->id);
        $total   = $this->posts()->countPublishedByIds($postIds);
        $pages   = (int) ceil($total / FrontendConst::NEWS_PAGE_SIZE);
        $page    = min(max(1, $requestedPage), max(1, $pages));

        $models = $total === 0
            ? []
            : $this->posts()->listPublishedPageByIds(
                $postIds,
                FrontendConst::NEWS_PAGE_SIZE,
                ($page - 1) * FrontendConst::NEWS_PAGE_SIZE
            );

        return [
            'tag' => [
                'id'   => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ],
            'posts' => $this->postList()->buildCards($models),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    private function tags(): TagMapper
    {
        /** @var TagMapper */
        return $this->getContainerEntry(TagMapper::class);
    }

    private function postTags(): PostTagMapper
    {
        /** @var PostTagMapper */
        return $this->getContainerEntry(PostTagMapper::class);
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
