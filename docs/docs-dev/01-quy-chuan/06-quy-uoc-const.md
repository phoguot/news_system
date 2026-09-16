# Quy ước Const

> **Hằng thuộc entity nào đặt cạnh entity đó** (`{Entity}Const.php` trong `Model/<Entity>/`); hằng **dùng chung** nhiều module → `Application/Constant/` (trước 13/09 là `Core/Constant/`); hằng kỹ thuật của module không gắn entity cụ thể → giữ `{Module}Const.php`. Chống magic number/string rải rác trong Service/Controller. Mẫu copy: [`../_templates/template-const.md`](../_templates/template-const.md).
> **Cập nhật 12/09/2026:** theo chuẩn tầng mới [`07-crud-convention.md`](07-crud-convention.md) — bỏ quy tắc cũ "1 module = 1 file const".

## 1. Vị trí & tên

| Loại hằng | Đường dẫn | Namespace / ví dụ |
|---|---|---|
| Hằng của 1 entity/bảng (STATUS, TYPE, LABELS, giới hạn riêng entity) | `module/{M}/src/Model/{Entity}/{Entity}Const.php` | `Frontend\Model\Contact\ContactConst::STATUS_SPAM` |
| Hằng dùng chung ≥ 2 module | `module/Application/src/Constant/{Chung}Const.php` | `Application\Constant\ContentConst::STATUS_PUBLISHED` |
| Hằng kỹ thuật module (session namespace, key route…) không gắn entity | `module/{M}/src/Constant/{M}Const.php` | `Admin\Constant\AdminConst::AUTH_SESSION_NAMESPACE` |

- Tên hằng `UPPER_SNAKE`, có prefix ngữ nghĩa: `STATUS_`, `TYPE_`, `ERROR_`.
- Map hiển thị: `const STATUS_LABELS = [self::STATUS_DRAFT => 'Bản nháp', ...]`.

## 2. Phạm vi đặt const — theo quyền sở hữu dữ liệu

Hằng phản ánh **giá trị cột trong `data/schema/schema.sql`** → đặt ở `Model/<Entity>/<Entity>Const.php` của module sở hữu bảng (Admin là module ghi các bảng này). Hằng **dùng chung** giữa Frontend và Admin (lọc theo `posts.status`) → đặt ở `Application\Constant\ContentConst` (trước 13/09 là `Core\`) để cả hai module `use` chung, **không** khai báo trùng ở 2 nơi.

Giá trị nghiệp vụ đã chốt (nguồn schema.sql + docs §3.3, §3.9):

| Bảng / cột | Hằng | Giá trị |
|---|---|---|
| `posts.status` | `ContentConst::STATUS_DRAFT` / `_PUBLISHED` / `_ARCHIVED` (dùng chung — `Application\Constant\ContentConst`) | `0` / `1` / `2` |
| `contacts.status` | `ContactConst::STATUS_NEW` / `_PROCESSING` / `_DONE` / `_SPAM` | `0` / `1` / `2` / `3` |
| Bài hẹn giờ | *(không có hằng riêng)* | `status=1` + `publishedAt` tương lai — bộ lọc `publishedAt <= NOW()` quyết định, xem docs §5.7 |

## 3. Luật

- **Không** hard-code `0`/`1`/`2` hay `'draft'` trong Service/Controller/view — luôn qua `XxxConst::...`.
- Không tự tạo class const trung gian cho 1 status (`PostStatus`, `StatusEnum` riêng lẻ) — gộp vào const của entity.
- Cùng một giá trị không trùng tên giữa 2 module; cần dùng chéo → `use` từ file gốc (xem mục 2).
- Giá trị hằng **phải khớp số liệu trong `schema.sql`**; đổi schema = đổi const trong cùng commit (checklist: [`04-checklist-review.md`](04-checklist-review.md)).
- `status` là `TINYINT` — hằng là số, không phải chuỗi.

## 4. Trạng thái trong repo (kiểm chứng 12/09/2026 — sau refactor + đợt CRUD)

- `Frontend/Constant/FrontendConst.php` đã **xoá**: toàn bộ hằng luồng liên hệ → `Frontend\Model\Contact\ContactConst` (status, rate-limit, honeypot, SUBMIT_*, MAX_LENGTH_*, ERROR_*), key settings → `Frontend\Model\Setting\SettingConst`.
- `Admin/src/Constant/AdminConst.php` **giữ** (đúng chuẩn): chỉ còn hằng kỹ thuật module — session namespace, `MAX_FAILED_LOGINS`, `LOCKOUT_MINUTES`, thông báo lỗi đăng nhập.
- `Application/Constant/ContentConst.php` **đã tạo** (trước 13/09 là `Core/Constant/`) ✅ (cùng đợt CRUD 12/09/2026): `STATUS_DRAFT/PUBLISHED/ARCHIVED` (0/1/2) + `STATUS_LABELS` dùng chung Frontend↔Admin; `SECTION_ITEM_POST/SERVICE/TEAM_MEMBER` (1/2/3 — khớp `home_section_items.itemType`).
- Hằng entity đợt CRUD (Admin): `Model\Post\PostConst` (INTENT_*, TAB_* + `TAB_LABELS`, `WORDS_PER_MINUTE=200`, `REVISION_KEEP=20`, `MAX_TAGS_PER_POST=20`, `MAX_LENGTH_*`, `FLAG_*`, `ERROR_*`), `Model\Category\CategoryConst`, `Model\Tag\TagConst` (slug/label/error riêng entity, giới hạn độ dài theo schema). Các mapper bảng phụ (`PostTag`, `PostRevision`, `PostViewDaily`, `HomeSectionItem`) chưa có `*Const` vì chưa có giá trị enum riêng — tạo khi cần, đúng mục 1.
