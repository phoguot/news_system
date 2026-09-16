# Yêu cầu phi chức năng

> Từ [docs §7](../../phan-tich-he-thong-website-tin-tuc.md), đối chiếu cấu hình thật trong `config/autoload/global.php`, `config/autoload/local.php.dist`, `bin/create-admin.php`. Ràng buộc kiến trúc: **một quản trị viên duy nhất, không phân quyền, không cronjob/job nền, xoá cứng** (docs §1.3, §12 lưu ý 7.4).

## 1. SEO (docs §7.1)

| Mã | Yêu cầu | Nguồn |
|---|---|---|
| NFR-SEO-1 | Slug không dấu, ngắn; `<title>` + meta description + canonical cho mọi trang | §7.1; `SlugService` |
| NFR-SEO-2 | Open Graph + Twitter Card; ảnh banner bài viết dùng làm `og:image` | §7.1; `posts.bannerMediaId` |
| NFR-SEO-3 | JSON-LD: `NewsArticle` (bài), `Organization` (trang chủ), `BreadcrumbList` (trang con) | §7.1 |
| NFR-SEO-4 | `sitemap.xml` tự sinh từ bài/danh mục/dịch vụ, cache; cập nhật khi nội dung đổi | §6.1; route `sitemap` |
| NFR-SEO-5 | Trang `?previewToken=` và `/tim-kiem` gắn `noindex` | §3.3.2, §7.1 |
| NFR-SEO-6 | `robots.txt` phục vụ tĩnh ở `public/` (hiện chưa có file — cần bổ sung) | §6.1; kiểm tra `public/` |
| NFR-SEO-7 | URL bài viết **không** chứa slug danh mục; hạn chế đổi slug đã lên top (hệ thống không có redirect) | §2.1, §7.1 |

## 2. Hiệu năng (docs §7.2)

| Mã | Yêu cầu | Nguồn |
|---|---|---|
| NFR-PERF-1 | Cache trang chủ + menu danh mục + settings (Filesystem `page_cache`); xoá theo sự kiện | §7.2; `global.php` `caches.page_cache` |
| NFR-PERF-2 | Cache có bài viết **TTL ≤ 60s** để bài hẹn giờ tự xuất hiện đúng lúc (không cron) | §5.7; `global.php` `ttl => 60` |
| NFR-PERF-3 | Danh sách luôn qua index `(status, publishedAt)`; kiểm `EXPLAIN` trước go-live | §4.4.3, §7.2 |
| NFR-PERF-4 | Phân trang `?page=` cho trang tin/danh mục/tìm kiếm (`Laminas\Paginator`) | §5.1–5.3, §6.1; `modules.config.php` |
| NFR-PERF-5 | Ảnh WebP + `srcset` biến thể `thumb/medium/large`; `loading="lazy"` ảnh dưới fold | §7.2; `media.variants`, `image_variants` global.php |
| NFR-PERF-6 | Phục vụ ảnh/tĩnh qua CDN khi lưu lượng tăng (đường dẫn media lưu tương đối để dễ chuyển S3/CDN) | §3.10, §7.2 |
| NFR-PERF-7 | Mục tiêu LCP < 2,5s trên mobile (trang chủ & chi tiết) | §7.2 |
| NFR-PERF-8 | Ghi lượt xem trực tiếp bằng upsert trong request (chấp nhận được với traffic doanh nghiệp thấp) | §3.3.4(9), §5.8 |

## 3. Bảo mật (docs §7.3)

