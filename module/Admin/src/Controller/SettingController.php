<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\ValidationException;
use Admin\Service\AdminAuthService;
use Admin\Service\SettingService;
use Frontend\Model\Setting\SettingConst;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Trang /admin/settings (docs §3.12 FR-39). Controller mỏng: chỉ chuyển POST
 * thô + id người ghi (từ AdminAuthService) cho SettingService — phần validate
 * theo `valueType` nằm trong Service chạy Filter.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class SettingController extends AbstractActionController
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'groups'       => $this->settings->listGrouped(),
            'groupLabels'  => SettingConst::GROUP_LABELS,
            'values'       => [],
            'errors'       => [],
            'flag'         => $this->queryFlag(),
            'csrfHash'     => $this->settings->saveFormCsrfHash(),
            'mediaOptions' => $this->settings->mediaOptions(),
        ]);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/settings');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $identity  = $this->auth->getIdentity();
        $updatedBy = $identity === null ? null : $identity['id'];

        try {
            $this->settings->saveForm($raw, $updatedBy);

            return $this->redirect()->toRoute(
                'admin/settings',
                [],
                ['query' => ['flag' => SettingConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            $model = new ViewModel([
                'groups'       => $this->settings->listGrouped(),
                'groupLabels'  => SettingConst::GROUP_LABELS,
                'values'       => $raw,
                'errors'       => $e->getErrors(),
                'flag'         => null,
                'csrfHash'     => $this->settings->saveFormCsrfHash(),
                'mediaOptions' => $this->settings->mediaOptions(),
            ]);
            $model->setTemplate('admin/setting/index');

            return $model;
        }
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        /** @var mixed $flagRaw */
        $flagRaw = $request->getQuery('flag');

        return is_string($flagRaw) ? $flagRaw : null;
    }
}
