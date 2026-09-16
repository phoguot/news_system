# Quy chuẩn Code

> Ghi lại **quy ước đang được áp dụng trong repo** (Laminas MVC 3.8.0, PHP 8.1–8.3). Kiểm chứng trực tiếp từ `module/*/src`, `module/*/config/module.config.php`, `config/`, `composer.json`.
> **Cập nhật 12/09/2026:** cấu trúc tầng module theo chuẩn mới — [`07-crud-convention.md`](07-crud-convention.md) (Model/Mapper thay Table, Filter thay Form, DI closure thay file Factory) — **đã refactor xong toàn bộ code hiện có trong cùng ngày**. File này giữ các mục không đổi (routing, view, escape, session, quality gates); bản đồ refactor + trạng thái từng dòng: [`../00-tong-quan/05-cau-truc-thu-muc.md`](../00-tong-quan/05-cau-truc-thu-muc.md) §3.

## 1. Cấu trúc module Laminas

Module nạp theo thứ tự trong [`config/modules.config.php`](../../../config/modules.config.php): `Laminas\*` → `Application` → `Frontend` → `Admin` (Core đã gộp về Application từ 13/09/2026).

| Module | Vai trò | Namespace → thư mục (`composer.json` PSR-4) |
|---|---|---|
| `Application` | Skeleton + dịch vụ dùng chung · trang lỗi 404/500 | `Application\` → `module/Application/src/` (từ 13/09 gộp Core — namespace `Core\` → `Application\`) |
| `Frontend` | Website công khai | `Frontend\` → `module/Frontend/src/` |
| `Admin` | CMS + JSON API | `Admin\` → `module/Admin/src/` |

Mỗi module có `src/Module.php` khai báo **`getConfig(): array`** nạp `config/module.config.php` (xem `module/Admin/src/Module.php`). Route + DI + view Manager đều khai báo trong `module.config.php`, **không** dùng plugin `getControllerConfig()`/`getServiceConfig()`. **Ngoại lệ đã duyệt:** `Admin\Module` và `Frontend\Module` có thêm `init(ModuleManager)` attach listener trên `EVENT_DISPATCH` (guard + `Application\Session\SessionBootstrap::ensureDefault`) — lưu ý service `'EventManager'` **non-shared** trong laminas-mvc, phải attach qua **SharedEventManager** với identifier `Laminas\Mvc\Application::class` (bẫy giải thích ở [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md)).

```
module/{M}/
├── src/
│   ├── Module.php                 (getConfig)
│   ├── Controller/                ({Entity}Controller — DI closure trong config, không có Factory/)
│   ├── Service/                   (nghiệp vụ; guard/builder cũng nằm đây)
│   ├── Model/{Entity}/            ({Entity}Mapper + {Entity}Model + {Entity}Const)  ← chuẩn 07
│   ├── Filter/{Entity}/           ({Entity}SaveFilter + {Entity}ListFilter — InputFilter)
│   ├── Exception/
│   └── View/Helper/               (chỉ Application: MediaUrl — trước 13/09 thuộc Core)
├── config/module.config.php       (router, controllers.factories = closure, view_manager)
└── view/{controller}/{action}.phtml
```

## 2. Phân tầng và trách nhiệm

| Tầng | Class / đường dẫn | Việc | Không làm |
|---|---|---|---|
| Controller | `src/Controller/{Entity}Controller.php` `extends AbstractActionController` | Nhận request, đọc param, gọi Service, trả `ViewModel`/`JsonModel` | Không query DB trực tiếp, không chứa logic xoá cứng |
| Service | `src/Service/{NghiepVu}Service.php` | Điều phối nhiều Mapper, validate, transaction, gửi mail, cache; **luồng API: làm trọn flow validate → quyền → tồn tại → mapper → trả response** (08 §2 — chi tiết overriding dòng cũ "không đụng HTTP") | Không render view |
| Mapper (TableDataGateway) | `src/Model/{Entity}/{Entity}Mapper.php` | 1 bảng = 1 Mapper; `select/insert/update/delete` + prepared statement | Không gọi `Mapper` khác để lấy dữ liệu phụ trợ; bỏ qua bản ghi mồ côi khi đọc |
| Model (POPO) | `src/Model/{Entity}/{Entity}Model.php` |Getter/setter, `fromRow()`, mô hình hoá 1 dòng | Không query DB |
| Filter | `src/Filter/{Entity}/{Entity}{Save,List}Filter.php` | InputFilter validate + sanitize input | Không ghi DB (chỉ READ check unique/exists) |
| View | `view/{controller}/{action}.phtml` | Hiển thị + escape | Không query DB |

> ✅ Tầng `Mapper`/`Model`/`Filter` theo chuẩn mới [`07-crud-convention.md`](07-crud-convention.md) **đã refactor xong 12/09/2026** — không còn `Table/`/`Form/`/`Guard/` trong repo (bản đồ: [`../00-tong-quan/05-cau-truc-thu-muc.md`](../00-tong-quan/05-cau-truc-thu-muc.md) §3). **Cập nhật 13/09/2026:** method đọc của Mapper **trả `{Entity}Model` hydrate qua `fromRow()`** (07 §3) — không còn trả `array` thô (riêng phép chiếu tổng hợp COUNT group-by giữ mảng scalar, 07 §5).

- Action đặt theo đuôi `Action` (kiểm chứng `module/Frontend/src/Controller/PostController.php`: `indexAction`, `listAction`, `detailAction`, `viewAction`).
- API JSON trả `JsonModel` (từ 13/09/2026: **Service trả `ApiResponseModel`** — JsonModel kèm statusCode, dựng bởi `Admin\Service\Api\ApiResultModel`; `ApiController::dispatchAction` chỉ router + gắn status). Chuẩn body `{success,data,meta,errors}` theo §6.2 docs.
- **Logic xoá cứng nhiều bảng nằm ở Service trong MỘT transaction** (không có FK, MySQL không cascade) — xem [`../02-thiet-ke/04-luong-nghiep-vu.md`](../02-thiet-ke/04-luong-nghiep-vu.md) và docs §4.6.

## 3. Dependency Injection (laminas-servicemanager)

| Loại | Cách đăng ký (chuẩn 07) | Ví dụ |
|---|---|---|
| Controller có dependency | `controllers.factories` = **closure inline** trong `module.config.php` ✅ (đã áp dụng toàn bộ 12/09/2026) | `PostController::class => static fn (ContainerInterface $c) => new PostController($c->get(PostService::class))` |
| Controller không dep | `InvokableFactory` (vẫn hợp lệ) | `HomeController::class => InvokableFactory::class` |
| Service/Mapper dùng chung | `service_manager.factories` = closure + alias | `DbService::class => closure`, alias `'DbAdapter' => DbService::class` |
| View helper | `view_helpers.factories` = closure + alias | `MediaUrl::class => closure`, alias `'mediaUrl'` |

**Không tạo file `XxxFactory implements FactoryInterface` mới** — toàn bộ DI khai closure trong `module.config.php` (mẫu: [`07-crud-convention.md`](07-crud-convention.md) §4). Toàn bộ `*Factory.php` cũ (Admin/Frontend/Application — trước 13/09 là Core, ~25 file) **đã xoá 12/09/2026**. Controller gọi dependency qua container, **không `new` tay** dịch vụ có trạng thái. **Cập nhật 13/09/2026 (07 §6):** `InputFilter` stateful theo request nên **Service** `new` trực tiếp mỗi lần chạy validate — Controller **không** `new` Filter (ngoại lệ cũ "controller new trực tiếp mỗi dispatch" đã bị bỏ).

## 4. Routing

Route khai báo `Laminas\Router\Http\Literal` / `Segment` trong `module.config.php`.

| Miền | URL | Route name (Laminas) | Nguồn |
|---|---|---|---|
| Frontend | `/`, `/tin-tuc`, `/tin-tuc/:slug`, `/danh-muc/:slug`, `/tag/:slug`, `/tim-kiem`, `/dich-vu`, `/dich-vu/:slug`, `/doi-ngu`, `/lien-he`, `/api/contact`, `/sitemap.xml` | `home`, `news`, `news-detail`, `category`, `tag`, `search`, `services`, `service-detail`, `team`, `contact`, `contact-submit`, `sitemap` | `Frontend/config/module.config.php` |
| Admin trang | `/admin`, `/admin/login`, `/admin/posts[/:action[/:id]]`, `/admin/home-sections`, ... | `admin` + child `login`, `posts`, `home-sections`, `banners`, `settings`, `account`... | `Admin/config/module.config.php` |
| Admin API | `/api/admin/:resource[/:id][/:sub]` | `admin-api` → `ApiController::dispatch` | `Admin/config/module.config.php` |

**Quy tắc đặt tên route:**
- **Đường dẫn URL frontend = tiếng Việt `kebab-case`** (`/tin-tuc`, `/danh-muc`, `/tim-kiem`, `/doi-ngu`, `/lien-he`), khớp docs §6.1.
- **Route name Laminas = ASCII `kebab-case`** (`news-detail`, `service-detail`, `contact-submit`, `home-sections`). Không dùng tên route tiếng Việt/không dấu lung tung.
- `:slug` luôn có `constraints => ['slug' => '[a-z0-9\-]+']` (xem các route `news-detail`, `category`, `tag`, `service-detail`).
- URL bài viết **không nhúng slug danh mục** để đổi danh mục không gãy link (docs §2.1).

## 5. View script

- Đường dẫn theo mặc định Laminas: `view/<tên-controller-dasherized>/<action>.phtml` (folder `home-section`, `service`; file `index.phtml`, `list.phtml`, `detail.phtml`, `view.phtml`, `login.phtml`). **Tên file theo action tiếng Anh** (mặc định Laminas), KHÔNG phải tên tiếng Việt — xem `find module/*/view`.
- Layout đăng ký tường minh qua `view_manager.template_map`: `'layout/frontend'`, `'layout/admin'` (2 file `module/{Frontend,Admin}/view/layout/*.phtml`). Layout mặc định toàn ứng dụng là **`layout/frontend`** — key `view_manager.layout` đặt trong `module/Frontend/config/module.config.php` (14/09/2026); Admin không dùng key này mà đổi template runtime qua `AuthGuard::decorateAdminLayout()`, còn login/reset là terminal view.
- `view_manager.template_path_stack` trỏ vào `../view`; Admin bật `'ViewJsonStrategy'` để `JsonModel` tự encode.
- Trong `.phtml`, mở đầu bằng `<?php /** @var \Laminas\View\Renderer\PhpRenderer $this */ ?>` để Psalm biết `$this` (xem `layout/frontend.phtml`).

## 6. Escape output & lọc HTML

| Dữ liệu | Cách xử lý | Nguồn |
|---|---|---|
| Chuỗi động in ra HTML | `$this->escapeHtml($x)` (attr: `escapeHtmlAttr`, URL: `escapeUrl`) | docs §7.3 |
| Link / asset | `$this->url('<route>', [...])`, `$this->basePath(...)` (tự escape) | `layout/frontend.phtml`, `layout/admin.phtml` |
| Ảnh media | qua view helper `mediaUrl` (`Application\View\Helper\MediaUrl` → `/uploads/...`), KHÔNG hardcode URL tuyệt đối | `module/Application/src/View/Helper/MediaUrl.php` |
| Nội dung rich text (`posts.content`, `services.content`) | đi qua **`HtmlPurifierService`** trước khi LƯU, chỉ in raw phần đã lọc | dep `ezyang/htmlpurifier ^4.18` (`composer.json`), docs §3.3.4(6) |
| SQL | prepared statement / `Laminas\Db\Sql` + placeholder `:ten` | `PDO::prepare` trong `bin/create-admin.php`, docs §7.3 |

> `DbService` **đã triển khai** (bao bọc `Laminas\Db\Adapter\Adapter` từ key config `db`). `HtmlPurifierService`, `SlugService` **đã code thật** (từ 13/09 thuộc `Application\Service`), `MediaService` thuộc `Admin\Service` (13/09 — chỉ admin upload) — factory đăng ký bằng closure trong `module/Application/config/module.config.php` (trước 13/09 thuộc Core). Chuẩn là mọi xử lý HTML/slug/resize media/DB phải đi qua các service này — không inline trong Controller.

## 7. Thời gian, bảo mật, phiên

| Chủ đề | Chuẩn dự án | Nguồn |
|---|---|---|
| Múi giờ | Kết nối `SET time_zone='+00:00'`, DB lưu UTC, chỉ đổi sang +07 ở view | `config/autoload/global.php` `driver_options` |
| API time | chuỗi **ISO 8601 UTC** (`...Z`), frontend tự quy đổi | docs §6.2 |
| Mật khẩu | `password_hash()` (`PASSWORD_DEFAULT`) + `password_verify()`; min 8 ký tự | `bin/create-admin.php` |
| Số admin | `bin/create-admin.php` chặn khi `users` đã có ≥1 dòng — hệ **1 admin, không phân quyền** | `bin/create-admin.php`, docs §1.3 |
| Phiên | `Laminas\Session` name `VANLANG_SESS`, cookie `HttpOnly`+`SameSite=Lax`, validators `RemoteAddr`+`HttpUserAgent`, `gc_maxlifetime=7200` — key config **`session_config` / `session_storage` / `session_manager`** (laminas-session v2); **mọi dispatch** gọi `Application\Session\SessionBootstrap::ensureDefault` (setDefaultManager + biến validator-fail thành reset phiên sạch, không 500) | `config/autoload/global.php`, `module/Application/src/Session/SessionBootstrap.php` |
| Token reset | chỉ lưu **hash** token (`password_reset_tokens.tokenHash`), hết hạn 60', dùng 1 lần | docs §3.11, `schema.sql` |
| Lockout | `users.failedLoginCount` + `lockedUntil` (khoá 15' sau 5 lần sai) — ✅ triển khai trong `Admin\Service\AdminAuthService` | `schema.sql`, docs §3.11, [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md) |
| CSRF / captcha form liên hệ | ✅ `ContactSaveFilter` (InputFilter + validator `Csrf` + honeypot ẩn `website`), `Application\Service\CaptchaService` verify server-side, `Frontend\Service\ContactService` rate-limit 3 lần/IP/10 phút → **HTTP 429** (docs §5.10) | `module/Frontend/src/{Filter,Service}`, `module/Application/src/Service/CaptchaService.php` |
| Cache | `page_cache` Filesystem **ttl=60s** (bài hẹn giờ tự hiện sau ≤60'), + cache trang chủ/settings xoá theo sự kiện | `config/autoload/global.php` `caches`, docs §5.7/§7.2 |

## 8. Xử lý lỗi

- Trang lỗi dùng `Application` module (`module/Application/view/error/{index,404}.phtml`) — giữ từ skeleton.
- API: validate fail → HTTP 422 kèm `errors`; hết phiên → 401; vượt rate-limit → 429; **bị chặn bởi ràng buộc khi xoá (danh mục còn bài, media đang dùng) → 409** (KHÔNG phải 422). Nguồn docs §6.2.
- Xoá file media vật lý lỗi → **rollback transaction** để không còn bản ghi mồ côi (docs §3.10).

## 9. Quality gates (bắt buộc pass trước merge)

| Công cụ | Chạy | Phạm vi (rule) |
|---|---|---|
| PHP_CodeSniffer | `vendor/bin/phpcs` | ruleset `phpcs.xml`: **PSR-12** + `Generic`/`Squiz` bổ trợ; soi `config`, `module`, `public/index.php`; cho phép `.phtml`, bỏ `LineLength` cho `.phtml` |
| Psalm | `vendor/bin/psalm --stats` | `psalm.xml`: `errorLevel=1`, `findUnusedCode`, soi `module`+`config`+`public/index.php`, bỏ `vendor/data/bin` |
| PHPUnit | `vendor/bin/phpunit` | `phpunit.xml.dist`: test `module/*/test`, nguồn `module/*/src`; qua `laminas-test` |

## 10. Tương quan với `01-quy-uoc-dat-ten.md` + `07-crud-convention.md`

> **12/09/2026:** dự án **bỏ** các sai biệt cũ (Table/Form/Factory file) và **theo sát chuẩn chung**: `{Entity}Mapper` ở `src/Model/`, `{Entity}Model` POPO, `{Entity}{Action}Filter` ở `src/Filter/`, DI closure. **Refactor đã thực hiện xong 12/09/2026** cho toàn bộ code hiện có. Chi tiết tầng + vị trí: [`07-crud-convention.md`](07-crud-convention.md). Bảng dưới chỉ còn giá trị **lịch sử** — các dạng "cũ" không còn trong repo.

| Điểm | Chuẩn hiện hành (07) | Dạng cũ (đã xoá khỏi repo) |
|---|---|---|
| Data access | `{Entity}Mapper` ở `src/Model/{Entity}/` | `{Entity}Table` ở `src/Table/` |
| Input validate | `{Entity}{Action}Filter` (InputFilter) ở `src/Filter/` | `Laminas\Form` ở `src/Form/` |
| DI | closure inline trong `module.config.php` | file `*Factory implements FactoryInterface` |
| Model | `{Entity}Model` POPO + `fromRow()` | Mapper/Table trả array thô |
| Guard | `{X}Guard` trong `src/Service/` | `src/Guard/` riêng |

**Không đổi:** camelCase biến/hàm/cột, PascalCase class, `{action}Action`, Service chứa nghiệp vụ, Controller mỏng, tên file view theo action Laminas (`index/list/detail.phtml`) — giữ nguyên theo `01-quy-uoc-dat-ten.md` §5–6 ở trên.

> Checklist áp dụng các quy tắc trên khi review: [`04-checklist-review.md`](04-checklist-review.md). Chuẩn DB đi kèm: [`02-quy-chuan-db.md`](02-quy-chuan-db.md).
