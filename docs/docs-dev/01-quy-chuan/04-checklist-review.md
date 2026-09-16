# Checklist Code Review

> Dùng cho **Van Lang — News CMS**. Copy vào mô tả PR hoặc tick trước khi request review. Mỗi mục suy ra từ [`02-quy-chuan-db.md`](02-quy-chuan-db.md), [`03-quy-chuan-code.md`](03-quy-chuan-code.md) và [docs phân tích](../../phan-tich-he-thong-website-tin-tuc.md). Vi phạm nhóm "Bắt buộc" = review không pass.

## 1. Chuẩn bị / thiết kế

- [ ] Xác định bảng + cột liên quan trong `data/schema/schema.sql` (không bịa cột).
- [ ] Đổi/siết nghiệp vụ → đã cập nhật [`../02-thiet-ke/01-yeu-cau-chuc-nang.md`](../02-thiet-ke/01-yeu-cau-chuc-nang.md) / [`../02-thiet-ke/04-luong-nghiep-vu.md`](../02-thiet-ke/04-luong-nghiep-vu.md) tương ứng.
- [ ] Nếu đụng state machine → [`../02-thiet-ke/05-may-trang-thai.md`](../02-thiet-ke/05-may-trang-thai.md) được cập nhật.

## 2. Database & migration (Bắt buộc)

- [ ] Có thay đổi schema → cập nhật `data/schema/schema.sql` (+ `seed.sql` nếu có dữ liệu nền) kèm câu `ALTER` tăng dần trong PR.
- [ ] Cột mới đặt **`camelCase`** (`categoryId`), không `snake_case`/`PascalCase` (DB §2).
- [ ] Bảng `snake_case` số nhiều; `ENGINE=InnoDB`; `utf8mb4_0900_ai_ci`.
- [ ] **Không** có `FOREIGN KEY` / `REFERENCES` / `ON DELETE CASCADE` (DB §1).
- [ ] **Không** thêm cột `deletedAt` / soft-delete (DB §7).
- [ ] **Không** có `COMMENT` trong DDL.
- [ ] Cột tham chiếu mới (`...Id`) → **có `KEY` ngay** trong migration.
- [ ] Cột `status`/`type` là `TINYINT UNSIGNED` + đã ghi mã vào bảng §4 `02-quy-chuan-db.md` (không `ENUM`).
- [ ] Cột giờ có `DEFAULT CURRENT_TIMESTAMP` (và `ON UPDATE` cho `updatedAt`); giá trị UTC.
- [ ] `slug`/`email`/`path` duy nhất → `UNIQUE KEY uq_...`.
- [ ] Bộ lọc bài viết index theo đúng thứ tự `(..., status, publishedAt)`.

## 3. Tầng ứng dụng

- [ ] Logic nằm ở **Service**; Controller chỉ nhận request → gọi Service → trả `ViewModel`/`JsonModel` (chuẩn [`07-crud-convention.md`](07-crud-convention.md)).
- [ ] Query qua **Mapper** (`src/Model/{Entity}/{Entity}Mapper.php`), **một mapper chỉ đụng bảng của nó** — không join/select bảng của mapper khác (rule 07 §5).
- [ ] **Xoá = xoá cứng trong MỘT transaction** (điều phối ở Service): xoá hết bản ghi con trước rồi mới cha theo docs §4.6 (`posts`→`post_tags`+`post_revisions`+`post_view_daily`+`home_section_items(itemType=1)`→`posts`).
- [ ] Chặn xoá đúng chỗ: danh mục còn bài/con (§5.15), media đang dùng (§5.14) → trả **409**, không xoá.
- [ ] Validate input bằng **InputFilter** ở `src/Filter/{Entity}/{Entity}{Save,List}Filter.php`, không validate tay rải rác trong Controller.
- [ ] 4 luật thực thi tầng (08): Controller mỏng — chỉ params → Service (§1); Service trọn flow validate → quyền → tồn tại → mapper → response (§2); update 1–2 trường dùng hàm `updateAttributeXxx` riêng, không `save` đè toàn field (§3); timestamp qua helper tập trung, không `time()` rải rác (§4).
- [ ] Tên biến/hàm/class đúng [`01-quy-uoc-dat-ten.md`](01-quy-uoc-dat-ten.md) (camelCase, `{action}Action`, PascalCase class).
- [ ] Không còn **magic number** cho `status`/`type` — dùng hằng trong `{Entity}Const.php` (entity) hoặc `Application/Constant/` (dùng chung — trước 13/09 là `Core/Constant/`) — [`06-quy-uoc-const.md`](06-quy-uoc-const.md).
- [ ] Query ở Mapper/Service **khai báo tường minh từng mệnh đề** (07 §5.1): tạo statement rồi gọi `columns()`/`where()`/`order()`/`limit()`/`set()`/`values()` mỗi cái một lệnh riêng — cấm chuỗi `->` nhiều mệnh đề trong một biểu thức.
- [ ] Dependency đăng ký bằng **closure inline trong `module.config.php`**, không tạo file `*Factory.php` mới, không `new` dịch vụ có trạng thái (07 §4).
- [ ] Route frontend dùng URL tiếng Việt kebab (`/tin-tuc`...), route name ASCII; `:slug` có `constraints`.

