<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\Tag\TagMapper;
use Admin\Model\User\UserMapper;
use Application\Factory\AppServiceFactory;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tầng đọc trang chi tiết bài viết (FR-03, docs §2.1/§3.3.2/§5.4):
 * bài CÔNG KHAI theo slug (scope `publicSelect` gom trong PostMapper — nháp/
 * hẹn giờ/slug lạ trả null, controller 404), dựng payload MẢNG THUẦN cho view:
 * banner, danh mục, tên tác giả (CHỈ string — UserModel mang `passwordHash`,
 * cấm hydrate model xuống view), ngày đăng giờ VN, thời gian đọc, tag,
 * bài liên quan, SEO meta (fallback title/excerpt).
 *
 * Bài liên quan (§5.4): SQL mẫu của spec JOIN chéo 3 bảng — trái luật kiến
 * trúc "1 mapper = 1 bảng" (07 §5). Tương đương có chủ đích, Service ghép
 * 2 bước: PostTagMapper::sharedTagCounts (đếm tag chung, single-table)
 * → PostMapper::listPublishedByIds cho nhóm điểm, + pool cùng danh mục
 * listPublishedByCategory làm phần bổ khuyết; sort (điểm DESC, publishedAt DESC,
 * id DESC), loại chính nó, cắt RELATED_LIMIT.
 *
 * Cache: KHÔNG — NFR §7.2 chỉ liệt kê "trang chủ, menu danh mục, settings";
 * detail còn lệch nhau theo từng slug, thêm viewCount thì cache 60s chỉ tổ
 * lệch số.
 *
 * FR-04: `detail(slug, previewToken)` — token không rỗng ⇒ mọi render đều
 * `noindex` (NFR-SEO-5); bài chưa công khai chỉ hiện khi slug+token khớp.
 */
class PostDetailService extends AppServiceFactory
{
    /** Múi giờ hiển thị công khai — docs §4.1 (khuôn HomeService/PostListService). */
    private const VIEW_TIMEZONE = 'Asia/Ho_Chi_Minh';

    /** Số bài liên quan hiển thị (§3.3.2 "4 bài"). */
    private const RELATED_LIMIT = 4;

    /** Bài cùng danh mục lấy làm pool bổ khuyết khi tag không đủ 4. */
    private const CATEGORY_POOL = 8;

    /**
     * Toàn bộ payload trang chi tiết; null = không có bài công khai và cũng
     * không khớp previewToken (controller 404).
     *
     * FR-04 (§3.3.2/§5.7 + NFR-SEO-5): mọi request có `?previewToken=` không
     * rỗng đều gắn `noindex` — kể cả khi bài đã công khai (URL kèm query là
     * bản sao preview, bản canonical khỏi query mới là thứ nên được index);
     * bài nháp/hẹn giờ chỉ hiện được khi slug + token khớp đúng một dòng
     * (`uq_posts_preview_token`).
     *
     * @return ?array<string, mixed>
     */
    public function detail(string $slug, string $previewToken = ''): ?array
    {
        $noindex = $previewToken !== '';
        $post    = $this->posts()->findPublishedBySlug($slug);
        if ($post === null) {
            $post = $noindex ? $this->posts()->findBySlugPreviewToken($slug, $previewToken) : null;
        }

        if ($post === null) {
            return null;
        }

        $tagIds = $this->postTags()->tagIdsForPost($post->id);
        $banner = $this->banner($post);

        return [
            'id'               => $post->id,
            'noindex'          => $noindex,
            'title'            => $post->title,
            'slug'             => $post->slug,
            'href'             => '/tin-tuc/' . $post->slug,
            'content'          => $post->content,
            'excerpt'          => $post->excerpt ?? '',
            'date'             => $this->viewDate($post->publishedAt),
            'minutes'          => $post->readingMinutes,
            'authorName'       => $this->authorName($post->authorId),
            'category'         => $this->categoryRef($post->categoryId),
            'banner'           => $banner,
            'tags'             => $this->tagChips($tagIds),
            'related'          => $this->related($post, $tagIds),
            'metaTitle'        => $post->metaTitle !== null && $post->metaTitle !== ''
                ? $post->metaTitle
                : $post->title,
            'metaDescription'  => $post->metaDescription !== null && $post->metaDescription !== ''
                ? $post->metaDescription
                : ($post->excerpt ?? ''),
        ];
    }

