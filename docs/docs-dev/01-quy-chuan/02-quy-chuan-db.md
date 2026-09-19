# Quy chuẩn DB

> Nguồn sự thật cấu trúc: [`data/schema/schema.sql`](../../../data/schema/schema.sql) (18 bảng) đối chiếu [§4 phân tích](../../phan-tich-he-thong-website-tin-tuc.md). Khi hai bên lệch nhau, **schema.sql thắng**. Bổ sung cho [`01-quy-uoc-dat-ten.md`](01-quy-uoc-dat-ten.md) — file đó nói *tên*, file này nói *chuẩn kỹ thuật DB*.

## 1. Nền tảng bắt buộc

| Hạng mục | Chuẩn dự án | Nguồn |
|---|---|---|
| Hệ quản trị | MySQL 8.0.19+ / engine `InnoDB` | `schema.sql` dòng 1 |
| Bảng mã | `utf8mb4`, collation **`utf8mb4_0900_ai_ci`** cho MỌI bảng/cột | mỗi `CREATE TABLE ... COLLATE=utf8mb4_0900_ai_ci` |
| Kết nối | `SET NAMES utf8mb4` + `SET time_zone = '+00:00'` | `schema.sql` dòng 4–5, `config/autoload/global.php` `PDO::MYSQL_ATTR_INIT_COMMAND` |
| Khoá chính | `id INT UNSIGNED AUTO_INCREMENT`; bảng tăng nhanh (`post_revisions`, `contact_submissions`) dùng `BIGINT UNSIGNED` | §4.1, `schema.sql` |
| Khoá ngoại | **KHÔNG khai báo FOREIGN KEY** — chỉ có `KEY` trên cột tham chiếu, toàn vẹn dữ liệu do tầng Service + transaction đảm nhiệm | §4.1, §4.6 |
| Xoá mềm | **KHÔNG có `deletedAt`** — mọi xoá là `DELETE` cứng (§4.6) | `schema.sql` không có `deletedAt` |
| `COMMENT` | DDL **không dùng `COMMENT`**; ý nghĩa cột đặt trong docs | §4.1 |

## 2. Đặt tên (tóm lại từ `01-quy-uoc-dat-ten.md`, chỉ bổ sung phần DB)

| Đối tượng | Quy tắc | Ví dụ thật |
|---|---|---|
| Bảng | `snake_case`, **số nhiều** | `posts`, `categories`, `home_section_items` |
| Cột | **`camelCase`** (KHÔNG `snake_case`) | `categoryId`, `publishedAt`, `isFeatured` |
| Cột tham chiếu | dạng `<Tên>Id`, luôn kèm index | `bannerMediaId` → `idx_posts_banner_media` |
| Boolean | `TINYINT(1)`, tiền tố `is` / `show` | `isActive`, `isFeatured`, `showContact`, `openNewTab` |
| Trạng thái | `TINYINT UNSIGNED` + mã số (không `ENUM`, không chuỗi) | `status`, `type`, `itemType`, `valueType` |
| Thời gian | `createdAt` / `updatedAt` (`DATETIME`), giá trị **UTC** | mỗi bảng có `DEFAULT CURRENT_TIMESTAMP [ON UPDATE ...]` |
| IP | `VARBINARY(16)` qua `INET6_ATON()` (IPv4 + IPv6) | `contact_submissions.ipAddress` |
| JSON | cột `JSON` cho dữ liệu có cấu trúc | `media.variants`, `home_sections.config`, `team_members.socialLinks` |

## 3. Quy tắc index / unique

Đặt tên theo prefix rõ nghĩa; tên index lấy mẫu từ schema:

