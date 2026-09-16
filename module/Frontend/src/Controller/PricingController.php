<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\PricingViewService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class PricingController extends AbstractActionController
{
    public function __construct(private readonly PricingViewService $pricingView)
    {
    }

    public function indexAction(): ViewModel
    {
        $group = trim((string) $this->params()->fromQuery('nhom', ''));
        $q     = trim((string) $this->params()->fromQuery('q', ''));
        $page  = (int) $this->params()->fromQuery('page', 1);
        $data  = $this->pricingView->list($group !== '' ? $group : null, $q !== '' ? $q : null, $page);

        return new ViewModel([
            'items'   => $data['items'],
            'groups'  => $data['groups'],
            'total'   => $data['total'],
            'page'    => $data['page'],
            'pages'   => $data['pages'],
            'perPage' => $data['perPage'],
            'group'   => $group,
            'q'       => $q,
        ]);
    }
}
