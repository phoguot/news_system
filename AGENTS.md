# AGENTS.md — Luật cho AI agent làm việc trong repo

Repo: **Van Lang — News CMS** (PHP 8.3 + Laminas MVC). Mọi agent (ZCode/Claude/Codex…) trước khi hành động phải theo đúng thứ tự ưu tiên đọc dưới đây.

## 1. Thứ tự ưu tiên đọc (BẮT BUỘC: skill > docs > code)

1. **SKILL trước** — rà danh sách skill khả dụng trong phiên; có skill phù hợp loại việc (test web, tài liệu, điều khiển máy tính, cấu trúc/CRUD…) thì **nạp skill và chạy theo quy trình của nó** trước khi làm. Skill = cách thức thực hiện.
2. **DOCS thứ hai** — `docs/docs-dev/` là **nguồn sự thật** về kiến trúc, quy chuẩn, bảo mật và trạng thái repo. Tối thiểu phải đọc trước mỗi task:
   - [`docs/docs-dev/README.md`](docs/docs-dev/README.md) — bản đồ tài liệu.
   - [`docs/docs-dev/01-quy-chuan/07-crud-convention.md`](docs/docs-dev/01-quy-chuan/07-crud-convention.md) — chuẩn tầng module (quan trọng nhất khi viết/sửa code).
   - [`docs/docs-dev/00-tong-quan/05-cau-truc-thu-muc.md`](docs/docs-dev/00-tong-quan/05-cau-truc-thu-muc.md) — cây thư mục chuẩn + trạng thái refactor.
   - Theo domain bị chạm mà đọc thêm: `01-quy-chuan/02-quy-chuan-db.md`, `03-quy-chuan-code.md`, `06-quy-uoc-const.md`, `05-van-hanh/03-xac-thuc-phan-quyen.md`, `04-huong-dan/*`.
3. **CODE cuối** — chỉ đọc code sau khi đã đi qua skill + docs, để xác nhận trạng thái thực tế và điểm sửa. Grep/đọc file code là bước xác minh, không phải bước quyết định thiết kế.

**Khi mâu thuẫn:** cách làm theo **skill**, chuẩn thức/kiến thức theo **docs** — không dùng skill để đổi chuẩn kiến trúc đã ghi trong docs. Chuẩn code mới = docs (code phải tuân theo docs). Nhưng nếu docs mô tả *trạng thái repo* lệch với code/schema thực tế → báo rõ cho user và **cập nhật docs trong cùng phiên**, không âm thầm sửa theo một trong hai bên.

## 2. Luật kỹ thuật (tóm tắt — chi tiết ở docs §1)

- Tầng module chuẩn: `src/Model/<Entity>/{<Entity>Mapper, <Entity>Const}`, `src/Filter/<Entity>/*Filter.php`, guard/builder nằm trong `src/Service/`. **Cấm** tạo mới `src/Table/`, `src/Form/`, `src/Guard/`, `Controller/Factory/`, và file `*Factory.php` **ngoài 2 file nền đã duyệt** `Application/src/Factory/{AppServiceFactory, AppInvokableFactory}.php` (13/09/2026).
- DI (chuẩn 13/09/2026 — 07 §4 bản mới): **Service** `extends AppServiceFactory`, constructor không nhận gì, dependency qua typed accessor `getContainerEntry(<Class>::class)`, đăng ký `Service\XService::class => AppInvokableFactory::class`; **Controller/Mapper** vẫn closure inline `static fn (ContainerInterface $c) => new X(...)`; controller không dependency → `InvokableFactory`. Ngoại lệ: KHÔNG CÒN (batch 7 — 13/09/2026) — 4 service `Application` từng nhận mảng config (`DbService`/`MailService`/`CaptchaService`/`HtmlPurifierService`) đã bỏ constructor, đọc `Config` lazy qua accessor, đăng ký `AppInvokableFactory`; sau khi sửa mọi `module.config.php` phải `php bin/clear-config-cache.php` vì `config_cache_enabled => true`. Test service: `(new X())->setContainer(new TestContainer([...]))`.
- Luồng chuẩn (12/09/2026): **Controller mỏng** (chỉ nhận request, inject id route vào raw, gọi Service, render) → **Service** chạy Filter validate input → Service gọi Mapper → **Mapper đọc trả `<Entity>Model` hydrate (`fromRow`)** → ngược về Service → View/JsonModel. Controller **không** `new` Filter, **không** nhận Mapper.
- 1 Mapper = 1 bảng, không JOIN chéo bảng; phép chiếu tổng hợp (COUNT group by…) có thể giữ mảng scalar.
- `InputFilter` là stateful (`setData`) → **Service** `new` trực tiếp mỗi request, **không** đăng ký vào container.
- Hằng: entity → `Model/<Entity>/<Entity>Const.php`; kỹ thuật module → `<Module>/Constant/`; dùng chung 2 module → `Application/Constant/` (13/09/2026: Core gộp vào Application).
- Filter (13/09/2026): MỌI InputFilter của Admin/Frontend `extends Application\Filter\AppInputFilter` — CSRF (`csrfHash()`), `fieldErrors()`, `setContainer`/`getContainer` và helper field (`addIdField`, `addStringField`, `addSlugField`, `addIntCastField`, `addRawField`, `addDateTimeField`) + getter kiểu (`positiveIdValue`…) nằm ở nền tảng; filter riêng chỉ khai báo field rồi gọi `parent::__construct($withCsrf)` **cuối constructor**. Vẫn `new` mỗi request trong Service — cấm đăng ký filter vào container (chi tiết: `01-quy-chuan/07-crud-convention.md` §6).
- View `.phtml`: không dùng control structure PHP trong template body (phpcs báo lỗi tokenizer) — dựng HTML fragment bằng string trong block PHP đầu file.
- Cache nền (13/09/2026 FR-39 — 03-kien-truc §Cache): dữ liệu công khai đọc-nhiều-đổi-chậm qua `Application\Service\PageCacheService` (`remember(key, producer)` / `forget(key)`, storage `page_cache` khai báo theo **schema laminas-cache v3** trong `global.php`, TTL 60s — không cronjob); tên service + key cache tại `Application/Constant/CacheConst.php`. Luồng đọc settings **không gọi thẳng mapper** — đi qua `Frontend\Service\SettingService`; dữ liệu trang chủ đi qua `Frontend\Service\HomeService` (payload **mảng thuần** để cache nguyên khối key `home-v1` — batch 9 13/09; Frontend đọc mapper Admin-owned qua container gộp, **chỉ SELECT**); service Admin sau khi ghi phải `invalidate()`/`forget()` key tương ứng — mọi service GHI mới chạm dữ liệu công khai cũng phải gắn hook `forget` (khuôn `pageCache()` instanceof-guard + `invalidateHome()` của batch 9). Cache **không phải dependency cứng**: storage thiếu/không dựng được → producer chạy thẳng (degrade). `data/cache/page` phải tồn tại (Filesystem adapter không tự tạo — `.gitkeep` trong repo); payload tự serialize bọc mảng 1 phần tử, KHÔNG bật plugin `serializer` (thiếu package).

