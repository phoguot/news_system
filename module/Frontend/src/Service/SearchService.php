<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Post\PostMapper;
use Application\Factory\AppServiceFactory;

/**
 * Tầng tìm kiếm bài viết toàn văn tiếng Việt (FR-07, docs §5.11 / §4):
 * sử dụng index FULLTEXT ft_posts_title_excerpt kết hợp scope công khai;
 * trả về danh sách tối đa 20 bài viết khớp nhất kèm thẻ meta noindex (NFR-SEO-5).
 * Từ khoá rỗng hoặc < 2 ký tự trả mảng rỗng không query DB.
 *
 * Card reuse PostListService::buildCards để resolve categoryName và media.
 *
 * Cache: KHÔNG — truy vấn động theo từ khóa người dùng, không thuộc danh mục cache nền (§7.2).
 */
class SearchService extends AppServiceFactory
{
    public const SEARCH_LIMIT = 20;

    /**
     * Payload cho trang tìm kiếm /tim-kiem?q=
     *
     * @return array{
     *     q: string,
     *     posts: list<array<string, mixed>>,
     *     total: int,
     *     noindex: bool
     * }
     */
    public function search(string $query): array
    {
        $q = trim($query);
        if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
            return [
                'q'       => $q,
                'posts'   => [],
                'total'   => 0,
                'noindex' => true,
            ];
        }

        $models = $this->posts()->searchPublished($q, self::SEARCH_LIMIT);
        $cards  = $this->postList()->buildCards($models);

        return [
            'q'       => $q,
            'posts'   => $cards,
            'total'   => count($cards),
            'noindex' => true,
        ];
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
