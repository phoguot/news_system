<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\PricingService;
use Frontend\Model\Pricing\PricingConst;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * CRUD bảng giá — Controller mỏng (07 §2): nhận raw + route id, gọi Service, render/PRG.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class PricingController extends AbstractActionController
{
    public function __construct(private readonly PricingService $pricingService)
    {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'               => $this->pricingService->listAll(),
            'flag'                => $this->queryFlag(),
            'deleteCsrfHash'      => $this->pricingService->deleteFormCsrfHash(),
            'reorderCsrfHash'     => $this->pricingService->reorderCsrfHash(),
            'activeCsrfHash'      => $this->pricingService->activeFormCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm mục bảng giá');
    }

    /**
     * Alias giữ tương thích với URL cũ /admin/pricing/add.
     *
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function addAction()
    {
        return $this->createAction();
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/pricing');
        }

        try {
            $model = $this->pricingService->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/pricing', [], ['query' => ['flag' => 'notfound']]);
        }

        return $this->renderForm($id, $model->toFormValues(), [], 'Sửa mục bảng giá');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/pricing');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();
        $id   = $this->intParam('id');
        $title = $id === null ? 'Thêm mục bảng giá' : 'Sửa mục bảng giá';

        try {
            $this->pricingService->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/pricing',
                [],
                ['query' => ['flag' => $id === null ? PricingConst::FLAG_CREATED : PricingConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['name' => PricingConst::ERROR_NOT_FOUND], $title);
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
            return $this->redirect()->toRoute('admin/pricing');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $rawArray = $post->toArray();
        // Route id inject
        $id = $this->intParam('id');
        if ($id !== null) {
            $rawArray['id'] = (string) $id;
        }
        $flag = $this->pricingService->deleteForm($rawArray);

        return $this->redirect()->toRoute('admin/pricing', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/pricing/active/:id — đổi nhanh cột Hiển thị từ danh sách.
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function activeAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/pricing');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $rawArray = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $rawArray['id'] = (string) $id;
        }

        return $this->redirect()->toRoute(
            'admin/pricing',
            [],
            ['query' => ['flag' => $this->pricingService->activeForm($rawArray)]]
        );
    }

    /**
     * POST /admin/pricing/reorder — kéo-thả đổi thứ tự bảng giá.
     * JS gửi XHR và cần JSON {flag, applied}; POST thường vẫn PRG.
     *
     * @return JsonModel|Response
     * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x — khuôn ApiResponseModel.
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function reorderAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/pricing');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $result = $this->pricingService->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/pricing',
            [],
            ['query' => ['flag' => $result['flag'], 'applied' => $result['applied']]]
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string>   $errors
     */
    private function renderForm(?int $id, array $values, array $errors, string $title): ViewModel
    {
        $model = new ViewModel([
            'id'       => $id,
            'values'   => $values,
            'errors'   => $errors,
            'title'    => $title,
            'csrfHash' => $this->pricingService->saveFormCsrfHash($id),
            'isEdit'   => $id !== null,
        ]);
        $model->setTemplate('admin/pricing/form');

        return $model;
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

    /**
     * id từ route param; segment không phải số → null.
     */
    private function intParam(string $name): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute($name);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
