# Hướng dẫn test

## Cấu hình test thực tế trong repo

| Hạng mục | Giá trị (nguồn) |
|---|---|
| Cấu hình | `phpunit.xml.dist` — `bootstrap="vendor/autoload.php"`, `cacheDirectory=".phpunit.cache"` |
| Testsuite | **một suite duy nhất** `Laminas MVC Skeleton Test Suite` → `./module/*/test` |
| Source (coverage include) | `./module/*/src` — chưa khai báo reporter/coverage threshold nào |
| Namespace test (`composer.json` → `autoload-dev`) | `ApplicationTest\` → `module/Application/test/` (gộp `CoreTest` từ 13/09), `FrontendTest\` → `module/Frontend/test/`, `AdminTest\` → `module/Admin/test/` |
| PHP `phpunit.xml` (không `.dist`) | bị gitignore (`.gitignore` dòng `phpunit.xml`) — dùng cho override cá nhân |
| CI | **Chưa có** — repo không có `.github/` hay pipeline; mọi lệnh dưới đây chạy tay |

### File test đang tồn tại (toàn bộ)

| File | Nội dung |
|---|---|
| `module/Application/test/ModuleTest.php` | `Application\Module::getConfig()` trả config có key `router` + `controllers` |
| `module/Application/test/Controller/IndexControllerTest.php` | `AbstractHttpControllerTestCase` (laminas-test): dispatch `/` → 200, `assertModuleName('frontend')`, `assertMatchedRouteName('home')`; dispatch `/invalid/route` → 404 |

```bash
$ composer test
OK (4 tests, 7 assertions)     # kiểm chứng 12/09/2026, PHP 8.3.28, PHPUnit 10.5.64
```

**Độ phủ thực tế (rà 13/09, đến batch 18):** ngoài "khởi động ứng dụng + routing", tầng **Service** của các FR đã code đều có test chạy trên **mock mapper + render SQL không cần DB**: auth admin (`AdminAuthServiceTest`), form liên hệ FR-10 (`ContactServiceTest` — 8 ca), nền cache FR-39 (`PageCacheServiceTest` + Setting/Home), danh sách/chi tiết FR-02/03 (`PostListServiceTest`/`PostDetailServiceTest` + SQL render), tìm kiếm FR-07, dịch vụ FR-08, đội ngũ FR-09, **sitemap FR-11** (`Frontend/test/Service/SitemapServiceTest` — 4 ca: thứ tự tĩnh + không `/tim-kiem`, lastmod W3C, nối slug, hit cache bỏ mapper; + `PostMapperSitemapSqlTest` và 2 case append `CategoryMapperSqlTest`/`ServiceMapperSqlTest`), cùng các test forget cache ở service Admin. **Vẫn chưa có bộ test chạm DB thật** (mọi test là mock/render — docs §7.2 nếu cần integration sẽ phải thêm config DB test riêng); `Application` dùng chung (DbService/SlugService/Mail/Captcha…) vẫn không có test riêng.

## Lệnh chạy

```bash
composer test                       # = vendor/bin/phpunit (chạy toàn bộ)
vendor/bin/phpunit                  # như trên
vendor/bin/phpunit --filter IndexControllerTest          # 1 class
vendor/bin/phpunit --filter testInvalidRouteDoesNotCrash # 1 test
vendor/bin/phpunit --testdox        # output dễ đọc theo tên test
vendor/bin/phpunit --coverage-text  # cần Xdebug hoặc PCov (chưa cấu hình trong repo)
```

> Test boot toàn bộ ứng dụng qua `config/application.config.php` → **đọc luôn `config/autoload/local.php` và `config/development.config.php`** nếu chúng tồn tại. Trước khi chạy test trong CI/người khác máy, nhớ `composer development-disable` để `display_exceptions`/debug toolbar không ảnh hưởng assertion.

## Lint + static analysis

| Công cụ | Lệnh | Cấu hình | Phạm vi / chuẩn |
|---|---|---|---|
| PHP_CodeSniffer | `composer cs-check` (sửa tự động: `composer cs-fix`) | `phpcs.xml` | `PSR12` + `Generic.Arrays.DisallowLongArraySyntax`, `Squiz.WhiteSpace.*`; kiểm `config/`, `module/`, `public/index.php`; mở rộng `php,dist,phtml`; `*.phtml` miễn `LineLength`; `config/*` + `public/index.php` + `*.phtml` miễn `PSR12.Files.FileHeader`; cache `.phpcs-cache` |
| Psalm | `composer static-analysis` | `psalm.xml` | `errorLevel="1"`, `findUnusedCode`/`findUnusedPsalmSuppress`/`findUnusedBaselineEntry` = true; soi `module/`, `config/`, `public/index.php`; bỏ `vendor/`, `data/`, `bin/`; stub `.psalm-stubs.phpstub`; plugin `Psalm\PhpUnitPlugin` |

```bash
vendor/bin/psalm --no-cache --stats   # khi nghi ngờ cache cũ
```

> **Baseline (đã chạy 12/09/2026):** `cs-check` báo lỗi trên các file stub — controller viết dồn 1 dòng vi phạm PSR12 (`phpcbf` sửa được gần hết); `psalm` báo `MissingReturnType`/`PossiblyUnusedMethod` cho các action stub và `DeprecatedClass` với `Laminas\View\Model\JsonModel`. Khi động vào file nào thì phải đưa file đó về sạch (thêm return type, format lại) ngay trong PR đó.

## Checklist test thủ công — luồng quan trọng

Chạy tay sau khi triển khai xong từng phần; đánh dấu "blocking" = lỗi là **không** go-live.

### Liên hệ (docs §3.9) — **blocking**

- [x] Gửi form `/lien-he` hợp lệ → có bản ghi `contact_submissions.status = 0` (`ipAddress` = `INET6_ATON`, `consentAt` UTC), 302 PRG `/lien-he?sent=1`. *(Smoke 12/09/2026.)*
- [x] Vượt **3 lần gửi / IP / 10 phút** → bị từ chối, trả **429** (docs §5.10). *(Smoke 12/09/2026: POST thứ 4 → 429, không thêm dòng DB; honeypot được check TRƯỚC rate-limit.)*
- [x] Không tick `consent` → server từ chối (validate ở server, không chỉ `required` của HTML). *(`ContactSaveFilter` dùng InputFilter `Identical('1')` — lỗi form render lại kèm message.)*
- [x] Email thiếu `site_key`/`secret_key` trong `local.php` → form vẫn phải xử lý được hoặc fail rõ ràng, không "tre" request. *(`CaptchaService::isEnabled()` false → bỏ qua verify, không gọi mạng; `verify()` có timeout 5s khi bật.)*
- [x] SMTP sai/sập → khách **vẫn** nhận thông báo thành công, có log để gửi lại thủ công (docs §3.9, §7.4). *(`MailService::sendMany()` try/catch(Throwable) → false, `ContactService` chỉ `error_log`, response không đổi — smoke 12/09/2026 trỏ SMTP chết vẫn 302 PRG.)*

### Bài hẹn giờ (docs §3.3.4, §5.7) — **blocking**

- [ ] Tạo bài `status=1`, `publishedAt` = tương lai +5 phút → **không** thấy ở `/`, `/tin-tuc`, sitemap; mở `/tin-tuc/{slug}?previewToken=...` → **xem được**.
- [ ] Đúng `publishedAt` → bài xuất hiện trên trang chủ/danh sách **trong ≤ 60 giây** (TTL `caches.page_cache`), không cần cron.
- [ ] Bài nháp/archived không hiển thị; bài archived không có trong `/sitemap.xml`.
- [ ] Huỷ hẹn giờ (về `draft`) → `publishedAt` bị xoá, link preview cũ không còn nghĩa.

### Media (docs §3.10)

- [ ] Upload JPG/PNG/WebP/GIF ≤ 5 MB → có bản gốc + biến thể `thumb/medium/large` (+ WebP), dòng `media.variants` JSON đúng, `path` dạng `YYYY/MM/<random>.<ext>`.
- [ ] Upload > 5 MB hoặc file đổi phần mở rộng (`.php`, `.svg`, `.phtml`) → **bị chặn**, lỗi mô tả rõ (kiểm MIME thật bằng `finfo`).
- [ ] Xoá media đang được bài/danh mục/banner dùng → bị **chặn**, trả danh sách nơi đang dùng (docs §5.14).
- [ ] Ảnh render qua helper `mediaUrl()` → URL `/uploads/...` mở được từ browser.

### Auth admin (docs §3.11) — **blocking**

- [x] Đăng nhập đúng bằng **email hoặc username** (1 ô định danh, 13/09/2026) → vào `/admin`; **5 lần sai liên tiếp** → khoá tạm 15 phút (`users.failedLoginCount`, `users.lockedUntil`). ✅ Đã verify HTTP 12/09/2026 (lần 5 báo khoá, mật khẩu đúng khi khoá vẫn bị từ chối) và 13/09/2026 (login `admin` → 302 dashboard; đổi username trong `/admin/account` rồi login bằng username mới OK; định danh lạ → cùng thông điệp chung).
- [x] Vào `/admin` / `/admin/posts` (và `GET|POST /api/admin/*`) **khi chưa đăng nhập** → 302 về login / 401 JSON, không lộ dữ liệu. ✅ `Admin\Service\AuthGuard`.
- [x] Đăng xuất → không còn dùng được trang admin (Back không lộ dữ liệu). *(clear identity + regenerate id; trang public vẫn back-able.)*
- [ ] Cookie session `VANLANG_SESS` có `HttpOnly` + `SameSite=Lax` (`config/autoload/global.php` key `session_config`); bật `Secure` khi chạy HTTPS.

### Nội dung & SEO (docs §7.1)

- [ ] Đăng bài có `<script>`/`onclick` trong content → bị lọc sạch trước khi lưu (`HtmlPurifierService`). *(đã gắn ở `PostService` lúc lưu 13/09 — item này là kiểm chứng thủ công)*
- [ ] `/sitemap.xml` liệt kê bài đã xuất bản + danh mục + dịch vụ, **không** có nháp/archived/bài hẹn giờ tương lai. *(hàng thật từ batch 18 13/09 — đã test tự động + smoke khớp DB; kiểm lại trên môi trường thật có rewrite)*
- [ ] `/tim-kiem?q=<từ tiếng Việt ≥ 2 ký tự>` trả kết quả (cần `innodb_ft_min_token_size = 2`), trang kết quả mang `noindex`.
- [ ] URL `/tin-tuc/{slug}` không chứa slug danh mục; chuyển danh mục không làm đổi URL.

### Xoá cứng (docs §4.6)

- [ ] Xoá bài → `posts`, `post_tags`, `post_revisions`, `post_view_daily`, `home_section_items(itemType=1)` sạch hết trong một transaction; slug được giải phóng để tái sử dụng.
- [ ] Xoá danh mục còn bài/con → **bị chặn**.
- [ ] Một bước giữa chừng lỗi → transaction rollback, không còn bản ghi mồ côi.

### Smoke nhanh trước mỗi lần deploy

```bash
composer test && composer cs-check && composer static-analysis
composer clear-config-cache   # sau khi đổi config
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/            # 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/khong-co    # 404
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin       # 200 (CHƯA có guard)
```

> **Baseline đã đo 12/09/2026 trên `php -S` (PHP 8.3.28):**
> - `GET /`, `/tin-tuc`, `/lien-he`, `/tim-kiem`, `/admin`, `/admin/login` → **200** nhưng là **nội dung stub**, `<title>` vẫn là "Laminas MVC Skeleton" (chưa bật `layout/frontend` / `layout/admin` — chưa có key `view_manager.layout`). *(Ghi chú 14/09/2026: baseline này hết hiệu lực phần layout — `view_manager.layout => 'layout/frontend'` đã đặt trong config Frontend; `<title>` trang công khai nay là "… - Vạn Lang".)*
> - `GET|POST /api/contact` **đã hết 500** — 12/09/2026 thay bằng trang `frontend/contact/index` + flow CSRF/captcha/rate-limit (xem `../03-tich-hop/02-xu-ly-loi.md`).
> - `POST /api/admin/posts` → **200 `{"success":true}`** dù **không** gửi cookie phiên → xác nhận chưa có auth guard.
> - `GET /sitemap.xml` và `GET /robots.txt` → **404**. Với `robots.txt`: chưa có file. Với `sitemap.xml`: route có tồn tại trong `module/Frontend/config/module.config.php`, nhưng **PHP built-in server không chuyển request có đuôi `.xml` vào `index.php`** → phải test sitemap trên Apache/nginx (rewrite) hoặc qua `AbstractHttpControllerTestCase::dispatch('/sitemap.xml')` trong PHPUnit, không test bằng `curl` trên `composer serve`.
>
> Khi code thật được viết, các mục trên phải đổi kết quả — cập nhật lại baseline ở đây.

Báo lỗi phát hiện theo [`../_templates/template-bao-cao-loi.md`](../_templates/template-bao-cao-loi.md). Danh sách bắt buộc trước go-live: [`../05-van-hanh/05-checklist-go-live.md`](../05-van-hanh/05-checklist-go-live.md).
