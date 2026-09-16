# Xử lý lỗi tích hợp

> Chiến lược **thực tế trong code**: module `Application` (giữ từ skeleton Laminas) lo trang lỗi 404/500; cấu hình `view_manager` trong `module/Application/config/module.config.php` quyết định mức độ chi tiết hiển thị. **Ứng dụng chưa có logger riêng và chưa có handler cho nghiệp vụ** (mail, upload, captcha, rate limit đều là stub — xem [`01-tich-hop-ngoai.md`](01-tich-hop-ngoai.md)).

## Cơ chế hiển thị lỗi đã có

| Lỗi | Nguồn | Trang/ kết quả | Ghi chú |
|---|---|---|---|
| Route không khớp, controller không tồn tại/không dispatch được | `Laminas\Mvc` | `error/404` → `module/Application/view/error/404.phtml` | Template in `$this->reason` (dịch sẵn 5 loại: controller không dispatch được, không tìm thấy controller, không hợp lệ, router không khớp...) |
| Exception / Throwable trong request | `Laminas\Mvc` dispatch + view | `error/index` → `module/Application/view/error/index.phtml` | Khi `display_exceptions` bật: in class exception, `file:line`, message, stack trace và exception lồng nhau (`getPrevious()`) |
| Lỗi đọc/ghi cache trang | `laminas-cache` | **Im lặng, bỏ qua cache** | `caches.page_cache` đăng ký plugin `ExceptionHandler` với `throw_exceptions => false` (`config/autoload/global.php`) — cache hỏng không làm sập request |
| Mất kết nối DB (CLI) | `bin/create-admin.php` | `fwrite(STDERR, "DB connect failed: ...")` + `exit(1)` | Exception được `catch (Throwable)` chủ đích ở CLI |
| Mất kết nối DB (web) | PDO/Laminas\Db | Trang `error/index` (500) | Chưa có retry/fallback riêng |

**Lưu ý khi dùng 2 trang lỗi này:** chúng **nguyên văn từ skeleton Laminas** — text tiếng Anh ("A 404 error occurred", "Additional information"). Từ 14/09/2026 (`view_manager.layout => 'layout/frontend'` trong config Frontend) chúng đã render **trong layout frontend** (header/footer Vạn Lang), không còn layout skeleton. Việc còn lại: dịch sang tiếng Việt (checklist [`../01-quy-chuan/04-checklist-review.md`](../01-quy-chuan/04-checklist-review.md)).

**Ví dụ lỗi đo được thực tế (12/09/2026, `php -S`, dev mode đang bật):**

| Request | Kết quả | Nguyên nhân |
|---|---|---|
| `GET /khong-co` | 404, in "A 404 error occurred" + "The requested URL could not be matched by routing." | `display_not_found_reason => true` |
| `GET /api/contact` | 500, in `Laminas\View\Exception\RuntimeException` + **đường dẫn tuyệt đối** `E:\My Tool\News\vendor\laminas\laminas-view\src\Renderer\PhpRenderer.php:577` + full stack trace | `display_exceptions => true` + action stub trả `ViewModel` không có template `frontend/contact/submit` |

→ Đây chính là bằng chứng vì sao **prod bắt buộc** `display_exceptions = false`: đang lộ cả cấu trúc thư mục cài đặt và đường đi của `vendor/`.

## Hai công tắc bật/tắt chi tiết lỗi

| Khoá | Giá trị đang commit | Ý nghĩa | Hệ quả khi quên tắt ở prod |
|---|---|---|---|
| `view_manager.display_exceptions` | `true` (module.config.php) và `true` (`development.local.php`) | Hiện file/line/stack trace trên trang lỗi | **Rò rỉ đường dẫn, query, secret** cho người dùng cuối |
| `view_manager.display_not_found_reason` | `true` (module.config.php) | Hiện lý do 404 chi tiết (controller nào, vì sao) | Lộ cấu trúc route/controller |

- Development mode (`composer development-enable`, tạo `config/development.config.php`) **tắt config cache** và nạp thêm `config/autoload/{,*.}{global,local}-development.php` → `development.local.php` bật `display_exceptions`.
- Production: **phải override** `view_manager.display_exceptions => false` và `display_not_found_reason => false` trong `config/autoload/local.php` (file này gitignore, xem `config/autoload/.gitignore`), đồng thời `display_errors = Off` trong `php.ini`. Checklist: [`../05-van-hanh/05-checklist-go-live.md`](../05-van-hanh/05-checklist-go-live.md).
- Sau khi sửa config production: `composer clear-config-cache` (hoặc `php bin/clear-config-cache.php`) vì `config_cache_enabled => true` trong `config/application.config.php`.

## Ghi gì, ghi ở đâu

| Loại | Hiện trạng trong repo |
|---|---|
| Log lỗi ứng dụng | **Chưa triển khai** — không có key `logger`/`laminas-log` trong `config/`, không có thư mục `data/logs/` (chỉ có `data/cache/`, `data/schema/`). Lỗi PHP rơi vào error log của SAPI (`php.ini` `error_log`) — thuộc tầng web server, không thuộc repo |
| Log gửi mail thất bại (docs §3.9: "ghi log để gửi lại thủ công") | ✅ `error_log()` ở **caller** — `Frontend\Service\ContactService::notifyAdmins` + `Admin\Service\PasswordResetService` (rà 14/09: bản cũ ghi "trong `MailService`" là sai chỗ — `MailService::sendMany` `catch(Throwable)` chỉ `return false`, không tự log) — gửi fail chỉ log, không đổi kết quả; hàng đợi gửi lại thủ công chưa làm |
| Nhật ký hoạt động admin | Không tồn tại theo thiết kế — docs v1.1 đã bỏ module nhật ký hoạt động |
| Access log / uptime | Thuộc tầng hạ tầng, xem [`../05-van-hanh/04-log-giam-sat.md`](../05-van-hanh/04-log-giam-sat.md) |

