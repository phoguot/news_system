<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Factory\AppServiceFactory;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\AdapterInterface;

/**
 * Cổng DB trung tâm: mọi Table phải lấy kết nối qua service này.
 * Xem docs/docs-dev/01-quy-chuan/03-quy-chuan-code.md §2.
 *
 * DI nền 07 §4 (batch 7 — 13/09/2026): không constructor — `Config` đọc lazy
 * qua `getContainerEntry('Config')` khi `getAdapter()` cần, đăng ký bằng
 * `AppInvokableFactory`. Hành vi bằng closure cũ (đọc key `db` của config merge).
 */
class DbService extends AppServiceFactory
{
    private ?AdapterInterface $adapter = null;

    public function getAdapter(): AdapterInterface
    {
        if ($this->adapter !== null) {
            return $this->adapter;
        }

        $this->adapter = new Adapter($this->dbConfig());

        return $this->adapter;
    }

    /**
     * Chạy một callback trong ĐÚNG một transaction (docs §4.6 — xoá cứng nhiều
     * bảng không FK phải rollback cùng nhau). Lỗi bất kỳ → rollback + rethrow.
     *
     * @template T
     * @param callable(): T $fn
     *
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        // beginTransaction/commit/rollback thuộc ConnectionInterface — có trên CẢ HAI
        // driver Pdo và Mysqli. (Bản cũ gọi transientBegin/Commit/Rollback: chỉ có ở
        // driver Mysqli → driver Pdo — cấu hình local.php phổ biến — fatal 500.)
        $connection = $this->getAdapter()->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $result = $fn();
            $connection->commit();
        } catch (\Throwable $e) {
            try {
                $connection->rollback();
            } catch (\Throwable) {
                // rollback thất bại (kết nối đã đứt) — ưu tiên ném lỗi nghiệp vụ gốc
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Key `db` trong config/autoload/global.php + local.php (đã ép về array
     * để `new Adapter()` nhận đúng shape).
     *
     * @return array<string, mixed>
     */
    private function dbConfig(): array
    {
        /** @var array<array-key, mixed>|\Laminas\Config\Config $config */
        $config = $this->getContainer()->get('Config');
        $all = is_array($config) ? $config : $config->toArray();
        /** @var array<string, mixed> $db */
        $db = is_array($all['db'] ?? null) ? $all['db'] : [];

        return $db;
    }
}
