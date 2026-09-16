<?php

declare(strict_types=1);

namespace Frontend;

use Application\Session\SessionBootstrap;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;
use Laminas\ModuleManager\ModuleManager;

class Module
{
    /**
     * Route frontend KHÔNG đi qua AuthGuard của Admin (nơi gọi setDefaultManager),
     * nên CSRF form liên hệ sẽ bootstrap SessionManager trần (cookie PHPSESSID) nếu
     * thiếu bước này. Attach qua SharedEventManager vì EventManager của Application
     * là service KHÔNG shared (xem docs-dev/01-quy-chuan/03, mục Module::init).
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
            static function (MvcEvent $event): void {
                SessionBootstrap::ensureDefault(
                    $event->getApplication()->getServiceManager()
                );
            },
            200
        );
    }

    public function getConfig(): array
    {
        /** @var array $config */
        $config = include __DIR__ . '/../config/module.config.php';

        return $config;
    }
}
