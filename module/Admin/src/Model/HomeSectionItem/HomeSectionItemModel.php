<?php

declare(strict_types=1);

namespace Admin\Model\HomeSectionItem;

/**
 * POPO entity `home_section_items` (chuẩn 07 §3): item đa hình chọn tay trong
 * section `mode=manual` (FR-33, docs §4.4.4). `itemType` =
 * ContentConst::SECTION_ITEM_* — 1 post · 2 service · 3 team_member; `itemId`
 * trỏ bảng chủ theo itemType (không FK — tầng ứng dụng validate tồn tại,
 * render bỏ qua mục đã ẩn/xoá).
 */
final class HomeSectionItemModel
{
    public int $id = 0;
    public int $sectionId = 0;
    public int $itemType = 0;
    public int $itemId = 0;
    public int $sortOrder = 0;

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m            = new static();
        $m->id        = (int) ($row['id'] ?? 0);
        $m->sectionId = (int) ($row['sectionId'] ?? 0);
        $m->itemType  = (int) ($row['itemType'] ?? 0);
        $m->itemId    = (int) ($row['itemId'] ?? 0);
        $m->sortOrder = (int) ($row['sortOrder'] ?? 0);

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @psalm-suppress PossiblyUnusedMethod render FR-33 phía Frontend chưa code — giữ khuôn 07 §3.
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'sectionId' => $this->sectionId,
            'itemType'  => $this->itemType,
            'itemId'    => $this->itemId,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
