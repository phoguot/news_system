<?php

declare(strict_types=1);

namespace Frontend;

use Application\Factory\AppInvokableFactory;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Psr\Container\ContainerInterface;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\HomeController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'news' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/tin-tuc',
                    'defaults' => [
                        'controller' => Controller\PostController::class,
                        'action'     => 'list',
                    ],
                ],
            ],
            'news-detail' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/tin-tuc/:slug',
                    'constraints' => ['slug' => '[a-z0-9\-]+'],
                    'defaults'    => [
                        'controller' => Controller\PostController::class,
                        'action'     => 'detail',
                    ],
                ],
            ],
            'category' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/danh-muc/:slug',
                    'constraints' => ['slug' => '[a-z0-9\-]+'],
                    'defaults'    => [
                        'controller' => Controller\CategoryController::class,
                        'action'     => 'view',
                    ],
                ],
            ],
            'tag' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/tag/:slug',
                    'constraints' => ['slug' => '[a-z0-9\-]+'],
                    'defaults'    => [
                        'controller' => Controller\TagController::class,
                        'action'     => 'view',
                    ],
                ],
            ],
            'search' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/tim-kiem',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'services' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/dich-vu',
                    'defaults' => [
                        'controller' => Controller\ServiceController::class,
                        'action'     => 'list',
                    ],
                ],
            ],
            'service-detail' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/dich-vu/:slug',
                    'constraints' => ['slug' => '[a-z0-9\-]+'],
                    'defaults'    => [
                        'controller' => Controller\ServiceController::class,
                        'action'     => 'detail',
                    ],
                ],
            ],
            'team' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/doi-ngu',
                    'defaults' => [
                        'controller' => Controller\TeamController::class,
                        'action'     => 'list',
                    ],
                ],
            ],
            'contact' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/lien-he',
                    'defaults' => [
                        'controller' => Controller\ContactController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'contact-submit' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/api/contact',
                    'defaults' => [
                        'controller' => Controller\ContactController::class,
                        'action'     => 'submit',
                    ],
                ],
            ],
            'about' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/gioi-thieu',
                    'defaults' => [
                        'controller' => Controller\AboutController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'pricing' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/bang-gia',
                    'defaults' => [
                        'controller' => Controller\PricingController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'sitemap' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap.xml',
                    'defaults' => [
                        'controller' => Controller\SitemapController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            // Mapper của container: closure inline. Service: AppInvokableFactory (07 §4, 13/09/2026).
            Model\Contact\ContactMapper::class => static function (ContainerInterface $c): Model\Contact\ContactMapper {
                return new Model\Contact\ContactMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Service\ServiceMapper::class => static function (ContainerInterface $c): Model\Service\ServiceMapper {
                return new Model\Service\ServiceMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Pricing\PricingMapper::class => static function (ContainerInterface $c): Model\Pricing\PricingMapper {
                return new Model\Pricing\PricingMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Menu\MenuMapper::class => static function (ContainerInterface $c): Model\Menu\MenuMapper {
                return new Model\Menu\MenuMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Setting\SettingMapper::class => static function (ContainerInterface $c): Model\Setting\SettingMapper {
                return new Model\Setting\SettingMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            // Service nền 07 §4 (13/09/2026): extends AppServiceFactory,
            // constructor không nhận gì — mồi container qua AppInvokableFactory.
            Service\ContactService::class => AppInvokableFactory::class,
            // SettingService = tầng đọc settings + cache 60s (FR-39, NFR-PERF-1);
            // Admin\Service\SettingService::saveForm() invalidate qua cùng instance
            // trong container gộp (05-cau-truc §4).
            Service\SettingService::class => AppInvokableFactory::class,
            // HomeService = tầng đọc + dựng payload trang chủ, cache key 'home-v1'
            // TTL 60s (FR-32 + FR-39). Đọc mapper Admin qua container gộp — 1 bảng
            // 1 mapper, select thuần (05-cau-truc §4); Admin forget key sau mọi ghi
            // ảnh hưởng home (Post/Banner/Service/TeamMember/HomeSection service).
            Service\HomeService::class => AppInvokableFactory::class,
            // PostListService = tầng đọc /tin-tuc phân trang + lọc theo slug
            // (FR-02). Menu danh mục cache 'category-menu-v1' (NFR §7.2);
            // Admin\Service\CategoryService forget key này + 'home-v1' sau ghi.
            // Đọc PostMapper/CategoryMapper/MediaMapper của Admin qua container
            // gộp — select thuần, precedent batch 9 (05-cau-truc §4).
            Service\PostListService::class => AppInvokableFactory::class,
            // PostDetailService = tầng đọc /tin-tuc/{slug} (FR-03): bài công
            // khai + banner/tác giả/tag/bài liên quan (§5.4 ghép 2 bước, không
            // JOIN chéo). Không cache — NFR §7.2 chỉ liệt kê home/menu/settings.
            Service\PostDetailService::class => AppInvokableFactory::class,
            // CategoryListService = tầng đọc /danh-muc/{slug} (FR-05): danh mục
            // BẬT + bài của nó và con đang bật (§5.3, không JOIN — activeChildIds
            // một lớp vì cây chặn 2 cấp ở FR-26); slug lạ/tắt → null → 404
            // (khác /tin-tuc: bỏ lọc im lặng). Không cache — key nổ theo
            // slug×trang, menu đã có 'category-menu-v1' riêng.
            Service\CategoryListService::class => AppInvokableFactory::class,
            // TagListService = tầng đọc /tag/{slug} (FR-06): id bài từ post_tags
            // (PostTagMapper) rồi MỘT query IN+scope public của PostMapper
            // (listPublishedPageByIds/countPublishedByIds) — 1 bảng 1 mapper.
            // Tag lạ → null → 404; tag rỗng bài vẫn 200. Không cache.
            Service\TagListService::class => AppInvokableFactory::class,
            // SearchService = tầng tìm kiếm toàn văn tiếng Việt /tim-kiem?q=
            // (FR-07, docs §5.11 / §4): FULLTEXT ft_posts_title_excerpt + scope công khai,
            // giới hạn 20 bài, noindex. Không cache.
            Service\SearchService::class => AppInvokableFactory::class,
            // ServiceViewService = tầng đọc dịch vụ công khai /dich-vu và /dich-vu/:slug
            // (FR-08, docs §2.1 / §3.5): danh sách active + chi tiết theo slug, media map chống N+1.
            Service\ServiceViewService::class => AppInvokableFactory::class,
            // TeamViewService = tầng đọc trang đội ngũ /doi-ngu (FR-09, docs §3.8 / §5.6):
            // danh sách thành viên active (isActive=1) sortOrder/id ASC; avatar map chống N+1;
            // email/SĐT ẩn khi showContact=0 (§3.8). Không cache (bảng ít bản ghi, §7.2).
            Service\TeamViewService::class => AppInvokableFactory::class,
            // SitemapService = tầng dựng /sitemap.xml (FR-11, NFR-SEO-4 — docs §6.1/§7.1):
            // trang tĩnh + bài public (publicScope — nháp/hẹn giờ/archived loại) + danh mục
            // BẬT + dịch vụ BẬT; payload mảng thuần {path, lastmod} cache 'sitemap-v1' TTL 60s.
            // PostService/CategoryService/ServiceService (Admin) forget key sau mọi ghi chạm nguồn.
            Service\SitemapService::class => AppInvokableFactory::class,
            Service\PricingViewService::class => AppInvokableFactory::class,
            Service\MenuService::class => AppInvokableFactory::class,
            // PostViewService = FR-41 ghi lượt xem (post_view_daily upsert + posts.viewCount),
            // dedup cookie/session 30', bỏ bot UA, transaction.
            Service\PostViewService::class => AppInvokableFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\HomeController::class     => static function (
                ContainerInterface $c
            ): Controller\HomeController {
                return new Controller\HomeController($c->get(Service\HomeService::class));
            },
            Controller\PostController::class     => static function (
                ContainerInterface $c
            ): Controller\PostController {
                return new Controller\PostController(
                    $c->get(Service\PostListService::class),
                    $c->get(Service\PostDetailService::class),
                    $c->get(Service\PostViewService::class)
                );
            },
            Controller\CategoryController::class => static function (
                ContainerInterface $c
            ): Controller\CategoryController {
                return new Controller\CategoryController($c->get(Service\CategoryListService::class));
            },
            Controller\TagController::class      => static function (
                ContainerInterface $c
            ): Controller\TagController {
                return new Controller\TagController($c->get(Service\TagListService::class));
            },
            Controller\SearchController::class   => static function (
                ContainerInterface $c
            ): Controller\SearchController {
                return new Controller\SearchController($c->get(Service\SearchService::class));
            },
            Controller\ServiceController::class  => static function (
                ContainerInterface $c
            ): Controller\ServiceController {
                return new Controller\ServiceController(
                    $c->get(Service\ServiceViewService::class),
                    $c->get(Service\PricingViewService::class),
                    $c->get(Service\SettingService::class)
                );
            },
            Controller\TeamController::class      => static function (
                ContainerInterface $c
            ): Controller\TeamController {
                return new Controller\TeamController($c->get(Service\TeamViewService::class));
            },
            // ContactSaveFilter NOT registered anywhere: stateful (setData) →
            // ContactService khởi tạo mới mỗi request (chuẩn 07 §2 đã cập nhật).
            Controller\ContactController::class  => static function (
                ContainerInterface $c
            ): Controller\ContactController {
                return new Controller\ContactController(
                    $c->get(Service\ContactService::class),
                    $c->get(\Application\Service\CaptchaService::class),
                    $c->get(Service\SettingService::class)
                );
            },
            Controller\AboutController::class    => static function (
                ContainerInterface $c
            ): Controller\AboutController {
                return new Controller\AboutController($c->get(Service\SettingService::class));
            },
            Controller\PricingController::class  => static function (
                ContainerInterface $c
            ): Controller\PricingController {
                return new Controller\PricingController($c->get(Service\PricingViewService::class));
            },
            Controller\SitemapController::class  => static function (
                ContainerInterface $c
            ): Controller\SitemapController {
                return new Controller\SitemapController($c->get(Service\SitemapService::class));
            },
        ],
    ],
    'view_manager' => [
        // Bật layout công khai cho toàn site (mốc 14/09/2026): trước đó key
        // này trống nên mọi trang render bằng layout skeleton Laminas — CSS
        // `assets/css/style.css` (hero slider, header, footer) không được nạp.
        // Admin không đổi: AuthGuard setTemplate('layout/admin') runtime;
        // login/reset là terminal view.
        'layout'       => 'layout/frontend',
        'template_map' => [
            'layout/frontend' => __DIR__ . '/../view/layout/frontend.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];
