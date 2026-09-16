<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Service\AdminAuthService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Đăng nhập / đăng xuất admin. Guard chặn các route còn lại — xem Admin\Service\AuthGuard.
 * Chuẩn 07 §2 (đã cập nhật): controller không dựng Filter — LoginFilter (kèm CSRF)
 * chạy trong AdminAuthService::attemptLogin(); view render HTML trực tiếp — 07 §6.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 */
class AuthController extends AbstractActionController
{
    public function __construct(
        private readonly AdminAuthService $authService,
    ) {
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function loginAction()
    {
        if ($this->authService->hasIdentity()) {
            return $this->redirect()->toRoute('admin/dashboard');
        }

        $error       = null;
        $identityRaw = '';
        $request     = $this->getRequest();

        if ($request instanceof HttpRequest && $request->isPost()) {
            /** @var ParametersInterface $post */
            $post   = $request->getPost();
            $result = $this->authService->attemptLogin($post->toArray());

            if ($result->isValid()) {
                return $this->redirect()->toRoute('admin/dashboard');
            }

            /** @var array<int, string> $messages */
            $messages = $result->getMessages();
            $error    = $messages === [] ? 'Đăng nhập thất bại.' : implode(' ', $messages);

            /** @var mixed $identity */
            $identity = $post->get('identity');
            $identityRaw = is_string($identity) ? $identity : '';
        }

        $model = new ViewModel([
            'error'    => $error,
            'csrfHash' => $this->authService->loginCsrfHash(),
            'identity' => $identityRaw,
        ]);
        $model->setTerminal(true);

        return $model;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function logoutAction(): Response
    {
        $this->authService->logout();

        return $this->redirect()->toRoute('admin/login');
    }
}