    /**
     * Danh mục tham chiếu cho breadcrumb; tắt/xoá → null (ẩn breadcrumb cấp
     * danh mục, không 404 — chế 404 theo danh mục thuộc FR-05).
     *
     * @return ?array{name: string, slug: string}
     */
    private function categoryRef(int $categoryId): ?array
    {
        if ($categoryId === 0) {
            return null;
        }

        $category = $this->categories()->findById($categoryId);
        if ($category === null || $category->isActive !== CategoryConst::ACTIVE) {
            return null;
        }

        return ['name' => $category->name, 'slug' => $category->slug];
    }

    /**
     * @param list<int> $tagIds
     *
     * @return list<array{name: string, href: string}>
     */
    private function tagChips(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $chips = [];
        foreach ($this->tags()->listByIds($tagIds) as $tag) {
            $chips[] = ['name' => $tag->name, 'href' => '/tag/' . $tag->slug];
        }

        return $chips;
    }

    /**
     * @return ?array{path: string, alt: string}
     */
    private function banner(PostModel $post): ?array
    {
        if ($post->bannerMediaId === null) {
            return null;
        }

        $media = $this->media()->findById($post->bannerMediaId);
        if (! $media instanceof MediaModel || $media->path === '') {
            return null;
        }

        return ['path' => $media->path, 'alt' => $media->altText ?? ''];
    }

    /**
     * Tên tác giả — chỉ string để UserModel (kèm passwordHash) không bao giờ
     * chạm view. Bài không tác giả (xoá tài khoản) → ''.
     */
    private function authorName(?int $authorId): string
    {
        if ($authorId === null) {
            return '';
        }

        $user = $this->users()->findById($authorId);

        return $user === null ? '' : $user->fullName;
    }

    /**
     * Bài liên quan — xem docblock đầu lớp về bản đồ §5.4 → 2 bước.
     *
     * @param list<int> $tagIds
     *
     * @return list<array<string, mixed>>
     */
    private function related(PostModel $post, array $tagIds): array
    {
        /** @var array<int, PostModel> $pool */
        $pool = [];
        /** @var array<int, int> $scores */
        $scores = [];

        if ($tagIds !== []) {
            $counts = $this->postTags()->sharedTagCounts($post->id, $tagIds);
            $ids    = array_map('intval', array_keys($counts));
            foreach ($this->posts()->listPublishedByIds($ids) as $candidate) {
                if ($candidate->id === $post->id) {
                    continue;
                }

                $pool[$candidate->id]   = $candidate;
                $scores[$candidate->id] = $counts[$candidate->id] ?? 0;
            }
        }

        foreach ($this->posts()->listPublishedByCategory($post->categoryId, self::CATEGORY_POOL) as $candidate) {
            if ($candidate->id === $post->id || isset($pool[$candidate->id])) {
                continue;
            }

            $pool[$candidate->id]   = $candidate;
            $scores[$candidate->id] = 0;
        }

        $ordered = array_keys($pool);
        usort($ordered, static fn (int $a, int $b): int => $scores[$b] <=> $scores[$a]
            ?: strcmp((string) $pool[$b]->publishedAt, (string) $pool[$a]->publishedAt)
            ?: $b <=> $a);

        $models = array_map(
            static fn (int $id): PostModel => $pool[$id],
            array_slice($ordered, 0, self::RELATED_LIMIT)
        );

        return $this->postList()->buildCards($models);
    }

    /**
     * Mốc 'Y-m-d H:i:s' UTC → 'd/m/Y' giờ VN (docs §4.1).
     */
    private function viewDate(?string $publishedAtUtc): string
    {
        if ($publishedAtUtc === null || $publishedAtUtc === '') {
            return '';
        }

        try {
            $utc = new DateTimeImmutable($publishedAtUtc . ' UTC');
        } catch (\Exception) {
            return '';
        }

        return $utc->setTimezone(new DateTimeZone(self::VIEW_TIMEZONE))->format('d/m/Y');
    }

    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function categories(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function users(): UserMapper
    {
        /** @var UserMapper */
        return $this->getContainerEntry(UserMapper::class);
    }

    private function postTags(): PostTagMapper
    {
        /** @var PostTagMapper */
        return $this->getContainerEntry(PostTagMapper::class);
    }

    private function tags(): TagMapper
    {
        /** @var TagMapper */
        return $this->getContainerEntry(TagMapper::class);
    }

    private function media(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function postList(): PostListService
    {
        /** @var PostListService */
        return $this->getContainerEntry(PostListService::class);
    }
}
