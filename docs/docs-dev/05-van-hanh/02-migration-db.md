# Migration & Schema DB

## Dự án KHÔNG có công cụ migration

| Kiểm chứng | Kết quả |
|---|---|
| `composer.json` → `require` / `require-dev` | **Không** có `doctrine/migrations`, không có `doctrine/dbal`, không có `robmorgan/phinx` (đã grep `composer.json` + `composer.lock` = 0 kết quả) |
| Script composer | Không có lệnh `migrate` / `migrations:diff` — chỉ có `clear-config-cache`, `cs-check`, `cs-fix`, `test`, `static-analysis`, `serve`, `development-*` |
| Cổng deploy | Không có bảng `migrations`/`migration_versions` trong `data/schema/schema.sql` (đủ 18 bảng, xem docs §4.2) |

→ **Toàn bộ versioning cấu trúc nằm trong file SQL + quy trình tay.** Không có "tool migrate của dự án" như template cũ giả định.

## Các file nguồn sự thật

| File | Vai trò | Lưu ý |
|---|---|---|
| `data/schema/schema.sql` | **DDL đầy đủ 18 bảng** — nguồn sự thật cấu trúc | `CREATE TABLE IF NOT EXISTS`; `SET NAMES utf8mb4;` + `SET time_zone = '+00:00';` ở đầu file; mọi bảng `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`; **không INSERT dữ liệu** |
| `data/schema/seed.sql` | Dữ liệu nền: 19 dòng `settings` + 6 `home_sections` + menu mặc định | Chỉ dùng cho DB mới; **không** chứa tài khoản/mật khẩu (docs §4.5) |
| `data/schema/README.md` | Hướng dẫn tạo DB + lưu ý `innodb_ft_min_token_size = 2` | Đọc trước khi import |
| `bin/create-admin.php` | "Seed" tài khoản admin duy nhất bằng CLI, nhập mật khẩu tương tác | Từ chối khi `users` đã có ≥ 1 dòng |
| `data/schema/YYYY-MM-DD-mo-ta.sql` (đề xuất) | **File incremental** cho DB đã có | **Quy ước này chưa tồn tại file nào trong repo** — từ nay đặt tên như vậy, để cạnh `schema.sql` |

⚠ **Bẫy lớn nhất:** `schema.sql` dùng `CREATE TABLE IF NOT EXISTS`, nên chạy lại trên DB cũ **không** thêm cột/đổi kiểu — nó im lặng bỏ qua bảng đã tồn tại. Mọi thay đổi trên môi trường đang có dữ liệu **phải** đi qua file incremental `ALTER`.

## Quy trình thêm/sửa bảng (4 bước, bắt buộc)

1. **Backup trước** (docs §7.4 — hệ thống xoá cứng, không thùng rác, backup là lớp phục hồi duy nhất):
   ```bash
   mysqldump -u root -p --single-transaction --routines --triggers \
     --default-character-set=utf8mb4 news_system > pre-migration-$(date +%F-%H%M).sql
   ```
2. **Sửa `data/schema/schema.sql`** (định nghĩa đầy đủ, giữ đúng chuẩn: cột `camelCase`, bảng `snake_case` số nhiều, `TINYINT UNSIGNED` + mã số cho `status`/`type`, `KEY` cho mọi cột tham chiếu, **không** `FOREIGN KEY`, **không** `deletedAt`, **không** `COMMENT`) — và cập nhật seed nếu có dữ liệu nền mới. Chuẩn chi tiết: [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md).
3. **Viết file incremental** `data/schema/YYYY-MM-DD-mo-ta.sql`, chỉ chứa câu `ALTER`/`CREATE TABLE IF NOT EXISTS` chạy được trên DB **cũ**:
   ```sql
   SET NAMES utf8mb4;
   SET time_zone = '+00:00';
   -- vd: thêm cột tham chiếu mới
   ALTER TABLE posts ADD COLUMN sourceMediaId INT UNSIGNED NULL AFTER thumbnailMediaId;
   ALTER TABLE posts ADD KEY idx_posts_source_media (sourceMediaId);   -- thêm cột Id → kèm index NGAY
   ```
   Kèm file **mô tả rollback** (thường chỉ là "restore `pre-migration-*.sql`").
4. **Chạy tuần tự trên prod, rồi clear cache app:**
   ```bash
   mysql -u root -p news_system < data/schema/2026-09-12-them-cot-x.sql
   composer clear-config-cache
   vendor/bin/phpunit
   ```

## Thứ tự áp dụng (vì KHÔNG có FOREIGN KEY)

