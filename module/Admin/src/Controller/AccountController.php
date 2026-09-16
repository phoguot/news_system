<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\User\UserConst;
use Admin\Service\AccountService;
use Admin\Service\AdminAuthService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Màn "Tài khoản của tôi" (FR-14, docs §3.11). Controller MỎNG (07 §2):
 * nhận request, lấy id từ session identity đưa vào raw, gọi AccountService,
 * render — không `new` Filter, không đụng Mapper.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class AccountController extends AbstractActionController
{
    public function __construct(
        private readonly AccountService $account,
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $identity = $this->auth->getIdentity();
        $user     = $identity === null ? null : $this->account->me($identity['id']);

        return new ViewModel([
            'user'             => $user,
            'avatar'           => $user === null ? null : $this->account->avatarMedia($user->avatarMediaId),
            'flag'             => $this->queryFlag(),
            'profileCsrfHash'  => $this->account->profileFormCsrfHash(),
            'passwordCsrfHash' => $this->account->passwordFormCsrfHash(),
            'profileErrors'    => [],
            'passwordErrors'   => [],
            'profileValues'    => [],
            'mediaOptions'     => $this->account->mediaOptions(),
        ]);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveProfileAction()
    {
        $identity = $this->auth->getIdentity();
        $request  = $this->getRequest();
        if ($identity === null || ! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/account');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        try {
            $this->account->profileForm($identity['id'], $raw);
        } catch (ValidationException $e) {
            return $this->renderForm($e->getErrors(), [], $raw);
        } catch (NotFoundException) {
            return $this->redirectWithFlag(UserConst::FLAG_NOT_FOUND);
        }

        return $this->redirectWithFlag(UserConst::FLAG_PROFILE);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function savePasswordAction()
    {
        $identity = $this->auth->getIdentity();
        $request  = $this->getRequest();
        if ($identity === null || ! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/account');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        try {
            $this->account->passwordForm($identity['id'], $raw);
        } catch (ValidationException $e) {
            return $this->renderForm([], $e->getErrors(), $raw);
        } catch (NotFoundException) {
            return $this->redirectWithFlag(UserConst::FLAG_NOT_FOUND);
        }

        return $this->redirectWithFlag(UserConst::FLAG_PASSWORD);
    }

    /**
     * PRG: quay lại /admin/account kèm ?flag= (toRoute 3 tham số — tham số
     * thứ 4 là bool $reusable, không phải options).
     */
    private function redirectWithFlag(string $flag): Response
    {
        return $this->redirect()->toRoute('admin/account', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * Render lại form với field errors (giá trị hồ sơ refill từ raw; mật khẩu
     * KHÔNG refill — input type=password luôn trống).
     *
     * @param array<string, string>   $profileErrors
     * @param array<string, string>   $passwordErrors
     * @param array<array-key, mixed> $raw
     */
    private function renderForm(array $profileErrors, array $passwordErrors, array $raw): ViewModel
    {
        $identity = $this->auth->getIdentity();
        $user     = $identity === null ? null : $this->account->me($identity['id']);

        $values = [];
        foreach (['fullName', 'email', 'username', 'phone', 'avatarMediaId'] as $field) {
            $values[$field] = isset($raw[$field]) && is_scalar($raw[$field]) ? (string) $raw[$field] : '';
        }

        return new ViewModel([
            'user'             => $user,
            'avatar'           => $user === null ? null : $this->account->avatarMedia($user->avatarMediaId),
            'flag'             => null,
            'profileCsrfHash'  => $this->account->profileFormCsrfHash(),
            'passwordCsrfHash' => $this->account->passwordFormCsrfHash(),
            'profileErrors'    => $profileErrors,
            'passwordErrors'   => $passwordErrors,
            'profileValues'    => $values,
            'mediaOptions'     => $this->account->mediaOptions(),
        ]);
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        $flagRaw = $request->getQuery('flag');

        return is_string($flagRaw) && $flagRaw !== '' ? $flagRaw : null;
    }
}
