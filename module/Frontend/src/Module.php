<?php

declare(strict_types=1);

namespace Frontend;

use Application\Session\SessionBootstrap;
use Frontend\Service\SiteVisitService;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Response;
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

        $shared->attach(
            Application::class,
            MvcEvent::EVENT_DISPATCH,
            static function (MvcEvent $event): void {
                $routeMatch = $event->getRouteMatch();
                /** @psalm-suppress MixedAssignment — route params do Laminas trả mixed */
                $controller = $routeMatch?->getParam('controller');
                if (! is_string($controller) || ! str_starts_with($controller, 'Frontend\\Controller\\')) {
                    return;
                }

                $request = $event->getRequest();
                if (! method_exists($request, 'isGet') || ! $request->isGet()) {
                    return;
                }

                /** @var string|null $userAgent */
                $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
                    ? $_SERVER['HTTP_USER_AGENT']
                    : null;
                /** @var array<string, string> $cookies */
                $cookies = $_COOKIE;

                try {
                    $tracker = $event->getApplication()->getServiceManager()->get(SiteVisitService::class);
                    $cookieHeader = $tracker->tryRecord($userAgent, $cookies);
                } catch (\Throwable $error) {
                    error_log('site visit tracking failed: ' . $error->getMessage());

                    return;
                }

                $response = $event->getResponse();
                if ($cookieHeader !== null && $response instanceof Response) {
                    $response->getHeaders()->addHeaderLine('Set-Cookie', $cookieHeader);
                }
            },
            100
        );
    }

    public function getConfig(): array
    {
        /** @var array $config */
        $config = include __DIR__ . '/../config/module.config.php';

        return $config;
    }
}
