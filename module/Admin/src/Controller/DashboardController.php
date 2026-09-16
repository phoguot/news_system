<?php

declare(strict_types=1);

namespace Admin\Controller;

use Admin\Service\DashboardService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang dashboard admin (docs §3.1). Controller MỎNG: một lần gọi
 * DashboardService::summary(), render — không query, không Filter (không form).
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params, Redirect…).
 */
class DashboardController extends AbstractActionController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** @return ViewModel */
    public function indexAction()
    {
        return new ViewModel(['summary' => $this->dashboard->summary()]);
    }
}
