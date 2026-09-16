<?php

return [
    'db' => [
        'driver'   => 'Pdo_Mysql',
        'hostname' => '127.0.0.1',
        'database' => 'news_system',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'driver_options' => [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci, time_zone = '+00:00'",
        ],
    ],
    // laminas-session v2: các factory đọc đúng 3 key dưới đây
    // (SessionConfigFactory/StorageFactory/SessionManagerFactory).
    'session_config' => [
        'name'                => 'VANLANG_SESS',
        'cookie_httponly'     => true,
        'cookie_secure'       => false,
        'cookie_samesite'     => 'Lax',
        'gc_maxlifetime'      => 7200,
        'remember_me_seconds' => 7200,
    ],
    'session_storage' => [
        'type'    => Laminas\Session\Storage\SessionArrayStorage::class,
        'options' => [],
    ],
    'session_manager' => [
        'enable_default_container_manager' => true,
        'validators' => [
            Laminas\Session\Validator\RemoteAddr::class,
            Laminas\Session\Validator\HttpUserAgent::class,
        ],
    ],
    // Schema laminas-cache v3: 'adapter' là chuỗi class, 'options'/'plugins' để
    // top-level (schema v2 lồng `adapter.name` bị v3 từ chối — StorageAdapterFactory
    // assertValidConfigurationStructure). Service `page_cache` resolve qua
    // StorageCacheAbstractServiceFactory (trừu tượng factory của module Laminas\Cache
    // đã nạp trong modules.config.php). TTL 60s: bài hẹn giờ tự xuất hiện đúng giờ
    // không cần cron (NFR-PERF-1/2). exceptionhandler chặn ném lỗi IO → cache chỉ
    // là tăng tốc, không phải dependency cứng. KHÔNG bật plugin 'serializer'
    // (cần package laminas-serializer — PageCacheService tự serialize payload).
    'caches' => [
        'page_cache' => [
            'adapter' => Laminas\Cache\Storage\Adapter\Filesystem::class,
            'options' => [
                'cache_dir' => 'data/cache/page',
                'ttl'       => 60,
            ],
            'plugins' => [
                [
                    'name'    => Laminas\Cache\Storage\Plugin\ExceptionHandler::class,
                    'options' => ['throw_exceptions' => false],
                ],
            ],
        ],
    ],
    'mail' => [
        'transport' => [
            'type' => 'smtp',
            'options' => [
                'host'              => '127.0.0.1',
                'port'              => 25,
                'connection_class'  => 'plain',
                'connection_config' => [
                    'username' => '',
                    'password' => '',
                ],
            ],
        ],
        'from' => 'no-reply@vanlang.local',
        // FR-13: base_url dùng để dựng liên kết tuyệt đối trong email đặt lại mật khẩu.
        // Override bằng local.php `mail.base_url` hoặc `app.site_url` khi deploy.
        'base_url' => '',
    ],
    'app' => [
        'site_name'      => 'Vạn Lang',
        'site_url'       => '', // FR-13 fallback: dùng khi mail.base_url trống
        'upload_dir'     => 'public/uploads',
        'upload_max_mb'  => 5,
        'allowed_mimes'  => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'image_variants' => ['thumb' => 400, 'medium' => 800, 'large' => 1600],
    ],
];
