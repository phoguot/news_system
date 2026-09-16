<?php

declare(strict_types=1);

namespace Admin\Model\Category;

/**
 * POPO entity `categories` (chuẩn 07 §3): CategoryMapper fill row qua
 * `fromRow()` — model không query DB, không biết HTTP.
 */
class CategoryModel
{
    public int $id = 0;
    public ?int $parentId = null;
    public string $name = '';
    public string $slug = '';
    public ?string $description = null;
    public ?int $coverMediaId = null;
    public int $sortOrder = 0;
    public int $isActive = CategoryConst::ACTIVE;
    public ?string $metaTitle = null;
    public ?string $metaDescription = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m             = new static();
        $m->id         = (int) ($row['id'] ?? 0);
        $m->parentId   = self::intOrNull($row['parentId'] ?? null);
        $m->name       = (string) ($row['name'] ?? '');
        $m->slug       = (string) ($row['slug'] ?? '');
        $m->description = self::strOrNull($row['description'] ?? null);
        $m->coverMediaId = self::intOrNull($row['coverMediaId'] ?? null);
        $m->sortOrder  = (int) ($row['sortOrder'] ?? 0);
        $m->isActive   = (int) ($row['isActive'] ?? CategoryConst::ACTIVE);
        $m->metaTitle  = self::strOrNull($row['metaTitle'] ?? null);
        $m->metaDescription = self::strOrNull($row['metaDescription'] ?? null);
        $m->createdAt  = (string) ($row['createdAt'] ?? '');
        $m->updatedAt  = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'parentId'        => $this->parentId,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'coverMediaId'    => $this->coverMediaId,
            'sortOrder'       => $this->sortOrder,
            'isActive'        => $this->isActive,
            'metaTitle'       => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'createdAt'       => $this->createdAt,
            'updatedAt'       => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form danh mục (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'name'            => $this->name,
            'slug'            => $this->slug,
            'parentId'        => $this->parentId ?? '',
            'description'     => $this->description ?? '',
            'coverMediaId'    => $this->coverMediaId ?? '',
            'sortOrder'       => $this->sortOrder,
            'isActive'        => $this->isActive,
            'metaTitle'       => $this->metaTitle ?? '',
            'metaDescription' => $this->metaDescription ?? '',
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
