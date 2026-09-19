<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Category\CategoryConst;
use Admin\Service\CategoryService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/categories (docs §3.2). PRG cho mọi form (chuẩn 07 §2
 * đã cập nhật) — trạng thái báo kết quả truyền qua query `flag`. Controller
 * mỏng: không dựng InputFilter, không đụng Mapper — CategoryService chạy filter
 * trên raw và trả kết quả/flag.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class CategoryController extends AbstractActionController
{
    public function __construct(
        private readonly CategoryService $categories,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        return new ViewModel([
            'items'           => $this->categories->listOrdered(),
            'flag'            => $this->queryFlag(),
            'csrfHash'        => $this->categories->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->categories->reorderCsrfHash(),
            'activeCsrfHash'  => $this->categories->activeFormCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm danh mục');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/categories');
        }

        try {
            $category = $this->categories->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/categories',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        return $this->renderForm($id, $category->toFormValues(), [], 'Sửa danh mục');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/categories');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm danh mục' : 'Sửa danh mục';

        try {
            $this->categories->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/categories',
                [],
                ['query' => ['flag' => $id === null ? CategoryConst::FLAG_CREATED : CategoryConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            $errors = $e->getErrors();

            return $this->renderForm(
                $id,
                $raw,
                $errors === [] ? ['parentId' => $e->getMessage()] : $errors,
                $title
            );
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['parentId' => CategoryConst::ERROR_NOT_FOUND], $title);
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
            return $this->redirect()->toRoute('admin/categories');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->categories->deleteForm($raw);

        return $this->redirect()->toRoute('admin/categories', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/categories/active/:id — đổi nhanh cột Hiển thị từ danh sách.
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function activeAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/categories');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        return $this->redirect()->toRoute(
            'admin/categories',
            [],
            ['query' => ['flag' => $this->categories->activeForm($raw)]]
        );
    }

    /**
     * POST /admin/categories/reorder — kéo-thả đổi thứ tự (FR-26). JS gửi XHR
     * (header X-Requested-With) nhận JSON {flag, applied}; POST trực tiếp rơi
     * về PRG. Lọc/ghi nằm ở CategoryService::formReorder (ReorderFilter + CSRF).
     *
     * @return JsonModel|Response
     * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x — khuôn ApiResponseModel.
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function reorderAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/categories');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->categories->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/categories',
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
            'id'            => $id,
            'values'        => $values,
            'errors'        => $errors,
            'title'         => $title,
            'csrfHash'      => $this->categories->saveFormCsrfHash($id),
            'parentOptions' => $this->categories->topLevelOptions(),
            'mediaOptions'  => $this->categories->mediaOptions(),
        ]);
        $model->setTemplate('admin/category/form');

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
