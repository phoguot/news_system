<?php

declare(strict_types=1);

namespace Frontend\Model\Setting;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

/**
 * Mapper cho bảng `settings` (docs §4.5, §3.12). Frontend đọc; bản ghi admin
 * của trang /admin/settings GHI QUA CÙNG class này (05-cau-truc §4 —
 * 1 bảng 1 mapper, Admin reuse qua container gộp).
 * Giá trị đơn lẻ KHÔNG đọc thẳng ở đây nữa — qua Frontend\Service\SettingService
 * (cache 60s FR-39; getValue(key) xoá 13/09/2026 vì hết caller).
 */
class SettingMapper
{
    public const TABLE_NAME = 'settings';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /**
     * Toàn bộ cài đặt theo thứ tự nhóm + sortOrder (trang /admin/settings
     * và map cache của SettingService).
     *
     * @return list<SettingModel>
     */
    public function listAll(): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->order(['groupCode' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->models($sql, $select);
    }

    /**
     * Ghi một giá trị (rỗng → NULL) kèm người ghi. `updatedAt` do DB tự đổi.
     */
    public function updateValue(int $id, ?string $value, ?int $updatedBy): void
    {
        $sql    = new Sql($this->db);
        $update = $sql->update(self::TABLE_NAME)
            ->set(['settingValue' => $value, 'updatedBy' => $updatedBy])
            ->where(['id' => $id]);

        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /**
     * settingKey của các cài đặt media đang trỏ vào một id media
     * (valueType 7 — FR-38, docs §5.14).
     *
     * @return list<string>
     */
    public function findKeysByMedia(int $mediaId): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME)
            ->columns(['settingKey'])
            ->where(['valueType' => SettingConst::VALUE_TYPE_MEDIA, 'settingValue' => (string) $mediaId]);

        $keys = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (is_array($row) && isset($row['settingKey']) && is_string($row['settingKey'])) {
                $keys[] = $row['settingKey'];
            }
        }

        return $keys;
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
     * @return list<SettingModel>
     */
    private function models(Sql $sql, Select $select): array
    {
        $models = [];
        foreach ($this->rows($sql, $select) as $row) {
            $models[] = SettingModel::fromRow($row);
        }

        return $models;
    }
}
