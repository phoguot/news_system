<?php

declare(strict_types=1);

namespace Frontend\Model\SiteVisitDaily;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;

/** Mapper sở hữu bảng site_visit_daily — khách duy nhất theo ngày UTC. */
class SiteVisitDailyMapper
{
    public const TABLE_NAME = 'site_visit_daily';

    public function __construct(private readonly AdapterInterface $db)
    {
    }

    /** Tăng một khách duy nhất cho ngày UTC hiện tại. */
    public function incrementToday(): void
    {
        $table = $this->db->getPlatform()->quoteIdentifier(self::TABLE_NAME);
        $sql   = 'INSERT INTO ' . $table . ' (visitDate, visitors) VALUES (UTC_DATE(), 1)'
            . ' ON DUPLICATE KEY UPDATE visitors = ' . $table . '.visitors + 1';
        $statement = $this->db->getDriver()->createStatement($sql);
        $statement->prepare();
        $statement->execute();
    }

    /** Tổng lượt khách-ngày đã ghi nhận từ trước đến nay. */
    public function countAll(): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COALESCE(SUM(visitors), 0)')]);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /** Tổng khách trong khoảng ngày UTC [fromDate, toDate). */
    public function countBetween(string $fromDate, string $toDate): int
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['total' => new Expression('COALESCE(SUM(visitors), 0)')]);

        $where = new Where();
        $where->greaterThanOrEqualTo('visitDate', $fromDate);
        $where->lessThan('visitDate', $toDate);
        $select->where($where);

        /** @var array<array-key, mixed>|bool|null $row */
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * @return array<string, int> map Y-m-d => visitors
     */
    public function countDailyBetween(string $fromDate, string $toDate): array
    {
        $sql    = new Sql($this->db);
        $select = $sql->select(self::TABLE_NAME);
        $select->columns(['visitDate', 'visitors']);

        $where = new Where();
        $where->greaterThanOrEqualTo('visitDate', $fromDate);
        $where->lessThan('visitDate', $toDate);
        $select->where($where);
        $select->order(['visitDate' => 'ASC']);

        $counts = [];
        /** @psalm-suppress MixedAssignment — dòng trả về từ driver luôn là mixed */
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = (string) ($row['visitDate'] ?? '');
            if ($date !== '') {
                $counts[$date] = (int) ($row['visitors'] ?? 0);
            }
        }

        return $counts;
    }
}
