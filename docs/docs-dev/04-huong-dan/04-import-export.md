# Import / Export (nếu có)

> **Kết luận nhanh:** dự án này **chưa có** luồng import nội dung dạng file (không công cụ import bài viết CSV/Excel, không màn hình upload hàng loạt). Đã có thật: các luồng **cài đặt & sao lưu** (`schema.sql` + `seed.sql`, `bin/create-admin.php`, `mysqldump`), route `sitemap.xml` (hàng thật từ 13/09 batch 18 — FR-11), và **xuất CSV hộp thư liên hệ ✅ 14/09 (FR-35)** — `ContactController::exportAction` qua route page `/contacts[/:action[/:id]]` (URL thật `/admin/contacts/export`), `ContactService::exportCsv` tái dùng đúng bộ lọc status/dịch vụ/từ-đọc. Bản API §6.2 `GET /api/admin/contacts/export` vẫn chưa có (page-first — cùng hướng lệch với FR-26/29/30/32/34).

## Những gì đang có thật

| Luồng | Chiều | Cách chạy | Nguồn trong repo |
|---|---|---|---|
| Tạo cấu trúc DB (18 bảng) | **Import** | `mysql -u root -p news_system < data/schema/schema.sql` | `data/schema/schema.sql` (`CREATE TABLE IF NOT EXISTS`, `SET NAMES utf8mb4`, `SET time_zone='+00:00'`) |
| Dữ liệu nền (settings + 6 home_sections + menu mặc định) | **Import** | `mysql -u root -p news_system < data/schema/seed.sql` | `data/schema/seed.sql` |
| Tài khoản admin duy nhất | **Import** (1 dòng vào `users`) | `php bin/create-admin.php --email=... --name=... [--phone=...]` + nhập mật khẩu tương tác | `bin/create-admin.php` — `password_hash(..., PASSWORD_DEFAULT)`; từ chối nếu `users` đã có bản ghi |
| Sao lưu / phục hồi toàn bộ DB | **Export/Import** | `mysqldump` / `mysql < file.sql` (xem dưới) | docs §7.4 — bắt buộc hằng ngày, giữ 30 bản |
| Ảnh/media trên đĩa | **Copy** | thư mục `public/uploads/` (theo `YYYY/MM/` + biến thể) | docs §3.10; helper `Application\View\Helper\MediaUrl` (trước 13/09 là `Core\`) |
| `sitemap.xml` | **Export** URL công khai | `GET /sitemap.xml` (route `sitemap` trong `module/Frontend/config/module.config.php`) | `Frontend\Controller\SitemapController` + `SitemapService::urls()` + `view/frontend/sitemap/index.phtml` — **hàng thật từ 13/09 batch 18 (FR-11)**: 5 trang tĩnh + bài published (`publicScope` loại nháp/hẹn giờ/archived) + danh mục/dịch vụ `isActive=1`; payload cache `sitemap-v1` TTL 60s. ⚠ Khi chạy `composer serve` (PHP built-in server) URL này vẫn trả **404 ngay ở tầng web server** (request có đuôi `.xml` không được đưa vào `index.php`) — kiểm trực tiếp cần Apache/nginx rewrite, `dispatch('/sitemap.xml')` trong PHPUnit, hoặc `php -S -t public <router-tạm-forward-index.php>` (cách đã smoke 13/09) |
| `robots.txt` | Export chính sách crawl | — | **Chưa có** file `public/robots.txt`, chưa có route |
| Export hộp thư liên hệ (Excel/CSV) | Export | — | **Chưa triển khai** (docs §3.9, §6.2 `GET /contacts/export`) |

## Sao lưu / khôi phục DB (thay cho "export/import" dữ liệu)

```bash
# Export (nên chạy ở giờ thấp điểm, kèm --single-transaction để không khoá InnoDB)
mysqldump -u root -p --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 news_system > backup-news_system-$(date +%F).sql

# Đóng gói uploads (đi kèm bản sao lưu DB — docs §7.4)
tar czf uploads-$(date +%F).tar.gz -C public uploads

# Import / restore
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p news_system < backup-news_system-YYYY-MM-DD.sql
```

- **Thứ tự an toàn:** luôn backup trước khi chạy SQL thay đổi cấu trúc — quy trình ở [`../05-van-hanh/02-migration-db.md`](../05-van-hanh/02-migration-db.md).
- Vì hệ thống **xoá cứng, không thùng rác**, bản sao lưu là **lớp phục hồi duy nhất** (docs §4.6, §7.4): mỗi quý diễn tập khôi phục, gồm cả tình huống khôi phục **một bài viết đơn lẻ** (restore DB backup vào schema tạm rồi `INSERT ... SELECT` đúng `postId`).
- Backup `mysqldump` không chứa `public/uploads`; ngược lại file ảnh không có trong DB → **hai phần phải đi thành cặp**, thiếu một là khôi phục không trọn vẹn (ảnh trong bài sẽ vỡ).
- Lưu bản sao **khác máy chủ chính** (docs §7.4).

## Import lại / làm mới môi trường dev

```bash
# Reset DB dev về trạng thái sạch (CẢNH BÁO: xoá toàn bộ dữ liệu đang có)
mysql -u root -p -e "DROP DATABASE news_system;"
mysql -u root -p -e "CREATE DATABASE news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p news_system < data/schema/schema.sql
mysql -u root -p news_system < data/schema/seed.sql
php bin/create-admin.php --email=admin@vanlang.vn --name="Quan tri Van Lang"
composer clear-config-cache
```

## Khi cần thêm import/export thật (hướng triển khai)

Chưa có mã nào; gợi ý làm theo chuẩn dự án (xem [`02-quy-trinh-phat-trien.md`](02-quy-trinh-phat-trien.md)):

| Tính năng | Thiết kế gợi ý | Ràng buộc phải giữ |
|---|---|---|
| Import bài viết từ CSV | Route `POST /api/admin/posts/import` + form trong `module/Admin/view/admin/post/`; parse bằng `fgetcsv`; **dry-run** trả danh sách lỗi theo dòng, chỉ ghi khi người dùng xác nhận | Bọc transaction; qua `SlugService` để sinh `slug`; lọc `content` bằng `HtmlPurifierService`; cột `camelCase`; không có `FOREIGN KEY` nên phải tự kiểm `categoryId`/`tagId` tồn tại |
| Export liên hệ CSV | `GET /admin/contacts/export` (hoặc `resource=contacts` qua `ApiController::dispatchAction`) trả `text/csv` với `Content-Disposition` | escape `"` và `,`; ghi `createdBy`/`ipAddress` đúng chuẩn `INET6_ATON`; cân nhắc ẩn `ipAddress` nếu không cần |
| Import media hàng loạt | `POST /api/admin/media` (multipart) nhiều file → `MediaService` | whitelist `app.allowed_mimes`, `app.upload_max_mb`, kiểm MIME bằng `finfo`, đổi tên ngẫu nhiên, sinh `app.image_variants` |
| Dọn dữ liệu liên hệ quá hạn | Nút "ẩn danh / xoá" trong hộp thư liên hệ | docs §3.9/§7.4 yêu cầu **thủ công**, không có job nền |
| Seed theo môi trường | tách `data/schema/seed-dev.sql` nếu cần dữ liệu mẫu | `seed.sql` hiện chỉ chứa dữ liệu nền thật (settings + home_sections), **không** có mật khẩu/tài khoản — tài khoản tạo bằng CLI |

## Định dạng file

| Loại file | Nơi chứa | Định dạng |
|---|---|---|
| `data/schema/schema.sql` | repo, commit | DDL MySQL 8, utf8mb4_0900_ai_ci, InnoDB, **không FK, không deletedAt, không COMMENT** |
| `data/schema/seed.sql` | repo, commit | `INSERT` settings + home_sections |
| `public/uploads/**` | disk, **không** commit (`public/uploads` rỗng) | ảnh gốc + biến thể `thumb/medium/large` + WebP |
| `data/cache/**` | disk, gitignore | config cache + page cache (Filesystem adapter) |
| Backup `.sql` + `.tar.gz` | ngoài repo | mysqldump + tar uploads |
