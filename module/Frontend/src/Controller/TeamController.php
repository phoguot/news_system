<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\TeamViewService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang đội ngũ công khai (FR-09, docs §3.8 / §5.6):
 * - listAction(): /doi-ngu — danh sách thành viên active.
 *   email / SĐT ẩn khi showContact = 0 (xử lý trong TeamViewService).
 *
 * Controller MỎNG (07 §luồng 12/09): chỉ nhận request, gọi TeamViewService,
 * render view. Các stub index/detail/view/submit bị xoá — không route nào trỏ tới.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi request/response/event/plugins
 *   qua ServiceManager initializer, không qua constructor.
 */
class TeamController extends AbstractActionController
{
    public function __construct(private readonly TeamViewService $teamViewService)
    {
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function listAction(): ViewModel
    {
        return new ViewModel($this->teamViewService->list());
    }
}
