<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Model\Category\CategoryMapper;
use Admin\Model\Post\PostMapper;
use Admin\Model\Post\PostModel;
use Admin\Service\DashboardService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Contact\ContactConst;
use Frontend\Model\Contact\ContactMapper;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\SiteVisitDaily\SiteVisitDailyMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard theo mockup 13/09/2026 (`Image/files/cms-doanh-nghiep.html`,
 * `#page-dashboard`): payload cho 4 thẻ thống kê + delta "so với tháng trước",
 * biểu đồ 12 tháng, bài mới nhất kèm tên chuyên mục ghép bằng truy vấn riêng
 * (07 §5 — mapper nào bảng nấy), cùng khối lượt xem tổng/7/30 ngày, biểu đồ
 * 30 ngày từ nguồn khách truy cập toàn site.
 */
final class DashboardServiceTest extends TestCase
{
    private PostMapper&MockObject $posts;
    private CategoryMapper&MockObject $categories;
    private ServiceMapper&MockObject $services;
    private ContactMapper&MockObject $contacts;
    private SiteVisitDailyMapper&MockObject $visits;
    private DashboardService $service;

    protected function setUp(): void
    {
        $this->posts      = $this->createMock(PostMapper::class);
        $this->categories = $this->createMock(CategoryMapper::class);
        $this->services   = $this->createMock(ServiceMapper::class);
        $this->contacts   = $this->createMock(ContactMapper::class);
        $this->visits     = $this->createMock(SiteVisitDailyMapper::class);

        // Mock tự trả rỗng/0 cho mọi method typed — test chỉ cài dữ liệu riêng
        $this->service = (new DashboardService())->setContainer(new TestContainer([
            PostMapper::class      => $this->posts,
            CategoryMapper::class  => $this->categories,
            ServiceMapper::class   => $this->services,
            ContactMapper::class   => $this->contacts,
            SiteVisitDailyMapper::class => $this->visits,
        ]));
    }

    public function testPayloadShapeMatchesDashboardWidgets(): void
    {
        $summary = $this->service->summary();

        self::assertSame(
            [
                'postTotal', 'categoryTotal', 'serviceTotal', 'newContacts',
                'postDelta', 'categoryDelta', 'serviceDelta', 'contactDelta',
                'monthlyPosts', 'currentMonth', 'recentPosts',
                'visitorTotal', 'visitorToday', 'visitorLast7Days', 'visitorLast30Days',
                'visitor30DayDelta', 'dailyVisitors',
            ],
            array_keys($summary)
        );
        self::assertCount(12, $summary['monthlyPosts']);
        self::assertGreaterThanOrEqual(1, $summary['currentMonth']);
        self::assertLessThanOrEqual(12, $summary['currentMonth']);
        self::assertSame([], $summary['recentPosts']);
        self::assertCount(30, $summary['dailyVisitors']);
    }

    public function testTotalsAndDeltasComeFromOwningMappers(): void
    {
        $this->posts->method('countTabs')->willReturn(['all' => 124, 'draft' => 5]);
        // Service gọi theo thứ tự: tháng này trước, tháng trước sau
        $this->posts->method('countCreatedBetween')->willReturnOnConsecutiveCalls(12, 0);
        $this->categories->method('countAll')->willReturn(8);
        $this->categories->method('countCreatedBetween')->willReturnOnConsecutiveCalls(1, 0);
        $this->services->method('countAll')->willReturn(6);
        $this->services->method('countCreatedBetween')->willReturnOnConsecutiveCalls(0, 1);
        $this->contacts->method('countByStatus')->willReturnMap([
            [ContactConst::STATUS_NEW, 18],
        ]);
        $this->contacts->method('countByStatusBetween')->willReturnOnConsecutiveCalls(5, 0);

        $summary = $this->service->summary();

        self::assertSame(124, $summary['postTotal']);
        self::assertSame(8, $summary['categoryTotal']);
        self::assertSame(6, $summary['serviceTotal']);
        self::assertSame(18, $summary['newContacts']);
        self::assertSame(12, $summary['postDelta']);
        self::assertSame(1, $summary['categoryDelta']);
        self::assertSame(-1, $summary['serviceDelta']);
        self::assertSame(5, $summary['contactDelta']);
    }

    public function testRecentPostsCarryPublishedDateAndJoinedCategoryName(): void
    {
        $published = (new PostModel())->fromRow([
            'id' => 3, 'categoryId' => 11, 'title' => 'Xu hướng công nghệ 2026',
            'publishedAt' => '2026-09-12 02:00:00', 'createdAt' => '2026-09-10 00:00:00',
        ]);
        $draft = (new PostModel())->fromRow([
            'id' => 4, 'categoryId' => 12, 'title' => 'Bài chưa hẹn giờ',
            'publishedAt' => null, 'createdAt' => '2026-09-11 00:00:00',
        ]);

        $this->posts->method('listLatestPublished')->willReturn([$published, $draft]);
        $this->categories->method('getNamesByIds')->willReturn([11 => 'Công nghệ']);

        $summary = $this->service->summary();

        self::assertSame([
            [
                'title'        => 'Xu hướng công nghệ 2026',
                'dateUtc'      => '2026-09-12 02:00:00',
                'categoryName' => 'Công nghệ',
            ],
            [
                'title'        => 'Bài chưa hẹn giờ',
                'dateUtc'      => '2026-09-11 00:00:00',
                'categoryName' => '',
            ],
        ], $summary['recentPosts']);
    }

    public function testVisitorMetricsFillMissingDates(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $first = $today->modify('-29 days')->format('Y-m-d');
        $last  = $today->format('Y-m-d');

        // Thứ tự: 30 ngày hiện tại, 30 ngày trước, hôm nay, 7 ngày.
        $this->visits->method('countBetween')->willReturnOnConsecutiveCalls(300, 240, 12, 80);
        $this->visits->method('countAll')->willReturn(1234);
        $this->visits->method('countDailyBetween')->willReturn([
            $first => 4,
            $last  => 12,
        ]);

        $summary = $this->service->summary();

        self::assertSame(1234, $summary['visitorTotal']);
        self::assertSame(12, $summary['visitorToday']);
        self::assertSame(80, $summary['visitorLast7Days']);
        self::assertSame(300, $summary['visitorLast30Days']);
        self::assertSame(60, $summary['visitor30DayDelta']);
        self::assertCount(30, $summary['dailyVisitors']);
        self::assertSame(['date' => $first, 'visitors' => 4], $summary['dailyVisitors'][0]);
        self::assertSame(['date' => $last, 'visitors' => 12], $summary['dailyVisitors'][29]);
        self::assertSame(0, $summary['dailyVisitors'][1]['visitors']);
    }
}
