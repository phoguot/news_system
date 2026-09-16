<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Contact\ContactActionFilter;
use Admin\Filter\Contact\ContactUpdateFilter;
use Application\Factory\AppServiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Contact\ContactModel;
use Frontend\Model\Service\ServiceMapper;

/**
 * Hộp thư liên hệ phía admin (docs §3.9 FR-35): lọc danh sách, xem chi tiết,
 * cập nhật status + adminNote (handledAt tự ghi khi chuyển sang "Đã xong"),
 * đánh dấu spam (chính là status=3), xoá. KHÔNG có create — luồng ghi do
 * khách qua Frontend\ContactService.
 * Bảng `contact_submissions` do mapper của Frontend sở hữu (05-cau-truc §4) —
 * Admin dùng lại cùng class qua container gộp.
 * Luồng chuẩn 07 §2: Service chạy Filter trên raw, Mapper trả ContactModel.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class ContactService extends AppServiceFactory
{
    /** Typed accessor cho các entry dùng lặp lại trong service. */
    private function contactMapper(): ContactMapper
    {
        /** @var ContactMapper */
        return $this->getContainerEntry(ContactMapper::class);
    }

    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    /**
     * Danh sách hộp thư theo bộ lọc GET thô (status/serviceId/từ ngày/đến ngày).
     * Giá trị không hợp lệ bị bỏ qua (lọc lỏng — là tuỳ chọn UI, không phải ràng buộc ghi).
     *
     * @param array<array-key, mixed> $query
     *
     * @return list<ContactModel>
     */
    public function listFiltered(array $query): array
    {
        return $this->contactMapper()->listFiltered(
            $this->intOrNullFromRaw($query['status'] ?? null),
            $this->intOrNullFromRaw($query['serviceId'] ?? null),
            $this->vnDayToUtcStart((string) ($query['dateFrom'] ?? '')),
            $this->vnDayToUtcEnd((string) ($query['dateTo'] ?? '')),
        );
    }

    /**
     * CSV hộp thư theo đúng bộ lọc hiện tại (FR-35 §3.9 "xuất").
     * Dùng chung biên UTC với `listFiltered` nên giữ tham số lọc cho export.
     * BOM UTF-8 đầu file để Excel tiếng Việt mở đúng dấu.
     */
    public function exportCsv(array $query): string
    {
        $rows         = $this->contactMapper()->listFiltered(
            $this->intOrNullFromRaw($query['status'] ?? null),
            $this->intOrNullFromRaw($query['serviceId'] ?? null),
            $this->vnDayToUtcStart((string) ($query['dateFrom'] ?? '')),
            $this->vnDayToUtcEnd((string) ($query['dateTo'] ?? '')),
            10000
        );
        $serviceNames = $this->serviceMapper()->listActiveOptions();

        $fh = fopen('php://temp', 'r+b');
        if ($fh === false) {
            return '';
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'ID',
            'Họ tên',
            'Email',
            'Điện thoại',
            'Dịch vụ',
            'Tiêu đề',
            'Nội dung',
            'Trạng thái',
            'Ghi chú admin',
            'Đồng ý lúc (UTC)',
            'Gửi lúc (UTC)',
            'Xử lý lúc (UTC)',
        ]);

        foreach ($rows as $r) {
            fputcsv($fh, [
                $r->id,
                $r->fullName,
                $r->email,
                $r->phone ?? '',
                $r->serviceId === null ? '' : ($serviceNames[$r->serviceId] ?? ('#' . $r->serviceId)),
                $r->subject ?? '',
                $r->message,
                ContactConst::STATUS_LABELS[$r->status] ?? (string) $r->status,
                $r->adminNote ?? '',
                $r->consentAt,
                $r->createdAt,
                $r->handledAt ?? '',
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        if (is_resource($fh)) {
            fclose($fh);
        }

        return is_string($csv) ? $csv : '';
    }

    /** @return array<int, string> id => tên (dropdown lọc + label chi tiết) */
    public function serviceOptions(): array
    {
        return $this->serviceMapper()->listActiveOptions();
    }

    /** @return array<int, string> status code => nhãn */
    public function statusLabels(): array
    {
        return ContactConst::STATUS_LABELS;
    }

    public function findOrFail(int $id): ContactModel
    {
        $contact = $this->contactMapper()->findById($id);
        if ($contact === null) {
            throw NotFoundException::forEntity('lượt liên hệ', $id);
        }

        return $contact;
    }

    /**
     * Form xử lý trên trang chi tiết: chạy ContactUpdateFilter (kèm CSRF).
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException lỗi từng trường
     * @throws NotFoundException   id không tồn tại
     */
    public function updateForm(array $raw): void
    {
        $filter = new ContactUpdateFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $id     = $filter->idValue();
        /** @var mixed $statusRaw */
        $statusRaw = $filter->getValue('status');
        $status = (int) $statusRaw;
        /** @var mixed $noteRaw */
        $noteRaw = $filter->getValue('adminNote');
        $note    = is_string($noteRaw) && trim($noteRaw) !== '' ? trim($noteRaw) : null;

        if ($id === null) {
            throw new ValidationException(['id' => ContactConst::ERROR_NOT_FOUND]);
        }

        $this->findOrFail($id);

        // handledAt: chỉ có mốc thời gian khi đánh dấu "Đã xong" (docs §3.9)
        $handledAt = $status === ContactConst::STATUS_DONE
            ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')
            : null;

        $this->contactMapper()->updateHandler($id, $status, $note, $handledAt);
    }

    /**
     * Xoá từ form: chạy ContactActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new ContactActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        $id = $filter->idValue();
        if ($id === null) {
            return 'notfound';
        }

        try {
            $this->findOrFail($id);
        } catch (NotFoundException) {
            return 'notfound';
        }

        $this->contactMapper()->delete($id);

        return ContactConst::FLAG_DELETED;
    }

    /** Hash CSRF cho form xử lý (status + ghi chú). */
    public function updateFormCsrfHash(): string
    {
        return (new ContactUpdateFilter())->csrfHash();
    }

    /** Hash CSRF cho form xoá. */
    public function deleteFormCsrfHash(): string
    {
        return (new ContactActionFilter())->csrfHash();
    }

    private function intOrNullFromRaw(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw >= 0 ? (int) $raw : null;
    }

    /**
     * 'Y-m-d' lịch VN → mốc UTC đầu ngày VN; sai/rỗng → null (bỏ lọc).
     *
     * @return non-empty-string|null
     */
    private function vnDayToUtcStart(string $date): ?string
    {
        $dt = $this->vnDay($date);
        if ($dt === null) {
            return null;
        }

        return $dt->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * 'Y-m-d' lịch VN → mốc UTC cuối ngày VN; sai/rỗng → null (bỏ lọc).
     *
     * @return non-empty-string|null
     */
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
}
