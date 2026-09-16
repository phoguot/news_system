# Cấu hình deploy

> App: Laminas MVC 3.8.0, PHP 8.1–8.3, MySQL 8. **Document root phải là `public/`** — toàn bộ `config/`, `module/`, `data/`, `vendor/` và các file HTML design tĩnh ở thư mục gốc (`index.html`, `gioi-thieu.html`, `lien-he.html`, `assets/`) phải nằm **ngoài** document root. `public/index.php` tự `chdir(dirname(__DIR__))` nên mọi đường dẫn tương đối (`data/cache`, `public/uploads`) tính từ gốc repo.

## File config

| File | Commit? | Nội dung | Ghi chú |
|---|---|---|---|
| `config/application.config.php` | Có | Danh sách module + `config_glob_paths` + **`config_cache_enabled => true`**, `cache_dir => 'data/cache/'` | Sau khi sửa config ở prod: `composer clear-config-cache` (hoặc `php bin/clear-config-cache.php`) |
| `config/modules.config.php` | Có | `Laminas\*` → `Application` → `Frontend` → `Admin` (trước 13/09 có `Core` giữa `Application` và `Frontend`) | Không sửa khi deploy |
| `config/autoload/global.php` | Có | `db` (default `127.0.0.1`, `news_system`, `root`, password rỗng, `SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci, time_zone='+00:00'`), `session`, `caches.page_cache` (ttl 60), `mail` (smtp `127.0.0.1:25`), `app` (`upload_dir`, `upload_max_mb=5`, `allowed_mimes`, `image_variants`) | **Password rỗng + `cookie_secure => false` + `display_exceptions => true` chỉ chấp nhận được ở dev** — prod phải override qua `local.php` |
| `config/autoload/local.php.dist` | Có | Khuôn mẫu: `db.*`, `mail.transport.*`, `mail.from`, `recaptcha.site_key/secret_key` | Copy thành `local.php` khi deploy |
| `config/autoload/local.php` | **Không** (`config/autoload/.gitignore`: `local.php`, `*.local.php`) | DB, SMTP, captcha **thật** | Secret nằm ở file PHP này, **không** dùng biến môi trường |
| `config/autoload/development.local.php` | **Không** (`*.local.php`) | Bật `display_exceptions` khi dev | **Không tồn tại** ở prod |
| `config/development.config.php` | **Không** (`.gitignore` gốc) | Do `composer development-enable` tạo; tắt config cache, nạp `*-development.php` | Chạy `composer development-disable` ở prod |
| `module/Application/config/module.config.php` | Có | `display_not_found_reason => true`, `display_exceptions => true` | ⚠ Hai giá trị này **đang `true` trong file commit** → prod **bắt buộc** override `false` trong `local.php` (xem [`../03-tich-hop/02-xu-ly-loi.md`](../03-tich-hop/02-xu-ly-loi.md)) |

**Không có `.env` / không đọc biến môi trường** trong code (kiểm tra: `config/container.php` chỉ `require` file PHP). docs §3.12/§7.4 nói "biến môi trường" — thực tế dự án dùng `local.php`; khi triển khai mail/captcha thì giữ nguyên hướng đó.

## Override bắt buộc cho production (mẫu `config/autoload/local.php`)

```php
<?php
return [
    'db' => [
        'hostname' => '127.0.0.1',
        'database' => 'news_system',
        'username' => 'vanlang_app',          // user riêng, KHÔNG dùng root
        'password' => '<mat-khau-that>',
    ],
    'mail' => [
        'transport' => [
            'options' => [
                'host' => 'smtp.example.com', 'port' => 587,
                'connection_class' => 'login',
                'connection_config' => ['username' => '...', 'password' => '...', 'ssl' => 'tls'],
            ],
        ],
        'from' => 'no-reply@vanlang.vn',
    ],
    'recaptcha' => ['site_key' => '<key>', 'secret_key' => '<secret>'],
    'view_manager' => [
        'display_exceptions' => false,        // che stack trace
        'display_not_found_reason' => false,  // che lý do 404
    ],
    'session' => [
        'config' => ['options' => ['cookie_secure' => true]], // bắt buộc khi có HTTPS
    ],
];
```

Kèm theo ở tầng PHP/web server: `display_errors = Off`, `log_errors = On`, `expose_php = Off` (`php.ini`/FPM pool); `upload_max_filesize` + `post_max_size` ≥ 5 MB (ngưỡng `app.upload_max_mb`), `memory_limit` đủ cho resize ảnh 1600px.

## Quyền ghi

| Đường dẫn | Ai ghi | Dùng cho |
|---|---|---|
| `data/cache/` (bao gồm `data/cache/page/`) | user PHP-FPM/Apache | config cache (`application.config.cache`, `application.module.cache`) + `page_cache` (TTL 60s cho bài hẹn giờ) |
| `public/uploads/` | user PHP-FPM/Apache | media gốc + biến thể. **Thư mục này rỗng và không có `.gitkeep` → phải tự tạo khi checkout**, `chmod 775`, `chown` theo web user |
| `config/autoload/local.php` | chỉ người deploy sửa; **web user phải đọc được** | `chown deploy:www-data config/autoload/local.php && chmod 640` (604/600 sẽ làm PHP-FPM không đọc được config → 500 trắng) |

