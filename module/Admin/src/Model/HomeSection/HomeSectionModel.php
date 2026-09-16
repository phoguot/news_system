<?php

declare(strict_types=1);

namespace Admin\Model\HomeSection;

use JsonException;

/**
 * POPO entity `home_sections` (chuẩn 07 §3): HomeSectionMapper fill row qua
 * `fromRow()` — model không query DB, không biết HTTP. `config` giữ nguyên
 * chuỗi JSON thô từ cột JSON; kiểm cấu trúc theo `type` ở HomeSectionService.
 */
class HomeSectionModel
{
    public int $id = 0;
    public int $type = HomeSectionConst::TYPE_FEATURED_POSTS;
    public ?string $title = null;
    public ?string $subtitle = null;
    public ?string $config = null;
    public int $sortOrder = 0;
    public int $isActive = HomeSectionConst::ACTIVE;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m             = new static();
        $m->id         = (int) ($row['id'] ?? 0);
        $m->type       = (int) ($row['type'] ?? HomeSectionConst::TYPE_FEATURED_POSTS);
        $m->title      = self::strOrNull($row['title'] ?? null);
        $m->subtitle   = self::strOrNull($row['subtitle'] ?? null);
        $m->config     = self::strOrNull($row['config'] ?? null);
        $m->sortOrder  = (int) ($row['sortOrder'] ?? 0);
        $m->isActive   = (int) ($row['isActive'] ?? HomeSectionConst::ACTIVE);
        $m->createdAt  = (string) ($row['createdAt'] ?? '');
        $m->updatedAt  = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API home-sections chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type,
            'title'     => $this->title,
            'subtitle'  => $this->subtitle,
            'config'    => $this->configArray(),
            'sortOrder' => $this->sortOrder,
            'isActive'  => $this->isActive,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form section (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'type'      => $this->type,
            'title'     => $this->title ?? '',
            'subtitle'  => $this->subtitle ?? '',
            'config'    => $this->configForTextarea(),
            'sortOrder' => $this->sortOrder,
            'isActive'  => $this->isActive,
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function configArray(): ?array
    {
        if ($this->config === null || $this->config === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($this->config, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** JSON đẹp (2 space, giữ tiếng Việt) cho textarea — rỗng nếu config trống. */
    public function configForTextarea(): string
    {
        $decoded = $this->configArray();
        if ($decoded === null) {
            return $this->config ?? '';
        }

        return (string) json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private static function strOrNull(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
