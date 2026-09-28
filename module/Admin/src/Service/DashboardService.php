<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostConst;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Model\PostViewDaily\PostViewDailyMapper;
use Application\Factory\AppServiceFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceMapper;

/**
 * Số liệu trang Tổng quan admin theo ĐÚNG mockup `Image/files/cms-doanh-nghiep.html`
 * (dashboard demo 13/09/2026): 4 thẻ thống kê (bài / danh mục / dịch vụ / liên hệ
 * mới + delta so với tháng trước), biểu đồ "Bài viết theo tháng" 12 tháng năm
 * hiện tại, danh sách "Bài viết mới nhất" kèm tên chuyên mục.
 *
 * Mỗi bảng đọc qua ĐÚNG mapper chủ bảng (07 §5 — không JOIN chéo); tên chuyên
 * mục của bài ghép bằng truy vấn riêng `CategoryMapper::getNamesByIds`.
 * Khối lượt xem bổ sung 28/09/2026: tổng, hôm nay, 7/30 ngày, biểu đồ 30 ngày
 * và top 5 bài. Ngày thống kê giữ UTC đúng schema/docs §5.8.
 *
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class DashboardService extends AppServiceFactory
{
    /** Số bài mới nhất hiển thị ở cột "Bài viết mới nhất" (mockup: 3 dòng). */
    private const RECENT_LIMIT = 3;
    private const TOP_VIEWED_LIMIT = 5;

    private function postMapper(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function categoryMapper(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function serviceMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function contactMapper(): ContactMapper
    {
        /** @var ContactMapper */
        return $this->getContainerEntry(ContactMapper::class);
    }

    private function postViewDailyMapper(): PostViewDailyMapper
    {
        /** @var PostViewDailyMapper */
        return $this->getContainerEntry(PostViewDailyMapper::class);
    }

    /**
     * Toàn bộ widget trang dashboard (mockup 13/09/2026).
     *
     * @return array{
     *     postTotal: int,
     *     categoryTotal: int,
     *     serviceTotal: int,
     *     newContacts: int,
     *     postDelta: int,
     *     categoryDelta: int,
     *     serviceDelta: int,
     *     contactDelta: int,
     *     monthlyPosts: list<int>,
     *     currentMonth: int,
     *     recentPosts: list<array{title: string, dateUtc: string, categoryName: string}>,
     *     viewTotal: int,
     *     viewToday: int,
     *     viewLast7Days: int,
     *     viewLast30Days: int,
     *     view30DayDelta: int,
     *     dailyViews: list<array{date: string, views: int}>,
     *     topViewedPosts: list<array{id: int, title: string, viewCount: int}>,
     * }
     */
    public function summary(): array
    {
        $vn  = new DateTimeZone('Asia/Ho_Chi_Minh');
        $now = new DateTimeImmutable('now', $vn);

        $monthStart = $now->modify('first day of this month 00:00:00');
        $prevStart  = $monthStart->modify('-1 month 00:00:00');
        $utc        = new DateTimeZone('UTC');

        $curFrom = $monthStart->setTimezone($utc)->format('Y-m-d H:i:s');
        $curTo   = $monthStart->modify('+1 month 00:00:00')->setTimezone($utc)->format('Y-m-d H:i:s');
        $prevFrom = $prevStart->setTimezone($utc)->format('Y-m-d H:i:s');

        $posts     = $this->postMapper();
        $tabs      = $posts->countTabs();
        $year      = (int) $now->format('Y');
        $recent    = $posts->listLatestPublished(self::RECENT_LIMIT);
        $catNames  = $this->categoryNames($recent);
        $views     = $this->postViewDailyMapper();

        $today        = new DateTimeImmutable('today', $utc);
        $tomorrow     = $today->modify('+1 day');
        $sevenStart   = $today->modify('-6 days');
        $thirtyStart  = $today->modify('-29 days');
        $previousFrom = $today->modify('-59 days');

        $todayDate       = $today->format('Y-m-d');
        $tomorrowDate    = $tomorrow->format('Y-m-d');
        $sevenStartDate  = $sevenStart->format('Y-m-d');
        $thirtyStartDate = $thirtyStart->format('Y-m-d');

        $viewLast30Days = $views->countBetween($thirtyStartDate, $tomorrowDate);
        $viewPrevious30 = $views->countBetween($previousFrom->format('Y-m-d'), $thirtyStartDate);

        return [
            'postTotal'       => $tabs[PostConst::TAB_ALL] ?? 0,
            'categoryTotal'   => $this->categoryMapper()->countAll(),
            'serviceTotal'    => $this->serviceMapper()->countAll(),
            'newContacts'     => $this->contactMapper()->countByStatus(ContactConst::STATUS_NEW),
            'postDelta'       => $posts->countCreatedBetween($curFrom, $curTo)
                - $posts->countCreatedBetween($prevFrom, $curFrom),
            'categoryDelta'   => $this->categoryMapper()->countCreatedBetween($curFrom, $curTo)
                - $this->categoryMapper()->countCreatedBetween($prevFrom, $curFrom),
            'serviceDelta'    => $this->serviceMapper()->countCreatedBetween($curFrom, $curTo)
                - $this->serviceMapper()->countCreatedBetween($prevFrom, $curFrom),
            'contactDelta'    => $this->contactMapper()
                ->countByStatusBetween(ContactConst::STATUS_NEW, $curFrom, $curTo)
                - $this->contactMapper()
                    ->countByStatusBetween(ContactConst::STATUS_NEW, $prevFrom, $curFrom),
            'monthlyPosts'    => array_slice(array_pad($posts->countPublishedByMonth($year), 12, 0), 0, 12),
            'currentMonth'    => (int) $now->format('n'),
            'recentPosts'     => array_map(
                static function (PostModel $p) use ($catNames): array {
                    return [
                        'title'        => $p->title,
                        'dateUtc'      => $p->publishedAt !== null && $p->publishedAt !== ''
                            ? $p->publishedAt
                            : $p->createdAt,
                        'categoryName' => $catNames[$p->categoryId] ?? '',
                    ];
                },
                $recent
            ),
            'viewTotal'       => $views->countAll(),
            'viewToday'       => $views->countBetween($todayDate, $tomorrowDate),
            'viewLast7Days'   => $views->countBetween($sevenStartDate, $tomorrowDate),
            'viewLast30Days'  => $viewLast30Days,
            'view30DayDelta'  => $viewLast30Days - $viewPrevious30,
            'dailyViews'      => $this->dailyViews(
                $thirtyStart,
                $views->countDailyBetween($thirtyStartDate, $tomorrowDate)
            ),
            'topViewedPosts'  => $posts->listTopViewed(self::TOP_VIEWED_LIMIT),
        ];
    }

    /**
     * Điền đủ 30 ngày cho biểu đồ, kể cả ngày không có lượt xem.
     *
     * @param array<string, int> $counts
     *
     * @return list<array{date: string, views: int}>
     */
    private function dailyViews(DateTimeImmutable $from, array $counts): array
    {
        $rows   = [];
        $cursor = $from;
        for ($i = 0; $i < 30; $i++) {
            $date   = $cursor->format('Y-m-d');
            $rows[] = ['date' => $date, 'views' => $counts[$date] ?? 0];
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $rows;
    }

    /**
     * id danh mục => tên cho tập bài mới nhất (1 truy vấn names, không JOIN —
     * 07 §5).
     *
     * @param list<PostModel> $posts
     *
     * @return array<int, string>
     */
    private function categoryNames(array $posts): array
    {
        $ids = [];
        foreach ($posts as $p) {
            if ($p->categoryId > 0) {
                $ids[] = $p->categoryId;
            }
        }

        return $this->categoryMapper()->getNamesByIds(array_values(array_unique($ids)));
    }
}
