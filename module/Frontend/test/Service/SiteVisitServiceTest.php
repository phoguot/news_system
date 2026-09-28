<?php

declare(strict_types=1);

namespace FrontendTest\Service;

use ApplicationTest\Helper\TestContainer;
use Frontend\Model\SiteVisitDaily\SiteVisitDailyMapper;
use Frontend\Service\SiteVisitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SiteVisitServiceTest extends TestCase
{
    private SiteVisitDailyMapper&MockObject $mapper;
    private SiteVisitService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SiteVisitDailyMapper::class);
        $this->service = (new SiteVisitService())->setContainer(new TestContainer([
            SiteVisitDailyMapper::class => $this->mapper,
        ]));
    }

    public function testNewBrowserIsCountedAndReceivesDailyCookie(): void
    {
        $this->mapper->expects(self::once())->method('incrementToday');

        $header = $this->service->tryRecord('Mozilla/5.0', []);

        self::assertNotNull($header);
        self::assertStringContainsString('site_visitor_day=' . gmdate('Y-m-d'), $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('HttpOnly', $header);
    }

    public function testBrowserAlreadyCountedTodayIsIgnored(): void
    {
        $this->mapper->expects(self::never())->method('incrementToday');

        self::assertNull($this->service->tryRecord('Mozilla/5.0', [
            'site_visitor_day' => gmdate('Y-m-d'),
        ]));
    }

    public function testBotIsIgnored(): void
    {
        $this->mapper->expects(self::never())->method('incrementToday');

        self::assertNull($this->service->tryRecord('Googlebot/2.1', []));
        self::assertNull($this->service->tryRecord(null, []));
    }
}
