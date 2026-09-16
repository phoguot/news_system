<?php

declare(strict_types=1);

namespace Admin\Model\Media;

/**
 * POPO entity `media` (chuẩn 07 §3): MediaMapper fill row qua `fromRow()`
 * — model không query DB, không đụng filesystem. `path` là đường dẫn tương đối
 * trong `public/uploads/` (docs §3.10); `variants` là chuỗi JSON thô
 * {thumb|medium|large => path} — giải mã qua `variantsArray()`.
 */
class MediaModel
{
    public int $id = 0;
    public string $disk = MediaConst::DISK_LOCAL;
    public string $path = '';
    public string $originalName = '';
    public string $mimeType = '';
    public int $sizeBytes = 0;
    public ?int $width = null;
    public ?int $height = null;
    public ?string $altText = null;
    public ?string $variants = null;
    public ?int $uploadedBy = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m               = new static();
        $m->id           = (int) ($row['id'] ?? 0);
        $m->disk         = (string) ($row['disk'] ?? MediaConst::DISK_LOCAL);
        $m->path         = (string) ($row['path'] ?? '');
        $m->originalName = (string) ($row['originalName'] ?? '');
        $m->mimeType     = (string) ($row['mimeType'] ?? '');
        $m->sizeBytes    = (int) ($row['sizeBytes'] ?? 0);
        $m->width        = self::intOrNull($row['width'] ?? null);
        $m->height       = self::intOrNull($row['height'] ?? null);
        $m->altText      = self::strOrNull($row['altText'] ?? null);
        $m->variants     = self::strOrNull($row['variants'] ?? null);
        $m->uploadedBy   = self::intOrNull($row['uploadedBy'] ?? null);
        $m->createdAt    = (string) ($row['createdAt'] ?? '');
        $m->updatedAt    = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API media chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'disk'         => $this->disk,
            'path'         => $this->path,
            'originalName' => $this->originalName,
            'mimeType'     => $this->mimeType,
            'sizeBytes'    => $this->sizeBytes,
            'width'        => $this->width,
            'height'       => $this->height,
            'altText'      => $this->altText,
            'variants'     => $this->variantsArray(),
            'uploadedBy'   => $this->uploadedBy,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form alt (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'id'      => $this->id,
            'altText' => $this->altText ?? '',
        ];
    }

    /**
     * Biến thể đã giải mã: tên biến thể => đường dẫn tương đối trong uploads.
     * JSON hỏng/rỗng → mảng rỗng (view tự in ảnh gốc).
     *
     * @return array<string, string>
     */
    public function variantsArray(): array
    {
        if ($this->variants === null || $this->variants === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($this->variants, true);

        if (! is_array($decoded)) {
            return [];
        }

        $variants = [];
        /** @psalm-suppress MixedAssignment — json_decode trả mảng mixed */
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $variants[$key] = $value;
            }
        }

        return $variants;
    }

    /**
     * Mảng biến thể encode để ghi cột variants (NULL nếu không có).
     *
     * @param array<string, string> $variants
     */
    public static function encodeVariants(array $variants): ?string
    {
        if ($variants === []) {
            return null;
        }

        $encoded = json_encode(
            $variants,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : null;
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
