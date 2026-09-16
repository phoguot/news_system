<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\CategoryListService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang danh mục /danh-muc/{slug} (FR-05, docs §3.2/§5.3). Controller MỎNG
 * (07 §luồng 12/09): slug từ route + `?page=` thô đẩy xuống
 * CategoryListService::page; null (slug lạ HOẶC danh mục TẮT — semantics
 * khác /tin-tuc ở đó là bỏ lọc im lặng) → notFoundAction() 404. Khuôn
 * template 404 theo đúng batch 11: ViewModel của CreateHttpNotFoundModel
 * không có template thật, phải setTemplate('error/404') tường minh
 * (not_found_template chỉ ăn cho route-404).
 *
 * Các action index/list/detail/submit của khung stub cũ bị xoá vì không route
 * nào trỏ tới (route `category` default action = `view`).
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class CategoryController extends AbstractActionController
{
    public function __construct(private readonly CategoryListService $categoryListService)
    {
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function viewAction(): ViewModel
    {
        $slug = trim((string) $this->params()->fromRoute('slug', ''));
        $page = (int) $this->params()->fromQuery('page', 1);

        $payload = $this->categoryListService->page($slug, $page);
        if ($payload === null) {
            $model = $this->notFoundAction();
            $model->setTemplate('error/404');

            return $model;
        }

        return new ViewModel($payload);
    }
}
