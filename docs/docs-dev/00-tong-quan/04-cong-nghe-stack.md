# Công nghệ & Stack

Toàn bộ phiên bản lấy trực tiếp từ `composer.json` (constraint Composer). Cột **constraint** giữ nguyên ký hiệu `^` / `~` của Composer, không suy ra version cụ thể.

## Yêu cầu hệ thống

| Thành phần | Phiên bản / yêu cầu | Ghi chú (nguồn) |
|---|---|---|
| Ngôn ngữ | PHP `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0` | `composer.json` → `require.php` |
| Hệ quản trị CSDL | MySQL 8.0.19+ | README; cần cú pháp `INSERT ... AS newRow` (upsert lượt xem) |
| Engine / charset | InnoDB · `utf8mb4` · collation `utf8mb4_0900_ai_ci` | docs v1.5 §4.1 · `schema.sql` |
| Quản lý package | Composer 2.x | README |
| Extension PHP | `pdo_mysql`, `gd` **hoặc** `imagick`, `fileinfo` | README (`gd`/`imagick` cho Intervention Image; `fileinfo` kiểm MIME upload) |
| Web server | Apache **hoặc** PHP built-in server (`composer serve`) | README / `public/web.config` |

> Lưu ý: README ghi "Laminas MVC **3.8**" nhưng `composer.json` khai báo `laminas/laminas-mvc: ^3.7.0`. Xem mục **Điểm chưa khớp**.

## Framework & thư viện chính (`require`)

| Package | Constraint | Vai trò |
|---|---|---|
| `laminas/laminas-mvc` | `^3.7.0` | Nền MVC: router, dispatch, controller, view |
| `laminas/laminas-db` | `^2.20` | Adapter `Pdo_Mysql` + `Laminas\Db\Sql` cho tầng Mapper (`Model/<Entity>/<Entity>Mapper`) |
| `laminas/laminas-form` | `^3.20` | Còn trong `require` nhưng **không còn class nào dùng** — validate bằng InputFilter thuần (`LoginFilter`, `ContactSaveFilter`, chuẩn 07 §6) |
| `laminas/laminas-inputfilter` | `^2.32` | Validate/sanitize input — mọi filter kế thừa nền tảng `Application\Filter\AppInputFilter` (07 §6, 13/09) |
| `laminas/laminas-filter` | `^2.41` | Bộ lọc dữ liệu (dùng bởi form/inputfilter) |
| `laminas/laminas-validator` | `^2.64` | Rule kiểm tra dữ liệu |
| `laminas/laminas-hydrator` | `^4.16` | Ánh xạ row ↔ object |
| `laminas/laminas-session` | `^2.23` | Phiên đăng nhập admin (cookie `VANLANG_SESS`) |
| `laminas/laminas-authentication` | `^2.18` | Xác thực tài khoản quản trị |
| `laminas/laminas-cache` | `^3.13` | Abstraction cache (Trang chủ, settings) |
| `laminas/laminas-cache-storage-adapter-filesystem` | `^2.5` | Adapter Filesystem cho `page_cache` & config cache |
| `laminas/laminas-paginator` | `^2.19` | Phân trang tin tức / danh sách admin |
| `laminas/laminas-mail` | `^2.25` | Gửi email liên hệ (SMTP, đồng bộ) |
| `laminas/laminas-i18n` | `^2.28` | Bộ lọc/i18n (hỗ trợ sinh slug tiếng Việt) |
| `ezyang/htmlpurifier` | `^4.18` | Lọc XSS nội dung rich-text trước khi lưu |
| `intervention/image` | `^3.11` | Resize/đổi định dạng, tạo biến thể thumb/medium/large |
| `ramsey/uuid` | `^4.7` | Chuỗi ngẫu nhiên đặt tên file upload |
| `laminas/laminas-component-installer` | `^3.4.0` | Tự ghi component vào `modules.config.php` khi `composer require` |
| `laminas/laminas-development-mode` | `^3.12.0` | Bật/tắt `development.config.php` |
| `laminas/laminas-skeleton-installer` | `^1.3.0` | Installer của skeleton lúc khởi tạo dự án |

## Công cụ phát triển (`require-dev`)

