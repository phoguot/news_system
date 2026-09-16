<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Media\MediaConst;
use Admin\Model\Media\MediaModel;
use Admin\Service\AdminAuthService;
use Admin\Service\MediaService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/media (docs §3.10; FR-36/37/38). PRG cho thao tác
 * tải lên/xoá, render-form khi lỗi validate. Controller mỏng: chỉ ghép id route
 * vào raw rồi gọi MediaService (chuẩn 07 §2).
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class MediaController extends AbstractActionController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'          => $this->media->listAll(),
            'flag'           => $this->queryFlag(),
            'uploadErrors'   => [],
            'uploadedCount'  => 0,
            'uploadCsrfHash' => $this->media->uploadFormCsrfHash(),
            'csrfHash'       => $this->media->deleteFormCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function uploadAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/media');
        }

        /** @var ParametersInterface $post */
        $post     = $request->getPost();
        $raw      = $post->toArray();
        $identity = $this->auth->getIdentity();
        /** @var array<array-key, mixed> $files */
        $files = $request->getFiles();

        try {
            $result = $this->media->uploadForm($raw, $files, $identity['id'] ?? null);
        } catch (ValidationException $e) {
            return $this->renderIndexWithErrors(array_values($e->getErrors()));
        }

        if ($result['errors'] !== []) {
            return $this->renderIndexWithErrors($result['errors'], $result['uploaded']);
        }

        return $this->redirect()->toRoute(
            'admin/media',
            [],
            ['query' => ['flag' => MediaConst::FLAG_UPLOADED]]
        );
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/media');
        }

        try {
            $model = $this->media->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/media',
                [],
                ['query' => ['flag' => MediaConst::FLAG_NOT_FOUND]]
            );
        }

        return $this->renderEdit($model, $model->toFormValues(), []);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAltAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/media');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        try {
            $this->media->saveAltForm($raw);
        } catch (ValidationException $e) {
            if ($id === null) {
                return $this->redirect()->toRoute('admin/media');
            }

            try {
                $model = $this->media->findOrFail($id);
            } catch (NotFoundException) {
                return $this->redirect()->toRoute(
                    'admin/media',
                    [],
                    ['query' => ['flag' => MediaConst::FLAG_NOT_FOUND]]
                );
            }

            $values            = $model->toFormValues();
            /** @var mixed $rawAlt */
            $rawAlt            = $raw['altText'] ?? '';
            $values['altText'] = is_string($rawAlt) ? $rawAlt : '';

            return $this->renderEdit($model, $values, $e->getErrors());
        }

        return $this->redirect()->toRoute(
            'admin/media',
            [],
            ['query' => ['flag' => MediaConst::FLAG_UPDATED]]
        );
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/media');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->media->deleteForm($raw);

        return $this->redirect()->toRoute('admin/media', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function usagesAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/media');
        }

        try {
            $model  = $this->media->findOrFail($id);
            $usages = $this->media->usages($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/media',
                [],
                ['query' => ['flag' => MediaConst::FLAG_NOT_FOUND]]
            );
        }

        return new ViewModel([
            'model'  => $model,
            'usages' => $usages,
        ]);
    }

    /**
     * @param list<string> $errors
     *
     * @return ViewModel
     */
    private function renderIndexWithErrors(array $errors, int $uploadedCount = 0): ViewModel
    {
        return new ViewModel([
            'items'          => $this->media->listAll(),
            'flag'           => null,
            'uploadErrors'   => $errors,
            'uploadedCount'  => $uploadedCount,
            'uploadCsrfHash' => $this->media->uploadFormCsrfHash(),
            'csrfHash'       => $this->media->deleteFormCsrfHash(),
        ]);
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $errors
     *
     * @return ViewModel
     */
    private function renderEdit(MediaModel $model, array $values, array $errors): ViewModel
    {
        $view = new ViewModel([
            'model'    => $model,
            'values'   => $values,
            'errors'   => $errors,
            'csrfHash' => $this->media->altFormCsrfHash(),
        ]);
        $view->setTemplate('admin/media/edit');

        return $view;
    }

    private function queryFlag(): ?string
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            return null;
        }

        $flagRaw = $request->getQuery('flag');

        return is_string($flagRaw) ? $flagRaw : null;
    }

    private function intParam(string $name): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute($name);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