## Chặn thực thi script trong `public/uploads/` (bắt buộc — README dòng "Ghi chú", docs §7.3)

Repo **không** có `.htaccess` nào. Chọn đúng một cấu hình theo web server:

**Nginx** (block `server`, đặt trước rule xử lý PHP):

```nginx
root /var/www/news/public;
index index.php;

location ^~ /uploads/ {
    # chỉ phục vụ file tĩnh, không bao giờ đưa .php lên PHP-FPM
    location ~* \.(php|phtml|phar|php[0-9]|cgi|pl|py|sh)$ { deny all; return 403; }
    expires 30d;
    access_log off;
}

location / { try_files $uri $uri/ /index.php$is_args$args; }

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

**Apache 2.4** — tạo `public/uploads/.htaccess`:

```apache
# Tắt hoàn toàn PHP + exec trong thư mục uploads
php_flag engine off
SetHandler None
RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar
Options -ExecCGI -Indexes
<FilesMatch "\.(php|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
```

và bật override cho `public/`: `<Directory /var/www/news/public> AllowOverride All Require all granted </Directory>`.

**IIS** — repo đã có `public/web.config` (rewrite về `index.php`, giữ nguyên file tĩnh). Thêm vào `public/uploads/web.config`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <handlers>
      <clear />
      <add name="StaticFile" path="*" verb="*" modules="StaticFileModule"
           resourceType="Either" requireAccess="Read" />
    </handlers>
    <httpErrors existingResponse="Auto" />
  </system.webServer>
</configuration>
```

Kiểm chứng: `curl -s -o /dev/null -w "%{http_code}" https://<host>/uploads/test.php` → phải **403/404**, không được chạy code. Cách an toàn nhất vẫn là **không** lưu file `.php` nào vào `public/uploads` (upload chỉ nhận `app.allowed_mimes`) — phần validate đó **đã triển khai 13/09** (`Admin\Service\MediaService` — trước tài liệu ghi `Core\Service\MediaService`).

## Rewrite về front controller (khi chưa dùng `php -S`)

```apache
# public/.htaccess — tương đương quy tắc trong web.config hiện có
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

## HTTPS

docs §7.3: **bắt buộc HTTPS** + cookie `HttpOnly`/`Secure`/`SameSite=Lax`. Hai vế đầu đã có sẵn (`global.php` đặt `cookie_httponly => true`, `cookie_samesite => 'Lax'`); vế `Secure` đang `false` → bật trong `local.php` như mẫu trên. Mở 80 → 301 về 443; bật HSTS sau khi cert ổn định.

## Cron: KHÔNG cần

Hệ thống **không có job nền nào** (docs ghi chú v1.2, §7.4). Lý do không cần cài crontab:

| Việc thường cần cron | Cách hệ thống xử lý | Bằng chứng trong repo |
|---|---|---|
| Xuất bản bài hẹn giờ | Bài là `status=1` + `publishedAt` tương lai; điều kiện `publishedAt <= NOW()` nằm trong query → tự hiện đúng giờ | docs §3.3.4 mục 1, §5.7 |
| Bài hiện sau `publishedAt` trễ nhất 60s | `caches.page_cache` adapter Filesystem `ttl => 60` | `config/autoload/global.php` |
| Ghi lượt xem | Upsert trực tiếp trong request | docs §5.8 |
| Gửi email liên hệ | Gửi đồng bộ, lỗi thì log để gửi lại | docs §3.9 |
| Dọn revision / token | `DELETE` ngay khi lưu / khi tạo token mới | docs §5.13, §4.4.1 |

> Chỉ cần cron cho **việc vận hành**: chạy `mysqldump` backup hằng ngày (xem [`04-log-giam-sat.md`](04-log-giam-sat.md)) — đó là cron của OS, không phải của ứng dụng. Nếu sau này chấp nhận cron, việc đầu tiên đáng chuyển là gửi email (docs §7.4).

## Các bước deploy

```bash
git clone <repo> /var/www/news && cd /var/www/news
composer install --no-dev --optimize-autoloader
cp config/autoload/local.php.dist config/autoload/local.php && chmod 640 config/autoload/local.php
mysql -u vanlang_app -p -e "CREATE DATABASE news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u vanlang_app -p news_system < data/schema/schema.sql
mysql -u vanlang_app -p news_system < data/schema/seed.sql
php bin/create-admin.php --email=admin@vanlang.vn --name="Quan tri Van Lang"   # chỉ lần đầu
mkdir -p public/uploads data/cache/page
chown -R www-data:www-data data/cache public/uploads && chmod -R ug+rwX data/cache public/uploads
composer development-disable   # đảm bảo prod không bật dev mode
composer clear-config-cache
```

Sau deploy: kiểm `https://<host>/` = 200, `https://<host>/khong-co` = 404 **không** lộ stack trace, `https://<host>/admin/login` mở được. Checklist đầy đủ: [`05-checklist-go-live.md`](05-checklist-go-live.md). Lần deploy sau chỉ đổi `schema.sql` → làm theo [`02-migration-db.md`](02-migration-db.md).
