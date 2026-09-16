<?php

declare(strict_types=1);

namespace Frontend\Model\Pricing;

/**
 * POPO entity `pricing_items` (chuẩn 07 §3): PricingMapper fill row qua `fromRow()`.
 */
class PricingModel
{
    public int $id = 0;
    public string $groupCode = PricingConst::GROUP_GENERAL;
    public string $name = '';
    public string $slug = '';
    public ?int $price = null;
    public ?string $unit = null;
    public ?string $note = null;
    public int $sortOrder = 0;
    public int $isActive = PricingConst::ACTIVE;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): static
    {
        $m             = new static();
        $m->id         = (int) ($row['id'] ?? 0);
        $m->groupCode  = (string) ($row['groupCode'] ?? PricingConst::GROUP_GENERAL);
        $m->name       = (string) ($row['name'] ?? '');
        $m->slug       = (string) ($row['slug'] ?? '');
        $m->price      = self::intOrNull($row['price'] ?? null);
        $m->unit       = self::strOrNull($row['unit'] ?? null);
        $m->note       = self::strOrNull($row['note'] ?? null);
        $m->sortOrder  = (int) ($row['sortOrder'] ?? 0);
        $m->isActive   = (int) ($row['isActive'] ?? PricingConst::ACTIVE);
        $m->createdAt  = (string) ($row['createdAt'] ?? '');
        $m->updatedAt  = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'groupCode' => $this->groupCode,
            'name'      => $this->name,
            'slug'      => $this->slug,
            'price'     => $this->price,
            'unit'      => $this->unit,
            'note'      => $this->note,
            'sortOrder' => $this->sortOrder,
            'isActive'  => $this->isActive,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'groupCode' => $this->groupCode,
            'name'      => $this->name,
            'slug'      => $this->slug,
            'price'     => $this->price ?? '',
            'unit'      => $this->unit ?? '',
            'note'      => $this->note ?? '',
            'sortOrder' => $this->sortOrder,
            'isActive'  => $this->isActive,
        ];
    }

    private static function intOrNull(mixed $raw): ?int
    {
        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    private static function strOrNull(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