| Package | Constraint | Vai trò | Lệnh |
|---|---|---|---|
| `phpunit/phpunit` | `^10.4` | Unit test (kịch bản `ApplicationTest`, `FrontendTest`, `AdminTest` — `CoreTest` đã gộp về `ApplicationTest` từ 13/09) | `composer test` |
| `laminas/laminas-test` | `^4.9` | Hạ tầng test controller/route Laminas | — |
| `squizlabs/php_codesniffer` | `^3.7` | Check/format coding standard (`phpcs.xml`) | `composer cs-check` / `cs-fix` |
| `dealerdirect/phpcodesniffer-composer-installer` | `^1.0` | Tự đăng chuẩn ruleset vào PHP_CodeSniffer | — |
| `vimeo/psalm` | `^5.13` | Phân tích tĩnh kiểu (`psalm.xml`, `.psalm-stubs.phpstub`) | `composer static-analysis` |
| `psalm/plugin-phpunit` | `^0.19.0` | Cầu nối Psalm ↔ PHPUnit | — |

## Composer scripts (trích `composer.json`)

| Script | Lệnh |
|---|---|
| `serve` | `php -S 0.0.0.0:8080 -t public` |
| `test` | `vendor/bin/phpunit` |
| `cs-check` / `cs-fix` | `vendor/bin/phpcs` / `vendor/bin/phpcbf` |
| `static-analysis` | `vendor/bin/psalm --stats` |
| `clear-config-cache` | `php bin/clear-config-cache.php` (chạy tự động sau `install`/`update`) |
| `development-enable` / `-disable` / `-status` | `laminas-development-mode …` |

## Autoload (PSR-4)

| Namespace | Thư mục | Test namespace |
|---|---|---|
| `Application\` | `module/Application/src/` | `ApplicationTest\` → `module/Application/test/` |
  
| ~~`Core\`~~ | ~~`module/Core/src/`~~ | ~~`CoreTest\`~~ — đã gộp về `Application` từ 13/09/2026 |
| `Frontend\` | `module/Frontend/src/` | `FrontendTest\` → `module/Frontend/test/` |
| `Admin\` | `module/Admin/src/` | `AdminTest\` → `module/Admin/test/` |

## Môi trường chạy

- **Dev:** `composer serve` (PHP built-in, cổng 8080) hoặc Apache document root `public/`. Bật `composer development-enable` để nạp `config/development.config.php` + `autoload/development.local.php`.
- **Config:** `config/autoload/global.php` (DB/session/cache/mail/app — commit); secrets (DB/SMTP/captcha) đặt ở `config/autoload/local.php` (gitignore, copy từ `local.php.dist`). Xong chạy `composer clear-config-cache` để áp dụng.
- **Schema/Seed:** `data/schema/schema.sql` (18 bảng) → `data/schema/seed.sql` (settings + home_sections/menu mặc định) → `php bin/create-admin.php` tạo tài khoản admin duy nhất. Không có công cụ migration version tự động; thay đổi schema theo quy trình ở [05-van-hanh/02-migration-db.md](../05-van-hanh/02-migration-db.md).

## Điểm chưa khớp (cần xác nhận)

| Hạng mục | `composer.json` | README / code | Hướng xử lý |
|---|---|---|---|
| Phiên bản Laminas MVC | `^3.7.0` | README ghi "Laminas MVC 3.8" | Constraint `^3.7.0` cho phép 3.8; bản cài thật xem `composer.lock`. Không sửa source, chỉ ghi nhận. |
| `laminas-form` / `inputfilter` | đã `require` | ✅ Form đăng nhập + liên hệ validate bằng **InputFilter thuần** (`Admin\Filter\Auth\LoginFilter`, `Frontend\Filter\Contact\ContactSaveFilter` — chuẩn 07 §6); `laminas-form` hiện không còn lớp nào dùng | Các form CRUD admin khác chưa có. |
| `laminas-authentication` | đã `require` | ✅ `AuthenticationService` + `Session` storage ns `VANLANG_ADMIN_AUTH` qua `AdminAuthService` | Xác thực session đã triển khai — xem [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md). |
| `intervention/image` · `htmlpurifier` · `ramsey/uuid` | đã `require` | `MediaService` / `HtmlPurifierService` / `SlugService` đều là class rỗng | Thư viện sẵn sàng, logic chưa viết. |
