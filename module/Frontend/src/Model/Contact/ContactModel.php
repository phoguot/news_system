<?php

declare(strict_types=1);

namespace Frontend\Model\Contact;

/**
 * POPO một dòng `contact_submissions` (docs §4.4.9, §3.9). Mapper đọc hydrate
 * qua fromRow(); `ipAddress` (VARBINARY) không map — hộp thư admin không hiển thị IP.
 */
class ContactModel
{
    public int $id = 0;
    public string $fullName = '';
    public string $email = '';
    public ?string $phone = null;
    public ?int $serviceId = null;
    public ?string $subject = null;
    public string $message = '';
    public string $consentAt = '';
    public int $status = ContactConst::STATUS_NEW;
    public ?string $adminNote = null;
    public ?string $handledAt = null;
    public ?string $userAgent = null;
    public ?string $sourceUrl = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m             = new static();
        $m->id         = (int) ($row['id'] ?? 0);
        $m->fullName   = (string) ($row['fullName'] ?? '');
        $m->email      = (string) ($row['email'] ?? '');
        $m->phone      = self::strOrNull($row['phone'] ?? null);
        $m->serviceId  = self::intOrNull($row['serviceId'] ?? null);
        $m->subject    = self::strOrNull($row['subject'] ?? null);
        $m->message    = (string) ($row['message'] ?? '');
        $m->consentAt  = (string) ($row['consentAt'] ?? '');
        $m->status     = (int) ($row['status'] ?? ContactConst::STATUS_NEW);
        $m->adminNote  = self::strOrNull($row['adminNote'] ?? null);
        $m->handledAt  = self::strOrNull($row['handledAt'] ?? null);
        $m->userAgent  = self::strOrNull($row['userAgent'] ?? null);
        $m->sourceUrl  = self::strOrNull($row['sourceUrl'] ?? null);
        $m->createdAt  = (string) ($row['createdAt'] ?? '');
        $m->updatedAt  = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API contacts chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'fullName'   => $this->fullName,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'serviceId'  => $this->serviceId,
            'subject'    => $this->subject,
            'message'    => $this->message,
            'consentAt'  => $this->consentAt,
            'status'     => $this->status,
            'adminNote'  => $this->adminNote,
            'handledAt'  => $this->handledAt,
            'userAgent'  => $this->userAgent,
            'sourceUrl'  => $this->sourceUrl,
            'createdAt'  => $this->createdAt,
            'updatedAt'  => $this->updatedAt,
        ];
    }

    /**
     * Giá trị điền form xử lý (hộp thư admin): id + status + ghi chú.
     *
     * @return array<array-key, mixed>
     */
    public function toFormValues(): array
    {
        return [
            'status'    => $this->status,
            'adminNote' => $this->adminNote ?? '',
        ];
    }

    private static function strOrNull(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
