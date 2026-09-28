<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Frontend\Service\MenuService;
use Throwable;

/**
 * Render menu công khai trong layout FE từ dữ liệu quản trị.
 */
final class FrontendMenu
{
    public function __construct(private readonly MenuService $menus)
    {
    }

    public function __invoke(string $class = 'nav'): string
    {
        try {
            $items = $this->menus->publicItems();
        } catch (Throwable) {
            $items = [];
        }
        if ($items === []) {
            $items = $this->fallback();
        }

        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $html = '<nav class="' . $this->e($class) . '">';
        foreach ($items as $item) {
            $label = $item['label'];
            $url = $item['url'];
            $target = $item['target'];
            $blank = $target === '_blank';

            $isActive = false;
            if ($url === '/') {
                $isActive = ($currentPath === '/');
            } else {
                $isActive = str_starts_with($currentPath, $url);
            }

            $classes = [];
            if ($isActive) {
                $classes[] = 'active';
            }

            $classAttr = $classes !== [] ? ' class="' . implode(' ', $classes) . '"' : '';

            $html .= '<a href="' . $this->e($url) . '"'
                . $classAttr
                . ($blank ? ' target="_blank" rel="noopener"' : '')
                . '>' . $this->e($label) . '</a>';
        }
        $html .= '</nav>';

        return $html;
    }

    /** @return list<array{label: string, url: string, target: string}> */
    private function fallback(): array
    {
        return [
            ['label' => 'Trang chủ', 'url' => '/', 'target' => '_self'],
            ['label' => 'Giới thiệu', 'url' => '/gioi-thieu', 'target' => '_self'],
            ['label' => 'Dịch vụ', 'url' => '/dich-vu', 'target' => '_self'],
            ['label' => 'Bảng giá', 'url' => '/bang-gia', 'target' => '_self'],
            ['label' => 'Tin tức', 'url' => '/tin-tuc', 'target' => '_self'],
            ['label' => 'Đội ngũ', 'url' => '/doi-ngu', 'target' => '_self'],
            ['label' => 'Liên hệ', 'url' => '/lien-he', 'target' => '_self'],
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
