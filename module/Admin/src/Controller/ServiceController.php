<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\ServiceService;
use Frontend\Model\Service\ServiceConst;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/services (docs §3.4). PRG cho mọi form (chuẩn 07 §2) —
 * cờ báo kết quả truyền qua query `flag`. Controller mỏng: không dựng
 * InputFilter, không đụng Mapper — ServiceService chạy filter trên raw, trả
 * kết quả/flag.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class ServiceController extends AbstractActionController
{
    public function __construct(
        private readonly ServiceService $services,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'           => $this->services->listAll(),
            'flag'            => $this->queryFlag(),
            'csrfHash'        => $this->services->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->services->reorderCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm dịch vụ');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/services');
        }

        try {
            $service = $this->services->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/services',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderForm($id, $service->toFormValues(), [], 'Sửa dịch vụ');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/services');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm dịch vụ' : 'Sửa dịch vụ';

        try {
            $this->services->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/services',
                [],
                ['query' => ['flag' => $id === null ? ServiceConst::FLAG_CREATED : ServiceConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['name' => ServiceConst::ERROR_NOT_FOUND], $title);
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
            return $this->redirect()->toRoute('admin/services');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->services->deleteForm($raw);

        return $this->redirect()->toRoute('admin/services', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/services/reorder — kéo-thả đổi thứ tự (FR-29). JS gửi XHR
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
            return $this->redirect()->toRoute('admin/services');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->services->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/services',
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
            'id'       => $id,
            'values'   => $values,
            'errors'   => $errors,
            'title'    => $title,
            'csrfHash' => $this->services->saveFormCsrfHash($id),
            'mediaOptions' => $this->services->mediaOptions(),
            'parentOptions' => $this->services->parentOptions($id),
        ]);
        $model->setTemplate('admin/service/form');

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
