<?php

declare(strict_types=1);

namespace Admin\Model\Review;

final class ReviewModel
{
    /** @psalm-suppress PossiblyUnusedProperty view template đọc trực tiếp. */
    public int $id = 0;
    public int $imageMediaId = 0;
    public ?string $altText = null;
    public int $sortOrder = 0;
    public int $isActive = ReviewConst::ACTIVE;

    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int) ($row['id'] ?? 0);
        $model->imageMediaId = (int) ($row['imageMediaId'] ?? 0);
        $alt = $row['altText'] ?? null;
        $model->altText = is_string($alt) && $alt !== '' ? $alt : null;
        $model->sortOrder = (int) ($row['sortOrder'] ?? 0);
        $model->isActive = (int) ($row['isActive'] ?? ReviewConst::ACTIVE);

        return $model;
    }

    /** @return array<array-key, mixed> */
    public function toFormValues(): array
    {
        return [
            'imageMediaId' => $this->imageMediaId,
            'altText' => $this->altText ?? '',
            'sortOrder' => $this->sortOrder,
            'isActive' => $this->isActive,
        ];
    }
}
