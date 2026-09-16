<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Tag\TagConst;
use Admin\Service\TagService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Trang /admin/tags (docs §3.4): danh sách kèm số bài, CRUD + gộp tag.
 * PRG với query flag (chuẩn 07 §2 đã cập nhật). Controller mỏng: mọi validate
 * (kèm CSRF) chạy trong TagService — controller không đụng Filter/Mapper.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class TagController extends AbstractActionController
{
    public function __construct(
        private readonly TagService $tags,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $tagList = $this->tags->listWithCounts();

        return new ViewModel([
            'items'      => $tagList,
            'flag'       => $this->queryFlag(),
            'csrfHash'   => $this->tags->actionCsrfHash(),
            'tagOptions' => array_map(
                static fn (object $t): array => ['id' => $t->id, 'name' => $t->name],
                $tagList
            ),
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Thêm tag');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/tags');
        }

        try {
            $tag = $this->tags->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/tags', [], ['query' => ['flag' => TagConst::FLAG_NOT_FOUND]]);
        }

        return $this->renderForm($id, $tag->toFormValues(), [], 'Sửa tag');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/tags');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Thêm tag' : 'Sửa tag';

        try {
            $this->tags->saveForm($id, $raw);

            return $this->redirect()->toRoute(
                'admin/tags',
                [],
                ['query' => ['flag' => $id === null ? TagConst::FLAG_CREATED : TagConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['name' => TagConst::ERROR_NOT_FOUND], $title);
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
            return $this->redirect()->toRoute('admin/tags');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->tags->deleteForm($raw);

        return $this->redirect()->toRoute('admin/tags', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * Gộp tag (docs §3.4): POST sourceId + targetId — validate trong service.
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function mergeAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/tags');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $flag = $this->tags->mergeForm($post->toArray());

        return $this->redirect()->toRoute('admin/tags', [], ['query' => ['flag' => $flag]]);
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
            'csrfHash' => $this->tags->saveFormCsrfHash($id),
        ]);
        $model->setTemplate('admin/tag/form');

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
