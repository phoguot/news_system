<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Reorder\ReorderFilter;
use Admin\Filter\TeamMember\TeamMemberActionFilter;
use Admin\Filter\TeamMember\TeamMemberSaveFilter;
use Admin\Model\Media\MediaMapper;
use Admin\Model\TeamMember\TeamMemberConst;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\TeamMember\TeamMemberModel;
use Admin\Model\User\UserMapper;
use Application\Constant\CacheConst;
use Application\Factory\AppServiceFactory;
use Application\Service\DbService;
use Application\Service\PageCacheService;

/**
 * Nghiệp vụ thành viên đội ngũ (docs §3.6 FR-34). Luồng chuẩn 07 §2: Service
 * chạy Filter trên raw, Mapper trả TeamMemberModel. `userId` (nullable, unique
 * `uq_team_members_user`) liên kết tài khoản CMS + `socialLinks` JSON (URL mạng
 * xã hội) đã có cột DB từ đầu — form nay ghi đủ hai trường này.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class TeamMemberService extends AppServiceFactory
{
    private function teamMapper(): TeamMemberMapper
    {
        /** @var TeamMemberMapper */
        return $this->getContainerEntry(TeamMemberMapper::class);
    }

    private function db(): DbService
    {
        /** @var DbService */
        return $this->getContainerEntry(DbService::class);
    }

    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
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
     * @return list<TeamMemberModel>
     */
    public function listAll(): array
    {
        return $this->teamMapper()->listAll();
    }

    /**
     * Thẻ avatar cho cột "Ảnh" ở danh sách — MỘT query media cho cả bảng
     * (mapCardsByIds chống N+1, 07 §5 Service điều phối mapper bảng khác).
     * Id trỏ media đã xoá sẽ không có trong map — view tự fallback.
     *
     * @param list<TeamMemberModel> $members
     *
     * @return array<int, array{path: string, alt: string, thumb: string, large: string}>
     */
    public function avatarCardsFor(array $members): array
    {
        $ids = [];
        foreach ($members as $member) {
            if ($member->avatarMediaId !== null) {
                $ids[$member->avatarMediaId] = true;
            }
        }

        return $this->mediaMapper()->mapCardsByIds(array_keys($ids));
    }

    public function findOrFail(int $id): TeamMemberModel
    {
        $member = $this->teamMapper()->findById($id);
        if ($member === null) {
            throw NotFoundException::forEntity('thành viên', $id);
        }

        return $member;
    }

    /**
     * Entry point form trang: chạy TeamMemberSaveFilter (kèm CSRF) trên POST thô.
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
        $filter = new TeamMemberSaveFilter($withCsrf);
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $userId = $filter->userIdValue();
        $socialLinks = $filter->socialLinksValue();

        if ($userId !== null) {
            $userMapper = $this->userMapper();
            if ($userMapper === null || $userMapper->findById($userId) === null) {
                throw new ValidationException(['userId' => TeamMemberConst::ERROR_USER_NOT_FOUND]);
            }
            $taken = $this->teamMapper()->findByUserId($userId);
            if ($taken !== null && $taken->id !== $id) {
                throw new ValidationException(['userId' => TeamMemberConst::ERROR_USER_TAKEN]);
            }
        }

        $data = $filter->getValues();
        $data['userId'] = $userId;
        $data['socialLinks'] = $socialLinks;
        $values = $this->buildValues($data);

        if ($id === null) {
            $this->create($values);

            return;
        }

        $this->update($id, $values);
    }

    private function userMapper(): ?UserMapper
    {
        /** @var UserMapper|null */
        $entry = $this->getContainerEntry(UserMapper::class);

        return $entry instanceof UserMapper ? $entry : null;
    }

    /**
     * Xoá từ form danh sách: chạy TeamMemberActionFilter rồi trả query flag PRG.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new TeamMemberActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? 'csrf' : 'notfound';
        }

        try {
            $this->delete((int) $filter->idValue());

            return TeamMemberConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return 'notfound';
        }
    }

    /** Hash CSRF cho form tạo/sửa thành viên. */
    public function saveFormCsrfHash(): string
    {
        return (new TeamMemberSaveFilter())->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new TeamMemberActionFilter())->csrfHash();
    }

    /** Hash CSRF cho lệnh kéo-thả đổi thứ tự (ReorderFilter). */
    public function reorderCsrfHash(): string
    {
        return (new ReorderFilter())->csrfHash();
    }

    /**
     * Kéo-thả thứ tự thành viên (FR-34 — mục reorder). Id nêu trong payload
     * nhận sortOrder 0..n-1; dòng không nêu giữ nguyên thứ tự và được đánh số
     * tiếp ở cuối. Chỉ ghi dòng thực đổi giá trị, trong một transaction;
     * invalidate khối nhân sự trên trang chủ.
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
        $mapper   = $this->teamMapper();

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
     * @return list<TeamMemberModel>
     */
    private function reorderSequence(array $requested): array
    {
        $rows   = $this->teamMapper()->listAll();
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
     * @param array<array-key, mixed> $values
     */
    public function create(array $values): int
    {
        $id = $this->teamMapper()->insert($values);
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
        $this->teamMapper()->update($id, $values);
        $this->invalidateHome();
    }

    /**
     * Xoá cứng (team_members là lá — không bảng nào tham chiếu id của nó).
     *
     * @throws NotFoundException
     */
    public function delete(int $id): void
    {
        $this->findOrFail($id);
        $this->teamMapper()->delete($id);
        $this->invalidateHome();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> values cho Mapper
     */
    private function buildValues(array $data): array
    {
        return [
            'fullName'      => trim((string) $data['fullName']),
            'positionTitle' => trim((string) $data['positionTitle']),
            'avatarMediaId' => $this->nullableInt($data['avatarMediaId'] ?? null),
            'bio'           => $this->nullableTrim($data['bio'] ?? null),
            'email'         => $this->nullableTrim($data['email'] ?? null),
            'phone'         => $this->nullableTrim($data['phone'] ?? null),
            'userId'        => $this->nullableInt($data['userId'] ?? null),
            'socialLinks'   => $this->cleanSocialLinks($data['socialLinks'] ?? null),
            'showContact'   => $this->flag($data['showContact'] ?? null),
            'sortOrder'     => (int) ($data['sortOrder'] ?? 0),
            'isFeatured'    => $this->flag($data['isFeatured'] ?? null),
            'isActive'      => $this->flag($data['isActive'] ?? null),
        ];
    }

    private function cleanSocialLinks(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (! is_string($k) || ! is_string($v)) {
                continue;
            }
            $key = trim($k);
            $url = trim($v);
            if ($key === '' || $url === '') {
                continue;
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }
            $clean[$key] = $url;
        }

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function flag(mixed $raw): int
    {
        return $raw === null || $raw === '' || $raw === '0' || $raw === false
            ? TeamMemberConst::INACTIVE
            : TeamMemberConst::ACTIVE;
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

    /** FR-32: khối nhân sự trên trang chủ đọc từ `team_members` — đổi gì cũng forget 'home-v1'. */
    private function invalidateHome(): void
    {
        $this->pageCache()?->forget(CacheConst::KEY_HOME);
    }
}
