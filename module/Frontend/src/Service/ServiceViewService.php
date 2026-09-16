<?php

declare(strict_types=1);

namespace Frontend\Service;

use Admin\Model\Media\MediaMapper;
use Application\Factory\AppServiceFactory;
use Frontend\Constant\FrontendConst;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Service\ServiceModel;

/**
 * Tầng đọc dịch vụ công khai (FR-08, docs §2.1 / §3.5):
 * - paginate(page): lưới phẳng phân trang isActive=1, sortOrder ASC, id ASC, 12/trang, kẹp ?page=.
 * - list(): wrapper toàn bộ active (giữ cho grouped fallback + home-section).
 * - detail(slug): chi tiết dịch vụ active theo slug; lạ hoặc tắt → null (404).
 *
 * Resolve media (icon/ảnh) qua MediaMapper::mapCardsByIds theo chuẩn 1 mapper 1 bảng (07 §5).
 * Không cache danh sách (FR-08 — bảng nhỏ, index isActive+sortOrder đủ; giống CategoryListService).
 */
class ServiceViewService extends AppServiceFactory
{
    /**
     * Gom dịch vụ cha + con cho trang /dich-vu box2/3 (docs §3.5 mở rộng).
     * Giữ lại cho tương thích — /dich-vu hiện dùng paginate() phẳng; grouped() để dự phòng.
     *
     * @psalm-suppress PossiblyUnusedMethod grouped() giữ cho compat, không còn gọi từ /dich-vu phân trang.
     *
     * @return list<array{parent: array<string,mixed>, children: list<array<string,mixed>>}>
     */
    public function grouped(): array
    {
        $parents = $this->services()->listAll();
        $parentsOnly = [];
        foreach ($parents as $p) {
            if ($p->parentId === null && $p->isActive === 1) {
                $parentsOnly[] = $p;
            }
        }
        // Media collect
        $mediaIds = [];
        foreach ($parents as $s) {
            if ($s->iconMediaId !== null) {
                $mediaIds[] = $s->iconMediaId;
            }
            if ($s->imageMediaId !== null) {
                $mediaIds[] = $s->imageMediaId;
            }
        }
        $mediaMap = $mediaIds === [] ? [] : $this->media()->mapCardsByIds(array_values(array_unique($mediaIds)));
        $toCard = function (\Frontend\Model\Service\ServiceModel $s) use ($mediaMap): array {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'href' => '/dich-vu/' . $s->slug,
                'shortDescription' => $s->shortDescription ?? '',
                'content' => $s->content ?? '',
                'icon' => $s->iconMediaId !== null ? ($mediaMap[$s->iconMediaId] ?? null) : null,
                'image' => $s->imageMediaId !== null ? ($mediaMap[$s->imageMediaId] ?? null) : null,
            ];
        };
        $allByParent = [];
        foreach ($parents as $s) {
            if ($s->parentId !== null && $s->isActive === 1) {
                $allByParent[$s->parentId][] = $toCard($s);
            }
        }
        $out = [];
        foreach ($parentsOnly as $p) {
            $out[] = ['parent' => $toCard($p), 'children' => $allByParent[$p->id] ?? []];
        }
        // Fallback nếu chưa có cha nào (DB cũ): trả phẳng như trước
        if ($out === []) {
            $flat = $this->list();
            if ($flat['services'] !== []) {
                $out[] = ['parent' => ['name' => 'Dịch vụ', 'slug' => '', 'href' => '', 'shortDescription' => '', 'content' => '', 'icon' => null, 'image' => null], 'children' => $flat['services']];
            }
        }

