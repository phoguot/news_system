# Kiến trúc tổng quan

Laminas MVC theo mô hình request → module → tầng dữ liệu → MySQL. Sơ đồ dưới đây là **kiến trúc mục tiêu** theo [phân tích v1.5](../../phan-tich-he-thong-website-tin-tuc.md); hiện repo mới ở giai đoạn khung (skeleton) — xem mục **Trạng thái triển khai** bên dưới.

## Sơ đồ luồng request

```
Browser
  │  HTTP
  ▼
Apache / php -S 0.0.0.0:8080 -t public
  │
  ▼
public/index.php  ──►  config/container.php  ──►  Laminas MVC (application bootstrap)
                                                        │
                                             Router (config + module.config.php)
                                                        │
                    ┌───────────────────────────────────┼───────────────────────────┐
                    ▼                                   ▼                           ▼
             Module Frontend                     Module Admin                  Module Application
        (trang đọc của khách)               (/admin + /api/admin)          (404/500 + index skeleton)
                    │                                   │
                    ▼                                   ▼
             Controller (mỏng) ─────────────────► Controller (mỏng)
                    │                                   │
                    └──────────────┬────────────────────┘
                                   ▼
                     Service (nghiệp vụ)  ──► Application: SlugService, HtmlPurifier,
                                   │           DbService, MediaService (Admin)
                                   ▼
                     Mapper (Model/<Entity>/)  ──►  Laminas\Db (Adapter & Sql)
                                   │
                                   ▼
                              MySQL 8  (news_system, utf8mb4, UTC)
```

## Vai trò từng tầng

| Tầng | Trách nhiệm | Ví dụ trong repo |
|---|---|---|
| Web server | Nhận HTTP, trỏ document root vào `public/`, chặn thực thi script trong `public/uploads` | `public/index.php`, `public/web.config` |
| Bootstrap / MVC | Nạp config đã merge, dựng ServiceManager, dispatch router → controller | `config/container.php`, `config/application.config.php` |
| Module | Khai báo route, controller, view, service cho một miền chức năng | `Frontend`, `Admin`, `Application` (Core đã gộp về Application 13/09) |
| Controller | **Mỏng**: nhận request, gọi Service, trả `ViewModel`/`JsonModel`; không chứa nghiệp vụ | `module/*/src/Controller/*.php` |
| Service | Nghiệp vụ: validate, transaction xoá cứng, lọc HTML, tạo variant ảnh, tính `readingMinutes`… | `Application/Service/*` (dùng chung — trước 13/09 là `Core/Service/*`); service theo module (dự kiến) |
| Mapper | **1 bảng = 1 Mapper** (`Model/<Entity>/<Entity>Mapper.php`) — lớp truy vấn/ghi cho đúng bảng mình sở hữu, dùng `Laminas\Db\Sql`; gom scope công khai `status=1 AND publishedAt<=NOW()`; không join bảng của mapper khác (chuẩn [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md) §5) | `module/*/src/Model/*/` (đang refactor từ `src/Table/`) |
| MySQL | Lưu trữ 16 bảng, không FOREIGN KEY, không `deletedAt`; kết nối đặt `time_zone='+00:00'` | `data/schema/schema.sql` |

## Module

