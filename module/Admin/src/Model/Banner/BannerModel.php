<?php

declare(strict_types=1);

namespace Admin\Model\Banner;

/**
 * POPO entity `banners` (chuẩn 07 §3): BannerMapper fill row qua `fromRow()`
 * — model không query DB, không biết HTTP. startAt/endAt là chuỗi UTC
 * 'Y-m-d H:i:s' (quy đổi giờ VN ở BannerService).
 */
class BannerModel
{
    public int $id = 0;
    public string $position = BannerConst::POSITION_HOME_HERO;
    public ?string $title = null;
    public ?string $subtitle = null;
    public int $imageMediaId = 0;
    public ?int $mobileImageMediaId = null;
    public ?string $linkUrl = null;
    public int $openNewTab = 0;
    public ?string $buttonText = null;
    public int $sortOrder = 0;
    public int $isActive = BannerConst::ACTIVE;
    public ?string $startAt = null;
    public ?string $endAt = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m                     = new static();
        $m->id                 = (int) ($row['id'] ?? 0);
        $m->position           = (string) ($row['position'] ?? BannerConst::POSITION_HOME_HERO);
        $m->title              = self::strOrNull($row['title'] ?? null);
        $m->subtitle           = self::strOrNull($row['subtitle'] ?? null);
        $m->imageMediaId       = (int) ($row['imageMediaId'] ?? 0);
        $m->mobileImageMediaId = self::intOrNull($row['mobileImageMediaId'] ?? null);
        $m->linkUrl            = self::strOrNull($row['linkUrl'] ?? null);
        $m->openNewTab         = (int) ($row['openNewTab'] ?? 0);
        $m->buttonText         = self::strOrNull($row['buttonText'] ?? null);
        $m->sortOrder          = (int) ($row['sortOrder'] ?? 0);
        $m->isActive           = (int) ($row['isActive'] ?? BannerConst::ACTIVE);
        $m->startAt            = self::strOrNull($row['startAt'] ?? null);
        $m->endAt              = self::strOrNull($row['endAt'] ?? null);
        $m->createdAt          = (string) ($row['createdAt'] ?? '');
        $m->updatedAt          = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod endpoint API banners chưa triển khai — giữ chuẩn 07 §6.
     */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'position'           => $this->position,
            'title'              => $this->title,
            'subtitle'           => $this->subtitle,
            'imageMediaId'       => $this->imageMediaId,
            'mobileImageMediaId' => $this->mobileImageMediaId,
            'linkUrl'            => $this->linkUrl,
            'openNewTab'         => $this->openNewTab === 1,
            'buttonText'         => $this->buttonText,
            'sortOrder'          => $this->sortOrder,
            'isActive'           => $this->isActive,
            'startAt'            => $this->startAt,
            'endAt'              => $this->endAt,
            'createdAt'          => $this->createdAt,
            'updatedAt'          => $this->updatedAt,
        ];
    }

    /** Giá trị điền lại form banner (input name => value). @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'position'           => $this->position,
            'title'              => $this->title ?? '',
            'subtitle'           => $this->subtitle ?? '',
            'imageMediaId'       => $this->imageMediaId,
            'mobileImageMediaId' => $this->mobileImageMediaId ?? '',
            'linkUrl'            => $this->linkUrl ?? '',
            'openNewTab'         => $this->openNewTab,
            'buttonText'         => $this->buttonText ?? '',
            'sortOrder'          => $this->sortOrder,
            'isActive'           => $this->isActive,
            'startAt'            => $this->startAt ?? '',
            'endAt'              => $this->endAt ?? '',
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