        return $out;
    }

    /**
     * Payload phân trang cho /dich-vu (FR-08 — lưới phẳng, 12/trang, kẹp ?page=).
     *
     * @return array{
     *     services: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    public function paginate(int $requestedPage): array
    {
        $total = $this->services()->countActive();
        $pages = (int) ceil($total / FrontendConst::SERVICE_PAGE_SIZE);
        $page  = min(max(1, $requestedPage), max(1, $pages));

        $models = $total === 0
            ? []
            : $this->services()->listActivePage(
                FrontendConst::SERVICE_PAGE_SIZE,
                ($page - 1) * FrontendConst::SERVICE_PAGE_SIZE
            );

        if ($models === []) {
            return [
                'services' => [],
                'total'    => $total,
                'page'     => $page,
                'pages'    => $pages,
            ];
        }

        $mediaIds = [];
        foreach ($models as $service) {
            if ($service->iconMediaId !== null) {
                $mediaIds[] = $service->iconMediaId;
            }
            if ($service->imageMediaId !== null) {
                $mediaIds[] = $service->imageMediaId;
            }
        }

        $mediaMap = $mediaIds === []
            ? []
            : $this->media()->mapCardsByIds(array_values(array_unique($mediaIds)));

        $items = [];
        foreach ($models as $service) {
            $icon  = $service->iconMediaId !== null ? ($mediaMap[$service->iconMediaId] ?? null) : null;
            $image = $service->imageMediaId !== null ? ($mediaMap[$service->imageMediaId] ?? null) : null;

            $items[] = [
                'id'               => $service->id,
                'name'             => $service->name,
                'slug'             => $service->slug,
                'href'             => '/dich-vu/' . $service->slug,
                'shortDescription' => $service->shortDescription ?? '',
                'icon'             => $icon,
                'image'            => $image,
            ];
        }

        return [
            'services' => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
        ];
    }

    /**
     * Payload cho trang danh sách dịch vụ /dich-vu — wrapper toàn bộ active.
     * Giữ lại cho grouped() fallback (DB cũ) + tương thích caller cũ.
     *
     * @return array{
     *     services: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function list(): array
    {
        $models = $this->services()->listActiveAll();
        if ($models === []) {
            return [
                'services' => [],
                'total'    => 0,
            ];
        }

        $mediaIds = [];
        foreach ($models as $service) {
            if ($service->iconMediaId !== null) {
                $mediaIds[] = $service->iconMediaId;
            }
            if ($service->imageMediaId !== null) {
                $mediaIds[] = $service->imageMediaId;
            }
        }

        $mediaMap = $mediaIds === []
            ? []
            : $this->media()->mapCardsByIds(array_values(array_unique($mediaIds)));

        $items = [];
        foreach ($models as $service) {
            $icon  = $service->iconMediaId !== null ? ($mediaMap[$service->iconMediaId] ?? null) : null;
            $image = $service->imageMediaId !== null ? ($mediaMap[$service->imageMediaId] ?? null) : null;

            $items[] = [
                'id'               => $service->id,
                'name'             => $service->name,
                'slug'             => $service->slug,
                'href'             => '/dich-vu/' . $service->slug,
                'shortDescription' => $service->shortDescription ?? '',
                'icon'             => $icon,
                'image'            => $image,
            ];
        }

        return [
            'services' => $items,
            'total'    => count($items),
        ];
    }

    /**
     * Payload cho trang chi tiết dịch vụ /dich-vu/{slug}.
     * Trả về null nếu slug không tồn tại hoặc dịch vụ bị tắt.
     *
     * @return ?array{
     *     service: array{
     *         id: int,
     *         name: string,
     *         slug: string,
     *         shortDescription: string,
     *         content: string,
     *         icon: ?array{path: string, alt: string, thumb: string, large: string},
     *         image: ?array{path: string, alt: string, thumb: string, large: string},
     *         metaTitle: string,
     *         metaDescription: string
     *     }
     * }
     */
    public function detail(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $service = $this->services()->findActiveBySlug($slug);
        if ($service === null) {
            return null;
        }

        $mediaIds = [];
        if ($service->iconMediaId !== null) {
            $mediaIds[] = $service->iconMediaId;
        }
        if ($service->imageMediaId !== null) {
            $mediaIds[] = $service->imageMediaId;
        }

        $mediaMap = $mediaIds === []
            ? []
            : $this->media()->mapCardsByIds(array_values(array_unique($mediaIds)));

        $icon  = $service->iconMediaId !== null ? ($mediaMap[$service->iconMediaId] ?? null) : null;
        $image = $service->imageMediaId !== null ? ($mediaMap[$service->imageMediaId] ?? null) : null;

        $metaTitle = $service->metaTitle !== null && $service->metaTitle !== ''
            ? $service->metaTitle
            : $service->name;

        $metaDescription = $service->metaDescription !== null && $service->metaDescription !== ''
            ? $service->metaDescription
            : ($service->shortDescription ?? '');

        return [
            'service' => [
                'id'               => $service->id,
                'name'             => $service->name,
                'slug'             => $service->slug,
                'shortDescription' => $service->shortDescription ?? '',
                'content'          => $service->content ?? '',
                'icon'             => $icon,
                'image'            => $image,
                'metaTitle'        => $metaTitle,
                'metaDescription'  => $metaDescription,
            ],
        ];
    }

    private function services(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function media(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }
}
