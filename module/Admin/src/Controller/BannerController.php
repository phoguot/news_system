<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Banner\BannerConst;
use Admin\Service\BannerService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/banners (docs §3.5). PRG cho form xoá, render-form
 * cho lưu lỗi (chuẩn 07 §2). Controller mỏng: chỉ ghép id route vào raw rồi
 * gọi BannerService.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class BannerController extends AbstractActionController
{
    public function __construct(
        private readonly BannerService $banners,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'           => $this->banners->listAll(),
            'positionLabels'  => BannerConst::POSITION_LABELS,
            'flag'            => $this->queryFlag(),
            'csrfHash'        => $this->banners->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->banners->reorderCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm banner');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/banners');
        }

        try {
            $banner = $this->banners->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/banners',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderForm($id, $this->banners->formValues($banner), [], 'Sửa banner');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/banners');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm banner' : 'Sửa banner';

        try {
            $this->banners->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/banners',
                [],
                ['query' => ['flag' => $id === null ? BannerConst::FLAG_CREATED : BannerConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['position' => BannerConst::ERROR_NOT_FOUND], $title);
        }
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/banners');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->banners->deleteForm($raw);

        return $this->redirect()->toRoute('admin/banners', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/banners/reorder — kéo-thả đổi thứ tự (FR-30). JS gửi XHR
     * nhận JSON {flag, applied}; POST trực tiếp rơi về PRG.
     *
     * @return JsonModel|Response
     * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x — khuôn ApiResponseModel.
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function reorderAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/banners');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->banners->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/banners',
            [],
            ['query' => ['flag' => $result['flag'], 'applied' => $result['applied']]]
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $errors
     *
     * @return ViewModel
     */
    private function renderForm(?int $id, array $values, array $errors, string $title): ViewModel
    {
        $model = new ViewModel([
            'id'             => $id,
            'values'         => $values,
            'errors'         => $errors,
            'title'          => $title,
            'csrfHash'       => $this->banners->saveFormCsrfHash(),
            'positionLabels' => BannerConst::POSITION_LABELS,
            'mediaOptions'   => $this->banners->mediaOptions(),
        ]);
        $model->setTemplate('admin/banner/form');

        return $model;
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
