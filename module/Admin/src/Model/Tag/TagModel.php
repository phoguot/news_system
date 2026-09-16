<?php

declare(strict_types=1);

namespace Admin\Model\Tag;

/**
 * POPO entity `tags` (chuẩn 07 §3): TagMapper fill row qua `fromRow()`.
 * `postCount` là trường ghép thêm bởi TagService (batch PostTagMapper::countsAll
 * — 07 §5), NULL khi model chưa được ghép.
 */
class TagModel
{
    public int $id = 0;
    public string $name = '';
    public string $slug = '';
    public string $createdAt = '';
    public string $updatedAt = '';

    /** Số bài đang dùng tag — do Service ghép, không phải cột DB. */
    public ?int $postCount = null;

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m            = new static();
        $m->id        = (int) ($row['id'] ?? 0);
        $m->name      = (string) ($row['name'] ?? '');
        $m->slug      = (string) ($row['slug'] ?? '');
        $m->createdAt = (string) ($row['createdAt'] ?? '');
        $m->updatedAt = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        $out = [
            'id'        => $this->id,
            'name'      => $this->name,
            'slug'      => $this->slug,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];

        if ($this->postCount !== null) {
            $out['postCount'] = $this->postCount;
        }

        return $out;
    }

    /**
     * Giá trị điền lại form tạo/sửa tag (input name => value).
     *
     * @return array<array-key, mixed>
     */
    public function toFormValues(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
