<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Category\CategoryModel;
use Admin\Model\HomeSection\HomeSectionConst;
use Admin\Model\HomeSection\HomeSectionMapper;
use Admin\Model\HomeSection\HomeSectionModel;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\HomeSectionItem\HomeSectionItemModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\TeamMember\TeamMemberModel;
use Admin\Service\HomeSectionService;
use Application\Constant\CacheConst;
use Application\Constant\ContentConst;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Bố cục trang chủ (FR-32): validate config JSON theo `type` + cascade xoá
 * home_section_items trong transaction. FR-33 (itemsForm): thêm/xoá/di chuyển
 * mục chọn tay — kiểm soát loại mục theo section, tồn tại bảng chủ, trùng,
 * trần 50, quyền sở hữu theo section và renumber sortOrder. Mọi mapper/db mock
 * hoàn toàn.
 */
final class HomeSectionServiceTest extends TestCase
{
    private HomeSectionMapper&MockObject $sections;
    private HomeSectionItemMapper&MockObject $items;
    private CategoryMapper&MockObject $categories;
    private PostMapper&MockObject $posts;
    private ServiceMapper&MockObject $services;
    private TeamMemberMapper&MockObject $teamMembers;
    private DbService&MockObject $db;
    private HomeSectionService $service;

    protected function setUp(): void
    {
        $this->sections    = $this->createMock(HomeSectionMapper::class);
        $this->items       = $this->createMock(HomeSectionItemMapper::class);
        $this->categories  = $this->createMock(CategoryMapper::class);
        $this->posts       = $this->createMock(PostMapper::class);
        $this->services    = $this->createMock(ServiceMapper::class);
        $this->teamMembers = $this->createMock(TeamMemberMapper::class);
        $this->db          = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );
        $this->service = (new HomeSectionService())->setContainer(new TestContainer([
            HomeSectionMapper::class     => $this->sections,
            HomeSectionItemMapper::class => $this->items,
            CategoryMapper::class        => $this->categories,
            PostMapper::class            => $this->posts,
            ServiceMapper::class         => $this->services,
            TeamMemberMapper::class      => $this->teamMembers,
            DbService::class             => $this->db,
        ]));
    }

    /**
     * POST thô hợp lệ tối thiểu + CSRF thật.
     *
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function raw(array $overrides = []): array
    {
        return $overrides + [
            'type' => (string) HomeSectionConst::TYPE_FEATURED_POSTS,
            'csrf' => $this->service->saveFormCsrfHash(),
        ];
    }

    public function testSaveFormCreatesWithCompactConfig(): void
    {
        $captured = null;
        $this->sections->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 42;
            }
        );

        $this->service->saveForm(null, $this->raw([
            'title'     => '  Tin nổi bật  ',
            'config'    => '{"mode": "auto", "limit": 5}',
            'isActive'  => '1',
            'sortOrder' => '2',
        ]));

        self::assertIsArray($captured);
        self::assertSame(HomeSectionConst::TYPE_FEATURED_POSTS, $captured['type']);
        self::assertSame('Tin nổi bật', $captured['title']);
        self::assertSame('{"mode":"auto","limit":5}', $captured['config']);
        self::assertSame(2, $captured['sortOrder']);
        self::assertSame(HomeSectionConst::ACTIVE, $captured['isActive']);
    }

    public function testSaveFormEmptyConfigStoresNull(): void
    {
        $captured = null;
        $this->sections->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 42;
            }
        );

        $this->service->saveForm(
            null,
            $this->raw(['type' => (string) HomeSectionConst::TYPE_LATEST_POSTS, 'config' => '  '])
        );

        self::assertIsArray($captured);
        self::assertNull($captured['config']);
    }

    public function testSaveFormRejectsNonObjectConfig(): void
    {
        $this->sections->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, $this->raw(['config' => '[1,2]']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_CONFIG, $e->getErrors()['config']);
        }
    }

    public function testSaveFormRejectsKeyNotAllowedForType(): void
    {
        $this->sections->expects(self::never())->method('insert');

        try {
            // latest_posts chỉ nhận limit — mode là khóa lạ
            $this->service->saveForm(null, $this->raw([
                'type'   => (string) HomeSectionConst::TYPE_LATEST_POSTS,
                'config' => '{"mode":"auto","limit":6}',
            ]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_UNKNOWN_KEY, $e->getErrors()['config']);
        }
    }

    public function testSaveFormRejectsOutOfRangeLimit(): void
    {
        try {
            $this->service->saveForm(null, $this->raw(['config' => '{"limit":100}']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_LIMIT, $e->getErrors()['config']);
        }
    }

    public function testSaveFormValidatesHeroTypes(): void
    {
        $hero = (string) HomeSectionConst::TYPE_HERO_BANNER;

        try {
            $this->service->saveForm(null, $this->raw([
                'type'   => $hero,
                'config' => '{"autoplay":"yes"}',
            ]));
            self::fail('Expected ValidationException (autoplay phải là bool JSON)');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_AUTOPLAY, $e->getErrors()['config']);
        }

        try {
            $this->service->saveForm(null, $this->raw([
                'type'   => $hero,
                'config' => '{"interval_ms":500}',
            ]));
            self::fail('Expected ValidationException (interval_ms dưới min)');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_INTERVAL, $e->getErrors()['config']);
        }
    }

    public function testCategoryPostsRequiresExistingCategoryId(): void
    {
        $type = (string) HomeSectionConst::TYPE_CATEGORY_POSTS;

        // Thiếu hẳn category_id
        try {
            $this->service->saveForm(null, $this->raw(['type' => $type, 'config' => '{"limit":6}']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_CATEGORY, $e->getErrors()['config']);
        }

        // category_id không tồn tại
        $this->categories->method('findById')->willReturn(null);
        try {
            $this->service->saveForm(null, $this->raw(['type' => $type, 'config' => '{"category_id":99}']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_CATEGORY, $e->getErrors()['config']);
        }
    }

    public function testCategoryPostsAcceptsExistingCategory(): void
    {
        $this->categories->method('findById')->willReturn(CategoryModel::fromRow(['id' => 7, 'name' => 'Công nghệ']));
        $captured = null;
        $this->sections->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 42;
            }
        );

        $this->service->saveForm(null, $this->raw([
            'type'   => (string) HomeSectionConst::TYPE_CATEGORY_POSTS,
            'config' => '{"category_id":7,"limit":10}',
        ]));

        self::assertIsArray($captured);
        self::assertSame('{"category_id":7,"limit":10}', $captured['config']);
    }

    public function testContactCtaRejectsBadButtonUrl(): void
    {
        try {
            $this->service->saveForm(null, $this->raw([
                'type'   => (string) HomeSectionConst::TYPE_CONTACT_CTA,
                'config' => '{"button_url":"mailto:x@y.z"}',
            ]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(HomeSectionConst::ERROR_BUTTON_URL, $e->getErrors()['config']);
        }
    }

    public function testSaveFormRequiresCsrf(): void
    {
        try {
            $this->service->saveForm(null, ['type' => '3']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }

    public function testSaveFormUpdatePath(): void
    {
        $this->sections->method('findById')->willReturn(HomeSectionModel::fromRow(['id' => 9]));
        $this->sections->expects(self::once())->method('update')->with(9, self::isType('array'));
        $this->sections->expects(self::never())->method('insert');

        $this->service->saveForm(9, $this->raw());
    }

    public function testSaveFormUpdateMissingRowThrowsNotFound(): void
    {
        $this->sections->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->saveForm(9, $this->raw());
    }

    public function testDeleteFormCascadesItemsInsideTransaction(): void
    {
        $this->sections->method('findById')->willReturn(HomeSectionModel::fromRow(['id' => 4]));
        $order = [];
        $this->items->expects(self::once())->method('deleteBySectionId')->with(4)->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'items';
            }
        );
        $this->sections->expects(self::once())->method('delete')->with(4)->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'section';
            }
        );

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(HomeSectionConst::FLAG_DELETED, $flag);
        self::assertSame(['items', 'section'], $order);
    }

    public function testDeleteFormRejectsBadCsrf(): void
    {
        $this->sections->expects(self::never())->method('delete');

        self::assertSame('csrf', $this->service->deleteForm(['id' => '4']));
    }

    public function testDeleteFormMissingRowReturnsNotfound(): void
    {
        $this->sections->method('findById')->willReturn(null);
        $this->sections->expects(self::never())->method('delete');
        $this->items->expects(self::never())->method('deleteBySectionId');

        $flag = $this->service->deleteForm(['id' => '99', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame('notfound', $flag);
    }

    // ---------------------------------------------------------------- FR-33

    /** Section FEATURED_POSTS mode=manual, id 7 (khớp MANUAL_ITEM_TYPES → post). */
    private function manualSection(): HomeSectionModel
    {
        return HomeSectionModel::fromRow([
            'id'     => 7,
            'type'   => HomeSectionConst::TYPE_FEATURED_POSTS,
            'config' => '{"mode":"manual","limit":5}',
        ]);
    }

    /**
     * POST thô cho itemsForm + CSRF thật.
     *
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function itemsRaw(array $overrides = []): array
    {
        return $overrides + [
            'csrf' => $this->service->itemsFormCsrfHash(),
        ];
    }

    public function testItemsFormRejectsBadCsrf(): void
    {
        $this->items->expects(self::never())->method('insert');

        self::assertSame('csrf', $this->service->itemsForm(7, ['op' => 'add', 'csrf' => 'sai']));
    }

    public function testItemAddAppendsAtEndOfList(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->posts->method('findById')->willReturn($this->createMock(PostModel::class));
        $this->items->method('existsItem')->willReturn(false);
        $this->items->method('listBySectionId')->willReturn([
            HomeSectionItemModel::fromRow([
                'id'        => 10,
                'sectionId' => 7,
                'itemType'  => 1,
                'itemId'    => 2,
                'sortOrder' => 4,
            ]),
        ]);
        $captured = null;
        $this->items->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 51;
            }
        );

        $flag = $this->service->itemsForm(7, $this->itemsRaw([
            'op'       => 'add',
            'itemType' => (string) ContentConst::SECTION_ITEM_POST,
            'itemId'   => '3',
        ]));

        self::assertSame(HomeSectionConst::FLAG_ITEM_ADDED, $flag);
        self::assertSame(
            ['sectionId' => 7, 'itemType' => 1, 'itemId' => 3, 'sortOrder' => 5],
            $captured
        );
    }

    public function testItemAddRejectsItemTypeNotMatchingSection(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->items->expects(self::never())->method('insert');

        $flag = $this->service->itemsForm(7, $this->itemsRaw([
            'op'       => 'add',
            'itemType' => (string) ContentConst::SECTION_ITEM_SERVICE,
            'itemId'   => '3',
        ]));

        self::assertSame('invalid', $flag);
    }

    public function testItemAddRejectsMissingTarget(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->posts->method('findById')->willReturn(null);
        $this->items->expects(self::never())->method('insert');

        $flag = $this->service->itemsForm(7, $this->itemsRaw([
            'op'       => 'add',
            'itemType' => (string) ContentConst::SECTION_ITEM_POST,
            'itemId'   => '99',
        ]));

        self::assertSame(HomeSectionConst::FLAG_ITEM_MISSING, $flag);
    }

    public function testItemAddRejectsDuplicate(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->posts->method('findById')->willReturn($this->createMock(PostModel::class));
        $this->items->method('existsItem')->willReturn(true);
        $this->items->expects(self::never())->method('insert');

        $flag = $this->service->itemsForm(7, $this->itemsRaw([
            'op'       => 'add',
            'itemType' => (string) ContentConst::SECTION_ITEM_POST,
            'itemId'   => '3',
        ]));

        self::assertSame(HomeSectionConst::FLAG_ITEM_DUPLICATE, $flag);
    }

    public function testItemAddRejectsOverLimit(): void
    {
        $rows = [];
        for ($i = 1; $i <= HomeSectionConst::ITEM_LIMIT; $i++) {
            $rows[] = HomeSectionItemModel::fromRow(['id' => $i, 'sectionId' => 7, 'sortOrder' => $i - 1]);
        }
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->posts->method('findById')->willReturn($this->createMock(PostModel::class));
        $this->items->method('existsItem')->willReturn(false);
        $this->items->method('listBySectionId')->willReturn($rows);
        $this->items->expects(self::never())->method('insert');

        $flag = $this->service->itemsForm(7, $this->itemsRaw([
            'op'       => 'add',
            'itemType' => (string) ContentConst::SECTION_ITEM_POST,
            'itemId'   => '999',
        ]));

        self::assertSame(HomeSectionConst::FLAG_ITEM_LIMIT, $flag);
    }

    public function testItemRemoveOnlyTouchesOwnSectionRow(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->items->method('listBySectionId')->willReturn([
            HomeSectionItemModel::fromRow(['id' => 10, 'sectionId' => 7, 'sortOrder' => 0]),
        ]);
        $this->items->expects(self::once())->method('deleteByIdAndSection')->with(10, 7);

        // id không thuộc section → notfound, không xoá
        self::assertSame('notfound', $this->service->itemsForm(7, $this->itemsRaw(['op' => 'remove', 'id' => '999'])));

        // id hợp lệ → xoá đúng theo cặp (id, sectionId)
        $flag = $this->service->itemsForm(7, $this->itemsRaw(['op' => 'remove', 'id' => '10']));

        self::assertSame(HomeSectionConst::FLAG_ITEM_REMOVED, $flag);
    }

    public function testItemMoveUpSwapsAndRenumbers(): void
    {
        $rows = [
            HomeSectionItemModel::fromRow(['id' => 10, 'sectionId' => 7, 'sortOrder' => 0]),
            HomeSectionItemModel::fromRow(['id' => 11, 'sectionId' => 7, 'sortOrder' => 1]),
            HomeSectionItemModel::fromRow(['id' => 12, 'sectionId' => 7, 'sortOrder' => 2]),
        ];
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->items->method('listBySectionId')->willReturn($rows);
        $updated = [];
        $this->items->method('updateSortOrder')->willReturnCallback(
            static function (int $id, int $sortOrder) use (&$updated): void {
                $updated[$id] = $sortOrder;
            }
        );

        $flag = $this->service->itemsForm(7, $this->itemsRaw(['op' => 'up', 'id' => '12']));

        self::assertSame(HomeSectionConst::FLAG_ITEM_MOVED, $flag);
        // 12 lên vị trí 1; 11 tụt xuống 2; 10 giữ nguyên 0 (không update)
        self::assertSame([12 => 1, 11 => 2], $updated);
    }

    public function testItemMoveAtBoundaryKeepsOrder(): void
    {
        $rows = [
            HomeSectionItemModel::fromRow(['id' => 10, 'sectionId' => 7, 'sortOrder' => 0]),
            HomeSectionItemModel::fromRow(['id' => 11, 'sectionId' => 7, 'sortOrder' => 1]),
        ];
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->items->method('listBySectionId')->willReturn($rows);
        $this->items->expects(self::never())->method('updateSortOrder');

        $flag = $this->service->itemsForm(7, $this->itemsRaw(['op' => 'up', 'id' => '10']));

        self::assertSame(HomeSectionConst::FLAG_ITEM_MOVED, $flag);
    }

    public function testItemsFormRejectsUnknownOp(): void
    {
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->items->expects(self::never())->method('insert');

        $flag = $this->service->itemsForm(7, $this->itemsRaw(['op' => 'xyz']));

        self::assertSame('invalid', $flag);
    }

    /**
     * FR-32/FR-39: thao tác ghi section (create/update/delete) và op add/remove/
     * move trên itemsForm đều forget 'home-v1'; op thất bại thì không.
     */
    public function testWritesForgetHomeCache(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $this->sections->method('insert')->willReturn(3);
        $this->sections->method('findById')->willReturn($this->manualSection());
        $this->posts->method('findById')->willReturn($this->createMock(PostModel::class));
        $this->items->method('existsItem')->willReturn(false);
        $this->items->method('listBySectionId')->willReturn([]);
        $this->items->method('insert')->willReturn(50);
        $service = (new HomeSectionService())->setContainer(new TestContainer([
            HomeSectionMapper::class     => $this->sections,
            HomeSectionItemMapper::class => $this->items,
            CategoryMapper::class        => $this->categories,
            PostMapper::class            => $this->posts,
            ServiceMapper::class         => $this->services,
            TeamMemberMapper::class      => $this->teamMembers,
            DbService::class             => $this->db,
            PageCacheService::class      => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        // create + update + delete + itemAdd thành công = 4 lần forget.
        $storage->expects(self::exactly(4))->method('removeItem')->with(CacheConst::KEY_HOME);

        $service->create(['type' => HomeSectionConst::TYPE_FEATURED_POSTS]);
        $service->update(7, ['type' => HomeSectionConst::TYPE_FEATURED_POSTS]);
        $service->delete(7);
        // itemAdd thành công (mục khớp loại, chưa trùng, còn trần) cũng phải forget.
        self::assertSame(
            HomeSectionConst::FLAG_ITEM_ADDED,
            $service->itemsForm(7, [
                'op'       => 'add',
                'itemType' => (string) ContentConst::SECTION_ITEM_POST,
                'itemId'   => '3',
                'csrf'     => $this->service->itemsFormCsrfHash(),
            ])
        );
    }

    /* ---- FR-32: kéo-thả thứ tự section (formReorder) ---- */

    public function testFormReorderAppliesNewSequenceAndRenumbers(): void
    {
        $this->sections->method('listAll')->willReturn([
            HomeSectionModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            HomeSectionModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            HomeSectionModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->sections->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '2,3,1',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(3, $result['applied']);
        self::assertSame([2 => 0, 3 => 1, 1 => 2], $writes);
    }

    public function testFormReorderRejectsBadCsrf(): void
    {
        $this->sections->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '2,1',
            'csrf' => 'sai-token',
        ]);

        self::assertSame('csrf', $result['flag']);
        self::assertSame(0, $result['applied']);
    }
}
