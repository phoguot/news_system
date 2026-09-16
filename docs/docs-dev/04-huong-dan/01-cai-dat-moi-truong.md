# Cài đặt môi trường

> Cho **Van Lang — News CMS** (Laminas MVC 3.8, PHP 8.1–8.3, MySQL 8). Lệnh lấy nguyên từ [`../../../README.md`](../../../README.md) và [`../../../data/schema/README.md`](../../../data/schema/README.md); đường dẫn config đã kiểm chứng trong repo.

## Yêu cầu

| Thành phần | Phiên bản / yêu cầu | Cách kiểm tra |
|---|---|---|
| PHP | 8.1 / 8.2 / 8.3 (`composer.json`: `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0`) | `php -v` |
| Extension PHP | `pdo_mysql`, `gd` **hoặc** `imagick` (Intervention Image), `fileinfo` (kiểm MIME upload). Khuyến nghị thêm `intl` (cho `laminas-i18n`), `mbstring`, `openssl` | `php -m` |
| Composer | 2.x | `composer --version` |
| MySQL | 8.0.19+ (cần `INSERT ... AS newRow` docs §5.8, `utf8mb4_0900_ai_ci`) | `mysql --version` |
| Web server | Không bắt buộc khi dev — dùng `php -S` (builtin server đã có sẵn logic trả file tĩnh trong `public/index.php`) | — |

> **Cấu hình MySQL cho tìm kiếm tiếng Việt:** đặt `innodb_ft_min_token_size = 2` trong `[mysqld]` của `my.ini`/`my.cnf` rồi khởi động lại MySQL, sau đó rebuild index `ft_posts_title_excerpt` — mặc định MySQL bỏ qua từ < 3 ký tự, làm mất âm tiết tiếng Việt ("an", "đi"...). Nguồn: `data/schema/README.md`, docs §4.4.3. Nên đặt **trước khi** import `schema.sql`.

## Các bước

```bash
# 1. Dependency (script post-install-cmd tự chạy clear-config-cache)
composer install

# 2. Config local — TÊN FILE THẬT là local.php.dist (không phải .env)
cp config/autoload/local.php.dist config/autoload/local.php
#   sửa trong local.php: db.password, mail.transport (host/port/auth),
#   mail.from, recaptcha.site_key + recaptcha.secret_key
#   local.php bị ignore bởi config/autoload/.gitignore (`local.php`, `*.local.php`) — không bao giờ commit

# 3. Tạo CSDL + import schema (16 bảng) + seed (settings + home_sections mặc định)
mysql -u root -p -e "CREATE DATABASE news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p news_system < data/schema/schema.sql
mysql -u root -p news_system < data/schema/seed.sql

# 4. Tài khoản admin duy nhất (hỏi mật khẩu tương tác, tối thiểu 8 ký tự)
php bin/create-admin.php --email=admin@vanlang.vn --name="Quan tri Van Lang" --username=admin
#   --username optional (3–32 ký tự thường, không dấu, bắt đầu bằng chữ); có thể thêm --phone=...  |  LỆNH TỪ CHỐI nếu bảng users đã có ≥ 1 dòng (hệ chỉ 1 admin, docs §1.3)

# 5. Chạy dev
composer serve            # = php -S 0.0.0.0:8080 -t public
# hoặc trực tiếp:
php -S 0.0.0.0:8080 -t public
```

### Tuỳ chọn cho dev (dev mode Laminas)

```bash
composer development-enable    # copy development.local.php.dist → development.local.php + tạo config/development.config.php
composer development-status
composer development-disable   # xoá config/development.config.php (file này bị gitignore)
```

Bật dev mode sẽ: tắt config cache, bật `view_manager.display_exceptions` (`config/autoload/development.local.php`) và nạp `config/autoload/laminas-developer-tools.local-development.php` (debug toolbar). **Production phải ở chế độ disable.**

### Phân quyền thư mục

| Đường dẫn | Quyền | Dùng cho |
|---|---|---|
| `data/cache/` | web server ghi được | config cache (`application.config.cache`, `application.module.cache`) + `data/cache/page/` cho `page_cache` (ttl 60s, `config/autoload/global.php`) |
| `public/uploads/` | web server ghi được | media theo `YYYY/MM/<random>.<ext>` (docs §3.10). **Thư mục này đang rỗng và không có `.gitkeep` → git không track; phải tự tạo khi deploy** |

### Sửa config mà không ăn

Config đã merge + cache → chạy `composer clear-config-cache` (hoặc `php bin/clear-config-cache.php`) rồi tải lại.

