<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\SettingService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Trang giới thiệu /gioi-thieu — nội dung tĩnh + thông tin liên hệ từ settings.
 */
class AboutController extends AbstractActionController
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function indexAction(): ViewModel
    {
        return new ViewModel([
            'settings' => $this->settings->all(),
            'mapUrl'   => $this->settings->mapEmbedUrl(),
        ]);
    }
}