## 3. Luật bảo mật (tuyệt đối không nới)

- CSRF cho **mọi** form: validator `Csrf` do nền tảng `Application\Filter\AppInputFilter` gắn vào các filter (kể từ 13/09/2026 — không filter nào tự code Csrf riêng nữa), hidden input render bằng `csrfHash()`; API JSON bỏ token phải dựa SameSite=Lax (luật cũ không đổi).
- Mọi query qua prepared statement (`prepareStatementForSqlObject`), bind tham số — không nội suy chuỗi vào SQL.
- Không commit secrets; chỉ có `config/autoload/local.php.dist` trong repo, `local.php` là gitignore.
- Khóa tài khoản 5 lần sai / 15 phút (`AdminAuthService` + `AdminConst`); bảng `users` chỉ **1 dòng** — `bin/create-admin.php` từ chối dòng thứ hai.
- Không phá cơ chế `Application\Session\SessionBootstrap` (gộp từ Core 13/09/2026) attach qua `Module::init()`/SharedEventManager — điều kiện sống còn cho CSRF + identity (giải thích: `05-van-hanh/03-xac-thuc-phan-quyen.md`).

## 4. Nghiệm thu trước khi báo hoàn thành

```
vendor/bin/phpunit          # baseline: 334/334 xanh (958 assertions — rà 16/09; repo được chỉnh song song bởi các phiên khác, số có thể tiếp tục nhảy — chỉ báo LỆCH GIẢM bất thường hoặc ĐỎ, không cần khớp chính xác; mốc cũ 328/939 hết hiệu lực)
vendor/bin/phpcs <files>    # baseline: 0 lỗi trên file động vào (module/Admin/view đã SẠCH TOÀN BỘ sau batch giao diện 13/09/2026 — mốc cũ "~74 lỗi ở 4 file" hết hiệu lực do rewrite + phpcbf; WARNING line-length không tính lỗi)
vendor/bin/psalm            # không được TĂNG quá baseline 162 lỗi (stub controller) — hiện 140 (--no-cache, 16/09; diễn biến 80→72→70→68→140: tăng do batch pricing/frontend thêm PossiblyUnused/MixedAssignment tồn dư — vẫn ≤ 162). LƯU Ý: repo biến đổi song song + chạy có cache báo lệch — luôn lấy `vendor/bin/psalm --no-cache` làm chuẩn tại thời điểm báo cáo
```

- thay đổi cấu trúc folder / luồng / quy chuẩn → **cập nhật docs cùng phiên** (docs phải luôn phản ánh đúng repo).
- Windows + Git Bash: dùng đường dẫn tuyệt đối; file tạm viết trong workspace (`/tmp` không thấy từ PHP phía Windows).

## 5. Ngôn ngữ

Trả lời user bằng **tiếng Việt**; docs và comment trong repo viết tiếng Việt theo văn phong hiện có.
