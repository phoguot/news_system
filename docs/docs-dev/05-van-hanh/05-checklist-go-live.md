# Checklist Go-live

> Bật cho người dùng thật **chỉ khi tất cả mục "Bắt buộc" được tick**. Mục ⚠ = phụ thuộc tính năng **chưa triển khai** trong code (controller/service còn stub) — phải làm xong code trước rồi mới kiểm. Nguồn: docs v1.5 §7 + `README.md` + cấu hình thật trong `config/autoload/`.

## 1. CSDL & dữ liệu nền — Bắt buộc

- [ ] MySQL **8.0.19+**, `SELECT VERSION();` đạt.
- [ ] DB tạo đúng collation: `SELECT @@character_set_database, @@collation_database;` → `utf8mb4` / **`utf8mb4_0900_ai_ci`** (lệnh tạo ở [`../04-huong-dan/01-cai-dat-moi-truong.md`](../04-huong-dan/01-cai-dat-moi-truong.md)).
- [ ] `SHOW TABLES;` = **đủ 16 bảng** theo docs §4.2.
- [ ] `SELECT COUNT(*) FROM settings;` = 19 và `SELECT COUNT(*) FROM home_sections;` = 6 (`data/schema/seed.sql`).
- [ ] **Không** có `FOREIGN KEY`: `SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='news_system' AND CONSTRAINT_TYPE='FOREIGN KEY';` → **0**.
- [ ] **Không** có cột `deletedAt` ở bất kỳ bảng nào (docs v1.5 bỏ xoá mềm).
- [ ] `innodb_ft_min_token_size = 2` đã đặt trong `my.cnf`/`my.ini` + đã restart + đã rebuild `ft_posts_title_excerpt`.
- [ ] Kết nối luôn UTC: `SELECT @@session.time_zone;` trả `+00:00` (`PDO::MYSQL_ATTR_INIT_COMMAND` trong `config/autoload/global.php`).

## 2. Tìm kiếm tiếng Việt — Bắt buộc ⚠

- [ ] `SELECT MATCH(title,excerpt) AGAINST('an' IN NATURAL LANGUAGE MODE) FROM posts LIMIT 1;` chạy được.
- [ ] `/tim-kiem?q=tăm%20sóc` (và một từ 2 ký tự như "an") **trả về bài viết** thay vì rỗng.
- [ ] Trang kết quả mang `noindex` (docs §7.1). *(SearchController đang stub)*

## 3. Bài hẹn giờ — Bắt buộc ⚠

- [ ] Tạo bài `status=1`, `publishedAt` = tương lai +2 phút → **chưa** thấy ở `/`, `/tin-tuc`, `/sitemap.xml`.
- [ ] Đúng `publishedAt` → bài hiện trên trang chủ **trong ≤ 60 giây**, **không** cần chạy lệnh nào (nhờ `publishedAt <= NOW()` + `caches.page_cache.ttl = 60`).
- [ ] Không có **bất kỳ** crontab/systemd timer nào cho ứng dụng (docs §7.4: hệ thống không có job nền).
- [ ] `GET /tin-tuc/{slug}?previewToken=...` với bài nháp → xem được; **không** để bài nháp bị index.

## 4. Xác thực admin — Bắt buộc ⚠ (xem [`03-xac-thuc-phan-quyen.md`](03-xac-thuc-phan-quyen.md))

