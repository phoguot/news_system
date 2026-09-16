<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\PostDetailService;
use Frontend\Service\PostListService;
use Frontend\Service\PostViewService;
use Laminas\Http\Header\SetCookie;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Tin tức frontend. Controller MỎNG (07 §luồng 12/09): chỉ nhận request,
 * đẩy tham số thô xuống Service, render. `list` = FR-02 (docs §5.1/§5.3);
 * `detail` = FR-03 (§2.1/§5.4): slug từ route → PostDetailService; null
 * (bài nháp/hẹn giờ/không tồn tại) → notFoundAction() — `error/404` đã gắn
 * `not_found_template` trong config Application. Action `index/view/submit`
 * của khung cũ bị xoá vì không route nào trỏ tới — khuôn HomeService batch 9.
 * FR-41: detail ghi lượt xem (post_view_daily + posts.viewCount) qua
 * PostViewService với dedup cookie/session 30' + bot UA, bỏ preview.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class PostController extends AbstractActionController
{
    public function __construct(
        private readonly PostListService $postListService,
        private readonly PostDetailService $postDetailService,
        private readonly PostViewService $postViewService
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function listAction(): ViewModel
    {
        $slug = trim((string) $this->params()->fromQuery('danh-muc', ''));
        $page = (int) $this->params()->fromQuery('page', 1);

        return new ViewModel(
            $this->postListService->paginate($slug === '' ? null : $slug, $page)
            + [
                'categories' => $this->postListService->filterCategories(),
                'topBanner'  => $this->postListService->topBanner(),
            ]
        );
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function detailAction(): ViewModel
    {
        $slug    = trim((string) $this->params()->fromRoute('slug', ''));
        $token   = trim((string) $this->params()->fromQuery('previewToken', ''));
        $payload = $this->postDetailService->detail($slug, $token);
        if ($payload === null) {
            $model = $this->notFoundAction();
            $model->setTemplate('error/404');

            return $model;
        }

        $isPreview = $token !== '';
        if (! $isPreview) {
            $ua = null;
            if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
                $ua = $_SERVER['HTTP_USER_AGENT'];
            }

            $cookies = [];
            if (isset($_COOKIE) && is_array($_COOKIE)) {
                foreach ($_COOKIE as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $cookies[$k] = $v;
                    }
                }
            }

            if (! isset($_SESSION) || ! is_array($_SESSION)) {
                $_SESSION = [];
            }
            /** @var array<string, mixed> $ref */
            $ref = &$_SESSION;
            $postId = (int) ($payload['id'] ?? 0);
            $recorded = $this->postViewService->tryRecord($postId, $ua, $isPreview, $cookies, $ref);
            if ($recorded) {
                $response = $this->getResponse();
                if ($response instanceof \Laminas\Http\Response) {
                    $cookieStr = $this->postViewService->cookieHeader($postId);
                    $parts = explode(';', $cookieStr);
                    $nv = explode('=', trim($parts[0] ?? ''), 2);
                    $name = trim($nv[0] ?? '');
                    $value = trim($nv[1] ?? '');
                    if ($name !== '') {
                        $cookie = new SetCookie($name, $value, time() + 1800, '/', null, false, true);
                        $cookie->setSameSite('Lax');
                        $response->getHeaders()->addHeader($cookie);
                    }
                }
            }
        }

        return new ViewModel($payload);
    }
}
