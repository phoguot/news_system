<?php

declare(strict_types=1);

namespace Admin;

use Application\Factory\AppInvokableFactory;
use Laminas\Authentication\AuthenticationService;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Psr\Container\ContainerInterface;

return [
    'router' => [
        'routes' => [
            'admin' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/admin',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'login' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/login',
                            'defaults' => [
                                'controller' => Controller\AuthController::class,
                                'action'     => 'login',
                            ],
                        ],
                    ],
                    'logout' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/logout',
                            'defaults' => [
                                'controller' => Controller\AuthController::class,
                                'action'     => 'logout',
                            ],
                        ],
                    ],
                    'dashboard' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/dashboard',
                            'defaults' => [
                                'controller' => Controller\DashboardController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'posts' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/posts[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\PostController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'categories' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/categories[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\CategoryController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'tags' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/tags[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\TagController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'services' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/services[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\ServiceController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'banners' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/banners[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\BannerController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'home-sections' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/home-sections[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\HomeSectionController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'team' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/team[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\TeamController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'contacts' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/contacts[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\ContactController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'media' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/media[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\MediaController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'pricing' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/pricing[/:action[/:id]]',
                            'defaults' => [
                                'controller' => Controller\PricingController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'settings' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/settings[/:action]',
                            'defaults' => [
                                'controller' => Controller\SettingController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'account' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/account[/:action]',
                            'defaults' => [
                                'controller' => Controller\AccountController::class,
                                'action'     => 'index',
                            ],
                        ],
                    ],
                    'forgot-password' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/forgot-password',
                            'defaults' => [
                                'controller' => Controller\PasswordResetController::class,
                                'action'     => 'forgot',
                            ],
                        ],
                    ],
                    'reset-password' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/reset-password',
                            'defaults' => [
                                'controller' => Controller\PasswordResetController::class,
                                'action'     => 'reset',
                            ],
                        ],
                    ],
                ],
            ],
            'admin-api' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/api/admin/:resource[/:id][/:sub]',
                    'defaults' => [
                        'controller' => Controller\ApiController::class,
                        'action'     => 'dispatch',
                    ],
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            // Mapper/cha-de của container: vẫn closure inline (07 §4 cũ).
            // Service: AppInvokableFactory + AppServiceFactory (07 §4, 13/09/2026).
            AuthenticationService::class => static function (): AuthenticationService {
                return new AuthenticationService(
                    new \Laminas\Authentication\Storage\Session(Constant\AdminConst::AUTH_SESSION_NAMESPACE)
                );
            },
            Model\User\UserMapper::class => static function (ContainerInterface $c): Model\User\UserMapper {
                return new Model\User\UserMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            // Service-locator 13/09: AdminAuthService kế thừa AppServiceFactory,
            // constructor không nhận gì — mồi container qua AppInvokableFactory.
            Service\AdminAuthService::class => AppInvokableFactory::class,
            Service\AuthGuard::class => AppInvokableFactory::class,

            // ---- Model/Mapper (mỗi mapper 1 bảng — chuẩn 07 §5) ----
            Model\Category\CategoryMapper::class => static function (
                ContainerInterface $c
            ): Model\Category\CategoryMapper {
                return new Model\Category\CategoryMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Tag\TagMapper::class => static function (ContainerInterface $c): Model\Tag\TagMapper {
                return new Model\Tag\TagMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\PostTag\PostTagMapper::class => static function (ContainerInterface $c): Model\PostTag\PostTagMapper {
                return new Model\PostTag\PostTagMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Post\PostMapper::class => static function (ContainerInterface $c): Model\Post\PostMapper {
                return new Model\Post\PostMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\PostRevision\PostRevisionMapper::class => static function (
                ContainerInterface $c
            ): Model\PostRevision\PostRevisionMapper {
                return new Model\PostRevision\PostRevisionMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            Model\PostViewDaily\PostViewDailyMapper::class => static function (
                ContainerInterface $c
            ): Model\PostViewDaily\PostViewDailyMapper {
                return new Model\PostViewDaily\PostViewDailyMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            Model\PasswordResetToken\PasswordResetTokenMapper::class => static function (
                ContainerInterface $c
            ): Model\PasswordResetToken\PasswordResetTokenMapper {
                return new Model\PasswordResetToken\PasswordResetTokenMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            Model\HomeSectionItem\HomeSectionItemMapper::class => static function (
                ContainerInterface $c
            ): Model\HomeSectionItem\HomeSectionItemMapper {
                return new Model\HomeSectionItem\HomeSectionItemMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            Model\Banner\BannerMapper::class => static function (ContainerInterface $c): Model\Banner\BannerMapper {
                return new Model\Banner\BannerMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\TeamMember\TeamMemberMapper::class => static function (
                ContainerInterface $c
            ): Model\TeamMember\TeamMemberMapper {
                return new Model\TeamMember\TeamMemberMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            Model\HomeSection\HomeSectionMapper::class => static function (
                ContainerInterface $c
            ): Model\HomeSection\HomeSectionMapper {
                return new Model\HomeSection\HomeSectionMapper(
                    $c->get(\Application\Service\DbService::class)->getAdapter()
                );
            },
            \Frontend\Model\Pricing\PricingMapper::class => static function (ContainerInterface $c): \Frontend\Model\Pricing\PricingMapper {
                return new \Frontend\Model\Pricing\PricingMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },
            Model\Media\MediaMapper::class => static function (ContainerInterface $c): Model\Media\MediaMapper {
                return new Model\Media\MediaMapper($c->get(\Application\Service\DbService::class)->getAdapter());
            },

            // ---- Service ----
            Service\PasswordResetService::class => AppInvokableFactory::class,
            Service\CategoryService::class => AppInvokableFactory::class,
            Service\TagService::class => AppInvokableFactory::class,
            Service\PostService::class => AppInvokableFactory::class,
            // Bảng `services` do mapper của Frontend sở hữu (05-cau-truc §4) —
            // Admin dùng qua cùng class, container hợp nhất toàn cục (07 §4–5).
            Service\ServiceService::class => AppInvokableFactory::class,
            Service\BannerService::class => AppInvokableFactory::class,
            Service\TeamMemberService::class => AppInvokableFactory::class,
            // Hộp thư admin dùng lại ContactMapper/ServiceMapper của Frontend
            // (bảng `contact_submissions` do Frontend sở hữu — 05-cau-truc §4).
            Service\ContactService::class => AppInvokableFactory::class,
            // Trang /admin/settings ghi bảng `settings` qua SettingMapper của
            // Frontend (sở hữu bảng — 05-cau-truc §4), không tạo mapper thứ hai.
            Service\SettingService::class => AppInvokableFactory::class,
            Service\HomeSectionService::class => AppInvokableFactory::class,
            // MediaService điều phối mapper của NHIỀU bảng để gom nơi sử dụng
            // media (FR-38, docs §5.14) — mỗi mapper vẫn chỉ đụng bảng của nó.
            // services/settings reuse mapper Frontend (05-cau-truc §4).
            Service\MediaService::class => AppInvokableFactory::class,
            Service\AccountService::class => AppInvokableFactory::class,
            // Dashboard tổng hợp số liệu từ nhiều bảng — Service điều phối
            // (1 bảng 1 Mapper, không JOIN chéo; docs §3.1)
            Service\DashboardService::class => AppInvokableFactory::class,
            Service\PricingService::class => AppInvokableFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\AuthController::class => static function (ContainerInterface $c): Controller\AuthController {
                return new Controller\AuthController($c->get(Service\AdminAuthService::class));
            },
            Controller\CategoryController::class => static function (
                ContainerInterface $c
            ): Controller\CategoryController {
                return new Controller\CategoryController($c->get(Service\CategoryService::class));
            },
            Controller\TagController::class => static function (ContainerInterface $c): Controller\TagController {
                return new Controller\TagController($c->get(Service\TagService::class));
            },
            Controller\PostController::class => static function (ContainerInterface $c): Controller\PostController {
                return new Controller\PostController(
                    $c->get(Service\PostService::class),
                    $c->get(Service\AdminAuthService::class)
                );
            },
            Controller\ApiController::class => static function (ContainerInterface $c): Controller\ApiController {
                return new Controller\ApiController(
                    $c->get(Service\CategoryService::class),
                    $c->get(Service\TagService::class),
                    $c->get(Service\PostService::class),
                    $c->get(Service\AdminAuthService::class)
                );
            },
            Controller\ServiceController::class => static function (
                ContainerInterface $c
            ): Controller\ServiceController {
                return new Controller\ServiceController($c->get(Service\ServiceService::class));
            },
            Controller\BannerController::class => static function (
                ContainerInterface $c
            ): Controller\BannerController {
                return new Controller\BannerController($c->get(Service\BannerService::class));
            },
            Controller\TeamController::class => static function (ContainerInterface $c): Controller\TeamController {
                return new Controller\TeamController($c->get(Service\TeamMemberService::class));
            },
            Controller\ContactController::class => static function (
                ContainerInterface $c
            ): Controller\ContactController {
                return new Controller\ContactController($c->get(Service\ContactService::class));
            },
            Controller\SettingController::class => static function (
                ContainerInterface $c
            ): Controller\SettingController {
                return new Controller\SettingController(
                    $c->get(Service\SettingService::class),
                    $c->get(Service\AdminAuthService::class)
                );
            },
            Controller\HomeSectionController::class => static function (
                ContainerInterface $c
            ): Controller\HomeSectionController {
                return new Controller\HomeSectionController($c->get(Service\HomeSectionService::class));
            },
            Controller\MediaController::class => static function (
                ContainerInterface $c
            ): Controller\MediaController {
                return new Controller\MediaController(
                    $c->get(Service\MediaService::class),
                    $c->get(Service\AdminAuthService::class)
                );
            },
            Controller\AccountController::class => static function (
                ContainerInterface $c
            ): Controller\AccountController {
                return new Controller\AccountController(
                    $c->get(Service\AccountService::class),
                    $c->get(Service\AdminAuthService::class)
                );
            },
            Controller\PasswordResetController::class => static function (
                ContainerInterface $c
            ): Controller\PasswordResetController {
                return new Controller\PasswordResetController($c->get(Service\PasswordResetService::class));
            },
            Controller\PricingController::class => static function (ContainerInterface $c): Controller\PricingController {
                return new Controller\PricingController($c->get(Service\PricingService::class));
            },
            Controller\DashboardController::class => static function (
                ContainerInterface $c
            ): Controller\DashboardController {
                return new Controller\DashboardController($c->get(Service\DashboardService::class));
            },
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'layout/admin' => __DIR__ . '/../view/layout/admin.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
