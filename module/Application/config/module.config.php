<?php

declare(strict_types=1);

namespace Application;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Psr\Container\ContainerInterface;

return [
    'router' => [
        'routes' => [
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => InvokableFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            // Nền 07 §4 (batch 7 — 13/09/2026): Db/Mail/Captcha/HtmlPurifier bỏ
            // constructor nhận config — đọc lazy qua getContainerEntry('Config'),
            // đăng ký AppInvokableFactory như mọi service khác.
            Service\DbService::class => Factory\AppInvokableFactory::class,
            Service\HtmlPurifierService::class => Factory\AppInvokableFactory::class,
            Service\MediaService::class => static fn (): Service\MediaService
                => new Service\MediaService(),
            Service\SlugService::class => static fn (): Service\SlugService
                => new Service\SlugService(),
            Service\MailService::class => Factory\AppInvokableFactory::class,
            Service\CaptchaService::class => Factory\AppInvokableFactory::class,
            // PageCacheService (FR-39, NFR-PERF-1): service nền cache — kế thừa
            // AppServiceFactory, mồi container qua AppInvokableFactory; accessor
            // storage() lấy service 'page_cache' do StorageCacheAbstractServiceFactory
            // sinh từ key 'caches' trong global.php.
            Service\PageCacheService::class => Factory\AppInvokableFactory::class,
        ],
        'aliases' => [
            'DbAdapter' => Service\DbService::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            View\Helper\MediaUrl::class => static fn (): View\Helper\MediaUrl
                => new View\Helper\MediaUrl(),
            View\Helper\SelectField::class => static fn (): View\Helper\SelectField
                => new View\Helper\SelectField(),
            View\Helper\MediaPicker::class => static fn (): View\Helper\MediaPicker
                => new View\Helper\MediaPicker(),
            View\Helper\FrontendMenu::class => static function (
                ContainerInterface $c
            ): View\Helper\FrontendMenu {
                return new View\Helper\FrontendMenu($c->get(\Frontend\Service\MenuService::class));
            },
        ],
        'aliases' => [
            'mediaUrl'    => View\Helper\MediaUrl::class,
            'selectField' => View\Helper\SelectField::class,
            'mediaPicker' => View\Helper\MediaPicker::class,
            'frontendMenu' => View\Helper\FrontendMenu::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];
