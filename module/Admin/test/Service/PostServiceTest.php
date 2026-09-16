<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\Post\PostConst;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostRevision\PostRevisionMapper;
use Admin\Model\PostRevision\PostRevisionModel;
use Admin\Model\PostTag\PostTagMapper;
use Admin\Model\PostViewDaily\PostViewDailyMapper;
use Admin\Model\Tag\TagMapper;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\PostService;
use Admin\Service\TagService;
use Application\Constant\CacheConst;
use Application\Constant\ContentConst;
use Application\Service\DbService;
use Application\Service\HtmlPurifierService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use ApplicationTest\Helper\TestContainer;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PostServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private PostTagMapper&MockObject $postTags;
    private PostRevisionMapper&MockObject $revisions;
    private PostViewDailyMapper&MockObject $viewDaily;
    private HomeSectionItemMapper&MockObject $sectionItems;
    private CategoryMapper&MockObject $categories;
    private TagMapper&MockObject $tags;
    private TagService&MockObject $tagService;
    private SlugService&MockObject $slugs;
    private HtmlPurifierService&MockObject $purifier;
    private DbService&MockObject $db;
    private PostService $service;

    protected function setUp(): void
    {
        $this->posts        = $this->createMock(PostMapper::class);
        $this->postTags     = $this->createMock(PostTagMapper::class);
        $this->revisions    = $this->createMock(PostRevisionMapper::class);
        $this->viewDaily    = $this->createMock(PostViewDailyMapper::class);
        $this->sectionItems = $this->createMock(HomeSectionItemMapper::class);
        $this->categories   = $this->createMock(CategoryMapper::class);
        $this->tags         = $this->createMock(TagMapper::class);
        $this->tagService   = $this->createMock(TagService::class);
        $this->slugs        = $this->createMock(SlugService::class);
        $this->purifier     = $this->createMock(HtmlPurifierService::class);
        $this->db           = $this->createMock(DbService::class);

        $this->purifier->method('purify')->willReturnArgument(0);
        $this->slugs->method('slugify')->willReturnCallback(
            static fn (string $t): string => strtolower(preg_replace('/\s+/', '-', trim($t)) ?? '')
        );
        $this->slugs->method('unique')->willReturnCallback(static fn (string $b): string => $b);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );
        $this->categories->method('findById')->willReturnCallback(
            static fn (int $id): ?CategoryModel => $id === 3
                ? CategoryModel::fromRow(['id' => 3])
                : null
        );

        $this->service = (new PostService())->setContainer(new TestContainer([
            PostMapper::class            => $this->posts,
            PostTagMapper::class         => $this->postTags,
            PostRevisionMapper::class    => $this->revisions,
            PostViewDailyMapper::class   => $this->viewDaily,
            HomeSectionItemMapper::class => $this->sectionItems,
            CategoryMapper::class        => $this->categories,
            TagMapper::class             => $this->tags,
            TagService::class            => $this->tagService,
            SlugService::class           => $this->slugs,
            HtmlPurifierService::class   => $this->purifier,
            DbService::class             => $this->db,
        ]));
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function postData(array $overrides = []): array
    {
        return $overrides + [
            'title'      => 'Hà Nội vào thu',
            'slug'       => '',
            'categoryId' => 3,
            'content'    => '<p>Mùa thu Hà Nội rất đẹp.</p>',
            'excerpt'    => '',
            'bannerMediaId' => '',
            'thumbnailMediaId' => '',
            'isFeatured' => '',
            'publishedAt' => '',
            'metaTitle'  => '',
            'metaDescription' => '',
        ];
    }

    public function testReadingMinutesUsesWordCountOverTwoHundred(): void
    {
        self::assertSame(1, PostService::readingMinutes('<p>' . str_repeat('word ', 10) . '</p>'));
        self::assertSame(2, PostService::readingMinutes('<p>' . str_repeat('word ', 201) . '</p>'));
        self::assertSame(1, PostService::readingMinutes(''));
    }

    public function testVnWallTimeToUtcConvertsSevenHoursBehind(): void
    {
        self::assertSame('2026-09-12 02:00:00', PostService::vnWallTimeToUtc('2026-09-12T09:00'));
        self::assertNull(PostService::vnWallTimeToUtc(''));
        self::assertNull(PostService::vnWallTimeToUtc(null));
    }

    public function testVnWallTimeRejectsGarbage(): void
    {
        $this->expectException(ValidationException::class);

        PostService::vnWallTimeToUtc('12/09/2026 9h');
    }

    public function testIsoToUtcHandlesZAndPlainUtc(): void
    {
        self::assertSame('2026-09-12 03:00:00', PostService::isoToUtc('2026-09-12T03:00:00Z'));
        self::assertSame('2026-09-12 03:00:00', PostService::isoToUtc('2026-09-12 03:00:00'));
        self::assertNull(PostService::isoToUtc(null));
    }

    public function testSaveAsDraftCreatesPreviewTokenAndRevision(): void
    {
        $this->posts->method('existsSlug')->willReturn(false);
        $this->posts->expects(self::once())->method('insert')->willReturnCallback(
            function (array $values): int {
                self::assertSame(ContentConst::STATUS_DRAFT, $values['status']);
                self::assertNull($values['publishedAt']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $values['previewToken']);

                return 42;
            }
        );
        $this->posts->method('findById')->willReturn(
            PostModel::fromRow($this->postData() + ['id' => 42, 'status' => 0])
        );
        $this->postTags->expects(self::once())->method('replaceForPost')->with(42, [7]);
        $this->tagService->method('ensureByName')->with('Thu')->willReturn(7);
        $this->revisions->expects(self::once())->method('insertRevision')
            ->with(42, 1, PostRevisionMapper::TYPE_MANUAL, self::anything(), null, self::anything());
        $this->revisions->method('recentKeptIds')->willReturn([1]);
        $this->revisions->expects(self::once())->method('deleteOlderThan')->with(42, [1]);

        $id = $this->service->save(1, null, $this->postData(), PostConst::INTENT_DRAFT, 'Thu');

        self::assertSame(42, $id);
    }

    public function testPublishIntentWithoutBannerFailsValidation(): void
    {
        $this->posts->method('existsSlug')->willReturn(false);
        $this->posts->expects(self::never())->method('insert');

        try {
            $this->service->save(1, null, $this->postData(), PostConst::INTENT_PUBLISH);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                ['bannerMediaId' => PostConst::ERROR_BANNER_REQUIRED],
                $e->getErrors()
            );
        }
    }

    public function testScheduleIntentStoresFutureUtcTime(): void
    {
        $this->posts->method('existsSlug')->willReturn(false);
        $this->posts->expects(self::once())->method('insert')->willReturnCallback(
            function (array $values): int {
                self::assertSame(ContentConst::STATUS_PUBLISHED, $values['status']);
                self::assertSame('2030-01-01 02:00:00', $values['publishedAt']);

                return 5;
            }
        );
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 5,
            'status' => 1,
            'title' => 't',
            'content' => 'c',
            'excerpt' => null,
        ]));
        $this->revisions->method('recentKeptIds')->willReturn([]);

        $this->service->save(
            1,
            null,
            $this->postData(['bannerMediaId' => '9', 'publishedAt' => '2030-01-01T09:00']),
            PostConst::INTENT_SCHEDULE
        );
    }

    public function testDraftIntentOnPublishedRowDoesNotTouchStatus(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 8,
            'status' => ContentConst::STATUS_PUBLISHED,
            'publishedAt' => '2026-01-01 00:00:00',
            'title' => 'old',
            'content' => '<p>old</p>',
            'excerpt' => null,
        ]));
        $this->posts->method('existsSlug')->willReturn(false);
        $this->posts->expects(self::once())->method('update')->with(8, self::callback(
            static fn (array $values): bool => ! array_key_exists('status', $values)
        ));
        $this->revisions->method('recentKeptIds')->willReturn([]);

        $this->service->save(1, 8, $this->postData(), PostConst::INTENT_DRAFT);
    }

    public function testPublishActionWithoutBannerThrowsValidation(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 2, 'status' => 0, 'title' => 't', 'content' => 'c', 'bannerMediaId' => null,
        ]));

        $this->expectException(ValidationException::class);

        $this->service->publish(2);
    }

    public function testPublishActionRecordsBeforePublishRevision(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 2,
            'status' => ContentConst::STATUS_DRAFT,
            'authorId' => 1,
            'title' => 't',
            'content' => 'c',
            'excerpt' => null,
            'bannerMediaId' => 9,
            'publishedAt' => null,
        ]));
        $this->revisions->expects(self::once())->method('insertRevision')
            ->with(2, 1, PostRevisionMapper::TYPE_BEFORE_PUBLISH, 't', null, 'c');
        $this->revisions->method('recentKeptIds')->willReturn([10]);
        $this->revisions->expects(self::once())->method('deleteOlderThan')->with(2, [10]);
        $this->posts->expects(self::once())->method('updatePublished')->with(
            2,
            self::callback(
                static fn (?string $v): bool => is_string($v)
                    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) === 1
            )
        );

        $this->service->publish(2);
    }

    public function testToDraftBlockedForLivePublishedPost(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 6,
            'status' => ContentConst::STATUS_PUBLISHED,
            'publishedAt' => '2020-01-01 00:00:00',
        ]));
        $this->posts->expects(self::never())->method('updateDrafted');

        $this->expectException(ConflictException::class);

        $this->service->toDraft(6);
    }

    public function testToDraftClearsScheduledTime(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 6,
            'status' => ContentConst::STATUS_PUBLISHED,
            'publishedAt' => $future,
        ]));
        $this->posts->expects(self::once())->method('updateDrafted')->with(6);

        $this->service->toDraft(6);
    }

    public function testDeleteCleansAllChildTablesInOneTransaction(): void
    {
        $this->posts->method('findById')->with(4)->willReturn(PostModel::fromRow(['id' => 4]));
        $this->postTags->expects(self::once())->method('deleteByPostId')->with(4);
        $this->revisions->expects(self::once())->method('deleteByPostId')->with(4);
        $this->viewDaily->expects(self::once())->method('deleteByPostId')->with(4);
        $this->sectionItems->expects(self::once())->method('deleteByItem')
            ->with(ContentConst::SECTION_ITEM_POST, 4);
        $this->posts->expects(self::once())->method('delete')->with(4);
        $this->db->expects(self::once())->method('transactional');

        $this->service->delete(4);
    }

    public function testListShortCircuitsEmptyTagResult(): void
    {
        $this->posts->method('countTabs')->willReturn(['all' => 0]);
        $this->postTags->method('postIdsByTag')->with(9)->willReturn([]);
        $this->posts->expects(self::never())->method('paginate');

        $result = $this->service->list(['tagId' => '9']);

        self::assertSame([], $result['rows']);
        self::assertSame(0, $result['total']);
    }

    public function testListAttachesCategoryNamesInBatch(): void
    {
        $this->posts->method('countTabs')->willReturn(['all' => 2]);
        $this->posts->method('paginate')->willReturn([
            'rows'  => [
                PostModel::fromRow(['id' => 1, 'categoryId' => 3, 'title' => 'A']),
                PostModel::fromRow(['id' => 2, 'categoryId' => 4, 'title' => 'B']),
            ],
            'total' => 2,
        ]);
        $this->categories->method('getNamesByIds')->with([3, 4])->willReturn([3 => 'Đời sống']);

        $result = $this->service->list([]);

        self::assertSame('Đời sống', $result['rows'][0]->categoryName);
        self::assertSame('(đã xoá)', $result['rows'][1]->categoryName);
    }

    public function testDetailReturnsTagsForForm(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow(['id' => 5, 'title' => 'x']));
        $this->postTags->method('tagIdsForPost')->with(5)->willReturn([2, 4]);
        $this->tags->method('getNamesByIds')->with([2, 4])->willReturn([2 => 'Hà Nội', 4 => 'Thu']);

        $detail = $this->service->detail(5);

        self::assertSame(5, $detail['post']->id);
        self::assertSame([2, 4], $detail['tagIds']);
        self::assertSame(['Hà Nội', 'Thu'], $detail['tagNames']);
    }

    public function testFindOrFailThrowsNotFound(): void
    {
        $this->posts->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->findOrFail(404);
    }

    /** FR-32/FR-11: publish/archive/delete bài đều forget 'home-v1' + 'sitemap-v1'. */
    public function testWritesForgetPublicCaches(): void
    {
        $forgotten = [];
        $storage   = $this->createMock(StorageInterface::class);
        $storage->method('removeItem')->willReturnCallback(
            static function (string $key) use (&$forgotten): bool {
                $forgotten[] = $key;

                return true;
            }
        );
        $this->posts->method('findById')
            ->willReturn(PostModel::fromRow(['id' => 9, 'title' => 'x', 'bannerMediaId' => 5]));

        $service = (new PostService())->setContainer(new TestContainer([
            PostMapper::class            => $this->posts,
            PostTagMapper::class         => $this->postTags,
            PostRevisionMapper::class    => $this->revisions,
            PostViewDailyMapper::class   => $this->viewDaily,
            HomeSectionItemMapper::class => $this->sectionItems,
            CategoryMapper::class        => $this->categories,
            TagMapper::class             => $this->tags,
            TagService::class            => $this->tagService,
            SlugService::class           => $this->slugs,
            HtmlPurifierService::class   => $this->purifier,
            DbService::class             => $this->db,
            PageCacheService::class      => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $service->publish(9);
        $service->archive(9);
        $service->delete(9);

        self::assertCount(6, $forgotten);
        self::assertSame(
            [CacheConst::KEY_HOME, CacheConst::KEY_SITEMAP],
            array_values(array_unique($forgotten))
        );
    }

    /* ================= FR-21: autosave / revisions / restore ================= */

    /**
     * Mảng `data` thuần trong envelope ApiResponseModel (JsonModel giữ
     * biến render dạng mixed — helper này gom lại một chỗ cho gọn assert).
     *
     * @return array<array-key, mixed>
     */
    private static function apiData(ApiResponseModel $response): array
    {
        /** @var array<array-key, mixed> $payload */
        $payload = $response->getVariables();
        /** @var array<array-key, mixed>|null $data */
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    public function testAutosaveEmptyBodySkipsWrite(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow(['id' => 3]));
        $this->revisions->expects(self::never())->method('deleteAutosaveByPostId');
        $this->revisions->expects(self::never())->method('insertRevision');

        $response = $this->service->autosaveApi(3, 1, []);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse((bool) (self::apiData($response)['autosaved'] ?? true));
    }

    public function testAutosaveReplacesPreviousAutosaveOnly(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow(['id' => 3]));
        $this->revisions->expects(self::once())->method('deleteAutosaveByPostId')->with(3);
        $this->revisions->expects(self::once())->method('insertRevision')
            ->with(3, 1, PostRevisionMapper::TYPE_AUTOSAVE, 'Tựa', 'Sapo', '<p>temp</p>');

        $response = $this->service->autosaveApi(3, 1, [
            'title' => 'Tựa', 'excerpt' => 'Sapo', 'content' => '<p>temp</p>',
        ]);

        self::assertTrue((bool) (self::apiData($response)['autosaved'] ?? false));
    }

    public function testAutosaveMissingPostThrowsNotFound(): void
    {
        $this->posts->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->autosaveApi(99, 1, ['title' => 't', 'content' => 'c']);
    }

    public function testRevisionsApiListsModels(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow(['id' => 3]));
        $this->revisions->method('listByPostId')->with(3)->willReturn([
            PostRevisionModel::fromRow([
                'id' => 12, 'postId' => 3, 'type' => PostRevisionMapper::TYPE_AUTOSAVE,
                'title' => 'a', 'content' => 'b', 'createdAt' => '2026-09-14 03:00:00',
            ]),
        ]);

        $rows = self::apiData($this->service->revisionsApi(3));
        $row  = (array) ($rows[0] ?? []);

        self::assertSame(12, (int) ($row['id'] ?? 0));
        self::assertSame('Tự lưu', (string) ($row['typeLabel'] ?? ''));
    }

    public function testRestoreForeignRevisionThrowsNotFound(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow(['id' => 3]));
        $this->revisions->method('findById')->willReturn(
            PostRevisionModel::fromRow(['id' => 7, 'postId' => 4])
        );

        $this->expectException(NotFoundException::class);

        $this->service->restoreApi(3, 7, 1);
    }

    public function testRestoreSnapshotsCurrentThenWritesRevisionContent(): void
    {
        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 3, 'title' => 'hiện tại', 'excerpt' => 'e', 'content' => '<p>hiện tại</p>',
        ]));
        $this->revisions->method('findById')->willReturn(
            PostRevisionModel::fromRow([
                'id' => 7, 'postId' => 3, 'title' => 'bản cũ', 'excerpt' => null, 'content' => '<p>cũ</p>',
            ])
        );
        $this->revisions->expects(self::once())->method('insertRevision')
            ->with(3, 1, PostRevisionMapper::TYPE_MANUAL, 'hiện tại', 'e', '<p>hiện tại</p>');
        $this->posts->expects(self::once())->method('update')->with(3, self::callback(
            static fn (array $v): bool => $v['title'] === 'bản cũ'
                && $v['excerpt'] === null
                && $v['content'] === '<p>cũ</p>'
                && $v['readingMinutes'] === 1
        ));
        $this->revisions->method('recentKeptIds')->willReturn([7, 8]);
        $this->revisions->expects(self::once())->method('deleteOlderThan')->with(3, [7, 8]);

        self::assertTrue(
            (bool) (self::apiData($this->service->restoreApi(3, 7, 1))['restored'] ?? false)
        );
    }

    public function testFormRestoreRejectsBadCsrf(): void
    {
        $this->revisions->expects(self::never())->method('findById');

        self::assertSame(
            'csrf',
            $this->service->formRestore(1, ['id' => '3', 'revisionId' => '7', 'csrf' => 'sai-token'])
        );
    }

    public function testFormRestoreRejectsMissingRevisionId(): void
    {
        $this->revisions->expects(self::never())->method('findById');

        self::assertSame(
            'notfound',
            $this->service->formRestore(1, [
                'id' => '3', 'revisionId' => 'abc', 'csrf' => $this->service->actionCsrfHash(),
            ])
        );
    }

    /* ================= FR-22: thao tác hàng loạt ================= */

    public function testBulkRejectsUnknownActionAndEmptyIds(): void
    {
        try {
            $this->service->bulkApi(['action' => 'teleport', 'ids' => [1]]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('action', $e->getErrors());
        }

        $this->expectException(ValidationException::class);

        $this->service->bulkApi(['action' => 'publish', 'ids' => []]);
    }

    public function testBulkDeleteRequiresExactConfirmCount(): void
    {
        $this->revisions->expects(self::never())->method('deleteByPostId');

        try {
            $this->service->bulkApi(['action' => 'delete', 'ids' => [1, 2]]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirmCount', $e->getErrors());
        }

        $this->expectException(ValidationException::class);

        $this->service->bulkApi(['action' => 'delete', 'ids' => [1, 2], 'confirmCount' => 1]);
    }

    public function testBulkPublishSkipsInvalidItemsPerId(): void
    {
        $noBanner   = PostModel::fromRow(['id' => 1, 'status' => 0, 'title' => 'a', 'content' => 'c']);
        $withBanner = PostModel::fromRow([
            'id' => 3, 'status' => 0, 'authorId' => 1, 'title' => 'a', 'content' => 'c', 'bannerMediaId' => 9,
        ]);
        $this->posts->method('findById')->willReturnMap([[1, $noBanner], [2, null], [3, $withBanner]]);
        $this->revisions->method('recentKeptIds')->willReturn([]);
        $this->posts->expects(self::once())->method('updatePublished');

        $payload = self::apiData($this->service->bulkApi([
            'action' => 'publish', 'ids' => [1, 2, 3],
        ]));

        self::assertSame(1, (int) $payload['applied']);
        $skipped = (array) $payload['skipped'];
        self::assertCount(2, $skipped);
        self::assertSame('banner', (string) ((array) $skipped[0])['reason']);
        self::assertSame('notfound', (string) ((array) $skipped[1])['reason']);
    }

    public function testBulkDeleteWithConfirmAppliesAll(): void
    {
        $this->posts->method('findById')->willReturnMap([
            [1, PostModel::fromRow(['id' => 1])],
            [2, PostModel::fromRow(['id' => 2])],
        ]);
        $this->posts->expects(self::exactly(2))->method('delete');

        $payload = self::apiData($this->service->bulkApi([
            'action' => 'delete', 'ids' => [1, 2], 'confirmCount' => 2,
        ]));

        self::assertSame(2, (int) $payload['applied']);
    }

    public function testBulkCategoryRejectsUnknownTarget(): void
    {
        // setUp: chỉ dm id=3 tồn tại → 99 phải bị chặn trước mọi vòng lặp.
        $this->posts->expects(self::never())->method('update');

        $this->expectException(ValidationException::class);

        $this->service->bulkApi(['action' => 'category', 'ids' => [1], 'categoryId' => 99]);
    }

    public function testBulkCategoryMovesExistingAndSkipsMissing(): void
    {
        $this->posts->method('findById')->willReturnCallback(
            static fn (int $id): ?PostModel => $id === 1
                ? PostModel::fromRow(['id' => 1])
                : null
        );
        $this->posts->expects(self::once())->method('update')->with(1, ['categoryId' => 3]);

        $payload = self::apiData($this->service->bulkApi([
            'action' => 'category', 'ids' => '1,5', 'categoryId' => 3,
        ]));

        self::assertSame(1, (int) $payload['applied']);
        self::assertCount(1, (array) $payload['skipped']);
    }

    public function testFormBulkFlags(): void
    {
        self::assertSame(
            'csrf',
            $this->service->formBulk(['action' => 'publish', 'ids' => '1'])['flag']
        );
        self::assertSame(
            PostConst::FLAG_BULK_INVALID,
            $this->service->formBulk([
                'action' => 'teleport', 'ids' => '1', 'csrf' => $this->service->bulkCsrfHash(),
            ])['flag']
        );

        $this->posts->method('findById')->willReturn(PostModel::fromRow([
            'id' => 2, 'status' => 0, 'authorId' => 1, 'title' => 'a', 'content' => 'c', 'bannerMediaId' => 9,
        ]));
        $this->revisions->method('recentKeptIds')->willReturn([]);

        $result = $this->service->formBulk([
            'action' => 'publish', 'ids' => ['2'], 'csrf' => $this->service->bulkCsrfHash(),
        ]);

        self::assertSame(PostConst::FLAG_BULK_DONE, $result['flag']);
        self::assertSame(1, $result['applied']);
    }
}
