<?php

declare(strict_types=1);

namespace Frontend\Model\Menu;

/**
 * POPO entity `menu_items`.
 */
class MenuModel
{
    public int $id = 0;
    public string $label = '';
    public string $url = '';
    public string $target = MenuConst::TARGET_SELF;
    public int $sortOrder = 0;
    public int $isActive = MenuConst::ACTIVE;

    /** @param array<array-key, mixed> $row */
    public static function fromRow(array $row): static
    {
        /** @psalm-suppress UnsafeInstantiation POPO không có constructor; khuôn hydrate hiện có của repo. */
        $m = new static();
        $m->id = (int) ($row['id'] ?? 0);
        $m->label = (string) ($row['label'] ?? '');
        $m->url = (string) ($row['url'] ?? '');
        $m->target = (string) ($row['target'] ?? MenuConst::TARGET_SELF);
        $m->sortOrder = (int) ($row['sortOrder'] ?? 0);
        $m->isActive = (int) ($row['isActive'] ?? MenuConst::ACTIVE);

        return $m;
    }

    /** @return array<string, mixed> */
    public function toFormValues(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
            'target' => $this->target,
            'sortOrder' => (string) $this->sortOrder,
            'isActive' => (string) $this->isActive,
        ];
    }
}
