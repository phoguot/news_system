<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\SearchService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang tìm kiếm bài viết /tim-kiem?q= (FR-07, docs §5.11 / §4).
 * Controller MỎNG (07 §luồng 12/09): nhận query param 'q', gọi SearchService,
 * render ViewModel. Các action stub cũ (list/detail/view/submit) bị xoá vì
 * không có route nào trỏ tới.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class SearchController extends AbstractActionController
{
    public function __construct(private readonly SearchService $searchService)
    {
    }

    public function indexAction(): ViewModel
    {
        $q = trim((string) $this->params()->fromQuery('q', ''));

        return new ViewModel($this->searchService->search($q));
    }
}