DB không có FK → MySQL **không** chặn thao tác sai thứ tự, cũng **không** cascade khi xoá. Trật tự là trách nhiệm của người chạy và của tầng ứng dụng (docs §4.1, §4.6):

| Khi tạo DB mới | Khi ALTER trên DB có dữ liệu | Khi xoá bản ghi |
|---|---|---|
| Chạy `schema.sql` rồi `seed.sql` là đủ — thứ tự bảng trong file (`users` → … → `settings`) không mang ý nghĩa ràng buộc, chỉ là trình bày docs §4.4 | Chạy incremental **trước** khi deploy code dùng cột/bảng mới (code cũ không biết cột mới → không hỏng; code mới chạy trên schema cũ → lỗi `Unknown column`) | Tầng ứng dụng phải tự xoá bản ghi con trong **cùng một transaction** rồi mới xoá cha (docs §4.6, mẫu §5.16) — MySQL không tự làm |
| Tài khoản admin tạo bằng `bin/create-admin.php` **sau** `seed.sql` | Đổi cột `NOT NULL` trên bảng có dữ liệu → `UPDATE` giá trị hợp lệ trước, rồi mới `ALTER` | Chặn xoá khi còn ràng buộc nghiệp vụ: danh mục còn bài/con (docs §5.15), media đang được dùng (docs §5.14) → trả HTTP 409 |
| `innodb_ft_min_token_size = 2` phải đặt **trước** khi tạo bảng có FULLTEXT, nếu không phải rebuild | Thêm FULLTEXT/`ALTER TABLE ... ADD FULLTEXT` cần thời gian; làm giờ thấp điểm | Không có `deletedAt` → không có "khôi phục", chỉ có restore backup |

Cột giờ: luôn `DATETIME` + default `CURRENT_TIMESTAMP` (`updatedAt` thêm `ON UPDATE CURRENT_TIMESTAMP`), giá trị UTC vì kết nối đã `time_zone='+00:00'` (`config/autoload/global.php`).

## Rebuild FULLTEXT (khi đổi `innodb_ft_min_token_size`)

```sql
-- Chạy SAU khi đã sửa my.cnf/my.ini và restart MySQL
ALTER TABLE posts DROP INDEX ft_posts_title_excerpt;
ALTER TABLE posts ADD FULLTEXT KEY ft_posts_title_excerpt (title, excerpt);
```

## Rollback

**Không có** `migrate:down`. Rollback = **restore bản backup** ở bước 1:

```bash
mysql -u root -p news_system < pre-migration-2026-09-12-1530.sql
```

Hệ quả phải chấp nhận: mọi dữ liệu tạo **sau** thời điểm backup cũng mất theo. Vì vậy:
- Không gộp nhiều thay đổi lớn vào một lần migration — mỗi file incremental một chủ đề.
- Backup ngay trước migration **và** backup hằng ngày theo lịch (docs §7.4, [`04-log-giam-sat.md`](04-log-giam-sat.md)).
- Với cột/bảng thêm mới không đụng dữ liệu cũ: có thể "rollback mềm" bằng `ALTER TABLE ... DROP COLUMN` mà không cần restore cả DB.
- **Không có** migration tự động cho `media`/file trên đĩa: nếu cấu trúc liên quan tới đường dẫn file, phải kèm bước migrate `public/uploads` (xem [`../04-huong-dan/04-import-export.md`](../04-huong-dan/04-import-export.md)).

## Checklist trước khi merge PR có đổi schema

- [ ] `schema.sql` đã cập nhật + file incremental `data/schema/YYYY-MM-DD-*.sql` đi kèm (2 file phải khớp nhau).
- [ ] Không có `FOREIGN KEY` / `REFERENCES` / `ON DELETE` / `deletedAt` / `ENUM` / `COMMENT`.
- [ ] Cột tham chiếu mới có `KEY`; cột lọc cùng `status`/`publishedAt` vào index theo đúng thứ tự `(…, status, publishedAt)`.
- [ ] Cột `status`/`type` mã số đã ghi vào bảng mã ở docs §4.4 và [`../02-thiet-ke/03-mo-hinh-du-lieu.md`](../02-thiet-ke/03-mo-hinh-du-lieu.md).
- [ ] Đã chạy incremental trên DB dev copy từ backup prod (nếu có thể) và chạy `vendor/bin/phpunit`.
- [ ] [`../01-quy-chuan/04-checklist-review.md`](../01-quy-chuan/04-checklist-review.md) mục "Database & migration" đã tick.
