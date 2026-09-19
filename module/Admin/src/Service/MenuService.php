<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Active\ActiveStatusFilter;
use Admin\Filter\Menu\MenuActionFilter;
use Admin\Filter\Menu\MenuSaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Frontend\Model\Menu\MenuConst;
use Frontend\Model\Menu\MenuMapper;
use Frontend\Model\Menu\MenuModel;
use Frontend\Service\MenuService as FrontendMenuService;

/**
 * Nghiệp vụ quản lý menu FE. Bảng do Frontend sở hữu mapper; Admin dùng lại.
 */
class MenuService extends AppServiceFactory
{
    private function menuMapper(): MenuMapper
    {
        /** @var MenuMapper */
        return $this->getContainerEntry(MenuMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    private function frontendMenu(): FrontendMenuService
    {
        /** @var FrontendMenuService */
        return $this->getContainerEntry(FrontendMenuService::class);
    }

    /** @return list<MenuModel> */
    public function listAll(): array
    {
        return $this->menuMapper()->listAll();
    }

    public function findOrFail(int $id): MenuModel
    {
        $model = $this->menuMapper()->findById($id);
        if ($model === null) {
            throw NotFoundException::forEntity('menu', $id);
        }

        return $model;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException
     */
    public function saveForm(?int $id, array $raw): void
    {
        $filter = new MenuSaveFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $this->buildValues($filter->getValues());
        if ($id === null) {
            $this->menuMapper()->insert($values);
        } else {
            $this->findOrFail($id);
            $this->menuMapper()->update($id, $values);
        }
        $this->frontendMenu()->invalidate();
    }

    /** @param array<array-key, mixed> $raw */
    public function deleteForm(array $raw): string
    {
        $filter = new MenuActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->findOrFail($filter->idValue());
            $this->menuMapper()->delete($filter->idValue());
            $this->frontendMenu()->invalidate();

            return MenuConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    public function saveFormCsrfHash(): string
    {
        return (new MenuSaveFilter())->csrfHash();
    }

    public function deleteFormCsrfHash(): string
    {
        return (new MenuActionFilter())->csrfHash();
    }

    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    /** Hash CSRF cho select bật/tắt nhanh trên danh sách. */
    public function activeFormCsrfHash(): string
    {
        return (new ActiveStatusFilter())->csrfHash();
    }

    /**
     * Đổi nhanh cột isActive từ danh sách — chỉ chạm đúng cờ hiển thị.
     *
     * @param array<array-key, mixed> $raw id + isActive + csrf
     */
    public function activeForm(array $raw): string
    {
        $filter = new ActiveStatusFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();
            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $id = $filter->idValue();
            $this->findOrFail($id);
            $this->menuMapper()->update($id, ['isActive' => $filter->activeValue()]);
            $this->frontendMenu()->invalidate();
            return 'active-updated';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array{flag: string, applied: int}
     */
    public function formReorder(array $raw): array
    {
        $filter = new ReorderFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            return ['flag' => 'csrf', 'applied' => 0];
        }

        $sequence = $this->reorderSequence($filter->idList());
        $changed = [];
        foreach ($sequence as $index => $row) {
            if ($row->sortOrder !== $index) {
                $changed[$row->id] = $index;
            }
        }
        if ($changed === []) {
            return ['flag' => 'reordered', 'applied' => 0];
        }

        $mapper = $this->menuMapper();
        $this->db()->transactional(static function () use ($mapper, $changed): void {
            foreach ($changed as $id => $index) {
                $mapper->update($id, ['sortOrder' => $index]);
            }
        });
        $this->frontendMenu()->invalidate();

        return ['flag' => 'reordered', 'applied' => count($changed)];
    }

    /**
     * @param list<int> $requested
     *
     * @return list<MenuModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows = $this->menuMapper()->listAll();
        $byId = [];
        $existing = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
            $existing[] = $row->id;
        }

        $left = array_flip($existing);
        $ordered = [];
        foreach ($requested as $id) {
            if (isset($left[$id])) {
                $ordered[] = $byId[$id];
                unset($left[$id]);
            }
        }
        foreach ($existing as $id) {
            if (isset($left[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function buildValues(array $data): array
    {
        return [
            'label' => trim((string) ($data['label'] ?? '')),
            'url' => trim((string) ($data['url'] ?? '')),
            'target' => (string) ($data['target'] ?? MenuConst::TARGET_SELF),
            'sortOrder' => (int) ($data['sortOrder'] ?? 0),
            'isActive' => $data['isActive'] === '0' || $data['isActive'] === 0 || $data['isActive'] === false
                ? MenuConst::INACTIVE
                : MenuConst::ACTIVE,
        ];
    }
}
