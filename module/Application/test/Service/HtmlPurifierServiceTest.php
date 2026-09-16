<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Service\HtmlPurifierService;
use PHPUnit\Framework\TestCase;

/**
 * Kiểm tra định hướng bảo mật (docs §7.3): script/sự kiện bị loại,
 * iframe chỉ YouTube/Vimeo embed được giữ (docs §3.3.4(6)).
 */
final class HtmlPurifierServiceTest extends TestCase
{
    private HtmlPurifierService $service;

    protected function setUp(): void
    {
        $this->service = new HtmlPurifierService();
    }

    public function testKeepsSafeRichText(): void
    {
        $out = $this->service->purify('<p><strong>Đoạn mở đầu</strong></p>');

        self::assertStringContainsString('<strong>Đoạn mở đầu</strong>', $out);
    }

    public function testStripsScriptAndEventHandlers(): void
    {
        $out = $this->service->purify(
            '<p onclick="alert(1)">nội dung</p><script>steal()</script>'
        );

        self::assertStringNotContainsString('script', strtolower($out));
        self::assertStringNotContainsString('onclick', strtolower($out));
        self::assertStringContainsString('nội dung', $out);
    }

    public function testAllowsYoutubeEmbedIframe(): void
    {
        $out = $this->service->purify(
            '<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe>'
        );

        self::assertStringContainsString('youtube.com/embed/abc123', $out);
    }

    public function testRejectsUnsafeIframeSource(): void
    {
        $out = $this->service->purify('<iframe src="https://evil.example/embed/x"></iframe>');

        self::assertStringNotContainsString('evil.example', $out);
    }
}
