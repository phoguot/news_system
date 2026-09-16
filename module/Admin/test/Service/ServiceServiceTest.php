<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\ValidationException;
use Admin\Service\ServiceService;
use Application\Constant\CacheConst;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Application\Service\SlugService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceConst;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;
use Laminas\Cache\Storage\StorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ServiceServiceTest extends TestCase
{
    private ServiceMapper&MockObject $services;
    private ContactMapper&MockObject $contacts;
    private SlugService&MockObject $slugs;
    private DbService&MockObject $db;
    private ServiceService $service;

    protected function setUp(): void
    {
        $this->services = $this->createMock(ServiceMapper::class);
        $this->contacts = $this->createMock(ContactMapper::class);
        $this->slugs    = $this->createMock(SlugService::class);
        $this->db       = $this->createMock(DbService::class);
        $this->db->method('transactional')->willReturnCallback(
            static fn (callable $fn): mixed => $fn()
        );

        $this->slugs->method('slugify')
            ->willReturnCallback(static fn (string $t): string => strtolower(trim($t)));
        $this->slugs->method('unique')
            ->willReturnCallback(static fn (string $base): string => $base);

        $this->service = (new ServiceService())->setContainer(new TestContainer([
            ServiceMapper::class => $this->services,
            ContactMapper::class => $this->contacts,
            SlugService::class   => $this->slugs,
            DbService::class     => $this->db,
        ]));
    }

    /** Hash CSRF thật — validator Laminas dùng session nên chạy được trong PHPUnit CLI. */
    private function csrf(): string
    {
        return $this->service->saveFormCsrfHash(null);
    }

    public function testSaveFormValidCreatesWithSlugFromName(): void
    {
        $this->services->method('existsSlug')->willReturn(false);
        $captured = null;
        $this->services->expects(self::once())->method('insert')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 11;
            });

        $this->service->saveForm(null, [
            'name'   => 'Tour Mien Phi',
            'csrf'   => $this->csrf(),
        ]);

        self::assertIsArray($captured);
        self::assertSame('Tour Mien Phi', $captured['name']);
        self::assertSame('tour mien phi', $captured['slug']);
        self::assertSame(ServiceConst::INACTIVE, $captured['isActive']);
        self::assertSame(0, $captured['sortOrder']);
        self::assertNull($captured['shortDescription']);
    }

    public function testSaveFormMissingNameRejectedWithoutInsert(): void
    {
        $this->services->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, ['name' => '', 'csrf' => $this->csrf()]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function testSaveFormBadSlugFormatRejected(): void
    {
        $this->services->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, [
                'name' => 'Xync du',
                'slug' => 'Slug Sai!!',
                'csrf' => $this->csrf(),
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('slug', $e->getErrors());
        }
    }

    public function testSaveFormEditRejectsSlugOwnedByAnotherRow(): void
    {
        $this->services->method('existsSlug')->willReturn(true);
        $this->services->expects(self::never())->method('update');

        try {
            $this->service->saveForm(3, ['name' => 'Khach', 'slug' => 'da-trung', 'csrf' => $this->csrf()]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('slug', $e->getErrors());
        }
    }

    public function testSaveFormMissingCsrfRejected(): void
    {
        $this->services->expects(self::never())->method('insert');

        try {
            $this->service->saveForm(null, ['name' => 'Thieu bao mat']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }

    public function testDeleteFormRejectsBadCsrf(): void
    {
        $this->services->expects(self::never())->method('delete');

        self::assertSame('csrf', $this->service->deleteForm(['id' => '4']));
    }

    public function testDeleteFormDeletesWhenNoContacts(): void
    {
        $this->services->method('findById')->willReturn(ServiceModel::fromRow(['id' => 4, 'name' => 'A']));
        $this->contacts->method('countByServiceId')->with(4)->willReturn(0);
        $this->services->expects(self::once())->method('delete')->with(4);

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(ServiceConst::FLAG_DELETED, $flag);
    }

    public function testDeleteFormBlockedWhenContactsReferenceService(): void
    {
        $this->services->method('findById')->willReturn(ServiceModel::fromRow(['id' => 4, 'name' => 'A']));
        $this->contacts->method('countByServiceId')->with(4)->willReturn(2);
        $this->services->expects(self::never())->method('delete');

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(ServiceConst::FLAG_DELETE_BLOCKED, $flag);
    }

    public function testDeleteFormMissingRowReturnsNotfound(): void
    {
        $this->services->method('findById')->willReturn(null);
        $this->services->expects(self::never())->method('delete');

        $flag = $this->service->deleteForm(['id' => '99', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame('notfound', $flag);
    }

    /** FR-32/FR-11: create/update/delete dịch vụ đều forget 'home-v1' + 'sitemap-v1'. */
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
        $this->services->method('existsSlug')->willReturn(false);
        $this->services->method('insert')->willReturn(8);
        $this->services->method('findById')->willReturn(ServiceModel::fromRow(['id' => 8, 'name' => 'A']));
        $this->contacts->method('countByServiceId')->willReturn(0);
        $service = (new ServiceService())->setContainer(new TestContainer([
            ServiceMapper::class    => $this->services,
            ContactMapper::class    => $this->contacts,
            SlugService::class      => $this->slugs,
            PageCacheService::class => (new PageCacheService())->setContainer(
                new TestContainer([CacheConst::SERVICE_PAGE_CACHE => $storage])
            ),
        ]));

        $service->create(['name' => 'Tour']);
        $service->update(8, ['name' => 'Tour']);
        $service->delete(8);

        self::assertCount(6, $forgotten);
        self::assertSame(
            [CacheConst::KEY_HOME, CacheConst::KEY_SITEMAP],
            array_values(array_unique($forgotten))
        );
    }

    /* ---- FR-29: kéo-thả đổi thứ tự (formReorder) ---- */

    public function testFormReorderAppliesNewSequenceAndRenumbers(): void
    {
        $this->services->method('listAll')->willReturn([
            ServiceModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            ServiceModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            ServiceModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->services->method('update')
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
        $this->services->method('listAll')->willReturn([
            ServiceModel::fromRow(['id' => 1, 'sortOrder' => 0]),
            ServiceModel::fromRow(['id' => 2, 'sortOrder' => 1]),
            ServiceModel::fromRow(['id' => 3, 'sortOrder' => 2]),
        ]);
        $writes = [];
        $this->services->method('update')
            ->willReturnCallback(static function (int $id, array $values) use (&$writes): void {
                $writes[$id] = (int) $values['sortOrder'];
            });

        $result = $this->service->formReorder([
            'ids'  => '2,99,1',
            'csrf' => $this->service->reorderCsrfHash(),
        ]);

        self::assertSame('reordered', $result['flag']);
        self::assertSame(2, $result['applied']);
        self::assertSame([2 => 0, 1 => 1], $writes);
    }

    public function testFormReorderRejectsBadCsrf(): void
    {
        $this->services->expects(self::never())->method('update');

        $result = $this->service->formReorder([
            'ids'  => '2,1',
            'csrf' => 'sai-token',
        ]);

        self::assertSame('csrf', $result['flag']);
        self::assertSame(0, $result['applied']);
    }
}
