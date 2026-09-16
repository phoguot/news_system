<?php

declare(strict_types=1);

namespace Frontend\Controller;

use Frontend\Service\SitemapService;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Route `sitemap` /sitemap.xml (FR-11 / NFR-SEO-4 — docs §6.1/§7.1).
 *
 * Controller MỎNG (07 §luồng 12/09): gắn Content-Type XML, đưa payload
 * `SitemapService::urls()` (đã cache key `sitemap-v1`) xuống view terminal —
 * `setTerminal(true)` để KHÔNG bọc layout frontend (output phải là XML thuần).
 * Các stub list/detail/view/submit của khung cũ bị xoá — không route nào trỏ tới.
 *
 * @psalm-suppress PropertyNotSetInConstructor Laminas mồi các property qua ServiceManager initializer.
 */
class SitemapController extends AbstractActionController
{
    public function __construct(private readonly SitemapService $sitemapService)
    {
    }

    public function indexAction(): ViewModel
    {
        // getHeaders() chỉ có trên Laminas\Http\Response — guard để không giả
        // định loại response của mọi environment.
        $response = $this->getResponse();
        if ($response instanceof HttpResponse) {
            $response->getHeaders()->addHeaders(['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        $model = new ViewModel(['urls' => $this->sitemapService->urls()]);
        $model->setTerminal(true);

        return $model;
    }
}
