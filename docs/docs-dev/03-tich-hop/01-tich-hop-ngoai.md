# Tích hợp bên ngoài

> Hiện trạng repo: khung Laminas MVC đã nối xong đường dây (config, route, schema, dependency `composer.json`), nhưng **nghiệp vụ trong controller/service còn là stub**. Cột "Trạng thái" ghi thẳng: code đã chạy được, hay mới có cấu hình/thiết kế nhưng **chưa triển khai** logic.
> Bối cảnh nghiệp vụ: [`../../phan-tich-he-thong-website-tin-tuc.md`](../../phan-tich-he-thong-website-tin-tuc.md) (docs v1.5) §3.9, §3.10, §7.3.

## Danh mục tích hợp

| Hệ thống | Loại | Cấu hình (key trong `config/autoload/`) | Thư viện (composer) | Trạng thái |
|---|---|---|---|---|
| MySQL 8 | CSDL | `db` (`global.php`, override ở `local.php`) | `laminas/laminas-db` | Cấu hình thật; `Application\Service\DbService` (trước 13/09 là `Core\`) — **đã triển khai** (Adapter + `transactional()`) |
| SMTP mail | Gửi email | `mail.transport` + `mail.from` (`global.php` mặc định; `local.php` override host/port/auth) | `laminas/laminas-mail` | **Mới cấu hình.** Không có file code nào gọi `Laminas\Mail` → email liên hệ **chưa triển khai** |
| Captcha | Chống spam form | `recaptcha.site_key`, `recaptcha.secret_key` (`local.php.dist`) | không có thư viện captcha | **Chưa triển khai** — có chỗ khai báo khoá, chưa có verify server, chưa có widget trong form |
| Intervention Image | Xử lý ảnh (GD/Imagick) | `app.image_variants`, `app.upload_dir`, `app.upload_max_mb`, `app.allowed_mimes` (`global.php`) | `intervention/image ^3.11` | **Đã triển khai 13/09:** `Admin\Service\MediaService` (trước 13/09 tài liệu ghi `Core\Service\MediaService`) — resize/variant/WebP **chưa triển khai** |
| HTMLPurifier | Lọc XSS nội dung | — (`Application\Service\HtmlPurifierService` — trước 13/09 là `Core\`) | `ezyang/htmlpurifier ^4.18` | Dependency có, service **đã triển khai** |
| Google Fonts (Be Vietnam Pro) | Asset CDN | hard-code trong `module/Frontend/view/layout/frontend.phtml` (`fonts.googleapis.com/css2?family=Be+Vietnam+Pro`) | — | **Đang dùng** ở frontend. Admin **không** dùng CDN và **không** dùng framework ngoài: từ batch giao diện 13/09/2026 dùng design-system tự chứa `public/css/admin.css` + `public/js/admin.js` (font `Inter` khai báo trong css, fallback hệ thống — không nạp Google Fonts), Bootstrap đã rút khỏi Admin |
| Google Analytics | Đo lường | khoá `ga_measurement_id` đã có trong `data/schema/seed.sql` (nhóm `seo`, giá trị `NULL`) | — | **Chưa triển khai** — chưa có thẻ `gtag.js`/`googletagmanager` trong layout nào (đã grep `module/`, `public/`) |
| Slug / UUID | Nội bộ, không gọi ngoài | — (`app` config không chứa khoá nào liên quan) | `ramsey/uuid` | `Application\Service\SlugService` (trước 13/09 là `Core\`) — **đã triển khai** → chuẩn hoá slug + đặt tên file ngẫu nhiên **đã triển khai** |

## Chi tiết theo hệ thống

### MySQL

- `config/autoload/global.php` đặt `PDO::MYSQL_ATTR_INIT_COMMAND = "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci, time_zone = '+00:00'"` → mọi kết nối tự chốt UTC, đúng docs §4.1.
- `config/autoload/local.php.dist` gợi ý `db.hostname / database / username / password` (password = `CHANGE_ME`).
- Khi kết nối hỏng: ứng dụng web → trang lỗi 500 (`error/index`); CLI `bin/create-admin.php` bắt `Throwable`, in `DB connect failed: ...` và `exit(1)` (đoạn cuối file).

### SMTP mail

| Khoá | `global.php` (mặc định, commit) | `local.php.dist` (điền thật, gitignore) |
|---|---|---|
| `mail.transport.type` | `smtp` | — |
| `mail.transport.options.host` | `127.0.0.1` | `smtp.example.com` |
| `mail.transport.options.port` | `25` | `587` |
| `connection_class` | `plain` | `login` |
| `connection_config.username/password/ssl` | rỗng | `CHANGE_ME` / `tls` |
| `mail.from` | `no-reply@vanlang.local` | `no-reply@example.com` |

- Thiết kế docs §3.9 + §7.4: **không hàng đợi/job** — gửi đồng bộ ngay sau khi lưu `contact_submissions`, timeout ngắn; gửi lỗi thì **ghi log để gửi lại thủ công**, không ảnh hưởng phản hồi "đã nhận" của khách. **Chưa triển khai** trong code.
- Địa chỉ nhận lấy từ `settings.notify_emails` (nhóm `contact`, xem `data/schema/seed.sql`), **không** nằm trong config file.

### Captcha

- Khoá dự phòng: `recaptcha.site_key` / `recaptcha.secret_key` trong `config/autoload/local.php.dist` — README gốc cũng hướng dẫn "sửa DB, SMTP, captcha trong local.php".
- docs §3.9 chọn **Cloudflare Turnstile hoặc reCAPTCHA v3** + honeypot + giới hạn 3 lần gửi/IP/10 phút. Thực tế: form `module/Frontend/view/frontend/contact/index.phtml` chỉ có `fullName`, `email`, `message`, `consent` — **không** honeypot, **không** captcha; `Frontend\Controller\ContactController::submitAction()` trả `ViewModel` rỗng → **chưa triển khai**.

### Ảnh / Media

- Whitelist MIME (`app.allowed_mimes`): `image/jpeg`, `image/png`, `image/webp`, `image/gif` — khớp docs §3.10 (không SVG).
- Kích thước tối đa `app.upload_max_mb = 5`; biến thể `thumb 400 / medium 800 / large 1600`.
- Helper `Application\View\Helper\MediaUrl::__invoke(?string $path)` — `final class` không extends `AbstractHelper`; 14/09 (FR-19) bỏ tham số `$variant`: chọn biến thể làm ở **tầng dữ liệu** (`mapCardsByIds()` đọc `variants` JSON → trả `thumb`), helper chỉ còn nối đường dẫn. Trả `'/uploads/' . ltrim($path, '/')` → hiển thị theo **đường dẫn tương đối** so với document root `public/`, đúng hướng chuyển S3/CDN sau này (docs §3.10).
- Upload thật ✅ đã triển khai 13/09 (FR-36/37/38 — `Admin\Service\MediaService`): kiểm dung lượng → MIME **thật** bằng `finfo` đối chiếu whitelist → tên ngẫu nhiên `bin2hex(random_bytes(16))` → lưu `date('Y/m')/` → `move_uploaded_file`/`rename` → biến thể Intervention Image.

## Cách hệ thống "suy hao" khi tích hợp lỗi

| Thành phần | Hành vi thiết kế (docs) | Hành vi thực tế trong code |
|---|---|---|
| MySQL | trang lỗi 500 | trang lỗi 500 (`error/index`); CLI in message + `exit(1)` |
| SMTP | log lỗi, khách vẫn thấy "gửi thành công" | chưa gửi mail → chưa có luồng fail |
| Captcha | thiếu/sai token → từ chối | chưa kiểm → form luôn "đạt" (không chặn gì) |
| Upload/Intervention | bỏ qua variant lỗi, vẫn giữ bản gốc | chưa xử lý ảnh → chưa có fail path |

Chi tiết lỗi & thông báo: [`02-xu-ly-loi.md`](02-xu-ly-loi.md). Bảo mật khi go-live: [`../05-van-hanh/05-checklist-go-live.md`](../05-van-hanh/05-checklist-go-live.md).

> Không có API bên thứ ba nào khác (không thanh toán, không SMS, không OAuth, không webhook CRM — docs §9 mục 8 để ngỏ). Toàn bộ nội dung tĩnh tự host trong `public/`; chỉ một phụ thuộc ngoài runtime là Google Fonts ở layout frontend.
