<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\PricingViewService;
use Frontend\Service\ServiceViewService;
use Frontend\Service\SettingService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang dịch vụ công khai (FR-08, docs §2.1 / §3.5):
 * - listAction(): /dich-vu — lưới phẳng phân trang 12/trang, ?page= kẹp trang cuối.
 * - detailAction(): /dich-vu/:slug — chi tiết dịch vụ active theo slug;
 *   slug lạ hoặc tắt → notFoundAction() + setTemplate('error/404').
 *
 * Controller MỎNG (07 §luồng 12/09): chỉ nhận request, gọi ServiceViewService,
 * render view. Các stub index/view/submit bị xoá vì không route nào trỏ tới.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 * @psalm-suppress MixedMethodCall plugin() của AbstractController trả động (Params…).
 */
class ServiceController extends AbstractActionController
{
    public function __construct(
        private readonly ServiceViewService $serviceViewService,
        private readonly PricingViewService $pricingViewService,
        private readonly SettingService $settingService,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function listAction(): ViewModel
    {
        $page = (int) $this->params()->fromQuery('page', 1);
        $payload = $this->serviceViewService->paginate($page);

        $pricing = $this->pricingViewService->list(null, null);
        $pricing['items'] = array_slice($pricing['items'], 0, 10);
        $mapUrl = $this->settingService->mapEmbedUrl();
        $settings = $this->settingService->all();

        return new ViewModel(array_merge($payload, [
            'pricing' => $pricing,
            'mapUrl'  => $mapUrl,
            'settings' => $settings,
        ]));
    }

    /** @psalm-suppress PossiblyUnusedMethod router dispatch gọi action động, không có lời gọi tĩnh. */
    public function detailAction(): ViewModel
    {
        $slug    = trim((string) $this->params()->fromRoute('slug', ''));
        $payload = $this->serviceViewService->detail($slug);

        if ($payload === null) {
            $model = $this->notFoundAction();
            $model->setTemplate('error/404');

            return $model;
        }

        return new ViewModel($payload);
    }
}
