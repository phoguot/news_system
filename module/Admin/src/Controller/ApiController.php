<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Exception\NotFoundException;
use Admin\Service\AdminAuthService;
use Admin\Service\Api\ApiResponseModel;
use Admin\Service\Api\ApiResultModel;
use Admin\Service\CategoryService;
use Admin\Service\PostService;
use Admin\Service\TagService;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Throwable;

/**
 * Dispatcher cho mọi `/api/admin/{resource}[/{id}][/{sub}]` (docs-dev 05 §5 —
 * một route gộp, chưa tách từng endpoint như §6.2). Sau khi áp pattern
 * webapp-be (docs-dev/08 §5): Service trả trọn envelope qua ApiResponseModel —
 * controller CHỈ còn là router: đọc request → gọi Service → gắn statusCode.
 * Exception nghiệp vụ map qua ApiResultModel::fromThrowable
 * (Validation 422 · Conflict 409 · NotFound 404).
 *
 * AuthGuard đã chặn 401 trước khi tới đây. API JSON KHÔNG dùng CSRF token của
 * form — phòng CSRF bằng cookie session `SameSite=Lax` (ghi rõ ở docs 05 §5);
 * Service chạy SaveFilter nội bộ với $withCsrf = false (saveApi/mergeApi).
 *
 * Đã triển khai: categories (CRUD + `PUT /categories/reorder` — FR-26), tags
 * (+merge), posts (CRUD, counts, publish, archive, draft, preview-token,
 * autosave + revisions/restore — FR-21, bulk — FR-22). Reorder các resource
 * khác (§6.2) chưa có trong API — trang admin dùng page-level
 * `POST /admin/{module}/reorder` (XHR → JSON, FR-26/29/30/32/34).
 *
 * Suppress theo đặc thù HTTP-plumbing của repo: dispatchAction do router gọi
 * động; body/query từ request là mixed có chủ đích — đã thu hẹp bằng
 * is_string/is_numeric tại từng điểm dùng.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi property qua ServiceManager initializer.
 * @psalm-suppress DeprecatedClass ApiResponseModel kế thừa JsonModel (chuẩn API docs-dev 05).
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedArrayAssignment
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedInferredReturnType
 * @psalm-suppress MixedReturnStatement
 */