- [x] Đã chạy `bin/create-admin.php`, đăng nhập được `/admin/login` bằng `password_verify()` thật. ✅ 12/09/2026 (`AdminAuthService` + smoke HTTP).
- [ ] **Mật khẩu mạnh** (≥ 8 ký tự, thực tế nên ≥ 12, không dùng `CHANGE_ME`). *(môi trường prod)*
- [x] Guard chặn `/admin/*` và **`/api/admin/*`** khi chưa đăng nhập — ✅ `Admin\Service\AuthGuard`: 302 về login cho trang, 401 + envelope JSON cho `admin-api*`.
- [x] `logoutAction` huỷ session thật (`clearIdentity` + `session_regenerate_id(true)` rồi redirect).
- [x] Khoá 15 phút sau 5 lần sai (`users.failedLoginCount`, `lockedUntil`) — ✅ verify: lần 5 báo khoá, tài khoản khoá từ chối cả mật khẩu đúng.
- [x] CSRF cho form đăng nhập (`Admin\Filter\Auth\LoginFilter` — InputFilter + validator `Csrf`, hidden render qua `csrfHash()`). ✅ Form liên hệ: `Frontend\Filter\Contact\ContactSaveFilter` CSRF + honeypot `website` + captcha server-side (`CaptchaService`).
- [ ] Bảng `users` chỉ **1 dòng** — không thêm user tay.

## 5. File cấu hình & secret — Bắt buộc

- [ ] `config/autoload/local.php` tồn tại, **không** nằm trong VCS (`git status` sạch; `config/autoload/.gitignore` có `local.php`, `*.local.php`).
- [ ] **Không** còn giá trị `CHANGE_ME` / rỗng: `db.password`, `mail.transport.options.host/port/connection_config.*`, `mail.from`, `recaptcha.site_key/secret_key`.
- [ ] `composer install --no-dev --optimize-autoloader` (không có `laminas-test`, `phpcs`, `psalm` ở prod).
- [ ] `composer development-status` → **disabled**; không còn `config/development.config.php` trên server.
- [ ] `php.ini`/FPM: `display_errors = Off`, `log_errors = On`, `expose_php = Off`; `upload_max_filesize` & `post_max_size` ≥ 5 MB.
- [ ] `view_manager.display_exceptions = false` và `display_not_found_reason = false` đã override trong `local.php` (module `Application` đang commit giá trị `true`).
- [ ] `session.config.options.cookie_secure = true` (đang `false` trong `global.php`) sau khi có HTTPS.
- [ ] Sau mọi sửa config: `composer clear-config-cache`.

## 6. Web server & `public/uploads` — Bắt buộc

- [ ] **Document root = `public/`** — không truy cập được `config/`, `module/`, `vendor/`, `data/` qua HTTP: `curl -s -o /dev/null -w "%{http_code}" https://<host>/config/autoload/local.php` → **404**.
- [ ] **Chặn thực thi script trong uploads** (README: "Ảnh `public/uploads/` cần chặn thực thi script"; docs §7.3). Kiểm chứng: đặt thử `public/uploads/x.php` rồi request → **403/404**, không chạy code. Snippet nginx + Apache `.htaccess` + IIS: [`01-cau-hinh-deploy.md`](01-cau-hinh-deploy.md).
- [ ] `public/uploads/` **tồn tại** (repo để trống, git không giữ thư mục rỗng) + web user ghi được.
- [ ] `data/cache/` và `data/cache/page/` web user ghi được; `data/cache/` **không** public.
- [ ] `options -Indexes` cho `uploads` và `data`; không liệt kê thư mục.
- [ ] **HTTPS** bật, HTTP → 301; cert hợp lệ (docs §7.3 yêu cầu HTTPS).
- [ ] `bin/create-admin.php` **nằm ngoài** docroot (đang đúng: `bin/` không thuộc `public/`) → không gọi được qua URL.
- [ ] Các file design tĩnh ở gốc repo (`index.html`, `gioi-thieu.html`, `lien-he.html`, `index.zip`, `assets/`) **không** được phục vụ (README nói giữ để tham chiếu).

## 7. Mail & chống spam — Bắt buộc ⚠

