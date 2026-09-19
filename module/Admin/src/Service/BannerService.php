<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Active\ActiveStatusFilter;
use Admin\Filter\Banner\BannerActionFilter;
use Admin\Filter\Banner\BannerSaveFilter;
use Admin\Filter\Reorder\ReorderFilter;
use Admin\Model\Banner\BannerConst;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Banner\BannerModel;
use Admin\Model\Media\MediaMapper;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Nghiệp vụ banner (docs §3.5): phân vị trí + thứ tự, lịch hiển thị
 * startAt/endAt nhập giờ VN → lưu UTC (quy tắc thời gian docs §4.1).
 * Luồng chuẩn 07 §2: Service chạy Filter trên raw, Mapper trả BannerModel.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class BannerService extends AppServiceFactory
{
    private function bannerMapper(): BannerMapper
    {
        /** @var BannerMapper */
        return $this->getContainerEntry(BannerMapper::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    /**
     * Ảnh trong thư viện media cho select box của form (07 §5 — Service điều
     * phối thêm mapper bảng khác, không join).
     *
     * @return list<array{id: int, label: string, path: string}>
     */
    public function mediaOptions(): array
    {
        return $this->mediaMapper()->listOptions();
    }

    /**
     * @return list<BannerModel>
     */
    public function listAll(): array
    {
        return $this->bannerMapper()->listAll();
    }

    public function findOrFail(int $id): BannerModel
    {
        $banner = $this->bannerMapper()->findById($id);
        if ($banner === null) {
            throw NotFoundException::forEntity('banner', $id);
        }

        return $banner;
    }

    /**
     * Giá trị điền form sửa: toFormValues của Model + startAt/endAt quy từ UTC
     * về giờ VN dạng datetime-local ('Y-m-d\TH:i') để input hiển thị đúng.
     *
     * @return array<array-key, mixed>
     */
    public function formValues(BannerModel $banner): array
    {
        $values             = $banner->toFormValues();
        $values['startAt']  = $this->utcToVnWallInput($banner->startAt);
        $values['endAt']    = $this->utcToVnWallInput($banner->endAt);

        return $values;
    }

    private function utcToVnWallInput(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        return $dt === false ? '' : $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d\TH:i');
    }

    /**
     * Entry point form trang: chạy BannerSaveFilter (kèm CSRF) trên POST thô.
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
        $filter = new BannerSaveFilter($withCsrf);
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
     * Xoá từ form danh sách: chạy BannerActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new BannerActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->delete((int) $filter->idValue());

            return BannerConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** Hash CSRF cho form tạo/sửa banner. */
    public function saveFormCsrfHash(): string
    {
        return (new BannerSaveFilter())->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new BannerActionFilter())->csrfHash();
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
            $this->bannerMapper()->update($id, ['isActive' => $filter->activeValue()]);
            $this->invalidateHome();

            return 'active-updated';
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /**
     * Kéo-thả thứ tự banner (FR-30). Id nêu trong payload nhận sortOrder
     * 0..n-1 theo thứ tự mới; dòng không nêu giữ nguyên thứ tự hiển thị và
     * được đánh số tiếp ở cuối — không mất dòng. Chỉ ghi dòng thực đổi giá trị,
     * trong một transaction; invalidate khối banner trang chủ.
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
        $mapper   = $this->bannerMapper();

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
     * @return list<BannerModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows   = $this->bannerMapper()->listAll();
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
     * Tạo banner — nhận values ĐÃ qua filter + buildValues. Trả về id mới.
     *
     * @param array<array-key, mixed> $values
     */
    public function create(array $values): int
    {
        $id = $this->bannerMapper()->insert($values);
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
        $this->bannerMapper()->update($id, $values);
        $this->invalidateHome();
    }

    /**
     * Xoá cứng (banner là lá — không bảng nào tham chiếu id của nó).
     *
     * @throws NotFoundException
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $this->bannerMapper()->delete($id);
        $this->invalidateHome();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> values cho Mapper
     */
    private function buildValues(array $data): array
    {
        $startAt = $this->vnTimeToUtc($data['startAt'] ?? null, 'startAt');
        $endAt   = $this->vnTimeToUtc($data['endAt'] ?? null, 'endAt');

        if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
            throw new ValidationException(['endAt' => BannerConst::ERROR_TIME_RANGE]);
        }

        return [
            'position'           => trim((string) $data['position']),
            'title'              => $this->nullableTrim($data['title'] ?? null),
            'subtitle'           => $this->nullableTrim($data['subtitle'] ?? null),
            'imageMediaId'       => (int) $data['imageMediaId'],
            'mobileImageMediaId' => $this->nullableInt($data['mobileImageMediaId'] ?? null),
            'linkUrl'            => $this->nullableTrim($data['linkUrl'] ?? null),
            'openNewTab'         => $this->flag($data['openNewTab'] ?? null),
            'buttonText'         => $this->nullableTrim($data['buttonText'] ?? null),
            'sortOrder'          => (int) ($data['sortOrder'] ?? 0),
            'isActive'           => $this->flag($data['isActive'] ?? null),
            'startAt'            => $startAt,
            'endAt'              => $endAt,
        ];
    }

    /**
     * Giờ VN tường minh (datetime-local form) → chuỗi UTC 'Y-m-d H:i:s'.
     * Rỗng → null; sai format → ValidationException theo đúng tên trường.
     */
    private function vnTimeToUtc(mixed $input, string $field): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', trim($input));
        $dt         = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $normalized,
            new DateTimeZone('Asia/Ho_Chi_Minh')
        );
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $normalized,
                new DateTimeZone('Asia/Ho_Chi_Minh')
            );
        }

        if ($dt === false) {
            throw new ValidationException([$field => BannerConst::ERROR_INVALID_TIME]);
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? BannerConst::INACTIVE
            : BannerConst::ACTIVE;
    }

    private function nullableTrim(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /** PageCacheService là dependency mềm (FR-39): test container thiếu key → null. */
    private function pageCache(): ?PageCacheService
    {
        $entry = $this->getContainerEntry(PageCacheService::class);

        return $entry instanceof PageCacheService ? $entry : null;
    }

    /** FR-32: banner hero là khối 1 của trang chủ — đổi gì cũng forget 'home-v1'. */
    private function invalidateHome(): void
    {
        $this->pageCache()?->forget(CacheConst::KEY_HOME);
    }
}