class ApiController extends AbstractActionController
{
    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly TagService $tagService,
        private readonly PostService $postService,
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * @return ApiResponseModel
     *
     * @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh.
     */
    public function dispatchAction()
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest) {
            $response = ApiResultModel::error(404, ['resource' => 'Endpoint không tồn tại.']);
            $this->setStatus($response->getStatusCode());

            return $response;
        }

        /** @var mixed $resourceRaw */
        $resourceRaw = $this->params()->fromRoute('resource');
        /** @var mixed $idRaw */
        $idRaw = $this->params()->fromRoute('id');
        /** @var mixed $subRaw */
        $subRaw = $this->params()->fromRoute('sub');

        $resource = is_string($resourceRaw) ? strtolower($resourceRaw) : '';
        $id       = is_numeric($idRaw) ? (int) $idRaw : null;
        $idSlug   = is_string($idRaw) && ! is_numeric($idRaw) ? strtolower($idRaw) : null;
        $sub      = is_string($subRaw) ? strtolower($subRaw) : null;
        $method   = strtoupper($request->getMethod());
        $body     = $this->readBody($request, $method);

        try {
            if ($resource === 'categories') {
                $response = $this->categories($id, $idSlug, $method, $body);
            } elseif ($resource === 'tags') {
                $response = $this->tags($id, $idSlug, $method, $body);
            } elseif ($resource === 'posts') {
                $response = $this->posts($id, $idSlug, $sub, $method, $body);
            } else {
                $response = ApiResultModel::error(404, ['resource' => 'Endpoint không tồn tại.']);
            }
        } catch (Throwable $e) {
            $mapped   = ApiResultModel::fromThrowable($e);
            $response = $mapped ?? throw $e;
        }

        $this->setStatus($response->getStatusCode());

        return $response;
    }

    /**
     * GET /categories · GET /categories/tree · POST /categories ·
     * PUT /categories/reorder (FR-26) ·
     * GET/PUT|PATCH/DELETE /categories/{id} (409 khi còn con/bài).
     *
     * @param array<array-key, mixed> $body
     */
    private function categories(?int $id, ?string $idSlug, string $method, array $body): ApiResponseModel
    {
        if ($method === 'GET' && $id === null) {
            return $this->categoryService->listApi($idSlug === 'tree');
        }

        if ($method === 'PUT' && $id === null && $idSlug === 'reorder') {
            return $this->categoryService->reorderApi($body);
        }

        if ($method === 'POST' && $id === null) {
            return $this->categoryService->saveApi(null, $body);
        }

        if ($id !== null && ($method === 'PUT' || $method === 'PATCH')) {
            return $this->categoryService->saveApi($id, $body);
        }

        if ($id !== null && $method === 'DELETE') {
            return $this->categoryService->deleteApi($id);
        }

        if ($id !== null && $method === 'GET') {
            return $this->categoryService->readApi($id);
        }

        throw NotFoundException::forEntity('endpoint categories', $idSlug ?? 'root');
    }

    /**
     * GET /tags?q= · POST /tags · POST /tags/merge {sourceId, targetId} ·
     * GET/PUT|PATCH/DELETE /tags/{id}.
     *
     * @param array<array-key, mixed> $body
     */
    private function tags(?int $id, ?string $idSlug, string $method, array $body): ApiResponseModel
    {
        if ($method === 'GET' && $id === null) {
            $qRaw = $body['q'] ?? '';

            return $this->tagService->listApi(is_string($qRaw) ? $qRaw : '');
        }

        if ($method === 'POST' && $idSlug === 'merge') {
            return $this->tagService->mergeApi($body);
        }

        if ($method === 'POST' && $id === null) {
            return $this->tagService->saveApi(null, $body);
        }

        if ($id !== null && ($method === 'PUT' || $method === 'PATCH')) {
            return $this->tagService->saveApi($id, $body);
        }

        if ($id !== null && $method === 'DELETE') {
            return $this->tagService->deleteApi($id);
        }

        if ($id !== null && $method === 'GET') {
            return $this->tagService->readApi($id);
        }

        throw NotFoundException::forEntity('endpoint tags', $idSlug ?? 'root');
    }

    /**
     * posts: list + meta · /counts · /bulk · create · {id} GET/PUT|PATCH/DELETE ·
     * {id}/publish · /archive · /draft · /preview-token · FR-21 autosave/revisions.
     *
     * @param array<array-key, mixed> $body
     */
    private function posts(?int $id, ?string $idSlug, ?string $sub, string $method, array $body): ApiResponseModel
    {
        if ($method === 'GET' && $idSlug === 'counts') {
            return $this->postService->countsApi();
        }

        if ($method === 'GET' && $id === null) {
            return $this->postService->listApi($body);
        }

        // FR-22: POST /posts/bulk — 'bulk' rơi vào slot `id` (không số → idSlug)
        // nên phải bắt TRƯỚC nhánh create (POST /posts không id).
        if ($method === 'POST' && $id === null && $idSlug === 'bulk') {
            return $this->postService->bulkApi($body);
        }

        if ($method === 'POST' && $id === null) {
            return $this->postService->saveApi($this->currentUserId(), null, $body);
        }

        // GET /posts/{id}/revisions — lịch sử (FR-21)
        if ($id !== null && $method === 'GET' && $sub === 'revisions') {
            return $this->postService->revisionsApi($id);
        }

        if ($id !== null && $method === 'GET' && $sub === null) {
            return $this->postService->readApi($id);
        }

        if ($id !== null && ($method === 'PUT' || $method === 'PATCH') && $sub === null) {
            return $this->postService->saveApi($this->currentUserId(), $id, $body);
        }

        if ($id !== null && $method === 'DELETE' && $sub === null) {
            return $this->postService->deleteApi($id);
        }

        // FR-21: POST /posts/{id}/revisions/{revisionId}/restore — route gộp chỉ
        // có 1 segment `sub`, nên revisionId truyền trong body (phần khớp §6.2).
        if ($id !== null && $method === 'POST' && $sub !== null) {
            // Restore có thể gửi `sub = "revisions"` kèm `revisionId` trong body.
            if ($sub === 'revisions' && isset($body['revisionId'])) {
                $revisionId = is_numeric($body['revisionId']) ? (int) $body['revisionId'] : 0;
                if ($revisionId > 0) {
                    return $this->postService->restoreApi($id, $revisionId, $this->currentUserId());
                }
            }

            return $this->postAction($id, $sub, $body);
        }

        throw NotFoundException::forEntity('endpoint posts', $idSlug ?? 'root');
    }

    /**
     * POST /posts/{id}/{sub}: publish|archive|draft|preview-token|autosave (docs §6.2).
     *
     * @param array<array-key, mixed> $body
     */
    private function postAction(int $id, string $sub, array $body): ApiResponseModel
    {
        return match ($sub) {
            'publish' => $this->postService->publishApi($id, $body),
            'archive' => $this->postService->archiveApi($id),
            'draft' => $this->postService->draftApi($id),
            'preview-token' => $this->postService->previewTokenApi($id),
            'autosave' => $this->postService->autosaveApi($id, $this->currentUserId(), $body),
            // reorder — chưa triển khai (§6.2 để mở) → 404 như route lạ
            default => throw NotFoundException::forEntity('endpoint posts', $sub),
        };
    }

    private function currentUserId(): int
    {
        $identity = $this->auth->getIdentity();
        if ($identity === null) {
            throw new NotFoundException('Phiên đăng nhập không còn hợp lệ.');
        }

        return $identity['id'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readBody(HttpRequest $request, string $method): array
    {
        if ($method === 'GET') {
            /** @var ParametersInterface $params */
            $params = $request->getQuery();

            return $params->toArray();
        }

        $header = $request->getHeader('Content-Type');
        $ct     = strtolower($header instanceof HeaderInterface ? $header->getFieldValue() : '');
        if (str_contains($ct, 'application/json')) {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $request->getContent(), true);

            return is_array($decoded) ? $decoded : [];
        }

        /** @var ParametersInterface $params */
        $params = $request->getPost();

        return $params->toArray();
    }

    /** Laminas trả ResponseInterface không khai báo setStatusCode — chỉ ghi khi là HTTP response. */
    private function setStatus(int $code): void
    {
        $response = $this->getResponse();
        if ($response instanceof Response) {
            $response->setStatusCode($code);
        }
    }
}
