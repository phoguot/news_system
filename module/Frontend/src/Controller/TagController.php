<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\TagListService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang tag /tag/{slug} (FR-06, docs §2.1/§6.1). Controller MỎNG (07
 * §luồng 12/09): slug route + `?page=` thô đẩy xuống TagListService::page;
 * null (slug lạ) → notFoundAction() + setTemplate('error/404') tường minh
 * (khuôn batch 11 — not_found_template chỉ ăn cho route-404). Tag không có
 * bật/tắt nên 404 chỉ vì lạ; tag không bài vẫn 200 rỗng.
 *
 * Các action index/list/detail/submit của khung stub cũ bị xoá vì không route
 * nào trỏ tới (route `tag` default action = `view`).
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class TagController extends AbstractActionController
{
    public function __construct(private readonly TagListService $tagListService)
    {
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function viewAction(): ViewModel
    {
        $slug = trim((string) $this->params()->fromRoute('slug', ''));
        $page = (int) $this->params()->fromQuery('page', 1);

        $payload = $this->tagListService->page($slug, $page);
        if ($payload === null) {
            $model = $this->notFoundAction();
            $model->setTemplate('error/404');

            return $model;
        }

        return new ViewModel($payload);
    }
}
