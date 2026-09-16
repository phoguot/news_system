<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\ContactService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Contact\ContactModel;
use Frontend\Model\Service\ServiceMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nghi vụ hộp thư admin (FR-35): lọc GET → biên UTC, updateForm gắn handledAt
 * khi DONE, cờ PRG của deleteForm. Mapper Frontend mock hoàn toàn.
 */
final class ContactServiceTest extends TestCase
{
    private ContactMapper&MockObject $contacts;
    private ServiceMapper&MockObject $services;
    private ContactService $service;

    protected function setUp(): void
    {
        $this->contacts = $this->createMock(ContactMapper::class);
        $this->services = $this->createMock(ServiceMapper::class);
        $this->service  = (new ContactService())->setContainer(new TestContainer([
            ContactMapper::class => $this->contacts,
            ServiceMapper::class => $this->services,
        ]));
    }

    public function testListFilteredNormalizesQueryToUtcBounds(): void
    {
        $captured = [];
        $this->contacts->method('listFiltered')
            ->willReturnCallback(
                function (
                    ?int $status,
                    ?int $serviceId,
                    ?string $from,
                    ?string $to,
                    int $limit = 200
                ) use (&$captured): array {
                    $captured = [$status, $serviceId, $from, $to, $limit];

                    return [];
                }
            );

        $this->service->listFiltered([
            'status'    => '1',
            'serviceId' => '7',
            'dateFrom'  => '2026-09-12',
            'dateTo'    => '2026-09-12',
        ]);

        // Ngày VN 2026-09-12 (UTC+7) → [2026-09-11 17:00:00, 2026-09-12 16:59:59] UTC
        self::assertSame([1, 7, '2026-09-11 17:00:00', '2026-09-12 16:59:59', 200], $captured);
    }

    public function testListFilteredKeepsStatusZeroAndDropsGarbage(): void
    {
        $captured = [];
        $this->contacts->method('listFiltered')
            ->willReturnCallback(
                function (
                    ?int $status,
                    ?int $serviceId,
                    ?string $from,
                    ?string $to
                ) use (&$captured): array {
                    $captured = [$status, $serviceId, $from, $to];

                    return [];
                }
            );

        $this->service->listFiltered([
            'status'    => '0',
            'serviceId' => '',
            'dateFrom'  => '12/09/2026',
            'dateTo'    => 'hom nay',
        ]);

        self::assertSame([0, null, null, null], $captured);
    }

    public function testUpdateFormWritesHandlerWithoutHandledAtForProcessing(): void
    {
        $this->contacts->method('findById')->willReturn(ContactModel::fromRow(['id' => 5]));
        $this->contacts->expects(self::once())->method('updateHandler')
            ->with(5, ContactConst::STATUS_PROCESSING, 'Ghi chú xử lý', null);

        $this->service->updateForm([
            'id'         => '5',
            'status'     => '1',
            'adminNote'  => '  Ghi chú xử lý  ',
            'csrf'       => $this->service->updateFormCsrfHash(),
        ]);
    }

    public function testUpdateFormStampsHandledAtWhenDone(): void
    {
        $this->contacts->method('findById')->willReturn(ContactModel::fromRow(['id' => 5]));
        $handledAt = null;
        $this->contacts->expects(self::once())->method('updateHandler')->willReturnCallback(
            static function (int $id, int $status, ?string $note, ?string $at) use (&$handledAt): void {
                $handledAt = $at;
            }
        );

        $this->service->updateForm([
            'id'     => '5',
            'status' => (string) ContactConst::STATUS_DONE,
            'csrf'   => $this->service->updateFormCsrfHash(),
        ]);

        self::assertIsString($handledAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $handledAt);
    }

    public function testUpdateFormRejectsUnknownStatus(): void
    {
        $this->contacts->expects(self::never())->method('updateHandler');

        try {
            $this->service->updateForm([
                'id'     => '5',
                'status' => '9',
                'csrf'   => $this->service->updateFormCsrfHash(),
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->getErrors());
        }
    }

    public function testUpdateFormRequiresCsrf(): void
    {
        try {
            $this->service->updateForm(['id' => '5', 'status' => '1']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }

    public function testUpdateFormMissingRowThrowsNotFound(): void
    {
        $this->contacts->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->updateForm([
            'id'     => '5',
            'status' => '1',
            'csrf'   => $this->service->updateFormCsrfHash(),
        ]);
    }

    public function testDeleteFormDeletesAndReturnsFlag(): void
    {
        $this->contacts->method('findById')->willReturn(ContactModel::fromRow(['id' => 4]));
        $this->contacts->expects(self::once())->method('delete')->with(4);

        $flag = $this->service->deleteForm(['id' => '4', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame(ContactConst::FLAG_DELETED, $flag);
    }

    public function testDeleteFormRejectsBadCsrf(): void
    {
        $this->contacts->expects(self::never())->method('delete');

        self::assertSame('csrf', $this->service->deleteForm(['id' => '4']));
    }

    public function testDeleteFormMissingRowReturnsNotfound(): void
    {
        $this->contacts->method('findById')->willReturn(null);
        $this->contacts->expects(self::never())->method('delete');

        $flag = $this->service->deleteForm(['id' => '99', 'csrf' => $this->service->deleteFormCsrfHash()]);

        self::assertSame('notfound', $flag);
    }

    public function testOptionsAndLabelsPassThrough(): void
    {
        $this->services->method('listActiveOptions')->willReturn([3 => 'Dịch vụ A']);

        self::assertSame([3 => 'Dịch vụ A'], $this->service->serviceOptions());
        self::assertSame(ContactConst::STATUS_LABELS, $this->service->statusLabels());
    }
}
