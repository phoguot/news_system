<?php

declare(strict_types=1);

namespace Application\Filter;

use HTMLPurifier;
use HTMLPurifier_Config;
use Laminas\Filter\AbstractFilter;

/**
 * Filter bọc HTMLPurifier cho InputFilter chain (dùng chung toàn dự án).
 * Gộp từ Core\Filter\HtmlPurifierFilter — namespace Application.
 * Service layer vẫn có Application\Service\HtmlPurifierService (purify trước lưu) làm nguồn
 * chính cho posts.content/services.content; filter này chỉ dùng khi cần purify per-field.
 *
 * @extends AbstractFilter<array<array-key, mixed>>
 * @psalm-suppress DeprecatedClass
 */
final class HtmlPurifierFilter extends AbstractFilter
{
    private ?HTMLPurifier $purifier = null;

    private const DEFAULT_CACHE_DIR = __DIR__ . '/../../../data/cache/htmlpurifier';

    public function __construct(
        private readonly string $cacheDir = self::DEFAULT_CACHE_DIR,
    ) {
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function filter($value)
    {
        if (! $value) {
            return $value;
        }

        $value = $this->getPurifier()->purify((string) $value);
        if ($value) {
            /** @psalm-suppress RedundantCast */
            $value = str_replace('&amp;', '&', (string) $value);
        }

        return $value;
    }

    private function getPurifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.SerializerPath', $this->ensureCacheDir());
        $config->set('URI.AllowedSchemes', [
            'data'   => true,
            'src'    => true,
            'http'   => true,
            'https'  => true,
            'mailto' => true,
            'tel'    => true,
        ]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.AllowedRel', ['nofollow', 'follow']);
        $config->set('Attr.EnableID', true);
        $config->set('Filter.YouTube', true);
        $config->set('HTML.SafeIframe', true);
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowTricky', true);
        $iframeRegexp = '%^(https?:)?//'
            . '(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/'
            . '|docs\.google\.com/|www\.google\.com/|www\.facebook\.com/|spinzam\.com/'
            . '|www\.slideshare\.net/|streamable\.com/|drive\.google\.com/'
            . '|spins0\.arqspin\.com/|cdn\.flipsnack\.com/)%';
        $config->set('URI.SafeIframeRegexp', $iframeRegexp);
        $config->set('HTML.DefinitionID', 'html5-definitions');
        $config->set('HTML.DefinitionRev', 1);
        $def = $config->maybeGetRawHTMLDefinition();
        if ($def) {
            $def->addElement('figcaption', 'Block', 'Flow', 'Common');
            $def->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            $def->addAttribute('blockquote', 'data-video-id', 'Text');
            $def->addAttribute('blockquote', 'data-embed-from', 'Text');
        }

        $this->purifier = new HTMLPurifier($config);

        return $this->purifier;
    }

    private function ensureCacheDir(): string
    {
        $path = str_replace('\\', '/', $this->cacheDir);
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
