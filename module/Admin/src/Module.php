<?php

declare(strict_types=1);

namespace Admin;

use Admin\Service\AuthGuard;
use Application\Session\SessionBootstrap;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\ModuleManager\ModuleManager;

class Module
{
    public function getConfig(): array
    {
        /** @var array $config */
        $config = include __DIR__ . '/../config/module.config.php';
        return $config;
    }

    /**
     * Gắn AuthGuard vào EVENT_DISPATCH qua SharedEventManager của identifier
     * 'Laminas\Mvc\Application'. Lưu ý: service 'EventManager' NON-SHARED
     * (ServiceManagerConfig laminas-mvc: 'EventManager' => false) nên instance của
     * ModuleManager KHÁC instance Application dùng khi dispatch; chỉ SharedEventManager
     * (shared service) mới đến được Application. Priority 100 > 1 của onDispatch.
     * Đồng thời đảm bảo mọi Laminas\Session\Container (CSRF, VANLANG_ADMIN_AUTH) dùng
     * đúng SessionManager đã cấu hình trong config/autoload/global.php.
     */
    public function init(ModuleManager $moduleManager): void
    {
        $shared = $moduleManager->getEventManager()->getSharedManager();
        if (! $shared instanceof SharedEventManagerInterface) {
            return;
        }

        $shared->attach(
            Application::class,
            MvcEvent::EVENT_DISPATCH,
            static function (MvcEvent $event) {
                $routeName = $event->getRouteMatch()?->getMatchedRouteName() ?? '';
                if (! str_starts_with($routeName, 'admin')) {
                    return null;
                }

                $services = $event->getApplication()->getServiceManager();

                // Luôn đồng nhất trước khi bất kỳ Container nào (CSRF/session auth) kịp
                // tạo SessionManager mặc định "trần" (getDefaultManager sẽ cache nó).
                // SessionBootstrap còn biến "validator fail" (UA/IP đổi) từ 500 thành
                // hủy phiên cũ + phiên mới sạch.
                SessionBootstrap::ensureDefault($services);

                $guard = $services->get(AuthGuard::class);

                return $guard->onDispatch($event);
            },
            100
        );
    }
}
