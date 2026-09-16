<?php

declare(strict_types=1);

namespace Frontend\Model\Service;

/**
 * POPO entity `services` (chuẩn 07 §3): ServiceMapper fill row qua `fromRow()`
 * — model không query DB, không biết HTTP.
 */
class ServiceModel
{
    public int $id = 0;
    public ?int $parentId = null;
    public string $name = '';
    public string $slug = '';
    public ?string $shortDescription = null;
    public ?string $content = null;
    public ?int $iconMediaId = null;
    public ?int $imageMediaId = null;
    public int $sortOrder = 0;
    public int $isActive = ServiceConst::ACTIVE;
    public ?string $metaTitle = null;
    public ?string $metaDescription = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m                   = new static();
        $m->id               = (int) ($row['id'] ?? 0);
        $m->parentId         = self::intOrNull($row['parentId'] ?? null);
        $m->name             = (string) ($row['name'] ?? '');
        $m->slug             = (string) ($row['slug'] ?? '');
        $m->shortDescription = self::strOrNull($row['shortDescription'] ?? null);
        $m->content          = self::strOrNull($row['content'] ?? null);
        $m->iconMediaId      = self::intOrNull($row['iconMediaId'] ?? null);
        $m->imageMediaId     = self::intOrNull($row['imageMediaId'] ?? null);
        $m->sortOrder        = (int) ($row['sortOrder'] ?? 0);
        $m->isActive         = (int) ($row['isActive'] ?? ServiceConst::ACTIVE);
        $m->metaTitle        = self::strOrNull($row['metaTitle'] ?? null);
        $m->metaDescription  = self::strOrNull($row['metaDescription'] ?? null);
        $m->createdAt        = (string) ($row['createdAt'] ?? '');
        $m->updatedAt        = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API services chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'parentId'         => $this->parentId,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'shortDescription' => $this->shortDescription,
            'content'          => $this->content,
            'iconMediaId'      => $this->iconMediaId,
            'imageMediaId'     => $this->imageMediaId,
            'sortOrder'        => $this->sortOrder,
            'isActive'         => $this->isActive,
            'metaTitle'        => $this->metaTitle,
            'metaDescription'  => $this->metaDescription,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form dịch vụ (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'parentId'         => $this->parentId ?? '',
            'name'             => $this->name,
            'slug'             => $this->slug,
            'shortDescription' => $this->shortDescription ?? '',
            'content'          => $this->content ?? '',
            'iconMediaId'      => $this->iconMediaId ?? '',
            'imageMediaId'     => $this->imageMediaId ?? '',
            'sortOrder'        => $this->sortOrder,
            'isActive'         => $this->isActive,
            'metaTitle'        => $this->metaTitle ?? '',
            'metaDescription'  => $this->metaDescription ?? '',
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
