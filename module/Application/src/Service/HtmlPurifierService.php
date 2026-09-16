<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Factory\AppServiceFactory;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Lọc XSS nội dung rich text TRƯỚC KHI LƯU (docs §3.3.4(6), §7.3; FR-16).
 * Cho phép iframe YouTube/Vimeo qua HTML.SafeIframe + whitelist regex src.
 * Cache definition ghi vào data/cache/htmlpurifier để không parse lại mỗi request.
 *
 * DI nền 07 §4 (batch 7 — 13/09/2026): bỏ constructor (closure cũ chưa từng truyền
 * cacheDir khác mặc định) — không dependency, đăng ký bằng `AppInvokableFactory`.
 */
class HtmlPurifierService extends AppServiceFactory
{
    private ?HTMLPurifier $engine = null;

    private const DEFAULT_CACHE_DIR = __DIR__ . '/../../../../data/cache/htmlpurifier';

    public function purify(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $this->ensureCacheDir());
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.EnableID', true);
        $config->set('HTML.SafeIframe', true);
        $safeIframe = '%^https://(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%';
        $config->set('URI.SafeIframeRegexp', $safeIframe);
        $config->set('CSS.AllowedProperties', [
            'text-align', 'font-weight', 'font-style', 'color', 'background-color',
        ]);

        $this->engine = new HTMLPurifier($config);

        return $this->engine;
    }

    private function ensureCacheDir(): string
    {
        $path = str_replace('\\', '/', self::DEFAULT_CACHE_DIR);
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