- [ ] Gửi **liên hệ thật** từ `/lien-he` → DB đã smoke OK (`contact_submissions.status=0`, `INET6_ATON(ip)`, `consentAt` UTC). Còn lại ở prod: email tới `settings.notify_emails` (SMTP prod chưa cấu hình — mail fail chỉ `error_log`, không đổi kết quả).
- [ ] Test SMTP fail (đổi sai password trong `local.php`) → khách **vẫn** thấy "đã nhận", lỗi được ghi log để gửi lại (docs §3.9). Chưa có code mail → **phải làm trước**.
- [ ] `recaptcha` (Turnstile/reCAPTCHA v3) **bật**: khoá `site_key`/`secret_key` đã điền, có widget trong form, verify server-side chặn request thiếu/sai token.
- [ ] Honeypot ẩn + giới hạn **3 lần / IP / 10 phút** → trả **429** (docs §3.9, §5.10).
- [ ] Validate server-side: `fullName`, `email`, `message`, `consent` bắt buộc (docs §3.9).
- [ ] Trang `/lien-he` có liên kết **chính sách bảo mật** (docs §3.9 lưu ý dữ liệu cá nhân); chốt thời hạn lưu liên hệ (vd 24 tháng) + cách xoá/ẩn danh **thủ công** vì không có job nền.

## 8. Media & nội dung — Bắt buộc ⚠

- [ ] Upload 1 ảnh → có file gốc + biến thể `thumb`(400)/`medium`(800)/`large`(1600) + WebP (`app.image_variants`), `media.path` là **đường dẫn tương đối** `YYYY/MM/<random>.<ext>` (docs §3.10).
- [ ] Chặn file > 5 MB, chặn MIME không nằm trong `app.allowed_mimes`, **không cho SVG**, kiểm MIME thật bằng `finfo` (không tin phần mở rộng).
- [ ] Ảnh render qua helper `mediaUrl()` → URL `/uploads/...` mở được từ browser (đặt `srcset` theo biến thể, `loading="lazy"` — docs §7.2).
- [ ] Xoá media đang dùng → **bị chặn**, trả danh sách nơi đang dùng (docs §5.14, HTTP 409).
- [ ] Nội dung HTML qua `HtmlPurifierService` trước khi lưu: `<script>`/`onclick` bị loại, iframe YouTube vẫn được (docs §3.3.4 mục 6).

## 9. Bảo mật nội dung & API — Bắt buộc ⚠

- [ ] 100% query dùng prepared statement (`Laminas\Db` Sql\Select / bound params).
- [ ] Escape mọi dữ liệu động khi in ra `.phtml`.
- [ ] Cấu hình nhạy cảm **không** nằm trong bảng `settings` — chỉ SMTP, khoá captcha, S3 ở `local.php` (docs §3.12, §4.4.7).
- [ ] Kiểm `EXPLAIN` các query danh sách đi đúng index `(…, status, publishedAt)` (docs §7.2).

## 10. SEO — Bắt buộc

- [ ] `/sitemap.xml` trên môi trường thật: liệt kê bài đã xuất bản (`status=1 AND publishedAt <= NOW()`), danh mục, dịch vụ; **không** có nháp/archived/bài hẹn giờ tương lai/trang `tim-kiem`. *(FR-11 đã code 13/09 batch 18 — smoke dev khớp DB 29 URL; riêng `composer serve` vẫn 404 đuôi `.xml` ở tầng web server → mục này kiểm sau rewrite nginx/Apache)*
- [ ] Sitemap cache đã có cơ chế: `sitemap-v1` TTL 60s + Admin forget ngay khi sửa bài/danh mục/dịch vụ (batch 18) — xác nhận prod `data/cache/page` ghi được và key hết hạn đúng; **không** cần tắt config cache.
- [ ] Có `public/robots.txt` (route này **chưa tồn tại** — docs §6.1 liệt kê, thực tế `curl /robots.txt` → 404): trỏ `Sitemap: https://<host>/sitemap.xml`, `Disallow: /tim-kiem`, `Disallow: /admin`.
- [ ] `<title>` trang không còn là **"Laminas MVC Skeleton"** — *(14/09/2026: đã bật `view_manager.layout => 'layout/frontend'` trong config Frontend, trang công khai render bằng `layout/frontend.phtml`)*. Còn lại: `headTitle` đang hard-code "Vạn Lang" trong layout, chưa đọc theo `settings.site_name` — chốt trước go-live.
- [ ] `<title>` + meta description + canonical cho trang chủ/bài/danh mục/dịch vụ; OG & Twitter Card dùng `bannerMediaId` làm `og:image`; JSON-LD `NewsArticle`/`Organization`/`BreadcrumbList` (docs §7.1).
- [ ] Trang `tim-kiem` và trang preview gắn `noindex`.
- [ ] Google Fonts tự host **hoặc** chấp nhận phụ thuộc CDN; đo LCP mobile < 2,5s (docs §7.2).

