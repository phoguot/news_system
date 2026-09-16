# Quy trình phát triển

> Quy trình lặp hằng ngày cho **Van Lang — News CMS**. Chuẩn code/DB bắt buộc: [`../01-quy-chuan/03-quy-chuan-code.md`](../01-quy-chuan/03-quy-chuan-code.md) và [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md); nguồn nghiệp vụ: [`../../phan-tich-he-thong-website-tin-tuc.md`](../../phan-tich-he-thong-website-tin-tuc.md) (docs v1.5).
> Repo đã có `.gitignore` (ignore `vendor/`, `config/autoload/local.php`, `*.local.php`, `data/cache/*`, `.phpunit.cache`, `.phpcs-cache`, `.psalm-cache`, `phpunit.xml`). Nếu bản checkout chưa có `.git` thì `git init` + thêm remote trước khi theo quy trình dưới đây.

## Vòng lặp 1 task

| Bước | Việc | Mốc kiểm tra |
|---|---|---|
| 1 | Đọc tài liệu: [`../02-thiet-ke/01-yeu-cau-chuc-nang.md`](../02-thiet-ke/01-yeu-cau-chuc-nang.md) + docs § tương ứng + [`../01-quy-chuan/03-quy-chuan-code.md`](../01-quy-chuan/03-quy-chuan-code.md) | Hiểu đúng ràng buộc (1 admin, không FK, không soft delete, không cronjob) |
| 2 | Tạo branch từ `main`: `feat/<tinh-nang>` · `fix/<loi>` · `chore/<viec>` (vd `feat/admin-post-list`) | Tên branch kebab-case, một branch = một task |
| 3 | Nếu là tính năng mới: copy [`../_templates/template-tinh-nang.md`](../_templates/template-tinh-nang.md) → `../02-thiet-ke/xx-ten.md`, điền luồng + màn hình + API trước khi code | File thiết kế được review |
| 4 | Nếu đụng DB: làm theo [`../05-van-hanh/02-migration-db.md`](../05-van-hanh/02-migration-db.md) (sửa `data/schema/schema.sql` + câu `ALTER` tăng dần) | Schema và tài liệu lệch nhau = **schema.sql thắng** |
| 5 | Code theo tầng: `Controller` → `Service` → `Mapper` (`src/Model/{Entity}/{Entity}Mapper.php`) + `Filter` cho input, route/DI closure trong `module.config.php` (chuẩn [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md)) | Không query DB ở controller, không render view ở service |
| 6 | Viết test cho phần mới (`module/{M}/test/...`) — skeleton chỉ có test của module Application, xem [`03-huong-dan-test.md`](03-huong-dan-test.md) | Test mới pass |
| 7 | Chạy đủ bộ công cụ (bảng dưới) **trước khi commit** | Sạch 3 công cụ |
| 8 | Commit nhỏ, message rõ hành động (vd `feat(admin): add post list with status tabs`); push; mở PR | Không commit `local.php`, `data/cache/`, `vendor/` |
| 9 | Tự tick [`../01-quy-chuan/04-checklist-review.md`](../01-quy-chuan/04-checklist-review.md) rồi mới request review | Reviewer chỉ sửa lỗi còn sót |

## Lệnh chạy trước khi commit

Dùng composer script (định nghĩa trong `composer.json` → `scripts`):

```bash
composer test              # vendor/bin/phpunit
composer cs-check          # vendor/bin/phpcs (PSR12 + rules Laminas, xem phpcs.xml)
composer cs-fix            # vendor/bin/phpcbf — tự sửa phần lớn lỗi format
composer static-analysis   # vendor/bin/psalm --stats (errorLevel 1, strict, xem psalm.xml)
composer clear-config-cache # sau khi sửa file trong config/ (config đang được cache)
```

> **Trạng thái baseline hiện tại của repo (đã chạy để kiểm chứng):** `composer test` **pass** (4 tests, 7 assertions). `composer cs-check` và `composer static-analysis` **đang fail** trên các file stub (mẫu controller viết 1 dòng, thiếu return type...). Khi sửa file nào thì đưa file đó về sạch trước rồi commit kèm; không bỏ qua/`@suppress` cho lỗi mới.

