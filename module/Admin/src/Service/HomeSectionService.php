<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Active\ActiveStatusFilter;
use Admin\Filter\HomeSection\HomeSectionActionFilter;
use Admin\Filter\HomeSection\HomeSectionItemsFilter;
use Admin\Filter\HomeSection\HomeSectionSaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\HomeSection\HomeSectionConst;
use Admin\Model\HomeSection\HomeSectionMapper;
use Admin\Model\HomeSection\HomeSectionModel;
use Admin\Model\HomeSectionItem\HomeSectionItemMapper;
use Admin\Model\HomeSectionItem\HomeSectionItemModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\TeamMember\TeamMemberMapper;
use Application\Constant\CacheConst;
use Application\Constant\ContentConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use Frontend\Model\Service\ServiceMapper;

/**
 * Nghiệp vụ bố cục trang chủ (docs §3.7, FR-32). Luồng chuẩn 07 §2: Service
 * chạy HomeSectionSaveFilter trên raw, validate `config` theo `type` (cần
 * CategoryMapper đối chiếu category_id — nên nằm ở Service, không ở filter),
 * Mapper trả HomeSectionModel. Xoá section chạy trong transaction cùng dọn
 * `home_section_items` (docs §4.4.4). FR-33 (13/09/2026): mục chọn tay cho
 * `mode=manual` quản qua itemsForm() — itemType phải khớp loại section
 * (HomeSectionConst::MANUAL_ITEM_TYPES), đối chiếu TỒN TẠI ở bảng chủ theo
 * đa hình (post/service/team_member — mỗi bảng qua mapper của nó, 07 §5).
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class HomeSectionService extends AppServiceFactory
{
    private function homeSectionMapper(): HomeSectionMapper
    {
        /** @var HomeSectionMapper */
        return $this->getContainerEntry(HomeSectionMapper::class);
    }

    private function homeSectionItemMapper(): HomeSectionItemMapper
    {
        /** @var HomeSectionItemMapper */
        return $this->getContainerEntry(HomeSectionItemMapper::class);
    }

    private function categoryMapper(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    private function postMapper(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function teamMemberMapper(): TeamMemberMapper
    {
        /** @var TeamMemberMapper */
        return $this->getContainerEntry(TeamMemberMapper::class);
    }

    /**
     * @return list<HomeSectionModel>
     */
    public function listAll(): array
    {
        return $this->homeSectionMapper()->listAll();
    }

    public function findOrFail(int $id): HomeSectionModel
    {
        $section = $this->homeSectionMapper()->findById($id);
        if ($section === null) {
            throw NotFoundException::forEntity('section trang chủ', $id);
        }

        return $section;
    }

    /**
     * Entry point form trang: chạy HomeSectionSaveFilter (kèm CSRF) trên POST thô.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException mang lỗi từng trường (fieldErrors của filter)
     */
    public function saveForm(?int $id, array $raw): void
    {
        $this->saveValidated($id, $raw, true);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function saveValidated(?int $id, array $raw, bool $withCsrf): void
    {
        $filter = new HomeSectionSaveFilter($withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $values = $this->buildValues($filter->getValues());

        if ($id === null) {
            $this->create($values);

            return;
        }

        $this->update($id, $values);
    }

    /**
     * Xoá từ form danh sách: chạy HomeSectionActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new HomeSectionActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->delete((int) $filter->idValue());

            return HomeSectionConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** Hash CSRF cho form tạo/sửa section. */
    public function saveFormCsrfHash(): string
    {
        return (new HomeSectionSaveFilter())->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new HomeSectionActionFilter())->csrfHash();
    }

    /** Hash CSRF cho lệnh kéo-thả đổi thứ tự (ReorderFilter). */
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
            $this->homeSectionMapper()->update($id, ['isActive' => $filter->activeValue()]);
            $this->invalidateHome();

            return 'active-updated';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /**
     * Kéo-thả thứ tự section trang chủ (FR-32 — phần section; mục `mode=manual`
     * vẫn xếp bằng nút lên/xuống của itemMove FR-33). Id nêu trong payload nhận
     * sortOrder 0..n-1; dòng không nêu giữ nguyên thứ tự và được đánh số tiếp
     * ở cuối — không mất dòng. Chỉ ghi dòng thực đổi giá trị, trong một
     * transaction; invalidate khối trang chủ.
     *
     * @param array<array-key, mixed> $raw `ids` (CSV/mảng) + `csrf`
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
        $mapper   = $this->homeSectionMapper();

        $changed = [];
        foreach ($sequence as $index => $row) {
            if ($row->sortOrder !== $index) {
                $changed[$row->id] = $index;
            }
        }
        if ($changed === []) {
            return ['flag' => 'reordered', 'applied' => 0];
        }

        $this->db()->transactional(static function () use ($mapper, $changed): void {
            foreach ($changed as $id => $index) {
                $mapper->update($id, ['sortOrder' => $index]);
            }
        });
        $this->invalidateHome();

        return ['flag' => 'reordered', 'applied' => count($changed)];
    }

    /**
     * Thứ tự dòng sau reorder: id được nêu trước (theo payload, bỏ id lạ),
     * phần còn lại giữ nguyên thứ tự listAll.
     *
     * @param list<int> $requested
     *
     * @return list<HomeSectionModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows   = $this->homeSectionMapper()->listAll();
        $byId   = [];
        $existing = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
            $existing[]     = $row->id;
        }

        /** @var array<int, int> $left */
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
     * Danh sách mục `mode=manual` của section, theo thứ tự hiển thị (FR-33).
     *
     * @return list<HomeSectionItemModel>
     *
     * @throws NotFoundException khi section không tồn tại
     */
    public function itemsList(int $sectionId): array
    {
        $this->findOrFail($sectionId);

        return $this->homeSectionItemMapper()->listBySectionId($sectionId);
    }

    /**
     * Bản ghi ứng viên cho select "thêm mục" theo loại mục được phép của
     * section (đa hình như referencedExists — mỗi bảng qua mapper chủ của nó).
     *
     * @return list<array{id: int, label: string}>
     */
    public function itemOptions(?int $itemType): array
    {
        if ($itemType === null) {
            return [];
        }

        if ($itemType === ContentConst::SECTION_ITEM_SERVICE) {
            $options = [];
            foreach ($this->serviceMapper()->listActiveOptions() as $id => $name) {
                $options[] = ['id' => $id, 'label' => $name];
            }

            return $options;
        }

        return match ($itemType) {
            ContentConst::SECTION_ITEM_POST => $this->postMapper()->listOptions(),
            ContentConst::SECTION_ITEM_TEAM_MEMBER => $this->teamMemberMapper()->listOptions(),
            default => [],
        };
    }

    /**
     * Danh mục cho dropdown `category_id` của section "Tin theo danh mục" —
     * để người dùng chọn tên thay vì gõ ID vào JSON (FR-32, UX config).
     *
     * @return list<array{id: int, label: string}>
     */
    public function categoryOptions(): array
    {
        $options = [];
        foreach ($this->categoryMapper()->listAll() as $category) {
            $options[] = ['id' => $category->id, 'label' => $category->name];
        }

        return $options;
    }

    /** Hash CSRF cho các form quản mục (thêm/xoá/di chuyển dùng chung một filter). */
    public function itemsFormCsrfHash(): string
    {
        return (new HomeSectionItemsFilter())->csrfHash();
    }

    /**
     * Một thao tác trên danh sách mục manual: chạy HomeSectionItemsFilter
     * (kèm CSRF) rồi nhánh theo `op`. Trả query flag PRG như deleteForm —
     * controller không bắt exception nghiệp vụ ở màn này.
     *
     * @param array<array-key, mixed> $raw op + id|itemType,itemId + csrf
     *
     * @throws NotFoundException khi section không tồn tại
     */
    public function itemsForm(int $sectionId, array $raw): string
    {
        $filter = new HomeSectionItemsFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'invalid';
        }

        $section = $this->findOrFail($sectionId);
        $op      = $filter->opValue();

        $result = match ($op) {
            'add'    => $this->itemAdd($section, $filter),
            'remove' => $this->itemRemove($section, $filter),
            'up'     => $this->itemMove($section, $filter, true),
            'down'   => $this->itemMove($section, $filter, false),
            default  => 'invalid',
        };
        $changed = [
            HomeSectionConst::FLAG_ITEM_ADDED,
            HomeSectionConst::FLAG_ITEM_REMOVED,
            HomeSectionConst::FLAG_ITEM_MOVED,
        ];
        if (in_array($result, $changed, true)) {
            $this->invalidateHome();
        }

        return $result;
    }

    /**
     * Thêm mục: itemType phải khớp loại section (MANUAL_ITEM_TYPES), bảng chủ
     * phải còn dòng, chưa trùng trong section, chưa vượt trần 50; gắn cuối
     * danh sách (sortOrder = max + 1).
     */
    private function itemAdd(HomeSectionModel $section, HomeSectionItemsFilter $filter): string
    {
        $allowed  = HomeSectionConst::MANUAL_ITEM_TYPES[$section->type] ?? 0;
        $itemType = $filter->intOf('itemType');
        $itemId   = $filter->intOf('itemId');
        if ($allowed === 0 || $itemType !== $allowed || $itemId <= 0) {
            return 'invalid';
        }

        if (! $this->referencedExists($itemType, $itemId)) {
            return HomeSectionConst::FLAG_ITEM_MISSING;
        }

        $items = $this->homeSectionItemMapper();
        if ($items->existsItem($section->id, $itemType, $itemId)) {
            return HomeSectionConst::FLAG_ITEM_DUPLICATE;
        }

        $rows = $items->listBySectionId($section->id);
        if (count($rows) >= HomeSectionConst::ITEM_LIMIT) {
            return HomeSectionConst::FLAG_ITEM_LIMIT;
        }

        $sortOrder = 0;
        foreach ($rows as $row) {
            $sortOrder = max($sortOrder, $row->sortOrder);
        }

        $items->insert([
            'sectionId' => $section->id,
            'itemType'  => $itemType,
            'itemId'    => $itemId,
            'sortOrder' => $sortOrder + 1,
        ]);

        return HomeSectionConst::FLAG_ITEM_ADDED;
    }

    /** Xoá mục — id dòng phải thuộc đúng section (chặn thao tác chéo từ raw sửa tay). */
    private function itemRemove(HomeSectionModel $section, HomeSectionItemsFilter $filter): string
    {
        $id  = $filter->intOf('id');
        $row = $this->findItemInSection($section->id, $id);
        if ($row === null) {
            return 'notfound';
        }

        $this->homeSectionItemMapper()->deleteByIdAndSection($id, $section->id);

        return HomeSectionConst::FLAG_ITEM_REMOVED;
    }

    /**
     * Di chuyển mục lên/xuống một vị trí rồi đánh số lại toàn bộ
     * sortOrder = 0..n-1 trong MỘT transaction (n ≤ 50 — bỏ qua khi đã ở
     * biên, trả cờ moved để PRG về đúng trang).
     */
    private function itemMove(HomeSectionModel $section, HomeSectionItemsFilter $filter, bool $up): string
    {
        $id  = $filter->intOf('id');
        $row = $this->findItemInSection($section->id, $id);
        if ($row === null) {
            return 'notfound';
        }

        $rows = $this->homeSectionItemMapper()->listBySectionId($section->id);
        $pos  = array_search($id, array_map(
            static fn (HomeSectionItemModel $item): int => $item->id,
            $rows
        ), true);
        if ($pos === false) {
            return 'notfound';
        }

        $swap = $up ? $pos - 1 : $pos + 1;
        if ($swap < 0 || $swap >= count($rows)) {
            return HomeSectionConst::FLAG_ITEM_MOVED;
        }

        $ordered             = $rows;
        $ordered[$swap]      = $rows[$pos];
        $ordered[$pos]       = $rows[$swap];

        $items = $this->homeSectionItemMapper();
        $this->db()->transactional(function () use ($items, $ordered): void {
            foreach (array_values($ordered) as $index => $item) {
                if ($item->sortOrder !== $index) {
                    $items->updateSortOrder($item->id, $index);
                }
            }
        });

        return HomeSectionConst::FLAG_ITEM_MOVED;
    }

    /** Dòng item thuộc đúng section (không tin id từ raw một mình). */
    private function findItemInSection(int $sectionId, int $id): ?HomeSectionItemModel
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($this->homeSectionItemMapper()->listBySectionId($sectionId) as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    /** Đối chiếu TỒN TẠI theo đa hình (docs §4.4.4 — mỗi bảng qua mapper chủ của nó). */
    private function referencedExists(int $itemType, int $itemId): bool
    {
        return match ($itemType) {
            ContentConst::SECTION_ITEM_POST => $this->postMapper()->findById($itemId) !== null,
            ContentConst::SECTION_ITEM_SERVICE => $this->serviceMapper()->findById($itemId) !== null,
            ContentConst::SECTION_ITEM_TEAM_MEMBER => $this->teamMemberMapper()->findById($itemId) !== null,
            default => false,
        };
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function create(array $values): int
    {
        $id = $this->homeSectionMapper()->insert($values);
        $this->invalidateHome();

        return $id;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws NotFoundException khi id không tồn tại
     */
    public function update(int $id, array $values): void
    {
        $this->findOrFail($id);
        $this->homeSectionMapper()->update($id, $values);
        $this->invalidateHome();
    }

    /**
     * Xoá section + dọn home_section_items trong MỘT transaction
     * (docs §4.4.4 — không để orphan item trỏ vào section đã chết).
     *
     * @throws NotFoundException
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $items    = $this->homeSectionItemMapper();
        $sections = $this->homeSectionMapper();
        $this->db()->transactional(function () use ($id, $items, $sections): void {
            $items->deleteBySectionId($id);
            $sections->delete($id);
        });
        $this->invalidateHome();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> values cho Mapper
     */
    private function buildValues(array $data): array
    {
        $type   = (int) $data['type'];
        $config = $this->normalizeConfig($type, $data['config'] ?? null);

        return [
            'type'      => $type,
            'title'     => $this->nullableTrim($data['title'] ?? null),
            'subtitle'  => $this->nullableTrim($data['subtitle'] ?? null),
            'config'    => $config,
            'sortOrder' => (int) ($data['sortOrder'] ?? 0),
            'isActive'  => $this->flag($data['isActive'] ?? null),
        ];
    }

    /**
     * Chuỗi JSON thô → validate theo type → encode compact để lưu.
     * Rỗng → null (section dùng config mặc định phía render).
     */
    private function normalizeConfig(int $type, mixed $raw): ?string
    {
        $text = trim((string) $raw);
        /** @var array<array-key, mixed>|null $decoded */
        $decoded = $text === '' ? null : json_decode($text, true);
        if ($text !== '' && ! is_array($decoded)) {
            throw new ValidationException(['config' => HomeSectionConst::ERROR_CONFIG]);
        }

        $error = $this->configError($type, $decoded);
        if ($error !== null) {
            throw new ValidationException(['config' => $error]);
        }

        if ($decoded === null) {
            return null;
        }

        return (string) json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Ràng buộc config theo `type` (02-quy-chuan-db §4 + seed.sql). Trả message
     * lỗi đầu tiên hoặc null nếu hợp lệ.
     *
     * @param array<array-key, mixed>|null $config
     */
    private function configError(int $type, ?array $config): ?string
    {
        $rules = match ($type) {
            HomeSectionConst::TYPE_HERO_BANNER    => ['autoplay' => 'bool', 'interval_ms' => 'interval'],
            HomeSectionConst::TYPE_FEATURED_POSTS => ['mode' => 'mode', 'limit' => 'limit'],
            HomeSectionConst::TYPE_LATEST_POSTS   => ['limit' => 'limit'],
            HomeSectionConst::TYPE_CATEGORY_POSTS => ['category_id' => 'category', 'limit' => 'limit'],
            HomeSectionConst::TYPE_SERVICES       => ['mode' => 'mode', 'limit' => 'limit'],
            HomeSectionConst::TYPE_TEAM           => ['mode' => 'mode', 'limit' => 'limit'],
            HomeSectionConst::TYPE_CONTACT_CTA    => ['button_text' => 'text', 'button_url' => 'url'],
            HomeSectionConst::TYPE_PROCESS        => ['steps' => 'steps'],
            default => [],
        };
        $config ??= [];

        foreach (array_keys($config) as $key) {
            if (! isset($rules[$key])) {
                return HomeSectionConst::ERROR_UNKNOWN_KEY;
            }
        }

        if ($type === HomeSectionConst::TYPE_CATEGORY_POSTS && ! isset($config['category_id'])) {
            return HomeSectionConst::ERROR_CATEGORY;
        }

        /** @var array<array-key, mixed> $value */
        foreach ($rules as $key => $rule) {
            if (! array_key_exists($key, $config)) {
                continue;
            }

            $error = $this->configValueError($rule, $config[$key]);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /** Khóa đã có mặt trong config — kiểm giá trị theo kiểu của rule. */
    private function configValueError(string $rule, mixed $value): ?string
    {
        return match ($rule) {
            'bool'     => is_bool($value) ? null : HomeSectionConst::ERROR_AUTOPLAY,
            'interval' => is_int($value)
                && $value >= HomeSectionConst::INTERVAL_MS_MIN
                && $value <= HomeSectionConst::INTERVAL_MS_MAX
                ? null : HomeSectionConst::ERROR_INTERVAL,
            'mode'     => is_string($value)
                && in_array($value, [HomeSectionConst::MODE_AUTO, HomeSectionConst::MODE_MANUAL], true)
                ? null : HomeSectionConst::ERROR_MODE,
            'limit'    => is_int($value)
                && $value >= HomeSectionConst::LIMIT_MIN
                && $value <= HomeSectionConst::LIMIT_MAX
                ? null : HomeSectionConst::ERROR_LIMIT,
            'category' => is_int($value)
                && $value > 0
                && $this->categoryMapper()->findById($value) !== null
                ? null : HomeSectionConst::ERROR_CATEGORY,
            'text'     => is_string($value)
                && mb_strlen($value) <= HomeSectionConst::MAX_LENGTH_BUTTON
                ? null : HomeSectionConst::ERROR_CONFIG,
            'url'      => is_string($value)
                && preg_match('#^(https?://|/)#', $value) === 1
                && mb_strlen($value) <= HomeSectionConst::MAX_LENGTH_URL
                ? null : HomeSectionConst::ERROR_BUTTON_URL,
            'steps'    => is_array($value) && array_is_list($value) && $this->isValidProcessSteps($value)
                ? null : HomeSectionConst::ERROR_PROCESS_STEPS,
            default => null,
        };
    }

    /** @param list<mixed> $steps */
    private function isValidProcessSteps(array $steps): bool
    {
        if (count($steps) < 3 || count($steps) > 6) {
            return false;
        }
        foreach ($steps as $step) {
            if (! is_array($step) || ! isset($step['title']) || ! is_string($step['title'])) {
                return false;
            }
            $title = trim($step['title']);
            if (mb_strlen($title) < 2 || mb_strlen($title) > 80) {
                return false;
            }
            if (isset($step['desc']) && ! is_string($step['desc'])) {
                return false;
            }
            if (isset($step['desc']) && mb_strlen($step['desc']) > 300) {
                return false;
            }
        }

        return true;
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? HomeSectionConst::INACTIVE
            : HomeSectionConst::ACTIVE;
    }

    private function nullableTrim(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /** PageCacheService là dependency mềm (FR-39): test container thiếu key → null. */
    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /** FR-32: section/item là khung trang chủ — đổi gì cũng forget 'home-v1'. */
    private function invalidateHome(): void
    {
        $this->pageCache()?->forget(CacheConst::KEY_HOME);
    }
}
