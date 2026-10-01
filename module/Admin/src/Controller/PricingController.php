<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\PricingService;
use Frontend\Model\Pricing\PricingConst;
use Frontend\Model\Service\ServiceModel;
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
            'tree'                => $this->pricingService->tree(),
            'flag'                => $this->queryFlag(),
            'reorderCsrfHash'     => $this->pricingService->reorderCsrfHash(),
            'activeCsrfHash'      => $this->pricingService->activeFormCsrfHash(),
        ]);
    }

    /**
     * @return Response
     */
    public function createAction()
    {
        return $this->redirect()->toRoute('admin/pricing');
    }

    /**
     * Alias giữ tương thích với URL cũ /admin/pricing/add.
     *
     * @return Response
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
            $context = $this->pricingService->configurationForService($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/pricing', [], ['query' => ['flag' => 'notfound']]);
        }

            $values = $context['config']?->toFormValues() ?? [
            'serviceId' => $context['service']->id,
            'price' => '',
            'unit' => '',
            'note' => '',
            'sortOrder' => $context['service']->sortOrder,
            'isActive' => PricingConst::INACTIVE,
            ];
            return $this->renderForm($id, $values, [], 'Cập nhật giá dịch vụ', $context['service'], $context['parent']);
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
        if ($id === null) {
            return $this->redirect()->toRoute('admin/pricing');
        }
        $raw['serviceId'] = (string) $id;
        $title = 'Cập nhật giá dịch vụ';
        try {
            $this->pricingService->saveForm($raw);
            return $this->redirect()->toRoute(
                'admin/pricing',
                [],
                ['query' => ['flag' => PricingConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            try {
                $context = $this->pricingService->configurationForService($id);
            } catch (NotFoundException) {
                return $this->redirect()->toRoute('admin/pricing', [], ['query' => ['flag' => 'notfound']]);
            }
            return $this->renderForm($id, $raw, $e->getErrors(), $title, $context['service'], $context['parent']);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/pricing', [], ['query' => ['flag' => 'notfound']]);
        }
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
            /** @var array<string, mixed> $result */
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
    private function renderForm(
        ?int $id,
        array $values,
        array $errors,
        string $title,
        ?ServiceModel $service = null,
        ?ServiceModel $parent = null
    ): ViewModel {
        $model = new ViewModel([
            'id'       => $id,
            'values'   => $values,
            'errors'   => $errors,
            'title'    => $title,
            'csrfHash' => $this->pricingService->saveFormCsrfHash(),
            'isEdit'   => true,
            'service'  => $service,
            'parent'   => $parent,
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
