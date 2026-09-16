# Database — Vạn Lang News

## Yêu cầu
- MySQL 8.0.19+ (InnoDB, utf8mb4, `time_zone = '+00:00'`)
- FULLTEXT tiếng Việt: đặt `innodb_ft_min_token_size = 2` rồi rebuild index `ft_posts_title_excerpt` (mặc định MySQL bỏ từ < 3 ký tự).

## Cài đặt
```bash
mysql -u root -p -e "CREATE DATABASE news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p news_system < data/schema/schema.sql
mysql -u root -p news_system < data/schema/seed.sql
php bin/create-admin.php --email=admin@vanlang.vn --name="Quan tri Van Lang"
```

> **Đường tắt cho môi trường cục bộ/dev:** `mysql -u root -p news_system < data/schema/2026-09-13-seed-admin.sql` chèn đúng 1 dòng admin với mật khẩu **dev công khai** ghi ngay đầu file (idempotent — chạy lại chỉ reset mật khẩu, không tạo dòng thứ hai). Production BẮT BUỘC dùng `bin/create-admin.php` và đổi mật khẩu sau lần đăng nhập đầu.

## Seed demo (16/16 bảng)

`mysql -u root -p news_system < data/schema/2026-09-13-seed-demo.sql` — kho dữ liệu demo phủ **mọi bảng**: 26 media · 9 danh mục (2 cấp) · 10 thẻ · 15 bài (10 published, 5 featured · 1 hẹn giờ · 3 nháp · 1 lưu trữ · 1 có previewToken) · revision 4 loại trạng thái · view_daily · 7 dịch vụ · 5 banner (hero + news_top, có cửa sổ ngày) · 8 home section đủ 7 type (featured/team `mode=manual` kèm items) · 5 nhân sự · 8 hộp thư phủ 4 status · 19 settings · 2 reset token.

- File **TỰ CHỨ**: không cần chạy `seed.sql` trước (settings + home sections dựng lại trong đây).
- **Idempotent cho DB demo**: xoá rồi chèn lại các bảng nội dung với ID cố định; `users` KHÔNG xoá (luật 1 dòng — chỉ `INSERT IGNORE`).
- Ảnh placeholder (gradient + chữ) khớp mọi `media.path` + variants: `php make-demo-placeholders.php` ở root repo (script một lần, sinh file vào `public/uploads/`).
- Chỉ dùng cho **local/dev** — nội dung tiếng Việt demo, mật khẩu admin công khai.


Copy `config/autoload/local.php.dist` thành `config/autoload/local.php` và điền DB, SMTP, captcha.

## Quy tắc
- Không FOREIGN KEY, không `deletedAt` — xóa là `DELETE` thật (xem docs mục 4.6).
- Cột dùng `camelCase`, bảng dùng `snake_case`.
- Thời gian lưu UTC, kết nối phải `SET time_zone = '+00:00'`.