## 4. Bảo mật (Bắt buộc)

- [ ] Mọi SQL dùng **prepared statement / placeholder** — không nối chuỗi.
- [ ] Nội dung HTML (`posts.content`, `services.content`) được **HTMLPurifier** lọc trước khi LƯU.
- [ ] Chuỗi động in ra view đã **escape** (`escapeHtml`/`escapeHtmlAttr`/`escapeUrl`); chỉ in raw phần đã lọc.
- [ ] Không lộ đường dẫn upload tuyệt đối — ảnh qua helper `mediaUrl`.
- [ ] Mật khẩu qua `password_hash()`; **không** log/mã hoá reversable; token reset chỉ lưu hash.
- [ ] Form có **CSRF**; form liên hệ có **captcha** + **rate-limit theo IP** (§5.10: ≥3 lần/10' → 429).
- [ ] API admin **xác thực phiên** ở mọi endpoint (không chỉ ẩn nút UI).
- [ ] Cookie phiên `HttpOnly` + `SameSite=Lax`; cấu hình nhạy cảm ở env/`local.php`, **không** vào DB.
- [ ] Không cho upload SVG; kiểm MIME thật; đổi tên file ngẫu nhiên; thư mục `uploads/` **không thực thi script** (cần khai báo ở web server).

## 5. Hiệu năng & cache

- [ ] Danh sách đi qua **index** đã chốt (không full-scan); nếu query lớn → kèm `EXPLAIN` trong PR (§7.2).
- [ ] Có **phân trang** cho danh sách (`?page=`, `Laminas\Paginator`).
- [ ] Trang chủ/danh mục/settings **cache**; mọi cache có bài hẹn giờ đặt **TTL ≤ 60s** hoặc hết hạn đúng `publishedAt` (§5.7/§7.2).
- [ ] Cache **xoá theo sự kiện** khi bài/banner/dịch vụ/nhân sự/section thay đổi.
- [ ] Ảnh có biến thể (`thumb/medium/large`) + `loading="lazy"` cho ảnh dưới màn hình đầu.

## 6. SEO

- [ ] Trang mới có `<title>`, meta description, canonical; OG/Twitter (banner bài = `og:image`).
- [ ] `sitemap.xml` sinh từ bài/danh mục/dịch vụ; đổi slug bài đã xuất bản → cân nhắc (hệ thống **không** có redirect).
- [ ] Trang tìm kiếm & trang `?previewToken=` gắn **`noindex`**.

## 7. Kiểm thử & static analysis (Bắt buộc)

- [ ] `vendor/bin/phpunit` — pass; thêm test cho hành vi mới (`module/{M}/test`, dùng `laminas-test`).
- [ ] `vendor/bin/phpcs` — sạch theo `phpcs.xml` (PSR-12 + Laminas style).
- [ ] `vendor/bin/psalm --stats` — sạch theo `psalm.xml` (errorLevel 1, `findUnusedCode`).
- [ ] Luồng xoá cứng & hẹn giờ có test chứng minh (transaction, `publishedAt <= NOW()`).

## 8. Tài liệu

- [ ] Cập nhật [`../02-thiet-ke/03-mo-hinh-du-lieu.md`](../02-thiet-ke/03-mo-hinh-du-lieu.md) nếu thêm/đổi bảng·quan hệ.
- [ ] Cập nhật [`../02-thiet-ke/02-yeu-cau-phi-chuc-nang.md`](../02-thiet-ke/02-yeu-cau-phi-chuc-nang.md) nếu đổi SEO/hiệu năng/bảo mật.
- [ ] Mã `status`/`type` mới được ghi vào docs §4.4 và [`02-quy-chuan-db.md`](02-quy-chuan-db.md).

## 9. Trước khi bấm merge

- [ ] Không còn placeholder `...` / `TODO` / comment thừa còn sót.
- [ ] Không để secret (`local.php`, SMTP, khoá captcha) lọt vào commit / log.
- [ ] Migration chạy lại được trên DB sạch (`schema.sql` idempotent + `seed.sql`).
- [ ] Diff nhỏ, mỗi PR một mục đích; mô tả PR nêu docs section liên quan (§4.6, §5.7...).
