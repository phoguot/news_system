<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Post\PostConst;
use Admin\Service\AdminAuthService;
use Admin\Service\PostService;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\ViewModel;

/**
 * Trang quản trị /admin/posts (docs §3.3). PRG mọi form (chuẩn 07 §2 đã cập
 * nhật), kết quả qua query `flag`. Controller MỎNG tuyệt đối: không dựng
 * InputFilter, không đụng Mapper — mọi validate + nghiệp vụ nằm trong
 * PostService; publish/archive/draft/delete trả query flag từ Service.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class PostController extends AbstractActionController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * @return ViewModel
     */
    public function indexAction()
    {
        $query = [];
        $request = $this->getRequest();
        if ($request instanceof HttpRequest) {
            /** @var ParametersInterface $queryParams */
            $queryParams = $request->getQuery();
            $query       = $queryParams->toArray();
        }

        $result  = $this->posts->list($query);
        $options = $this->posts->formOptions();

        return new ViewModel([
            'result'          => $result,
            'tab'             => $result['tab'],
            'tabLabels'       => PostConst::TAB_LABELS,
            'flag'            => $this->queryFlag($request),
            'bulkCounts'      => [
                'applied' => (int) ($query['applied'] ?? 0),
                'skipped' => (int) ($query['skipped'] ?? 0),
            ],
            'csrfHash'        => $this->posts->actionCsrfHash(),
            'bulkCsrfHash'    => $this->posts->bulkCsrfHash(),
            'categoryOptions' => $options['categories'],
            'tagOptions'      => $options['tags'],
            'filters'         => $result['filters'],
        ]);
    }

    /**
     * @return ViewModel
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function createAction()
    {
        return $this->renderForm(null, [], [], 'Viết bài mới');
    }

    /**
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function editAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/posts');
        }

        try {
            $detail = $this->posts->detail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute(
                'admin/posts',
                [],
                ['query' => ['flag' => 'notfound']]
            );
        }

        $values         = $detail['post']->toFormValues();
        $values['tags'] = implode(', ', $detail['tagNames']);

        return $this->renderForm($id, $values, [], 'Sửa bài viết');
    }

    /**
     * POST create/update: chỉ gom raw + id rồi giao hết cho PostService
     * (filter + CSRF + ghi đều ở service).
     *
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function saveAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/posts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id    = $this->intParam('id');
        $title = $id === null ? 'Viết bài mới' : 'Sửa bài viết';

        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->redirect()->toRoute('admin/login');
        }

        try {
            $this->posts->saveForm($userId, $id, $raw);

            return $this->redirect()->toRoute(
                'admin/posts',
                [],
                ['query' => ['flag' => $id === null ? PostConst::FLAG_CREATED : PostConst::FLAG_UPDATED]]
            );
        } catch (ValidationException $e) {
            return $this->renderForm($id, $raw, $e->getErrors(), $title);
        } catch (NotFoundException) {
            return $this->renderForm($id, $raw, ['categoryId' => PostConst::ERROR_NOT_FOUND], $title);
        }
    }

    /**
     * POST /admin/posts/publish/:id — xuất bản ngay hoặc theo lịch VN (docs §3.3.3).
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function publishAction()
    {
        return $this->statusAction('publish');
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function archiveAction()
    {
        return $this->statusAction('archive');
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function draftAction()
    {
        return $this->statusAction('draft');
    }

    /**
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function deleteAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/posts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = $this->posts->formDelete($raw);

        return $this->redirect()->toRoute('admin/posts', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * POST /admin/posts/bulk (FR-22): thao tác hàng loạt từ checkbox danh sách.
     * id KHÔNG nằm ở route — danh sách id + action + csrf (và categoryId /
     * confirmCount khi cần) đến từ body form, service chạy PostBulkFilter.
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function bulkAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/posts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();

        $result = $this->posts->formBulk($post->toArray());

        return $this->redirect()->toRoute('admin/posts', [], ['query' => [
            'flag'    => $result['flag'],
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
        ]]);
    }

    /**
     * Nhánh chung publish/archive/draft: raw (kèm id từ route) → service trả
     * query flag PRG (CSRF + filter đã chạy trong service).
     */
    private function statusAction(string $action): Response
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/posts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();

        $id = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $flag = match ($action) {
            'publish' => $this->posts->formPublish($raw),
            'archive' => $this->posts->formArchive($raw),
            default   => $this->posts->formDraft($raw),
        };

        return $this->redirect()->toRoute('admin/posts', [], ['query' => ['flag' => $flag]]);
    }

    /**
     * GET /admin/posts/revisions/:id — trang lịch sử + so sánh & khôi phục (FR-21).
     *
     * @return ViewModel|Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function revisionsAction()
    {
        $id = $this->intParam('id');
        if ($id === null) {
            return $this->redirect()->toRoute('admin/posts');
        }

        try {
            $post = $this->posts->findOrFail($id);
        } catch (NotFoundException) {
            return $this->redirect()->toRoute('admin/posts', [], ['query' => ['flag' => 'notfound']]);
        }

        $request = $this->getRequest();
        $flag    = null;
        if ($request instanceof HttpRequest) {
            /** @var mixed $raw */
            $raw  = $request->getQuery('flag');
            $flag = is_string($raw) ? $raw : null;
        }

        $model = new ViewModel([
            'post'      => $post,
            'revisions' => $this->posts->listRevisions($id),
            'flag'      => $flag,
            'csrfHash'  => $this->posts->actionCsrfHash(),
        ]);
        $model->setTemplate('admin/post/revisions');

        return $model;
    }

    /**
     * POST /admin/posts/restore/:id — khôi phục một bản (FR-21).
     *
     * @return Response
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function restoreAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->redirect()->toRoute('admin/posts');
        }

        /** @var ParametersInterface $post */
        $post = $request->getPost();
        $raw  = $post->toArray();
        $id   = $this->intParam('id');
        if ($id !== null) {
            $raw['id'] = (string) $id;
        }

        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->redirect()->toRoute('admin/login');
        }

        $flag = $this->posts->formRestore($userId, $raw);

        return $this->redirect()->toRoute(
            'admin/posts',
            ['action' => 'revisions', 'id' => $id],
            ['query' => ['flag' => $flag]]
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
        $status = null;
        if ($id !== null) {
            try {
                $status = $this->posts->findOrFail($id)->status;
            } catch (NotFoundException) {
                $id = null;
            }
        }

        $options = $this->posts->formOptions();
        $revisions = null;
        if ($id !== null) {
            try {
                $revisions = $this->posts->listRevisions($id);
            } catch (\Throwable) {
                $revisions = null;
            }
        }
        $model   = new ViewModel([
            'id'              => $id,
            'values'          => $values,
            'errors'          => $errors,
            'title'           => $title,
            'status'          => $status,
            'csrfHash'        => $this->posts->saveFormCsrfHash($id),
            'categoryOptions' => $options['categories'],
            'mediaOptions'    => $options['media'],
            'revisions'       => $revisions,
            'revisionActionCsrf' => $this->posts->actionCsrfHash(),
        ]);
        $model->setTemplate('admin/post/form');

        return $model;
    }

    /** @param HttpRequest|mixed $request */
    private function queryFlag(mixed $request): ?string
    {
        if (! $request instanceof HttpRequest) {
            return null;
        }

        /** @var mixed $flagRaw */
        $flagRaw = $request->getQuery('flag');

        return is_string($flagRaw) ? $flagRaw : null;
    }

    /** @return int|null id admin đăng nhập (bảng users chỉ 1 dòng — docs §2.6) */
    private function currentUserId(): ?int
    {
        $identity = $this->auth->getIdentity();

        return $identity === null ? null : $identity['id'];
    }

    private function intParam(string $name): ?int
    {
        /** @var mixed $raw */
        $raw = $this->params()->fromRoute($name);

        return is_numeric($raw) ? (int) $raw : null;
    }
}