## Kiểm tra cài đặt thành công

- [ ] `composer install` không lỗi, có thư mục `vendor/` (đã bao gồm `vendor/laminas/laminas-mail`, `vendor/intervention/image`, `vendor/ezyang/htmlpurifier`).
- [ ] `config/autoload/local.php` tồn tại và **không** xuất hiện trong `git status`.
- [ ] `mysql -u root -p news_system -e "SHOW TABLES;"` trả **đúng 16 bảng** (`users`, `password_reset_tokens`, `media`, `categories`, `tags`, `posts`, `post_tags`, `post_revisions`, `post_view_daily`, `services`, `banners`, `home_sections`, `home_section_items`, `team_members`, `contact_submissions`, `settings`).
- [ ] `SELECT COUNT(*) FROM settings;` > 0 và `SELECT COUNT(*) FROM home_sections;` = 6 (từ `data/schema/seed.sql`).
- [ ] `php bin/create-admin.php ...` in `Admin created: <email> (id 1)`; chạy lần 2 bị **từ chối** (`Refusing: users table already has 1 row(s)`).
- [ ] `vendor/bin/phpunit` → `OK (4 tests, 7 assertions)` (suite skeleton, xem [`03-huong-dan-test.md`](03-huong-dan-test.md)).
- [ ] `http://localhost:8080/` trả HTTP **200** và in nội dung stub `<h1>Vạn Lang - Trang chủ</h1>` (`module/Frontend/view/frontend/home/index.phtml`).
- [ ] Các route công khai trả 200: `/tin-tuc`, `/tin-tuc/{slug}`, `/danh-muc/{slug}`, `/tag/{slug}`, `/tim-kiem`, `/dich-vu`, `/doi-ngu`, `/lien-he`.
- [ ] `/admin` và `/admin/login` trả 200 — **mở được mà chưa cần đăng nhập** (chưa có guard, xem [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md)).
- [ ] `http://localhost:8080/khong-ton-tai` trả **404** với trang `error/404` ("A 404 error occurred").
- [ ] Ghi được file vào `data/cache/` và `public/uploads/`.

> **Hai hành vi đã đo trực tiếp bằng `php -S` (cần biết khi kiểm cài đặt):**
> 1. **`/sitemap.xml` trả 404 khi chạy `composer serve`** — PHP built-in server tự xử lý request có phần mở rộng `.xml` như file tĩnh, không đưa vào `public/index.php`, nên route `sitemap` không chạy tới. Muốn test sitemap phải dùng web server có rewrite (Apache/nginx/IIS) hoặc router script riêng. `robots.txt` cũng chưa tồn tại → 404.
> 2. **`GET`/`POST /api/contact` trả 500** (`Laminas\View\Exception\RuntimeException: Unable to render template "frontend/contact/submit"`) vì action stub trả `ViewModel` mà không có file view. Đây là **lỗi scaffold đã biết**, không phải lỗi cài đặt của bạn.
> 3. ~~Trang không dùng layout của dự án~~ — **đã sửa 14/09/2026**: `view_manager.layout => 'layout/frontend'` được đặt trong `module/Frontend/config/module.config.php` nên trang công khai render bằng `layout/frontend.phtml` (CSS `assets/css/style.css`, header/footer Vạn Lang). Trang Admin không đổi: `AuthGuard` setTemplate `layout/admin` lúc runtime khi đăng nhập; login/reset là terminal view. (Trước mốc này mọi trang rơi vào layout skeleton `module/Application/view/layout/layout.phtml` — `<title>` "Laminas MVC Skeleton", hero slider trang chủ vỡ giao diện vì CSS site không được nạp.)

> **Trạng thái repo khi vừa clone (scaffold):** các trang đều **render được** nhưng nội dung là stub — controller Frontend/Admin trả `ViewModel`/`JsonModel` rỗng (`module/Frontend/src/Controller/*.php`), `DbService`/`SlugService`/`HtmlPurifierService` là class **không có body** (`module/Application/src/Service/` — trước 13/09 là `module/Core/...`); `MediaService` thuộc `module/Admin/src/Service/`. Đăng nhập `/admin/login` **chưa** kiểm tra mật khẩu và **chưa** có guard chặn `/admin`. Chi tiết: [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md).
>
> `index.html`, `gioi-thieu.html`, `lien-he.html`, `assets/` ở **thư mục gốc** là bản design tĩnh để tham chiếu, **không** nằm trong `public/` → không được web phục vụ (README mục Ghi chú).
