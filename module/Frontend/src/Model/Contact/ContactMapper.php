<?php

declare(strict_types=1);

namespace Frontend\Model\Contact;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/**
 * Mapper cho bảng `contact_submissions` (docs §4.4, §5.10) — 1 mapper 1 bảng,
 * không join chéo bảng (chuẩn 07 §5).
 * IP lưu dạng nhị phân 16 byte qua INET6_ATON; mọi mốc thời gian truyền vào
 * đã là chuỗi UTC 'Y-m-d H:i:s' (docs §4.1).
 */
class ContactMapper
{
    public const TABLE_NAME = 'contact_submissions';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Đếm số lần gửi trong cửa sổ $minutes gần nhất của một IP (docs §5.10).
     */
    public function countRecentSubmissionsFromIp(string $ip, int $minutes): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['recentCount' => new Expression('COUNT(*)')])
            ->where([
                'ipAddress = INET6_ATON(:ip)',
                'createdAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $minutes . ' MINUTE)',
            ]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute(['ip' => $ip])->current();

        return is_array($row) ? (int) ($row['recentCount'] ?? 0) : 0;
    }

    /**
     * Chèn một lượt gửi form. Trả về id bản ghi vừa tạo.
     *
     * @param array{
     *     fullName: string, email: string, phone?: string|null, serviceId?: int|null,
     *     subject?: string|null, message: string, consentAtUtc: string,
     *     ipAddress?: string|null, userAgent?: string|null, sourceUrl?: string|null
     * } $data
     */
    public function insertSubmission(array $data): int
    {
        $values = [
            'fullName'  => $data['fullName'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'serviceId' => $data['serviceId'] ?? null,
            'subject'   => $data['subject'] ?? null,
            'message'   => $data['message'],
            'consentAt' => $data['consentAtUtc'],
            'status'    => ContactConst::STATUS_NEW,
            'userAgent' => $data['userAgent'] ?? null,
            'sourceUrl' => $data['sourceUrl'] ?? null,
        ];

        $ip = $data['ipAddress'] ?? null;
        if (is_string($ip) && $ip !== '') {
            $values['ipAddress'] = new Expression('INET6_ATON(:ipBinary)');
        } else {
            $values['ipAddress'] = null;
        }

        $sql    = new Sql($this->db);
        $insert = $sql->insert(self::TABLE_NAME)->values($values);

        $parameters = is_string($ip) && $ip !== '' ? ['ipBinary' => $ip] : [];
        $result     = $sql->prepareStatementForSqlObject($insert)->execute($parameters);

        return (int) $result->getGeneratedValue();
    }

    /**
     * Đếm lượt liên hệ đang tham chiếu một dịch vụ (Service admin dùng khi
     * chặn xoá — projection COUNT, không hydrate Model).
     */
    public function countByServiceId(int $serviceId): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['refCount' => new Expression('COUNT(*)')])
            ->where(['serviceId' => $serviceId]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['refCount'] ?? 0) : 0;
    }

    /**
     * Đếm hộp thư theo trạng thái (dashboard FR — docs §3.1 "Liên hệ mới chưa
     * xử lý"); projection COUNT, không hydrate Model.
     */
    public function countByStatus(int $status): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['cnt' => new Expression('COUNT(*)')])
            ->where(['status' => $status]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;
    }

    /**
     * Đếm hộp thư một trạng thái tạo trong khoảng [fromUtc, toUtc) — mốc so
     * sánh "so với tháng trước" card "Liên hệ mới" (mockup dashboard 13/09/2026).
     */
    public function countByStatusBetween(int $status, string $fromUtc, string $toUtc): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COUNT(*)')]);

        $where = new Where();
        $where->equalTo('status', $status);
        $where->greaterThanOrEqualTo('createdAt', $fromUtc);
        $where->lessThan('createdAt', $toUtc);
        $select->where($where);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Danh sách hộp thư admin (docs §3.9): lọc optional theo status/serviceId
     * và khoảng `createdAt` UTC [from, to]; mới nhất trước, giới hạn $limit dòng.
     * Bảng do Frontend sở hữu — Admin dùng lại qua container (05-cau-truc §4).
     *
     * Giá trị lọc đi qua `Where` + ValueBinder (prepared statement bind đúng
     * tham số) — chuỗi 'col = :x' thô sẽ KHÔNG được bind khi execute() không
     * nhận params → lỗi PDO runtime; chuẩn batch 4 đã chốt dùng đối tượng Where.
     *
     * @return list<ContactModel>
     */
    public function listFiltered(
        ?int $status = null,
        ?int $serviceId = null,
        ?string $createdFromUtc = null,
        ?string $createdToUtc = null,
        int $limit = 200
    ): array {
        $where = new Where();
        if ($status !== null) {
            $where->equalTo('status', $status);
        }
        if ($serviceId !== null) {
            $where->equalTo('serviceId', $serviceId);
        }
        if ($createdFromUtc !== null) {
            $where->greaterThanOrEqualTo('createdAt', $createdFromUtc);
        }
        if ($createdToUtc !== null) {
            $where->lessThanOrEqualTo('createdAt', $createdToUtc);
        }

        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['createdAt' => 'DESC', 'id' => 'DESC'])
            ->limit($limit)
            ->where($where);

        return $this->models($sql, $select);
    }

    public function findById(int $id): ?ContactModel
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->where(['id' => $id])
            ->limit(1);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? ContactModel::fromRow($row) : null;
    }

    /**
     * Cập nhật trạng thái + ghi chú admin (luồng xử lý docs §3.9).
     * `$handledAtUtc` = NOW UTC khi chuyển sang STATUS_DONE, null khi về các
     * trạng thái mở lại — Service quyết định giá trị.
     */
    public function updateHandler(int $id, int $status, ?string $adminNote, ?string $handledAtUtc): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME)
            ->set([
                'status'    => $status,
                'adminNote' => $adminNote,
                'handledAt' => $handledAtUtc,
            ])
            ->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * Xoá cứng một lượt liên hệ (admin dọn hộp thư — docs §3.9).
     */
    public function delete(int $id): void
    {
        $sql    = new Sql($this->db);
        $delete = $sql->delete(self::TABLE_NAME)->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(Sql $sql, Select $select): array
    {
        $rows   = [];
        $result = $sql->prepareStatementForSqlObject($select)->execute();
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<ContactModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = ContactModel::fromRow($row);
        }

        return $models;
    }
}