| Mã | Yêu cầu | Nguồn |
|---|---|---|
| NFR-SEC-1 | Toàn bộ query dùng **prepared statement** (`Laminas\Db`/PDO), không nối chuỗi | §7.3; `bin/create-admin.php` `prepare()` |
| NFR-SEC-2 | Lọc XSS nội dung HTML bằng **HTMLPurifier** trước khi lưu; escape mọi dữ liệu khi in template (`escapeHtml`) | §3.3.4(6), §7.3; `ezyang/htmlpurifier`, `HtmlPurifierService` |
| NFR-SEC-3 | Mật khẩu `password_hash()` (min 8 ký tự), verify `password_verify()`; **không** có bảng role/permission | §3.11; `bin/create-admin.php` |
| NFR-SEC-4 | Khoá tạm tài khoản sau 5 lần đăng nhập sai (`failedLoginCount`, `lockedUntil` 15') | §3.11; `schema.sql` `users` |
| NFR-SEC-5 | Quên mật khẩu: lưu **hash token** (`tokenHash CHAR(64)`), hạn 60', dùng 1 lần, xoá token cũ khi tạo mới | §3.11, §4.4.1; `password_reset_tokens` |
| NFR-SEC-6 | Phiên: cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` khi prod HTTPS), validator `RemoteAddr`+`HttpUserAgent`, `gc_maxlifetime=7200` | §7.3; `global.php` `session` |
| NFR-SEC-7 | CSRF token cho form; form liên hệ + captcha (`recaptcha` site/secret trong `local.php`) | §7.3; `config/autoload/local.php.dist` |
| NFR-SEC-8 | **Rate-limit liên hệ theo IP**: ≥3 lần / 10 phút → từ chối HTTP 429; honeypot | §3.9, §5.10; `idx_contact_submissions_ip_created` |
| NFR-SEC-9 | Upload: kiểm MIME thật (`finfo`), chặn SVG, giới hạn 5MB, đổi tên ngẫu nhiên; **cấm thực thi script trong `public/uploads/`** ở web server | §3.10, §7.3; `global.php` `app.allowed_mimes/upload_max_mb` |
| NFR-SEC-10 | Xác thực phiên ở **mọi** API quản trị (`/api/admin/*`), không tin vào việc ẩn nút UI | §7.3 |
| NFR-SEC-11 | Cấu hình nhạy cảm (SMTP, khoá captcha, S3) nằm ở env/`local.php` — **không** lưu vào `settings` | §3.12, §4.4.7 |
| NFR-SEC-12 | Bản đồ lưu **địa chỉ hoặc URL nhúng**, không lưu `<iframe>` admin dán (chống XSS); FE ưu tiên địa chỉ để đổi địa chỉ là đổi map | §3.12; `map_address`, `address`, `map_embed_url` |
| NFR-SEC-13 | Dữ liệu cá nhân liên hệ: checkbox consent (`consentAt`), trang chính sách, thời hạn lưu (ẩn danh/xoá sau ~24 tháng, thủ công) | §3.9 |

## 4. Vận hành & sao lưu (docs §7.4)

| Mã | Yêu cầu | Nguồn |
|---|---|---|
| NFR-OPS-1 | **Sao lưu DB hằng ngày (giữ 30 bản) + thư mục `public/uploads/`**, lưu ngoài máy chủ chính — bắt buộc vì xoá cứng, không thùng rác | §7.4, §4.6 |
| NFR-OPS-2 | Diễn tập khôi phục mỗi quý, gồm khôi phục 1 bài viết bị xoá nhầm | §7.4 |
| NFR-OPS-3 | **Không có job định kỳ**; các việc đổi thành xử lý đồng bộ/thủ công (bảng dưới) | §7.4 |
| NFR-OPS-4 | Giám sát lỗi ứng dụng (VD Sentry) + uptime | §7.4 |
| NFR-OPS-5 | Yêu cầu môi trường: PHP 8.1–8.3, MySQL 8.0.19+, extension `pdo_mysql`, `gd`/`imagick`, `fileinfo` | README «Yêu cầu», `composer.json` |

**Không cronjob — cách thay thế (§7.4):**

| Việc thường giao cron | Cách dự án xử lý | Nguồn |
|---|---|---|
| Xuất bản bài hẹn giờ | `status=1 AND publishedAt <= NOW()` trong query + cache TTL ≤ 60' | §5.7 |
| Ghi lượt xem | upsert trực tiếp trong request | §5.8 |
| Email thông báo liên hệ | gửi **đồng bộ** sau khi lưu, timeout ngắn, lỗi → log để gửi lại | §3.9 |
| Dọn revision thừa | `DELETE` ngay sau khi tạo revision (giữ 20) | §5.13 |
| Dọn token reset mật khẩu | xoá token cũ của user khi tạo token mới | §4.4.1 |
| Ẩn danh liên hệ quá hạn | quản trị viên làm thủ công từ giao diện | §3.9 |

## 5. Chất lượng code

| Mã | Yêu cầu | Nguồn |
|---|---|---|
| NFR-QA-1 | PSR-12 + Laminas style; `vendor/bin/phpcs` sạch | `phpcs.xml` |
| NFR-QA-2 | Psalm `errorLevel=1`, `findUnusedCode`; `vendor/bin/psalm --stats` sạch | `psalm.xml` |
| NFR-QA-3 | Unit/feature test qua `laminas-test`; `vendor/bin/phpunit` pass | `phpunit.xml.dist`, `composer.json` |

> Ràng buộc DB kèm theo ở [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md); cách hiện thực ở [`../01-quy-chuan/03-quy-chuan-code.md`](../01-quy-chuan/03-quy-chuan-code.md).
