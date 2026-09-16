<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Application\Service\CaptchaService;
use Frontend\Model\Contact\ContactConst;
use Frontend\Service\ContactService;
use Frontend\Service\SettingService;
use Laminas\Http\PhpEnvironment\Request as PhpEnvironmentRequest;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Trang /lien-he (GET) + endpoint /api/contact (POST).
 * Chuẩn 07 §2 (đã cập nhật): validate (ContactSaveFilter, kèm CSRF + captcha
 * token) chạy trong ContactService::submit(); controller chỉ dựng raw array +
 * meta (ip/UA/referer), nhận `{status, id, errors}` và render lại form khi lỗi.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 */
class ContactController extends AbstractActionController
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly CaptchaService $captcha,
        private readonly SettingService $settingService,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $sent  = false;
        $request = $this->getRequest();
        if ($request instanceof HttpRequest) {
            /** @var ParametersInterface $query */
            $query = $request->getQuery();
            /** @var mixed $sentRaw */
            $sentRaw = $query->get('sent');
            $sent    = is_string($sentRaw) && $sentRaw === '1';
        }

        return $this->renderForm(null, $sent, [], []);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function submitAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('contact');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $result = $this->contactService->submit($raw, [
            'ip'        => $this->serverString('REMOTE_ADDR'),
            'userAgent' => $this->serverString('HTTP_USER_AGENT'),
            'sourceUrl' => $this->refererString(),
        ]);

        switch ($result['status']) {
            case ContactConst::SUBMIT_INVALID:
                return $this->renderForm(
                    ContactConst::ERROR_FORM_INVALID,
                    false,
                    $this->rawValues($raw),
                    $result['errors']
                );

            case ContactConst::SUBMIT_RATE_LIMITED:
                /** @var Response $response */
                $response = $this->getResponse();
                $response->setStatusCode(429);

                return $this->renderForm(
                    ContactConst::ERROR_RATE_LIMITED,
                    false,
                    $this->rawValues($raw),
                    []
                );

            case ContactConst::SUBMIT_CAPTCHA_FAIL:
                return $this->renderForm(ContactConst::ERROR_CAPTCHA, false, $this->rawValues($raw), []);

            default:
                // PRG: redirect về /lien-he?sent=1 để F5 không gửi lại
                return $this->redirect()->toRoute('contact', [], ['query' => ['sent' => '1']]);
        }
    }

    private function serverString(string $serverKey): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof PhpEnvironmentRequest) {
            return null;
        }

        /** @var mixed $value */
        $value = $request->getServer($serverKey);

        return is_string($value) ? $value : null;
    }

    private function refererString(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        /** @var mixed $referer */
        $referer = $request->getMetadata('referer');

        return is_string($referer) ? $referer : null;
    }

    /**
     * Giá trị thô người dùng vừa nhập để view điền lại (raw POST — chưa qua filter).
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    private function rawValues(array $raw): array
    {
        $values = [];
        foreach (['fullName', 'email', 'phone', 'serviceId', 'subject', 'message', 'consent'] as $field) {
            /** @var mixed $value */
            $value          = $raw[$field] ?? '';
            $values[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * Render lại trang /lien-he kèm form + thông báo (dùng chung cho index & các nhánh lỗi submit).
     *
     * @param array<string, string> $values giá trị điền lại
     * @param array<string, string> $errors lỗi theo từng trường
     */
    private function renderForm(?string $error, bool $sent, array $values, array $errors): ViewModel
    {
        $model = new ViewModel([
            'error'          => $error,
            'sent'           => $sent,
            'values'         => $values,
            'errors'         => $errors,
            'csrfHash'       => $this->contactService->csrfHash(),
            'serviceOptions' => $this->contactService->serviceOptions(),
            'captchaEnabled' => $this->captcha->isEnabled(),
            'captchaSiteKey' => $this->captcha->siteKey(),
            'settings'       => $this->settingService->all(),
            'mapUrl'         => $this->settingService->mapEmbedUrl(),
        ]);
        $model->setTemplate('frontend/contact/index');

        return $model;
    }
}