## 11. Sao lưu & giám sát — Bắt buộc (docs §7.4)

- [ ] Cron backup **DB hằng ngày, giữ 30 bản**, `mysqldump --single-transaction` + backup `public/uploads`.
- [ ] Bản sao lưu **khác máy chủ chính** (rsync/s3).
- [ ] Khôi phục **đã diễn tập** ít nhất 1 lần trên môi trường khác (restore DB + uploads, site chạy được).
- [ ] Uptime check cho `https://<host>/` + cảnh báo email/Telegram.
- [ ] `error_log` PHP trỏ vào file có logrotate; access log bật; kiểm 5xx (xem [`04-log-giam-sat.md`](04-log-giam-sat.md)).

## 12. Kiểm cuối (5 phút)

- [ ] `composer test` pass; `composer cs-check` + `composer static-analysis` sạch trên các file đã đụng.
- [ ] `curl -I https://<host>/` → 200; `curl -I https://<host>/khong-co` → 404 **không lộ stack trace** (baseline cũ `GET /api/contact` 500 **đã hết** — route giờ 302 về `/lien-he`; lỗi khác xem [`../03-tich-hop/02-xu-ly-loi.md`](../03-tich-hop/02-xu-ly-loi.md)).
- [ ] `curl -s -X POST https://<host>/api/admin/posts` khi **chưa đăng nhập** → **401/redirect**, không trả `{"success":true}` như hiện tại.
- [ ] Gửi 1 liên hệ thật → thấy trong `/admin/contacts` + nhận email.
- [ ] Đăng bài test → xem từ frontend → **Lưu trữ** (không Xoá) → ẩn khỏi site → **Xoá** → biến mất vĩnh viễn (docs §3.3.1: ưu tiên Lưu trữ, xoá là không khôi phục).
- [ ] Mở `/admin` bằng trình duyệt ẩn danh → bị chặn.
- [ ] Tài liệu vận hành (`05-van-hanh/`) đã được người vận hành đọc.

## Điểm cần chốt trước go-live

| # | Câu hỏi | Quyết định |
|---|---|---|
| 1 | Dịch vụ có trang chi tiết riêng hay chỉ hiện ở trang chủ? (docs §9.1) | ☐ có ☐ không |
| 2 | Bao nhiêu người dùng trong tương lai? → nếu > 1 thì phải dựng lại `roles`/`activity_logs` (docs §9.9) | ☐ luôn 1 ☐ sẽ mở rộng |
| 3 | Gửi mail liên hệ **đồng bộ** (request chờ SMTP) hay mở cron riêng cho mail? (docs §9.11) | ☐ đồng bộ ☐ cron |
| 4 | Thống kê lượt xem theo ngày **UTC** hay giờ VN (UTC+7)? (docs §9.10) | ☐ UTC ☐ VN |
| 5 | Thời hạn lưu dữ liệu liên hệ: bao lâu, xoá hẳn hay ẩn danh? (docs §9.12) | ___ tháng |
| 6 | Có chấp nhận phụ thuộc Google Fonts CDN, hay tự host font? | ☐ CDN ☐ tự host |
| 7 | Ai chạy backup/restore và diễn tập mỗi quý? (docs §7.4) | người: ___ |
