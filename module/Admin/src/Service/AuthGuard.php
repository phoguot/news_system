<?php

declare(strict_types=1);

namespace Admin\Service;

use Application\Factory\AppServiceFactory;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\Router\RouteStackInterface;

/**
 * Chặn mọi route `admin*` (trang + API JSON) khi chưa đăng nhập.
 * Gắn vào EVENT_DISPATCH qua Admin\Module::init() — xem docs-dev/05-van-hanh/03.
 * Vị trí theo chuẩn 07: guard nằm trong Service/, không có thư mục Guard/ riêng.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 */
class AuthGuard extends AppServiceFactory
{
    /** Route admin ai cũng được truy cập */
    private const PUBLIC_ROUTES = ['admin/login', 'admin/logout', 'admin/forgot-password', 'admin/reset-password'];

    private function auth(): AdminAuthService
    {
        /** @var AdminAuthService */
        return $this->getContainerEntry(AdminAuthService::class);
    }

    /** Service 'Router' = TreeRouteStack (đăng ký dưới key chuỗi 'Router'). */
    private function router(): RouteStackInterface
    {
        /** @var RouteStackInterface */
        return $this->getContainerEntry('Router');
    }

    public function onDispatch(MvcEvent $event): ?Response
    {
        $routeMatch = $event->getRouteMatch();
        if (! $routeMatch instanceof RouteMatch) {
            return null;
        }

        $routeName = $routeMatch->getMatchedRouteName();
        if (! str_starts_with($routeName, 'admin') || in_array($routeName, self::PUBLIC_ROUTES, true)) {
            return null;
        }

        $identity = $this->auth()->getIdentity();
        if ($identity !== null) {
            $this->decorateAdminLayout($event, $identity);

            return null;
        }

        // API → 401 JSON theo chuẩn envelope; trang → 302 về login
        return str_starts_with($routeName, 'admin-api')
            ? $this->unauthorizedJson()
            : $this->redirectToLogin();
    }

    /**
     * @param array{id: int, email: string, fullName: string, username: string|null} $identity
     */
    private function decorateAdminLayout(MvcEvent $event, array $identity): void
    {
        $viewModel = $event->getViewModel();
        $viewModel->setTemplate('layout/admin');
        $viewModel->setVariable('adminIdentity', $identity);
    }

    private function redirectToLogin(): Response
    {
        $loginUrl = (string) $this->router()->assemble([], ['name' => 'admin/login']);
        $response = new Response();
        $response->setStatusCode(302);
        $response->getHeaders()->addHeaderLine('Location', $loginUrl);

        return $response;
    }

    private function unauthorizedJson(): Response
    {
        $body = [
            'success' => false,
            'data'    => null,
            'meta'    => null,
            'errors'  => ['auth' => 'Chưa đăng nhập hoặc phiên đã hết hạn.'],
        ];

        $response = new Response();
        $response->setStatusCode(401);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->setContent((string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response;
    }
}
