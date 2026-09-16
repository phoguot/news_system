<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\HomeService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang chủ công khai (FR-32): controller MỎNG — chỉ gọi HomeService (payload
 * section đã cache, docs 07 §2) rồi render. 4 action placeholder thừa của bản
 * khung cũ (list/detail/view/submit) bị xoá vì không route nào trỏ tới —
 * tin chi tiết thuộc PostController.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 */
class HomeController extends AbstractActionController
{
    public function __construct(private readonly HomeService $homeService)
    {
    }

    public function indexAction(): ViewModel
    {
        return new ViewModel(['sections' => $this->homeService->sections()]);
    }
}
