<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Service\MenuService;
use Frontend\Model\Menu\MenuConst;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * CRUD menu công khai — controller mỏng.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động.
 */
class MenuController extends AbstractActionController
{
    public function __construct(private readonly MenuService $menus)
    {
    }

    /** @return ViewModel */
    public function indexAction()
    {
        return new ViewModel([
            'items' => $this->menus->listAll(),
            'flag' => $this->queryFlag(),
            'deleteCsrfHash' => $this->menus->deleteFormCsrfHash(),
            'reorderCsrfHash' => $this->menus->reorderCsrfHash(),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm menu');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/menus');
        }

        try {
            $model = $this->menus->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/menus', [], ['query' => ['flag' => 'notfound']]);
        }

        return $this->renderForm($id, $model->toFormValues(), [], 'Sửa menu');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/menus');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw = $post->toArray();
        $id = $this->intParam('id');
        $title = $id === null ? 'Thêm menu' : 'Sửa menu';

        try {
            $this->menus->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/menus',
                [],
                ['query' => ['flag' => $id === null ? MenuConst::FLAG_CREATED : MenuConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['label' => MenuConst::ERROR_NOT_FOUND], $title);
        }
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/menus');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw = $post->toArray();
        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        return $this->redirect()->toRoute(
            'admin/menus',
            [],
            ['query' => ['flag' => $this->menus->deleteForm($raw)]]
        );
    }

    /**
     * @return JsonModel|Response
     * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x — khuôn hiện có.
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động.
     */
    public function reorderAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/menus');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $result = $this->menus->formReorder($post->toArray());

        if ($request->isXmlHttpRequest()) {
            return new JsonModel($result);
        }

        return $this->redirect()->toRoute(
            'admin/menus',
            [],
            ['query' => ['flag' => $result['flag'], 'applied' => $result['applied']]]
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(?int $id, array $values, array $errors, string $title): ViewModel
    {
        $model = new ViewModel([
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
            'title' => $title,
            'csrfHash' => $this->menus->saveFormCsrfHash(),
        ]);
        $model->setTemplate('admin/menu/form');

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

    private function intParam(string $name): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute($name);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