## Xử lý lỗi theo nghiệp vụ (thiết kế docs §3.9/§6.2 vs thực tế)

| Tình huống | Thiết kế docs | Code hiện tại | Việc cần làm khi triển khai |
|---|---|---|---|
| Validate form liên hệ fail | HTTP 422 + `errors` từng trường (`module/Frontend/src/Controller/ContactController::submitAction()` route `contact-submit`) | ✅ (khác thiết kế) — form HTML render lại kèm `formElementErrors` từng trường, HTTP 200, không phải JSON 422 vì đây là form trình duyệt | Sau này nếu mở API JSON cho liên hệ thì dùng envelope §6.2 |
| Vượt rate limit 3 lần/IP/10 phút | HTTP 429 | ✅ `ContactService` đếm theo `INET6_ATON(ip)+createdAt` qua `idx_contact_submissions_ip_created`; `ContactController` set 429 + trang lỗi; OK thì PRG | Đã smoke: POST thứ 4/10 phút cùng IP trả 429 |
| Captcha sai/thiếu | Từ chối gửi | ✅ `Application\Service\CaptchaService` (trước 13/09 là `Core\`) verify server-side TRƯỚC khi INSERT (reCAPTCHA v3; Turnstile chỉ đổi `verify_url`); secret rỗng (dev) → tự cho qua | Khi deploy nhập `recaptcha.site_key/secret_key` vào `local.php`; hidden `g-recaptcha-response` đã có trong view |
| Gửi SMTP lỗi | Lưu DB vẫn thành công, thông báo khách không đổi, ghi log gửi lại | ✅ `Application\Service\MailService` (trước 13/09 là `Core\`) bọc `try/catch(Throwable)` + timeout 5 giây; `ContactService` chỉ `error_log`, vẫn trả OK | Hàng đợi gửi lại chưa làm (docs §3.9 chấp nhận đồng bộ) |
| Upload vượt 5 MB / MIME không phép | Chặn, báo lỗi field (`app.upload_max_mb`, `app.allowed_mimes`) | ✅ **đã triển khai 13/09** (`Admin\Service\MediaService` — trước 13/09 tài liệu ghi `Core\Service\MediaService`) | Kiểm `finfo` từ nội dung file, không tin phần mở rộng (docs §3.10) |
| Xoá bản ghi còn ràng buộc (danh mục còn bài, media đang dùng) | HTTP 409 + mô tả nơi đang dùng | ✅ đã triển khai (rà 14/09 xác minh code) — `Admin\Exception\ConflictException` + controller map 409: `CategoryService::delete` còn con/còn bài (§5.14), `MediaService::delete` còn được banner/post/home-section tham chiếu (§5.15); form page hiển thị lý do dạng field/flash error | Transaction xoá bản ghi con đã có ở FR-22 bulk (batch 14/09) |
| Hết phiên đăng nhập admin | HTTP 401 | ✅ `Admin\Service\AuthGuard` (401 JSON cho `admin-api*`, 302 cho trang) | Xem [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md) |
| Session validator fail (UA/IP đổi giữa 2 request) | Không định nghĩa trong docs — laminas-session v2 ném RuntimeException cứng | ✅ `Application\Session\SessionBootstrap` (trước 13/09 là `Core\Session\SessionBootstrap`) hủy phiên cũ, start phiên mới sạch (khách không thấy 500) | Giữ nguyên; nếu bỏ validators trong `session_manager` thì helper vẫn tương thích |

## Chuẩn phản hồi API khi triển khai (theo docs §6.2)

```json
{ "success": false, "data": null, "meta": null, "errors": { "email": ["Email không hợp lệ"] } }
```

- Lỗi validate → **422**, hết phiên → **401**, vượt rate limit → **429**, bị chặn ràng buộc → **409**.
- Khoá JSON dùng `camelCase`; thời điểm dạng ISO 8601 UTC.
- Hiện `Admin\Controller\ApiController::dispatchAction()` mới trả `{"success": true}` cố định — chưa phân nhánh lỗi.

## Phân loại nhanh khi vận hành

| Triệu chứng | Nghi ngờ đầu tiên | Chỗ nhìn |
|---|---|---|
| Trang trắng/500 | Exception chưa bật display → xem error log SAPI hoặc tạm bật `display_exceptions` trong `local.php` ở môi trường test | `module/Application/view/error/index.phtml` |
| 404 cho URL đúng | Route thiếu/conflict giữa `module/*/config/module.config.php` (các route tên `home`, `news`, `admin`... gộp global) | `config/modules.config.php` |
| Sửa config không ăn | Config cache cũ (`data/cache/`) | `composer clear-config-cache` |
| Bài hẹn giờ không hiện | Cache trang cũ quá TTL hoặc TTL > 60s | `caches.page_cache.options.ttl` (`global.php`), docs §5.7 |
| Ảnh không hiện | Đường dẫn `media.path` không tương đối hoặc thiếu `public/uploads` writable | helper `Application\View\Helper\MediaUrl` (trước 13/09 là `Core\`) |
