<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostConst;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Application\Factory\AppServiceFactory;
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
 * Widget lượt xem KHÔNG có — quyết định chốt 14/09/2026 (user): dashboard giữ
 * đúng mockup (vốn không có khối lượt xem), dù FR-41 đã có nguồn số liệu thật.
 *
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class DashboardService extends AppServiceFactory
{
    /** Số bài mới nhất hiển thị ở cột "Bài viết mới nhất" (mockup: 3 dòng). */
    private const RECENT_LIMIT = 3;

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
        ];
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