| Loại | Mẫu tên | Ví dụ thật trong `schema.sql` |
|---|---|---|
| Unique | `uq_<bang>_<cot>` | `uq_posts_slug (slug)`, `uq_categories_slug`, `uq_tags_slug`, `uq_services_slug`, `uq_users_email`, `uq_media_path` |
| Unique đơn (nullable) | cho phép nhiều `NULL` | `uq_posts_preview_token (previewToken)`, `uq_team_members_user (userId)` |
| Unique tổ hợp | chặn trùng quan hệ | `uq_home_section_items (sectionId, itemType, itemId)` |
| Index thường | `idx_<bang>_<vai tro>` | `idx_posts_author (authorId)`, `idx_media_uploaded_by` |
| Index phục vụ lọc công khai | `<cotLoc>, status, publishedAt` | `idx_posts_category_status_published (categoryId, status, publishedAt)`, `idx_posts_featured_status_published` |
| Index bao điều kiện + sort | `(status, publishedAt)` | `idx_posts_status_published` |
| Index cây cha–con | `(parentId, sortOrder)` | `idx_categories_parent_sort` |
| Index media | mỗi cột `...MediaId` có 1 index | `idx_banners_mobile_image_media`, `idx_services_icon_media` |
| FULLTEXT | `ft_<bang>_<cot>` | `ft_posts_title_excerpt (title, excerpt)` |

- **Cột mới trỏ sang bảng khác → phải kèm index ngay trong migration** (vì không có FK, MySQL không tự tạo index tham chiếu).
- Bộ index bài viết luôn theo đúng thứ tự `status, publishedAt` để khớp điều kiện công khai `status = 1 AND publishedAt <= NOW()` rồi `ORDER BY publishedAt DESC` (§4.4.3 ghi chú). Không đảo thứ tự cột.

## 4. Bảng mã `status` / `type` (giá trị số)

| Cột | Mã | Nguồn |
|---|---|---|
| `posts.status` | `0`=draft · `1`=published · `2`=archived | §4.4.3, `schema.sql` `status TINYINT UNSIGNED DEFAULT 0` |
| `post_revisions.type` | `0`=manual · `1`=autosave · `2`=before_publish | §4.4.3 |
| `home_sections.type` | `1`=hero_banner · `2`=featured_posts · `3`=latest_posts · `4`=category_posts · `5`=services · `6`=team · `7`=contact_cta | §4.4.4 |
| `home_section_items.itemType` | `1`=post · `2`=service · `3`=team_member | §4.4.4 |
| `contact_submissions.status` | `0`=new · `1`=processing · `2`=done · `3`=spam | §4.4.6 |
| `settings.valueType` | `1`=string · `2`=text · `3`=html · `4`=number · `5`=boolean · `6`=json · `7`=media | §4.4.7 |

> Mã số định nghĩa ở tầng ứng dụng; DB chỉ lưu số. Khi thêm trạng thái/type mới phải cập nhật bảng này + docs §4.4 tương ứng.

## 5. Thời gian = UTC

- Giờ trong `createdAt/updatedAt/publishedAt/startAt/endAt/handledAt...` **luôn là UTC**.
- Kết nối bắt buộc chạy `SET time_zone = '+00:00'` ngay sau khi mở, nếu không `CURRENT_TIMESTAMP`/`NOW()` sẽ lệch (§4.1, `global.php` `PDO::MYSQL_ATTR_INIT_COMMAND`).
- Đổi sang giờ Việt Nam (+07:00) ở tầng hiển thị, KHÔNG đổi khi lưu.
- `viewDate` thống kê tính `UTC_DATE()`; nếu cần theo ngày VN thì `DATE(CONVERT_TZ(NOW(),'+00:00','+07:00'))` và ghi rõ lựa chọn (§5.8).

## 6. FULLTEXT tiếng Việt

| Yêu cầu | Chi tiết | Nguồn |
|---|---|---|
| Cỡ token tối thiểu | `innodb_ft_min_token_size = 2` trong cấu hình MySQL **rồi rebuild index** — mặc định 3 bỏ sót âm tiết "an", "đi" | §4.4.3, README |
| Index | `ft_posts_title_excerpt` trên `(title, excerpt)` | `schema.sql` dòng 115 |
| Truy vấn | `MATCH(...) AGAINST(:keyword IN NATURAL LANGUAGE MODE)` + lọc công khai | §5.11 |
| Khi phình to | cân nhắc Meilisearch/Elasticsearch (§8 giai đoạn 3) | §4.4.3 |

