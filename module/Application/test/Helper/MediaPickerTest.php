<?php

declare(strict_types=1);

namespace ApplicationTest\Helper;

use Application\View\Helper\MediaPicker;
use PHPUnit\Framework\TestCase;

/**
 * MediaPicker — khung preview ảnh trong form Admin (helper thuần, không
 * container/DB) → test trực tiếp chuỗi HTML trả về.
 *
 * Regression (14/09/2026): branch "đã chọn ảnh" từng render `alt="` không đóng
 * dấu nháy (chuỗi `' alt=">'`) → browser nuốt nguyên span `.media-pick-empty`
 * vào thuộc tính alt của img, attribute `hidden` dính sang img → ảnh đã lưu
 * KHÔNG hiển thị, icon camera placeholder ("ảnh mặc định") hiện đè lên; bấm ×
 * không về được trạng thái rỗng đúng. Test chốt cấu trúc thẻ img + span.
 */
final class MediaPickerTest extends TestCase
{
    /**
     * Helper dedupe `script.media-lib` theo static per-request — reset giữa
     * các test để mỗi test đứng độc lập.
     */
    protected function setUp(): void
    {
        $prop = new \ReflectionProperty(MediaPicker::class, 'emittedLibs');
        $prop->setValue(null, []);
    }

    /** @return list<array{id: int, label: string, path: string}> */
    private function mediaOptions(): array
    {
        return [
            ['id' => 3, 'label' => 'banner.jpg', 'path' => '2026/09/banner.jpg'],
            ['id' => 7, 'label' => 'logo.png', 'path' => '2026/09/logo.png'],
        ];
    }

    public function testSelectedImageRendersWellFormedImgAndHiddenEmptyState(): void
    {
        $html = (new MediaPicker())('imageMediaId', $this->mediaOptions(), '7');

        // Thẻ img đóng đủ dấu nháy và kết thúc bằng `>` ngay sau alt=""
        // (không còn nuốt span phía sau vào thuộc tính).
        $this->assertStringContainsString(
            '<img class="media-pick-preview" src="/uploads/2026/09/logo.png" alt="">',
            $html,
        );
        // Span empty là phần tử RIÊNG (bị hidden vì đang có ảnh) — không bị
        // hòa vào thẻ img.
        $this->assertStringContainsString('<span class="media-pick-empty" aria-hidden="true" hidden>', $html);
        $this->assertStringContainsString('name="imageMediaId"', $html);
        $this->assertStringContainsString('value="7">', $html);
        // × hiện khi có ảnh chọn → có button clear và không mang attribute hidden.
        $this->assertStringContainsString('<button type="button" class="media-pick-clear"', $html);
        $this->assertStringNotContainsString('aria-label="Bỏ chọn ảnh" hidden', $html);
    }

    public function testEmptyStateShowsCameraAndHidesClearButton(): void
    {
        $html = (new MediaPicker())('imageMediaId', $this->mediaOptions(), '');

        $this->assertStringContainsString('<img class="media-pick-preview" src="" alt="" hidden>', $html);
        $this->assertStringContainsString('<span class="media-pick-empty" aria-hidden="true">', $html);
        $this->assertStringContainsString(
            'class="media-pick-clear" title="Bỏ chọn ảnh" aria-label="Bỏ chọn ảnh" hidden',
            $html,
        );
    }

    public function testDeadIdKeepsImgHiddenAndShowsError(): void
    {
        $html = (new MediaPicker())('imageMediaId', $this->mediaOptions(), '99');

        $this->assertStringContainsString('value="99">', $html);
        $this->assertStringContainsString('<img class="media-pick-preview" src="" alt="" hidden>', $html);
        $this->assertStringContainsString('media-pick-error', $html);
    }

    public function testJsonLibDedupedAcrossFields(): void
    {
        $picker = new MediaPicker();
        $a      = $picker('imageMediaId', $this->mediaOptions(), '');
        $b      = $picker('mobileImageMediaId', $this->mediaOptions(), '3');

        $this->assertSame(1, substr_count($a . $b, 'class="media-lib"'));
    }
}