| Module | Namespace | Vai trò | Route |
|---|---|---|---|
| `Frontend` | `Frontend\` | Trang đọc cho khách: Home, Post, Category, Tag, Search, Service, Team, Contact, Sitemap, About, Pricing | `/`, `/tin-tuc`, `/danh-muc/{slug}`, `/tag/{slug}`, `/tim-kiem`, `/dich-vu`, `/dich-vu/{slug}`, `/doi-ngu`, `/lien-he`, `/gioi-thieu`, `/bang-gia`, `/sitemap.xml`, `POST /api/contact` |
| `Admin` | `Admin\` | CMS cho quản trị viên: Auth, Dashboard, Post, Category, Tag, Service, Banner, HomeSection, Team, Contact, Media, Setting, Account, Api | `/admin/*` (trang) + `/api/admin/:resource[/:id][/:sub]` (JSON) |
| `Application` | `Application\` | Skeleton + dịch vụ dùng chung (từ 13/09 gộp Core): `DbService`, `SlugService`, `HtmlPurifierService`, `CaptchaService`, `DateService`, `PageCacheService` (nền cache `page_cache` — FR-39), helper `mediaUrl` + trang lỗi 404/500 | (fallback) |

## Luồng JSON của admin API

`/api/admin/*` trả về `ApiController::dispatch` → `JsonModel`. Chuẩn phản hồi (docs 6.2): `{ success, data, meta, errors }`; thời gian ISO 8601 UTC; lỗi validate 422, hết phiên 401, vượt rate limit 429, vi phạm ràng buộc khi xoá 409.

## Cache

| Đối tượng | Cách xử lý |
|---|---|
| Config + module class map | Filesystem cache tại `data/cache/` (`config_cache_enabled`, `module_map_cache_enabled`) |
| Settings (FR-39 — ✅ 13/09) | `Frontend\Service\SettingService` bọc map `settingKey=>settingValue` (nguồn `SettingMapper::listAll`) qua `Application\Service\PageCacheService::remember(key, producer)` — **TTL = 60s**; storage `page_cache` (Filesystem, `data/cache/page`) khai báo trong `global.php` theo **schema laminas-cache v3** (`adapter` là chuỗi class + `options`/`plugins` top-level — schema v2 lồng `adapter.name` cũ là bug latent, nay đã sửa). Plugin `serializer` **không dùng** (cần package `laminas-serializer` chưa cài) → PageCacheService tự `serialize` payload bọc mảng 1 phần tử (phân biệt giá trị `false`/`null` đã cache với miss). `data/cache/page` có `.gitkeep` trong repo vì Filesystem adapter **không tự tạo** `cache_dir`; storage thiếu/không dựng được → service degrade, producer chạy thẳng — cache là tăng tốc, không phải dependency cứng |
| Bài hẹn giờ / trang chủ | Không cronjob: điều kiện `publishedAt <= NOW()` nằm trong truy vấn + **TTL ≤ 60s** nên bài hẹn giờ tự xuất hiện sau tối đa một vòng TTL. Cache dữ liệu trang chủ ✅ 13/09 batch 9 (FR-32/39): `Frontend\Service\HomeService::sections()` dựng payload mảng thuần cho cả 7 loại section rồi chạy qua `PageCacheService::remember('home-v1')` (key tại `CacheConst::KEY_HOME`, vẫn dùng chung storage `page_cache`); mục trỏ bài/banner đã ẩn/xoá **tự bỏ qua** ngay lúc dựng (§8 luồng nghiệp vụ) |
| Danh mục cho menu FE + danh sách tin (FR-02 — ✅ 13/09 batch 10) | `Frontend\Service\PostListService::filterCategories()` qua `remember(CacheConst::KEY_CATEGORY_MENU)` — key `category-menu-v1`, TTL 60s, chung storage `page_cache` (đúng mục "chủ/menu danh mục" §7.2 spec). **Danh sách bài KHÔNG cache**: key sẽ nổ theo trang×danh mục trong khi mỗi lần ghi đã có forget; page 1 là dữ liệu động nhất. Mọi ghi danh mục phía Admin (`CategoryService` create/update/delete) forget `category-menu-v1` **và** `home-v1` (khối `category_posts` trang chủ đọc `name` lúc dựng payload — đóng lỗ hổng batch 9) |
| Bảng giá (16/09 — PricingViewService; cập nhật 17/09) | Cache **một** key `pricing-v1` chứa toàn bộ mục active TTL 60s, chung storage `page_cache`; `list(?group,?q,?page)` filter + phân trang 20 dòng/trang trên mảng đã cache; ghi phía Admin (`PricingService`) forget `pricing-v1` + `home-v1` (Box4 /dich-vu) |
| Sitemap (FR-11 — ✅ 13/09 batch 18) | `Frontend\Service\SitemapService::urls()` trả payload `{path, lastmod}` **không gắn host** (host do `serverUrl()` lúc render nên một cache phục vụ mọi host) bọc `remember(CacheConst::KEY_SITEMAP)` — key `sitemap-v1`, TTL 60s, chung storage `page_cache`. Nguồn: 5 trang tĩnh + `PostMapper::sitemapPublished` (`publicScope` — loại nháp/hẹn giờ/archived) + `sitemapActiveSlugs` của Category/Service mapper; `/tim-kiem` loại theo noindex NFR-SEO-5 |
| Vô hiệu hoá | ✅ settings: `Admin\Service\SettingService::saveForm()` gọi `Frontend\SettingService::invalidate()` (→ `forget` key `settings-v1`) sau mọi lần ghi. ✅ 13/09 batch 9 — bài/banner/dịch vụ/nhân sự/section: 5 service ghi Admin (`PostService`/`BannerService`/`ServiceService`/`TeamMemberService`/`HomeSectionService`, kể cả `itemsForm`) gọi `PageCacheService::forget('home-v1')` sau **mọi lần ghi thành công** — dependency mềm (test container thiếu key → no-op), sửa nội dung thấy ngay không chờ TTL. ✅ 16/09 — `PricingService` `invalidatePublicCaches()` forget `pricing-v1` + `home-v1`; `ServiceService` gate parentId; `SettingService` `mapEmbedUrl()` ưu tiên `map_address`/`address` nên đổi địa chỉ là FE đổi map — `contact/index` + `service/list` Box5 đọc qua SettingService (cache settings-v1) — không gọi mapper trực tiếp
| Vô hiệu hoá | ✅ batch 18 — `PostService`/`ServiceService`/`CategoryService` đổi helper thành `invalidatePublicCaches()`: forget thêm `sitemap-v1` sau mọi ghi chạm dữ liệu trong sitemap (Banner/Team/HomeSection không feed sitemap → giữ home-only) |

## Pipeline media

Upload (Intervention Image) → kiểm MIME thật (`finfo`, không tin phần mở rộng) → đổi tên ngẫu nhiên → lưu `public/uploads/YYYY/MM/` → sinh biến thể `thumb` 400 / `medium` 800 / `large` 1600 (+ WebP) → ghi **đường dẫn tương đối** vào `media.path`/`media.variants` → hiển thị qua helper `mediaUrl` (`/uploads/<path>`). Cấu hình trong `global.php`: `upload_dir`, `upload_max_mb=5`, `allowed_mimes`, `image_variants`.

## Xác thực admin

- Phiên đăng nhập (`laminas-session`, cookie `VANLANG_SESS`, `HttpOnly`, `SameSite=Lax`), một quản trị viên duy nhất trong bảng `users`.
- Đăng nhập qua `Admin\Controller\AuthController` (`/admin/login`, `/admin/logout`); mọi API admin phải kiểm tra phiên, không chỉ ẩn nút trên UI.
- Tạo tài khoản ban đầu bằng CLI: `php bin/create-admin.php --email=... --name=...` (hỏi mật khẩu tương tác, băm bằng `password_hash()`).
- Bảo vệ tài khoản đơn: khoá tạm 15 phút sau 5 lần sai (`failedLoginCount`, `lockedUntil`).

## Trạng thái triển khai (đối chiếu code thực tế)

Repo hiện là **khung sườn**, chưa phải bản chạy đầy đủ:

- `Admin`/`Frontend` có `Controller/` (không còn `Controller/Factory/` — DI bằng closure trong config), đa số action còn là placeholder; data access **đã refactor xong 12/09/2026** sang chuẩn `Model/<Entity>/{Mapper,Const}` + `Filter/` (Admin: `Model/User/UserMapper`, `Filter/Auth/LoginFilter`; Frontend: `Model/{Contact,Service,Setting}`, `Filter/Contact/ContactSaveFilter`) — chuẩn áp dụng ở [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md). Đã có code thật: đăng nhập admin + form liên hệ.
- `Application/Service/*` (DbService, SlugService, HtmlPurifierService, CaptchaService, DateService…) **đã code thật** (trước 13/09 thuộc `Core/Service/*` — factory & wiring nay ở `Application/config/module.config.php`); `MediaService` thuộc `Admin/Service` (chỉ admin upload). Helper `MediaUrl` (`Application\View\Helper\MediaUrl`) đã có thân hàm.
- Route (Frontend + Admin + `admin-api`), config DB/session/cache/mail/app, `schema.sql` (16 bảng), `seed.sql` và `bin/create-admin.php` **đã có và đúng**.

## Nguyên tắc thiết kế

1. Controller mỏng, mọi nghiệp vụ nằm ở Service; mỗi bảng một Mapper (`Model/<Entity>/`, không join bảng của mapper khác).
2. DB chỉ dùng index, **không FOREIGN KEY** → tầng ứng dụng tự bảo vệ toàn vẹn & tự xoá bản ghi con trong cùng transaction.
3. **Xoá cứng**, không thùng rác; muốn ẩn mà giữ dữ liệu thì dùng `archived` / `isActive=0`.
4. Không cronjob / job nền: hẹn giờ, lượt xem, email, dọn revision/token đều xử lý đồng bộ + TTL cache.
5. Thời gian lưu & trao đổi API bằng UTC; đổi sang giờ Việt Nam ở tầng hiển thị.
6. Tên bảng `snake_case`, tên cột `camelCase`; không dùng `ENUM` (trạng thái/`type` là `TINYINT` + mã số).
