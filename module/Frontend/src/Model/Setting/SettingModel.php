<?php

declare(strict_types=1);

namespace Frontend\Model\Setting;

/**
 * POPO một dòng `settings` (docs §4.4.7, §3.12). Mapper đọc hydrate qua fromRow().
 */
class SettingModel
{
    public int $id = 0;
    public string $groupCode = '';
    public string $settingKey = '';
    public ?string $settingValue = null;
    public int $valueType = SettingConst::VALUE_TYPE_STRING;
    public string $label = '';
    public int $sortOrder = 0;
    public ?int $updatedBy = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m               = new static();
        $m->id           = (int) ($row['id'] ?? 0);
        $m->groupCode    = (string) ($row['groupCode'] ?? '');
        $m->settingKey   = (string) ($row['settingKey'] ?? '');
        $m->settingValue = self::strOrNull($row['settingValue'] ?? null);
        $m->valueType    = (int) ($row['valueType'] ?? SettingConst::VALUE_TYPE_STRING);
        $m->label        = (string) ($row['label'] ?? '');
        $m->sortOrder    = (int) ($row['sortOrder'] ?? 0);
        $m->updatedBy    = self::intOrNull($row['updatedBy'] ?? null);
        $m->createdAt    = (string) ($row['createdAt'] ?? '');
        $m->updatedAt    = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API settings chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'groupCode'    => $this->groupCode,
            'settingKey'   => $this->settingKey,
            'settingValue' => $this->settingValue,
            'valueType'    => $this->valueType,
            'label'        => $this->label,
            'sortOrder'    => $this->sortOrder,
            'updatedBy'    => $this->updatedBy,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
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
