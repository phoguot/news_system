<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Category\CategoryConst;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Post\PostMapper;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\CategoryService;
use Application\Constant\CacheConst;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use ApplicationTest\Helper\TestContainer;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CategoryServiceTest extends TestCase
{
    private CategoryMapper&MockObject $categories;
    private PostMapper&MockObject $posts;
    private SlugService&MockObject $slugs;
    private DbService&MockObject $db;
    private CategoryService $service;

    protected function setUp(): void
    {
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->posts      = $this->createMock(PostMapper::class);
        $this->slugs      = $this->createMock(SlugService::class);
        $this->db         = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );

        $this->slugs->method('slugify')
            ->willReturnCallback(static fn (string $t): string => strtolower(trim($t)));
        $this->slugs->method('unique')
            ->willReturnCallback(static fn (string $base): string => $base);

        $this->service = (new CategoryService())->setContainer(new TestContainer([
            CategoryMapper::class => $this->categories,
            PostMapper::class     => $this->posts,
            SlugService::class    => $this->slugs,
            DbService::class      => $this->db,
        ]));
    }

    public function testListOrdersChildrenRightAfterParentAndOrphansLast(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 2, 'parentId' => 1, 'name' => 'Con A']),
            CategoryModel::fromRow(['id' => 1, 'parentId' => null, 'name' => 'Cha']),
            CategoryModel::fromRow(['id' => 5, 'parentId' => 99, 'name' => 'Mồ côi']),
            CategoryModel::fromRow(['id' => 3, 'parentId' => 1, 'name' => 'Con B']),
        ]);

        $ordered = $this->service->listOrdered();

        self::assertSame(
            [1, 2, 3, 5],
            array_map(static fn (array $i): int => $i['category']->id, $ordered)
        );
        self::assertSame([0, 1, 1, 1], array_map(static fn (array $i): int => $i['depth'], $ordered));
    }

    public function testCreateInsertsSlugGeneratedFromName(): void
    {
        $this->categories->method('existsSlug')->willReturn(false);
        $captured = null;
        $this->categories->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 11;
            });

        $id = $this->service->create(['name' => 'Phóng sự', 'isActive' => '1']);

        self::assertSame(11, $id);
        self::assertIsArray($captured);
        self::assertNull($captured['parentId']);
        self::assertSame(1, $captured['isActive']);
        self::assertSame(0, $captured['sortOrder']);
    }

    public function testCreateUnderSecondLevelParentIsRejected(): void
    {
        $this->categories->method('findById')
            ->with(4)
            ->willReturn(CategoryModel::fromRow(['id' => 4, 'parentId' => 1]));

        try {
            $this->service->create(['name' => 'X', 'parentId' => '4']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['parentId' => CategoryConst::ERROR_PARENT_DEPTH], $e->getErrors());
        }
    }

    public function testUpdateToOwnIdFails(): void
    {
        $this->categories->method('findById')
            ->willReturn(CategoryModel::fromRow(['id' => 7, 'parentId' => null]));

        try {
            $this->service->update(7, ['name' => 'X', 'parentId' => '7']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['parentId' => CategoryConst::ERROR_PARENT_SELF], $e->getErrors());
        }
    }

    public function testUpdateParentWhenHasChildrenFails(): void
    {
        $this->categories->method('findById')->willReturnCallback(
            static fn (int $id): CategoryModel => $id === 8
                ? CategoryModel::fromRow(['id' => 8, 'parentId' => null])
                : CategoryModel::fromRow(['id' => 9, 'parentId' => null])
        );
        $this->categories->method('countChildren')->with(8)->willReturn(2);

        try {
            $this->service->update(8, ['name' => 'X', 'parentId' => '9']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['parentId' => CategoryConst::ERROR_PARENT_HAS_CHILD], $e->getErrors());
        }
    }

    public function testDeleteBlockedWhenStillHasPosts(): void
    {
        $this->categories->method('findById')->willReturn(CategoryModel::fromRow(['id' => 3, 'parentId' => null]));
        $this->categories->method('countChildren')->willReturn(0);
        $this->posts->method('countByCategoryId')->with(3)->willReturn(4);
        $this->categories->expects(self::never())->method('delete');

        $this->expectException(ConflictException::class);

        $this->service->delete(3);
    }

    public function testDeleteBlockedReasonsListBothConstraints(): void
    {
        $this->categories->method('findById')->willReturn(CategoryModel::fromRow(['id' => 3]));
        $this->categories->method('countChildren')->willReturn(1);
        $this->posts->method('countByCategoryId')->willReturn(2);

        try {
            $this->service->delete(3);
            self::fail('Expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(
                [CategoryConst::ERROR_DELETE_CHILDREN, CategoryConst::ERROR_DELETE_POSTS],
                $e->getReasons()
            );
        }
    }

    public function testDeleteHardDeletesWhenUnblocked(): void
    {
        $this->categories->method('findById')->willReturn(CategoryModel::fromRow(['id' => 3]));
        $this->categories->method('countChildren')->willReturn(0);
        $this->posts->method('countByCategoryId')->willReturn(0);
        $this->categories->expects(self::once())->method('delete')->with(3);

        $this->service->delete(3);
    }

    public function testFindOrFailThrowsNotFound(): void
    {
        $this->categories->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->findOrFail(999);
    }

    /** FR-02/FR-11: create/update/delete danh mục forget 'category-menu-v1' + 'home-v1' + 'sitemap-v1'. */
    public function testWritesForgetCategoryMenuAndHomeCaches(): void
    {
        $forgotten = [];
        $storage   = $this->createMock(StorageInterface::class);
        $storage->method('removeItem')->willReturnCallback(
            static function (string $key) use (&$forgotten): bool {
                $forgotten[] = $key;

                return true;
            }
        );

        $this->categories->method('existsSlug')->willReturn(false);
        $this->categories->method('findById')->willReturn(CategoryModel::fromRow(['id' => 3]));
        $this->categories->method('countChildren')->willReturn(0);
        $this->categories->method('insert')->willReturn(3);
        $this->posts->method('countByCategoryId')->willReturn(0);

        $service = (new CategoryService())->setContainer(new TestContainer([
            CategoryMapper::class   => $this->categories,
            PostMapper::class       => $this->posts,
            SlugService::class      => $this->slugs,
            PageCacheService::class => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $service->create(['name' => 'X']);
        $service->update(3, ['name' => 'Y']);
        $service->delete(3);

        self::assertCount(9, $forgotten);
        self::assertSame(
            [CacheConst::KEY_CATEGORY_MENU, CacheConst::KEY_HOME, CacheConst::KEY_SITEMAP],
            array_values(array_unique($forgotten))
        );
    }

    /* ---- FR-26: kéo-thả đổi thứ tự (formReorder) ---- */

    public function testFormReorderAppliesNewSequenceAndRenumbers(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            CategoryModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            CategoryModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->categories->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '3,1,2',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(3, $result['applied']);
        self::assertSame([3 => 0, 1 => 1, 2 => 2], $writes);
    }

    public function testFormReorderIgnoresUnknownIdsAndAppendsMissingRows(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            CategoryModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            CategoryModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->categories->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '2,99,1',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        // 99 lạ bị bỏ; id 3 không nêu giữ cuối → [2,1,3]; chỉ 2 dòng đổi số.
        self::assertSame('reordered', $result['flag']);
        self::assertSame(2, $result['applied']);
        self::assertSame([2 => 0, 1 => 1], $writes);
    }

    public function testFormReorderSameOrderWritesNothing(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            CategoryModel::fromRow(['id' => 2, 'sortOrder' => 1]),
        ]);
        $this->categories->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '1,2',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(0, $result['applied']);
    }

    public function testFormReorderRejectsBadCsrf(): void
    {
        $this->categories->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '1,2',
            'csrf' => 'sai-token',
        ]);

        self::assertSame('csrf', $result['flag']);
        self::assertSame(0, $result['applied']);
    }

    public function testReorderApiAppliesWithoutCsrf(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            CategoryModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            CategoryModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->categories->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->apiData($this->service->reorderApi(['ids' => [3, 1, 2]]));

        self::assertSame('reordered', $result['flag']);
        self::assertSame(3, $result['applied']);
        self::assertSame([3 => 0, 1 => 1, 2 => 2], $writes);
    }

    public function testReorderApiEmptyIdsChangesNothing(): void
    {
        $this->categories->method('listAll')->willReturn([
            CategoryModel::fromRow(['id' => 1, 'sortOrder' => 0]),
        ]);
        $this->categories->expects(self::never())->method('update');

        $result = $this->apiData($this->service->reorderApi([]));

        self::assertSame('reordered', $result['flag']);
        self::assertSame(0, $result['applied']);
    }

    /**
     * Payload `data` của envelope API (JsonModel — getVariables, không serialize).
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
}