## 7. Migration / khai báo bảng

| Chuẩn dự án | Chi tiết |
|---|---|
| Một file DDL gộp | `data/schema/schema.sql` — `CREATE TABLE IF NOT EXISTS` đầy đủ 18 bảng |
| Seed | `data/schema/seed.sql` — `settings` + `home_sections` mặc định |
| Incremental (khi cần) | file `data/schema/YYYY-MM-DD-mo-ta.sql` chứa `ALTER`/`CREATE` mới; chạy tuần tự |
| Thêm cột | nếu cột tham chiếu bảng khác → kèm `KEY` ngay trong câu `ALTER` |
| Không FK | migration **không** được thêm `FOREIGN KEY`/`REFERENCES` |
| Không soft-delete | **không** thêm `deletedAt` ở bất kỳ bảng nào |

## 8. Bảng "được phép / cấm"

| # | ✅ Được phép | ❌ Cấm |
|---|---|---|
| 1 | `CREATE TABLE IF NOT EXISTS` trong `schema.sql` | `DROP TABLE` không kiểm soát trong migration |
| 2 | Cột `camelCase` (`categoryId`) | Cột `snake_case` (`category_id`), cột `PascalCase` |
| 3 | Bảng `snake_case` số nhiều (`posts`) | Bảng số ít (`post`), tên PascalCase |
| 4 | `KEY` trên cột tham chiếu, không ràng buộc | `FOREIGN KEY` / `REFERENCES` / `ON DELETE CASCADE` |
| 5 | `DELETE` cứng kèm xoá con trong transaction (§4.6) | `deletedAt` / "thùng rác" / khôi phục mềm |
| 6 | `TINYINT UNSIGNED` + mã số cho `status` | `ENUM(...)`, chuỗi `('draft','published')` |
| 7 | `utf8mb4_0900_ai_ci` | collation khác (`utf8mb4_general_ci`, `...unicode_ci`) |
| 8 | Giờ UTC + `SET time_zone='+00:00'` | giờ địa phương (`+07:00`) lưu vào DB |
| 9 | `innodb_ft_min_token_size=2` cho FULLTEXT Việt | để mặc định 3 với tiếng Việt |
| 10 | `UNIQUE` cho `slug` toàn bảng, `email`, `path`, `tokenHash` | `slug` trùng / không unique |
| 11 | `TINYINT(1)` `is*`/`show*` cho boolean | `BIT`/`VARCHAR(1)` |
| 12 | `VARBINARY(16)` cho IP (`INET6_ATON`) | `VARCHAR(45)` lưu IP dạng chuỗi |
| 13 | `COMMENT` đặt trong docs §4.4 | `COMMENT` trong DDL |

## 9. Kiểm tra nhanh trước khi merge thay đổi DB

- [ ] Cột mới dùng `camelCase`, đúng kiểu đã chốt ở mục 2.
- [ ] Bảng/cột là `utf8mb4_0900_ai_ci` / `InnoDB`.
- [ ] Không có `FOREIGN KEY`, `deletedAt`, hay `COMMENT`.
- [ ] Cột tham chiếu mới đã có `KEY` trong cùng migration.
- [ ] Cột `status`/`type` dùng `TINYINT UNSIGNED` + có dòng trong bảng mã §4.
- [ ] Cột giờ `createdAt/updatedAt` có `DEFAULT CURRENT_TIMESTAMP` và (nếu đổi được) `ON UPDATE CURRENT_TIMESTAMP`.
- [ ] Thay đổi phản ánh vào `schema.sql` và (nếu liên quan) `seed.sql`.
- [ ] Có mục cập nhật docs §4.2/§4.4 kèm theo (schema.sql là chuẩn, đối chiếu lại nếu lệch).

> Xem tầng Service xử lý xoá cứng không FK ở [`../02-thiet-ke/04-luong-nghiep-vu.md`](../02-thiet-ke/04-luong-nghiep-vu.md) và [`03-quy-chuan-code.md`](03-quy-chuan-code.md).
