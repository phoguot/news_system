<?php

declare(strict_types=1);

namespace ApplicationTest\Helper;

use Application\View\Helper\SelectField;
use PHPUnit\Framework\TestCase;

/**
 * SelectField — nền select box thay ô nhập ID trong form Admin (chuẩn 13/09/2026).
 * Helper thuần (không container/DB) → test trực tiếp chuỗi HTML trả về.
 */
final class SelectFieldTest extends TestCase
{
    /** @return list<array{id: int, label: string}> */
    private function mediaOptions(): array
    {
        return [
            ['id' => 3, 'label' => 'banner.jpg'],
            ['id' => 7, 'label' => 'logo.png'],
        ];
    }

    public function testRendersPlaceholderAndSelectedOption(): void
    {
        $html = (new SelectField())('imageMediaId', $this->mediaOptions(), '7', true);

        $this->assertStringContainsString('<select class="form-select" id="f-imageMediaId"', $html);
        $this->assertStringContainsString('name="imageMediaId"', $html);
        $this->assertStringContainsString(' required', $html);
        $this->assertStringContainsString('<option value="">— Chọn trong danh sách —</option>', $html);
        $this->assertStringContainsString('<option value="7" selected>#7 · logo.png</option>', $html);
        // 14/09: field ảnh media chuyển sang MediaPicker — SelectField thuần
        // select, không còn preview img lẫn nút popup.
        $this->assertStringNotContainsString('media-pick', $html);
        $this->assertStringNotContainsString('data-src', $html);
    }

    public function testPickButtonOnlyForMediaOptions(): void
    {
        $html = (new SelectField())('itemId', [['id' => 12, 'label' => 'Bài A']], '12');

        $this->assertStringNotContainsString('media-pick-btn', $html);
    }

    public function testFallbackOptionWhenCurrentNotInList(): void
    {
        $html = (new SelectField())('itemId', [['id' => 12, 'label' => 'Bài A']], '99');

        $this->assertStringContainsString('#12 · Bài A', $html);
        $this->assertStringContainsString('value="99" selected>#99 · (không còn trong danh sách)', $html);
        $this->assertStringNotContainsString('media-pick-preview', $html);
        $this->assertStringNotContainsString(' required', $html);
    }

    public function testNonSelectedOptionWithoutCurrentHasNoPreview(): void
    {
        $html = (new SelectField())('thumbnailMediaId', $this->mediaOptions(), '');

        $this->assertStringNotContainsString('selected', $html);
        $this->assertStringNotContainsString('media-pick-preview', $html);
    }

    public function testEscapesLabelAndPlaceholder(): void
    {
        $html = (new SelectField())(
            'coverMediaId',
            [['id' => 1, 'label' => '<script>x</script>']],
            '',
            false,
            '"><b onmouseover=h>',
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"><b', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;b', $html);
    }
}