## Thêm một tính năng = thêm những gì

| Thành phần | Đường dẫn mẫu đã có trong repo | Ghi chú |
|---|---|---|
| Route + DI | `module/Admin/config/module.config.php`, `module/Frontend/config/module.config.php` | DI bằng **closure inline** trong `controllers.factories`/`service_manager.factories` (07 §4); không tạo file `*Factory.php` mới |
| Controller | `module/Admin/src/Controller/PostController.php` | Action kết thúc bằng `Action`, trả `ViewModel`/`JsonModel` |
| Service | `module/{M}/src/Service/{Entity}Service.php` (+ Application dùng chung — trước 13/09 là Core) | Đăng ký closure trong `module.config.php` → `service_manager.factories` |
| Mapper + Model + Const | `module/{M}/src/Model/{Entity}/` (`{Entity}Mapper.php`, `{Entity}Model.php`, `{Entity}Const.php`) | 1 bảng = 1 Mapper, inject `DbService`/alias `DbAdapter`; **không** dùng `src/Table/` (đã xoá khỏi repo 12/09/2026) |
| Filter | `module/{M}/src/Filter/{Entity}/{Entity}{Save,List}Filter.php` | InputFilter, thay cho `src/Form/` (07 §6) |
| View | `module/Admin/view/admin/{controller}/{action}.phtml`, layout `view/layout/admin.phtml` | Design-system tự host `public/css/admin.css` + `public/js/admin.js` (batch giao diện 13/09/2026 — port từ mockup `Image/files`, không framework ngoài) |
| Const/mã số | copy [`../_templates/template-const.md`](../_templates/template-const.md) → `Model/{Entity}/{Entity}Const.php` (chung → `Application/Constant/` — trước 13/09 là `Core/Constant/`) | Ví dụ `status`: 0=draft · 1=published · 2=archived (docs §4.4.3) — **không** dùng `ENUM` |
| Tài liệu tính năng | `../02-thiet-ke/xx-ten.md` (từ `template-tinh-nang.md`) | Bảng mới → `../_templates/template-bang-du-lieu.md` |

## Quy tắc nghiệp vụ hay bị quên (từ docs v1.5)

- **Không có cronjob.** Bài hẹn giờ = `status=1` + `publishedAt` tương lai, tự hiện nhờ `publishedAt <= NOW()` trong query → **cache trang phải có TTL ≤ 60s** (`caches.page_cache` trong `config/autoload/global.php` đã đặt `ttl => 60`).
- **Xoá là DELETE cứng**, không `deletedAt`, không thùng rác. Vì DB **không FK**, phải tự xoá bản ghi con trong cùng transaction (docs §4.6; mẫu ở §5.16).
- **Cột `camelCase`, bảng `snake_case`**, không `COMMENT` trong DDL, giờ lưu UTC (`SET time_zone = '+00:00'` đã đặt ở `PDO::MYSQL_ATTR_INIT_COMMAND`).
- **HTML người dùng nhập phải qua `HtmlPurifierService`** trước khi lưu (docs §3.3.4 mục 6) — service này hiện là stub.
- **Mọi API `/api/admin/*` phải kiểm tra phiên đăng nhập ở backend**, không dựa vào việc ẩn nút trên UI (docs §7.3).

## Review

- Người review dùng [`../01-quy-chuan/04-checklist-review.md`](../01-quy-chuan/04-checklist-review.md) (đã viết riêng cho dự án này, có mục DB bắt buộc).
- PR chỉ merge khi: tick đủ checklist + `composer test`/`cs-check`/`static-analysis` sạch trên các file thay đổi + migration (nếu có) đã theo [`../05-van-hanh/02-migration-db.md`](../05-van-hanh/02-migration-db.md).
- Chưa có CI/bot tự động trong repo (không có `.github/`) — bộ ba lệnh trên đang là "quality gate" thủ công.
