<?php

declare(strict_types=1);

namespace Frontend\Model\PricingGroup;

use Frontend\Model\Pricing\PricingConst;

/** Cấu hình hiển thị một nhóm bảng giá, liên kết một dịch vụ cha. */
class PricingGroupModel
{
    public int $id = 0;
    public int $serviceId = 0;
    public int $sortOrder = 0;
    public int $isActive = PricingConst::ACTIVE;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): static
    {
        $model = new static();
        $model->id = (int) ($row['id'] ?? 0);
        $model->serviceId = (int) ($row['serviceId'] ?? 0);
        $model->sortOrder = (int) ($row['sortOrder'] ?? 0);
        $model->isActive = (int) ($row['isActive'] ?? PricingConst::ACTIVE);
        $model->createdAt = (string) ($row['createdAt'] ?? '');
        $model->updatedAt = (string) ($row['updatedAt'] ?? '');

        return $model;
    }
}
