<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Post\PostActionFilter;
use Admin\Filter\Post\PostBulkFilter;
use Admin\Filter\Post\PostListFilter;
use Admin\Filter\Post\PostSaveFilter;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Post\PostConst;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostRevision\PostRevisionMapper;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\PostViewDaily\PostViewDailyMapper;
use Admin\Model\Tag\TagMapper;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\Api\ApiResultModel;
use Application\Constant\CacheConst;
use Application\Constant\ContentConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DateService;
use Application\Service\DbService;
use Application\Service\HtmlPurifierService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Nghiệp vụ bài viết (docs §3.3, các luồng 04-luong-nghiep-vu §1 + §6 + §7).
 * Luồng chuẩn 07 §2 (đã cập nhật): Controller chỉ nhận/gửi request —
 * Service chạy Filter (`new` mỗi request, stateful), gọi Mapper, nhận về
 * PostModel đã hydrate, ghép trang trí hiển thị rồi trả Model cho Controller.
 * - lưu bản nháp → slug unique, content qua HTMLPurifier, readingMinutes, previewToken
 * - xuất bản / hẹn giờ → CÙNG status=1, khác publishedAt (hiện tại vs tương lai);
 *   banner bắt buộc (docs §3.3.4(3)); revision before_publish + cắt còn 20 (docs §5.13)
 * - đổi trạng thái theo máy trạng thái (05-may-trang-thai) — không có thùng rác
 * - xoá cứng: 4 bảng con + posts trong MỘT transaction (docs §4.6 / §5.16)
 * Điều phối nhiều Mapper nhưng mỗi Mapper chỉ đụng đúng bảng của nó (chuẩn 07 §5).
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class PostService extends AppServiceFactory
{
    /** Nhánh form publish sai định dạng thời gian — view map sang thông báo. */
    public const FLAG_TIME_INVALID = 'time-invalid';

    /** Typed accessor cho từng dependency lấy từ container (07 §4). */
    private function posts(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function postTags(): PostTagMapper
    {
        /** @var PostTagMapper */
        return $this->getContainerEntry(PostTagMapper::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function revisions(): PostRevisionMapper
    {
        /** @var PostRevisionMapper */
        return $this->getContainerEntry(PostRevisionMapper::class);
    }

    private function views(): PostViewDailyMapper
    {
        /** @var PostViewDailyMapper */
        return $this->getContainerEntry(PostViewDailyMapper::class);
    }

    private function homeItems(): HomeSectionItemMapper
    {
        /** @var HomeSectionItemMapper */
        return $this->getContainerEntry(HomeSectionItemMapper::class);
    }

    private function categories(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function tags(): TagMapper
    {
        /** @var TagMapper */
        return $this->getContainerEntry(TagMapper::class);
    }

    private function tagService(): TagService
    {
        /** @var TagService */
        return $this->getContainerEntry(TagService::class);
    }

    private function slugs(): SlugService
    {
        /** @var SlugService */
        return $this->getContainerEntry(SlugService::class);
    }

    private function purifier(): HtmlPurifierService
    {
        /** @var HtmlPurifierService */
        return $this->getContainerEntry(HtmlPurifierService::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    /**
     * Danh sách quản trị + đếm theo tab (docs §3.3.1, §5.12).
     * Service tự chạy PostListFilter trên query thô (form trang) hoặc body thô
     * (API, có `status` int thay `tab`). Lọc theo tag: PostTagMapper trả ids →
     * PostMapper lọc IN (không join chéo bảng).
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{
     *     rows: list<PostModel>,
     *     total: int,
     *     page: int,
     *     perPage: int,
     *     counts: array<string, int>,
     *     tab: string,
     *     filters: array{categoryId: int, tagId: int, q: string, featured: string, dateFrom: string, dateTo: string}
     * }
     */
    public function list(array $raw): array
    {
        $filter = new PostListFilter();
        $filter->setData($raw);
        // Giá trị sai rơi về mặc định an toàn ở các getter — không chặn request
        $filter->isValid();

        $counts       = $this->posts()->countTabs();
        $page         = $filter->pageValue();
        $perPage      = $filter->perPageValue();
        $categoryId   = $filter->categoryIdValue();
        $tagId        = $filter->tagIdValue();
        $q            = $filter->qValue();
        $featured     = $filter->featuredValue();
        $dateFrom     = $filter->dateFromValue();
        $dateTo       = $filter->dateToValue();
        // Ngày VN 'Y-m-d' (input type=date) → UTC range cho cột publishedAt (docs §3.3.1 "khoảng ngày xuất bản").
        $dateFromUtc = $dateFrom !== null ? $this->vnDayToUtcStart($dateFrom) : null;
        $dateToUtc   = $dateTo !== null ? $this->vnDayToUtcEnd($dateTo) : null;

        $postIds = null;
        if ($tagId !== null) {
            $postIds = $this->postTags()->postIdsByTag($tagId);
            if ($postIds === []) {
                return $this->emptyList(
                    $counts,
                    $page,
                    $perPage,
                    $filter,
                    $categoryId,
                    $tagId,
                    $q,
                    $dateFrom,
                    $dateTo,
                    $featured
                );
            }
        }

        $result = $this->posts()->paginate(
            [
                'tab'         => $filter->tabValue(),
                'categoryId'  => $categoryId,
                'postIds'     => $postIds,
                'q'           => $q,
                'featured'    => $featured,
                'dateFromUtc' => $dateFromUtc,
                'dateToUtc'   => $dateToUtc,
            ],
            $page,
            $perPage
        );

        $rows = $result['rows'];

        $categoryIds = [];
        foreach ($rows as $row) {
            $categoryIds[] = $row->categoryId;
        }

        $names = $this->categories()->getNamesByIds(array_values(array_unique($categoryIds)));
        foreach ($rows as $row) {
            // Trường hiển thị ghép batch — model Decorated, không phải cột DB
            $row->categoryName = $names[$row->categoryId] ?? '(đã xoá)';
        }

        return [
            'rows'    => $rows,
            'total'   => $result['total'],
            'page'    => $page,
            'perPage' => $perPage,
            'counts'  => $counts,
            'tab'     => $filter->tabValue(),
            'filters' => [
                'categoryId' => $categoryId ?? 0,
                'tagId'      => $tagId ?? 0,
                'q'          => $q,
                'featured'   => $featured ? '1' : '',
                'dateFrom'   => $dateFrom ?? '',
                'dateTo'     => $dateTo ?? '',
            ],
        ];
    }

    /** Đếm theo tab cho GET /api/admin/posts/counts (docs §5.12). */
    public function counts(): array
    {
        return $this->posts()->countTabs();
    }

    /**
     * Bài + tag cho form soạn thảo/API.
     *
     * @return array{post: PostModel, tagNames: list<string>, tagIds: list<int>}
     */
    public function detail(int $id): array
    {
        $post     = $this->findOrFail($id);
        $tagIds   = $this->postTags()->tagIdsForPost($id);

        return [
            'post'     => $post,
            'tagIds'   => $tagIds,
            'tagNames' => array_values($this->tags()->getNamesByIds($tagIds)),
        ];
    }

    public function findOrFail(int $id): PostModel
    {
        $post = $this->posts()->findById($id);
        if ($post === null) {
            throw NotFoundException::forEntity('bài viết', $id);
        }

        return $post;
    }

    /**
     * Entry point form trang /admin/posts: chạy PostSaveFilter (kèm CSRF) trên
     * POST thô rồi mới ghi.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException mang lỗi từng trường (fieldErrors của filter)
     */
    public function saveForm(int $userId, ?int $id, array $raw): int
    {
        return $this->saveValidated($userId, $id, $raw, true);
    }

    /**
     * Entry point API JSON: publishedAt ISO 8601 (chuẩn API docs-dev/05 §1)
     * được quy về chuỗi giờ VN tường minh để dùng chung một filter format;
     * CSRF bỏ qua (SameSite=Lax — 07 §6). Trả trọn envelope — chuẩn 08 §5
     * (luồng API trả response từ Service).
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException field errors → ApiResultModel map 422
     */
    public function saveApi(int $userId, ?int $id, array $raw): ApiResponseModel
    {
        if (array_key_exists('publishedAt', $raw)) {
            $raw['publishedAt'] = self::isoToVnWallInput($raw['publishedAt']);
        }

        $savedId = $this->saveValidated($userId, $id, $raw, false);

        return ApiResultModel::ok($this->detailApiResponse($savedId));
    }

    /* ------------------------------------------------------------------ */
    /* Entry point API JSON — trả ApiResponseModel (docs-dev/05 §5.3)      */
    /* ------------------------------------------------------------------ */

    /** GET /posts — list + meta phân trang; bộ lọc do PostListFilter trong list(). */
    public function listApi(array $raw): ApiResponseModel
    {
        $result = $this->list($raw);

        return ApiResultModel::ok(
            array_map(
                static fn (PostModel $row): array => ApiResultModel::serializeModel($row),
                $result['rows']
            ),
            ['page' => $result['page'], 'perPage' => $result['perPage'], 'total' => $result['total']]
        );
    }

    /** GET /posts/counts (docs §5.12). */
    public function countsApi(): ApiResponseModel
    {
        return ApiResultModel::ok($this->counts());
    }

    /** GET /posts/{id} — bài + tagIds/tagNames. */
    public function readApi(int $id): ApiResponseModel
    {
        return ApiResultModel::ok($this->detailApiResponse($id));
    }

    /** DELETE /posts/{id} — xoá cứng kèm bảng con (§5.16). */
    public function deleteApi(int $id): ApiResponseModel
    {
        $this->delete($id);

        return ApiResultModel::ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * POST /posts/{id}/publish — body có thể kèm `publishedAt` ISO UTC (hẹn giờ).
     *
     * @param array<array-key, mixed> $body
     */
    public function publishApi(int $id, array $body): ApiResponseModel
    {
        $this->publish($id, self::isoToUtc($body['publishedAt'] ?? null));

        return ApiResultModel::ok($this->detailApiResponse($id));
    }

    /** POST /posts/{id}/archive. */
    public function archiveApi(int $id): ApiResponseModel
    {
        $this->archive($id);

        return ApiResultModel::ok($this->detailApiResponse($id));
    }

    /** POST /posts/{id}/draft — bài đã lên sóng → ConflictException (409). */
    public function draftApi(int $id): ApiResponseModel
    {
        $this->toDraft($id);

        return ApiResultModel::ok($this->detailApiResponse($id));
    }

    /* ---- FR-21: revision + autosave (docs §3.3.4(5), §5.13) ---- */

    /**
     * POST /posts/{id}/autosave — CHỈ giữ 1 bản autosave mới nhất mỗi bài
     * (xóa autosave cũ rồi insert kiểu autosave). Dùng filter KHÔNG yêu cầu nghiêm
     * (title/content được trống) để phù hợp luồng tự lưu mỗi 60s.
     *
     * @param array<array-key, mixed> $body
     */
    public function autosaveApi(int $id, int $userId, array $body): ApiResponseModel
    {
        $this->findOrFail($id);

        $title   = trim((string) ($body['title'] ?? ''));
        $excerpt = $this->nullableTrim($body['excerpt'] ?? null);
        $content = trim((string) ($body['content'] ?? ''));
        if ($title === '' && $content === '' && ($excerpt === null || $excerpt === '')) {
            return ApiResultModel::ok(['autosaved' => false, 'reason' => 'empty']);
        }

        // Lọc XSS để nội dung tự lưu không mang HTML độc khi khôi phục.
        $content = $this->purifier()->purify($content);

        $this->revisions()->deleteAutosaveByPostId($id);
        $this->revisions()->insertRevision(
            $id,
            $userId,
            PostRevisionMapper::TYPE_AUTOSAVE,
            $title !== '' ? $title : '(tự lưu)',
            $excerpt,
            $content
        );

        return ApiResultModel::ok(['autosaved' => true]);
    }

    /** GET /posts/{id}/revisions — lịch sử phiên bản. */
    public function revisionsApi(int $id): ApiResponseModel
    {
        $this->findOrFail($id);
        $rows = $this->revisions()->listByPostId($id);

        return ApiResultModel::ok(array_map(
            static fn (\Admin\Model\PostRevision\PostRevisionModel $m): array => $m->toArray(),
            $rows
        ));
    }

    /**
     * POST /posts/{id}/revisions/{revisionId}/restore — khôi phục phiên bản
     * (title/excerpt/content) về bài. Không đổi slug/status; ghi 1 revision
     * manual mới snapshot trạng thái trước khôi phục.
     */
    public function restoreApi(int $id, int $revisionId, int $userId): ApiResponseModel
    {
        $post = $this->findOrFail($id);
        $rev  = $this->revisions()->findById($revisionId);
        if ($rev === null || $rev->postId !== $id) {
            throw NotFoundException::forEntity('phiên bản', $revisionId);
        }

        $posts     = $this->posts();
        $revisions = $this->revisions();
        $this->db()->transactional(static function () use ($id, $post, $rev, $userId, $posts, $revisions): void {
            // Snapshot trạng thái hiện tại thành revision manual trước khi ghi đè.
            $revisions->insertRevision(
                $id,
                $userId,
                PostRevisionMapper::TYPE_MANUAL,
                $post->title,
                $post->excerpt,
                $post->content
            );
            $posts->update($id, [
                'title'          => $rev->title,
                'excerpt'        => $rev->excerpt,
                'content'        => $rev->content,
                'readingMinutes' => PostService::readingMinutes($rev->content),
            ]);
        });
        $this->trimRevisions($id);
        $this->invalidatePublicCaches();

        return ApiResultModel::ok(['restored' => true, 'revisionId' => $revisionId]);
    }

    /** POST /posts/{id}/preview-token — vô hiệu link cũ, trả token mới. */
    public function previewTokenApi(int $id): ApiResponseModel
    {
        return ApiResultModel::ok(['previewToken' => $this->regeneratePreviewToken($id)]);
    }

    /**
     * Shape `data` của một bài trên API: row đã serialize + tag.
     *
     * @return array{row: array<array-key, mixed>, tagIds: list<int>, tagNames: list<string>}
     */
    private function detailApiResponse(int $id): array
    {
        $detail = $this->detail($id);

        return [
            'row'      => ApiResultModel::serializeModel($detail['post']),
            'tagIds'   => $detail['tagIds'],
            'tagNames' => $detail['tagNames'],
        ];
    }

    /**
     * Tạo hoặc cập nhật bài theo intent draft|publish|schedule (docs §3.3.2–§3.3.3).
     * Nhận values ĐÃ qua PostSaveFilter (saveForm/saveApi mới là chỗ chạy filter).
     * Toàn bộ ghi (posts + post_tags + revision) nằm trong MỘT transaction.
     *
     * @param int                     $userId  admin đang thao tác (authorId)
     * @param int|null                $id      NULL = tạo mới
     * @param array<array-key, mixed> $data    đã validate
     * @param string                  $intent  draft|publish|schedule (PostConst::INTENT_*)
     * @param mixed                   $tagsCsv input "gõ-tìm-tạo-mới": chuỗi CSV tên tag hoặc mảng string
     *
     * @return int id bài
     *
     * @throws ValidationException  (banner thiếu khi publish, thời gian hẹn giờ sai format)
     * @throws NotFoundException    (bài/danh mục không còn)
     */
    public function save(int $userId, ?int $id, array $data, string $intent, mixed $tagsCsv = null): int
    {
        $isNew   = $id === null;
        $current = $id === null ? null : $this->findOrFail($id);

        $posts = $this->posts();
        $slugs = $this->slugs();

        $title   = trim((string) ($data['title'] ?? ''));
        $content = $this->purifier()->purify((string) ($data['content'] ?? ''));

        $slugBase = trim((string) ($data['slug'] ?? ''));
        $slug     = $slugBase !== ''
            ? $slugs->slugify($slugBase, PostConst::MAX_LENGTH_SLUG)
            : $slugs->slugify($title, PostConst::MAX_LENGTH_SLUG);
        $slug     = $slugs->unique(
            $slug !== '' ? $slug : 'bai-viet',
            static fn (string $candidate): bool => $posts->existsSlug($candidate, $id)
        );

        $publishedAtUtc = $this->resolvePublishedAt($data, $intent, $current);

        $categoryId = (int) ($data['categoryId'] ?? 0);
        if ($categoryId <= 0 || $this->categories()->findById($categoryId) === null) {
            throw new ValidationException(['categoryId' => PostConst::ERROR_CATEGORY_REQUIRED]);
        }

        $values = [
            'categoryId'       => $categoryId,
            'authorId'         => $userId,
            'title'            => $title,
            'slug'             => $slug,
            'excerpt'          => $this->nullableTrim($data['excerpt'] ?? null),
            'content'          => $content,
            'bannerMediaId'    => $this->nullablePositiveInt($data['bannerMediaId'] ?? null),
            'thumbnailMediaId' => $this->nullablePositiveInt($data['thumbnailMediaId'] ?? null),
            'isFeatured'       => $this->flag($data['isFeatured'] ?? null),
            'readingMinutes'   => self::readingMinutes($content),
            'metaTitle'        => $this->nullableTrim($data['metaTitle'] ?? null),
            'metaDescription'  => $this->nullableTrim($data['metaDescription'] ?? null),
        ];

        if ($intent === PostConst::INTENT_PUBLISH || $intent === PostConst::INTENT_SCHEDULE) {
            if ($values['bannerMediaId'] === null) {
                throw new ValidationException(['bannerMediaId' => PostConst::ERROR_BANNER_REQUIRED]);
            }

            $values['status']      = ContentConst::STATUS_PUBLISHED;
            $values['publishedAt'] = $publishedAtUtc;
        } elseif ($isNew) {
            $values['status']      = ContentConst::STATUS_DRAFT;
            $values['publishedAt'] = null;
        }
        // intent draft trên bài đang published/archived: KHÔNG đổi status —
        // chuyển trạng thái là hành động riêng theo 05-may-trang-thai.

        $tagIds   = $this->resolveTagIds($tagsCsv);
        $postTags = $this->postTags();

        $postId = $this->db()->transactional(
            function () use ($isNew, $id, $values, $tagIds, $userId, $posts, $postTags): int {
                if ($isNew) {
                    $values['previewToken'] = $this->newPreviewToken();
                    $postId = $posts->insert($values);
                } else {
                    $postId = (int) $id;
                    $posts->update($postId, $values);
                }

                $postTags->replaceForPost($postId, $tagIds);
                $this->recordRevision($postId, $userId, PostRevisionMapper::TYPE_MANUAL);

                return $postId;
            }
        );
        $this->invalidatePublicCaches();

        return $postId;
    }

    /**
     * Xuất bản / hẹn giờ từ bài đã lưu (docs §3.3.3). $publishedAtUtc NULL = ngay bây giờ.
     * publishedAt ĐÃ hiển thị công khai không bị ghi đè (chỉ hẹn giờ mới đổi thời điểm).
     */
    public function publish(int $id, ?string $publishedAtUtc = null): void
    {
        $post = $this->findOrFail($id);
        if ($post->bannerMediaId === null) {
            throw new ValidationException(['bannerMediaId' => PostConst::ERROR_BANNER_REQUIRED]);
        }

        $alreadyLive = $post->status === ContentConst::STATUS_PUBLISHED
            && $post->publishedAt !== null
            && $post->publishedAt <= self::nowUtc();

        $revisions = $this->revisions();
        $posts     = $this->posts();
        $this->db()->transactional(
            function () use ($id, $post, $publishedAtUtc, $alreadyLive, $revisions, $posts): void {
                $revisions->insertRevision(
                    $id,
                    $post->authorId,
                    PostRevisionMapper::TYPE_BEFORE_PUBLISH,
                    $post->title,
                    $post->excerpt,
                    $post->content
                );
                $this->trimRevisions($id);

                $posts->updatePublished(
                    $id,
                    $alreadyLive && $publishedAtUtc === null
                        ? $post->publishedAt
                        : ($publishedAtUtc ?? self::nowUtc())
                );
            }
        );
        // Bài vừa lên sóng / đổi hẹn giờ → trang chủ phải thấy ngay (FR-32).
        $this->invalidatePublicCaches();
    }

    /** Gỡ khỏi website — giữ toàn bộ dữ liệu (docs §3.3.3, 05-may-trang-thai). */
    public function archive(int $id): void
    {
        $this->findOrFail($id);
        $this->posts()->updateArchived($id);
        $this->invalidatePublicCaches();
    }

    /**
     * Về nháp / huỷ hẹn giờ: xoá publishedAt theo docs §3.3.3
     * (chỉ cho bài published chưa hiển thị hoặc archived; bài đã lên sóng thì
     * hướng dẫn admin archive trước).
     */
    public function toDraft(int $id): void
    {
        $post = $this->findOrFail($id);

        if ($post->status === ContentConst::STATUS_PUBLISHED && ! self::isScheduled($post)) {
            throw new ConflictException(
                ['Bài đã hiển thị công khai — dùng Lưu trữ để gỡ, không trả về nháp giữ URL.']
            );
        }

        $this->posts()->updateDrafted($id);
        $this->invalidatePublicCaches();
    }

    /** Sinh previewToken mới, vô hiệu link cũ (docs §3.3.2 FR-04). Trả token mới. */
    public function regeneratePreviewToken(int $id): string
    {
        $this->findOrFail($id);
        $token = $this->newPreviewToken();
        $this->posts()->updatePreviewToken($id, $token);

        return $token;
    }

    /**
     * Xoá cứng bài + bản ghi con, MỘT transaction (docs §5.16: post_tags,
     * post_revisions, post_view_daily, home_section_items(itemType=post), posts).
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $postTags  = $this->postTags();
        $revisions = $this->revisions();
        $views     = $this->views();
        $homeItems = $this->homeItems();
        $posts     = $this->posts();
        $this->db()->transactional(
            static function () use ($id, $postTags, $revisions, $views, $homeItems, $posts): void {
                $postTags->deleteByPostId($id);
                $revisions->deleteByPostId($id);
                $views->deleteByPostId($id);
                $homeItems->deleteByItem(ContentConst::SECTION_ITEM_POST, $id);
                $posts->delete($id);
            }
        );
        $this->invalidatePublicCaches();
    }

    /* ---- FR-21: revision view helpers cho trang quản trị ---- */

    /**
     * @return list<\Admin\Model\PostRevision\PostRevisionModel>
     */
    public function listRevisions(int $postId): array
    {
        $this->findOrFail($postId);

        return $this->revisions()->listByPostId($postId);
    }

    /**
     * Khôi phục phiên bản cho form trang (CSRF qua PostActionFilter + revisionId).
     *
     * @param array<array-key, mixed> $raw phải có `id` + `revisionId` + `csrf`
     */
    public function formRestore(int $userId, array $raw): string
    {
        $filter = new PostActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return self::actionFlag($filter);
        }

        $id = (int) $filter->idValue();
        $revisionIdRaw = $raw['revisionId'] ?? null;
        if (! is_numeric($revisionIdRaw) || (int) $revisionIdRaw <= 0) {
            return 'notfound';
        }

        try {
            $this->restoreApi($id, (int) $revisionIdRaw, $userId);

            return 'restored';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /* ------------------------------------------------------------------ */
    /* FR-22: thao tác hàng loạt (publish / archive / category / delete)    */
    /* ------------------------------------------------------------------ */

    /**
     * POST /posts/bulk — API JSON (không CSRF, phòng vệ SameSite=Lax).
     * Lỗi cứng (action sai, ids rỗng, xoá không xác nhận đủ số, danh mục
     * đích không hợp lệ) → ValidationException (422 qua fromThrowable);
     * lỗi từng bài (thiếu banner, bài không còn) chỉ `skipped`.
     *
     * @param array<array-key, mixed> $body
     */
    public function bulkApi(array $body): ApiResponseModel
    {
        $filter = new PostBulkFilter(false);
        $filter->setData($body);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        return ApiResultModel::ok($this->runBulk(
            $filter->actionValue(),
            $filter->idsRaw(),
            $filter->categoryIdValue(),
            $filter->confirmCountValue()
        ));
    }

    /**
     * Bulk cho form trang (CSRF qua PostBulkFilter). Trả cờ PRG + số đếm để
     * controller nhét vào query, view dựng thông báo.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{flag: string, applied: int, skipped: int}
     */
    public function formBulk(array $raw): array
    {
        $filter = new PostBulkFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return [
                'flag'    => isset($errors['csrf']) ? 'csrf' : PostConst::FLAG_BULK_INVALID,
                'applied' => 0,
                'skipped' => 0,
            ];
        }

        try {
            $result = $this->runBulk(
                $filter->actionValue(),
                $filter->idsRaw(),
                $filter->categoryIdValue(),
                $filter->confirmCountValue()
            );
        } catch (ValidationException) {
            return ['flag' => PostConst::FLAG_BULK_INVALID, 'applied' => 0, 'skipped' => 0];
        }

        return [
            'flag'    => PostConst::FLAG_BULK_DONE,
            'applied' => $result['applied'],
            'skipped' => count($result['skipped']),
        ];
    }

    /**
     * Lõi bulk: chạy từng id bằng chính method đơn lẻ (publish/archive/delete
     * — thừa hưởng validate + revision + cache), KHÔNG mở transaction gộp
     * (mỗi bài một phạm vi riêng, bài lỗi không kéo lùi bài đã xong).
     *
     * @param mixed $idsRaw mảng id (form/JSON) hoặc CSV — chuẩn hoá nội bộ
     *
     * @return array{applied: int, skipped: list<array{id: int, reason: string}>}
     */
    private function runBulk(
        string $action,
        mixed $idsRaw,
        ?int $categoryId,
        ?int $confirmCount,
    ): array {
        if (! in_array($action, PostConst::BULK_ACTIONS, true)) {
            throw new ValidationException(['action' => PostConst::ERROR_BULK_ACTION]);
        }

        $ids = self::normalizeBulkIds($idsRaw);
        if ($ids === []) {
            throw new ValidationException(['ids' => PostConst::ERROR_BULK_EMPTY]);
        }

        // Spec §3.3.1: xoá hàng loạt bắt buộc gõ đúng số bài để xác nhận.
        if ($action === PostConst::BULK_DELETE && $confirmCount !== count($ids)) {
            throw new ValidationException(['confirmCount' => PostConst::ERROR_BULK_CONFIRM]);
        }

        if (
            $action === PostConst::BULK_CATEGORY
            && ($categoryId === null || $this->categories()->findById($categoryId) === null)
        ) {
            throw new ValidationException(['categoryId' => PostConst::ERROR_BULK_CATEGORY]);
        }

        $applied = 0;
        $skipped = [];
        foreach ($ids as $id) {
            try {
                match ($action) {
                    PostConst::BULK_PUBLISH  => $this->publish($id),
                    PostConst::BULK_ARCHIVE  => $this->archive($id),
                    PostConst::BULK_DELETE   => $this->delete($id),
                    default                  => $this->moveCategorySingle($id, (int) $categoryId),
                };
                $applied++;
            } catch (ValidationException) {
                $skipped[] = ['id' => $id, 'reason' => 'banner'];
            } catch (NotFoundException) {
                $skipped[] = ['id' => $id, 'reason' => 'notfound'];
            } catch (ConflictException) {
                $skipped[] = ['id' => $id, 'reason' => 'conflict'];
            }
        }

        // category KHÔNG đi qua publish/archive/delete nên tự invalidate một lần.
        if ($action === PostConst::BULK_CATEGORY && $applied > 0) {
            $this->invalidatePublicCaches();
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function moveCategorySingle(int $id, int $categoryId): void
    {
        $this->findOrFail($id);
        $this->posts()->update($id, ['categoryId' => $categoryId]);
    }

    /**
     * ids gốc (mảng từ form `ids[]`/JSON hoặc CSV) → danh sách id dương,
     * đã loại trùng, giữ thứ tự xuất hiện.
     *
     * @param mixed $raw kiểm kiểu nội bộ — mọi giá trị không phải số dương bị bỏ
     *
     * @return list<int>
     */
    private static function normalizeBulkIds(mixed $raw): array
    {
        $items = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);

        /** @var list<int> $ids */
        $ids = [];
        /** @psalm-suppress MixedAssignment — $items kế thừa giá trị thô từ request (mixed chủ đích). */
        foreach ($items as $item) {
            if (is_numeric($item) && (int) $item > 0 && ! in_array((int) $item, $ids, true)) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /* Form hành động (PRG): chạy PostActionFilter rồi trả query flag     */
    /* ------------------------------------------------------------------ */

    /**
     * POST publish từ danh sách: raw phải có `id` (controller ghép từ route)
     * + `csrf` (+ `publishedAt` giờ VN nếu hẹn giờ).
     *
     * @param array<array-key, mixed> $raw
     */
    public function formPublish(array $raw): string
    {
        $filter = new PostActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return self::actionFlag($filter);
        }

        try {
            $this->publish((int) $filter->idValue(), self::vnWallTimeToUtc($filter->publishedAtRaw()));

            return PostConst::FLAG_PUBLISHED;
        } catch (ValidationException) {
            return 'banner-required';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** @param array<array-key, mixed> $raw */
    public function formArchive(array $raw): string
    {
        $filter = new PostActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return self::actionFlag($filter);
        }

        try {
            $this->archive((int) $filter->idValue());

            return PostConst::FLAG_ARCHIVED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** @param array<array-key, mixed> $raw */
    public function formDraft(array $raw): string
    {
        $filter = new PostActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return self::actionFlag($filter);
        }

        try {
            $this->toDraft((int) $filter->idValue());

            return PostConst::FLAG_DRAFTED;
        } catch (ConflictException) {
            return 'published-locked';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** @param array<array-key, mixed> $raw */
    public function formDelete(array $raw): string
    {
        $filter = new PostActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return self::actionFlag($filter);
        }

        try {
            $this->delete((int) $filter->idValue());

            return PostConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /* ------------------------------------------------------------------ */
    /* CSRF hash + options cho view (controller không đụng filter/mapper)  */
    /* ------------------------------------------------------------------ */

    /** Hash CSRF cho form soạn bài (PostSaveFilter). */
    public function saveFormCsrfHash(?int $id): string
    {
        return (new PostSaveFilter($this->posts(), $this->categories(), $id))->csrfHash();
    }

    /** Hash CSRF chung cho các form hành động trên dòng danh sách. */
    public function actionCsrfHash(): string
    {
        return (new PostActionFilter())->csrfHash();
    }

    /** Hash CSRF riêng cho form bulk ở danh sách (FR-22 — PostBulkFilter). */
    public function bulkCsrfHash(): string
    {
        return (new PostBulkFilter())->csrfHash();
    }

    /**
     * Options select cho form soạn bài + bộ lọc danh sách.
     *
     * @return array{
     *     categories: list<array{id: int, label: string}>,
     *     tags: list<array{id: int, name: string}>,
     *     media: list<array{id: int, label: string, path: string}>
     * }
     */
    public function formOptions(): array
    {
        $rows  = $this->categories()->listAll();
        $names = [];
        foreach ($rows as $row) {
            $names[$row->id] = $row->name;
        }

        $categoryOptions = [];
        foreach ($rows as $row) {
            $categoryOptions[] = [
                'id'    => $row->id,
                'label' => $row->parentId !== null && isset($names[$row->parentId])
                    ? '— ' . $names[$row->parentId] . ' / ' . $row->name
                    : $row->name,
            ];
        }

        $tagOptions = [];
        foreach ($this->tags()->listAll() as $tag) {
            $tagOptions[] = ['id' => $tag->id, 'name' => $tag->name];
        }

        return [
            'categories' => $categoryOptions,
            'tags'       => $tagOptions,
            'media'      => $this->mediaMapper()->listOptions(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers tĩnh dùng chung (giữ nguyên API cũ cho controller/CLI)      */
    /* ------------------------------------------------------------------ */

    /**
     * readingMinutes = ceil(số từ / 200), tối thiểu 1 (docs §3.3.4(7), FR-24).
     * Số từ = token tách theo khoảng trắng (đếm đúng cho tiếng Việt đa âm tiết).
     */
    public static function readingMinutes(string $htmlContent): int
    {
        $text  = trim(strip_tags($htmlContent));
        $words = [];
        if ($text !== '') {
            $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts)) {
                $words = $parts;
            }
        }

        return max(1, (int) ceil(count($words) / PostConst::WORDS_PER_MINUTE));
    }

    /** Bài đang hẹn giờ? (published + publishedAt tương lai — docs §5.7) */
    public static function isScheduled(PostModel $post): bool
    {
        return $post->status === ContentConst::STATUS_PUBLISHED
            && $post->publishedAt !== null
            && $post->publishedAt > self::nowUtc();
    }

    /**
     * Giờ VN tường minh (datetime-local form) → chuỗi UTC 'Y-m-d H:i:s'.
     * NULL/mỗi rỗng → null; sai format → ValidationException.
     */
    public static function vnWallTimeToUtc(mixed $input): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', trim($input));
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $normalized,
            new DateTimeZone('Asia/Ho_Chi_Minh')
        );
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $normalized,
                new DateTimeZone('Asia/Ho_Chi_Minh')
            );
        }

        if ($dt === false) {
            throw new ValidationException(['publishedAt' => PostConst::ERROR_INVALID_TIME]);
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * ISO 8601 (khai báo trong API, docs-dev/01-quy-chuan/05 §1) → chuỗi UTC DB.
     * Lõi parse ở Application\Service\DateService; đây là wrapper đổi null-sai-format
     * thành ValidationException đúng field `publishedAt` cho luồng Admin.
     */
    public static function isoToUtc(mixed $input): ?string
    {
        $utc = DateService::isoToUtc($input);
        if ($utc === null && is_string($input) && trim($input) !== '') {
            throw new ValidationException(['publishedAt' => PostConst::ERROR_INVALID_TIME]);
        }

        return $utc;
    }

    /**
     * ISO 8601 phía API → chuỗi giờ VN tường minh 'Y-m-d\TH:i' để tái sử dụng
     * đúng PostSaveFilter của form (một nguồn validate duy nhất).
     */
    public static function isoToVnWallInput(mixed $iso): ?string
    {
        $utc = self::isoToUtc($iso);

        return $utc === null ? null : PostModel::utcToVnWallInput($utc);
    }

    public static function nowUtc(): string
    {
        return DateService::nowUtc();
    }

    /* ------------------------------------------------------------------ */
    /* Nội bộ                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Chạy PostSaveFilter trên raw rồi chuyển tiếp sang save(). $withCsrf: form
     * trang true, API JSON false (cookie SameSite=Lax — 07 §6).
     *
     * @param array<array-key, mixed> $raw
     */
    private function saveValidated(int $userId, ?int $id, array $raw, bool $withCsrf): int
    {
        // Nút "Lưu nháp" (intent=draft) cho phép content trống + title ngắn để
        // soạn dở vẫn lưu được. CHỈ bật khi bài là NHÁP hoặc tạo mới: trên bài
        // đã xuất bản/lưu trữ, nháp không được xoá trắng nội dung công khai.
        $draftMode = (($raw['intent'] ?? null) === PostConst::INTENT_DRAFT)
            && ($id === null
                || $this->findOrFail($id)->status === ContentConst::STATUS_DRAFT);

        $filter = new PostSaveFilter($this->posts(), $this->categories(), $id, $draftMode, $withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $filter->getValues();
        $intent = (string) ($values['intent'] ?? PostConst::INTENT_DRAFT);

        return $this->save($userId, $id, $values, $intent, $values['tags'] ?? null);
    }

    /** Cờ PRG khi PostActionFilter không hợp lệ, theo đúng trường lỗi. */
    private static function actionFlag(PostActionFilter $filter): string
    {
        $errors = $filter->fieldErrors();

        return match (true) {
            isset($errors['csrf'])        => 'csrf',
            isset($errors['publishedAt']) => self::FLAG_TIME_INVALID,
            default                       => 'notfound',
        };
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{
     *     rows: list<PostModel>, total: int, page: int, perPage: int,
     *     counts: array<string, int>, tab: string,
     *     filters: array{
     *         categoryId: int, tagId: int, q: string,
     *         featured: string, dateFrom: string, dateTo: string
     *     }
     * }
     */
    private function emptyList(
        array $counts,
        int $page,
        int $perPage,
        PostListFilter $filter,
        ?int $categoryId,
        ?int $tagId,
        string $q,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $featured = false
    ): array {
        return [
            'rows'    => [],
            'total'   => 0,
            'page'    => $page,
            'perPage' => $perPage,
            'counts'  => $counts,
            'tab'     => $filter->tabValue(),
            'filters' => [
                'categoryId' => $categoryId ?? 0,
                'tagId'      => $tagId ?? 0,
                'q'          => $q,
                'featured'   => $featured ? '1' : '',
                'dateFrom'   => $dateFrom ?? '',
                'dateTo'     => $dateTo ?? '',
            ],
        ];
    }

    /** 'Y-m-d' lịch VN → đầu ngày VN quy về UTC; sai/rỗng → null (bỏ lọc). */
    private function vnDayToUtcStart(string $date): ?string
    {
        $dt = $this->vnDay($date);
        if ($dt === null) {
            return null;
        }

        return $dt->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** 'Y-m-d' lịch VN → cuối ngày VN quy về UTC; sai/rỗng → null (bỏ lọc). */
    private function vnDayToUtcEnd(string $date): ?string
    {
        $dt = $this->vnDay($date);
        if ($dt === null) {
            return null;
        }

        return $dt->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function vnDay(string $date): ?DateTimeImmutable
    {
        if (trim($date) === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', trim($date), new DateTimeZone('Asia/Ho_Chi_Minh'));

        return $dt === false ? null : $dt;
    }

    /** @return string|null chuỗi UTC 'Y-m-d H:i:s' cho publishedAt; NULL = không đặt */
    private function resolvePublishedAt(array $data, string $intent, ?PostModel $current): ?string
    {
        if ($intent === PostConst::INTENT_PUBLISH) {
            // Đã hẹn giờ → giữ lịch dự kiến nếu form không đổi; còn lại = ngay bây giờ
            if (
                $current !== null
                && self::isScheduled($current)
                && self::vnWallTimeToUtc($data['publishedAt'] ?? null) === null
            ) {
                return $current->publishedAt ?? self::nowUtc();
            }

            return self::nowUtc();
        }

        if ($intent === PostConst::INTENT_SCHEDULE) {
            $utc = self::vnWallTimeToUtc($data['publishedAt'] ?? null);
            if ($utc === null) {
                throw new ValidationException(['publishedAt' => 'Chọn thời điểm hẹn giờ (giờ Việt Nam).']);
            }

            return $utc;
        }

        return null;
    }

    /**
     * Chuỗi CSV tên tag / mảng string → danh sách tagId, tag mới tự tạo
     * (docs §3.4 "gõ để tìm, chưa có thì tạo mới").
     *
     * @return list<int>
     */
    private function resolveTagIds(mixed $tagsCsv): array
    {
        if (is_array($tagsCsv)) {
            $parts = $tagsCsv;
        } elseif (is_string($tagsCsv) && trim($tagsCsv) !== '') {
            $parts = preg_split('/[,;]/u', $tagsCsv) ?: [];
        } else {
            $parts = [];
        }

        $tagService = $this->tagService();
        $ids        = [];
        /** @psalm-suppress MixedAssignment — $parts là mixed theo input tagsCsv */
        foreach ($parts as $part) {
            $name = trim((string) $part);
            if ($name === '') {
                continue;
            }

            $ids[] = $tagService->ensureByName($name);
            if (count($ids) >= PostConst::MAX_TAGS_PER_POST) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    private function recordRevision(int $postId, int $userId, int $type): void
    {
        $post = $this->posts()->findById($postId);
        if ($post === null) {
            return;
        }

        $this->revisions()->insertRevision(
            $postId,
            $userId,
            $type,
            $post->title,
            $post->excerpt,
            $post->content
        );
        $this->trimRevisions($postId);
    }

    /** Cắt revision thủ công còn > 20 bản, ngay sau khi ghi (docs §5.13 — không job). */
    private function trimRevisions(int $postId): void
    {
        $revisions = $this->revisions();
        $keep      = $revisions->recentKeptIds($postId, PostConst::REVISION_KEEP);
        $revisions->deleteOlderThan($postId, $keep);
    }

    private function newPreviewToken(): string
    {
        return bin2hex(random_bytes(PostConst::PREVIEW_TOKEN_BYTES));
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false || $raw === 0
            ? 0
            : 1;
    }

    private function nullableTrim(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function nullablePositiveInt(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** PageCacheService là dependency mềm (FR-39): test container thiếu key → null. */
    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /**
     * FR-32/FR-11: mọi thay đổi bài (nội dung/trạng thái/xoá) đều ảnh hưởng
     * khối posts trang chủ VÀ sitemap (/tin-tuc/{slug}) — ép tính lại
     * 'home-v1' + 'sitemap-v1' ngay, không đợi TTL 60s. forget() idempotent,
     * gọi cả khi không đổi gì cũng rẻ.
     */
    private function invalidatePublicCaches(): void
    {
        $cache = $this->pageCache();
        $cache?->forget(CacheConst::KEY_HOME);
        $cache?->forget(CacheConst::KEY_SITEMAP);
    }
}
