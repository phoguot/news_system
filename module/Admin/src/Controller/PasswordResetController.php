<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Service\PasswordResetService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Quên / đặt lại mật khẩu (FR-13, docs §3.11/§4.4.1/§6.2).
 * Controller mỏng: chỉ nhận raw + gọi Service, render view/PRG.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class PasswordResetController extends AbstractActionController
{
    public function __construct(private readonly PasswordResetService $service)
    {
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress MixedMethodCall
     * @psalm-suppress MixedArgument
     */
    public function forgotAction(): ViewModel
    {
        $request = $this->getRequest();
        $errors = [];
        $sent = $this->params()->fromQuery('flag') === 'forgot-sent';

        if ($request instanceof HttpRequest && $request->isPost()) {
            /** @var array<array-key, mixed> $raw */
            $raw = $request->getPost()->toArray();
            $result = $this->service->requestReset($raw);
            if ($result['ok']) {
                return $this->prg('admin/forgot-password', ['flag' => 'forgot-sent']);
            }

            $errors = $result['errors'];
        }

        $model = new ViewModel([
            'errors' => $errors,
            'sent' => $sent,
            'csrfHash' => $this->service->forgotCsrfHash(),
        ]);
        $model->setTerminal(true);

        return $model;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress MixedMethodCall
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedAssignment
     */
    public function resetAction(): ViewModel
    {
        $request = $this->getRequest();
        $errors = [];
        $token = (string) $this->params()->fromQuery('token', '');
        $done = $this->params()->fromQuery('flag') === 'reset-ok';

        if ($request instanceof HttpRequest && $request->isPost()) {
            /** @var array<array-key, mixed> $raw */
            $raw = $request->getPost()->toArray();
            if ($token !== '' && ! isset($raw['token'])) {
                $raw['token'] = $token;
            }

            $result = $this->service->resetPassword($raw);
            if ($result['ok']) {
                return $this->prg('admin/login', ['flag' => 'reset-ok']);
            }

            $errors = $result['errors'];
            $token = (string) ($raw['token'] ?? $token);
        }

        $model = new ViewModel([
            'errors' => $errors,
            'token' => $token,
            'done' => $done,
            'csrfHash' => $this->service->resetCsrfHash(),
        ]);
        $model->setTerminal(true);

        return $model;
    }

    /**
     * @param array<string, string> $query
     * @psalm-suppress MixedMethodCall
     */
    private function prg(string $route, array $query): ViewModel
    {
        $url = $this->url()->fromRoute($route, [], ['query' => $query]);

        return $this->redirectWithUrl($url);
    }

    /** @psalm-suppress MixedMethodCall */
    private function redirectWithUrl(string $url): ViewModel
    {
        $response = $this->getResponse();
        if ($response instanceof \Laminas\Http\Response) {
            $response->setStatusCode(302);
            $response->getHeaders()->addHeaderLine('Location', $url);
        }

        $model = new ViewModel();
        $model->setTerminal(true);

        return $model;
    }
}
