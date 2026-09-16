# Van Lang — News CMS (Laminas MVC)

Website tin tức doanh nghiệp trên **Laminas MVC 3.8** (Zend Framework kế nhiệm, PHP 8.1–8.3). Một admin, không cronjob, không phân quyền.

Chi tiết nghiệp vụ & DB: [docs/phan-tich-he-thong-website-tin-tuc.md](docs/phan-tich-he-thong-website-tin-tuc.md) (v1.5).

## Yêu cầu
- PHP 8.1 / 8.2 / 8.3
- MySQL 8.0.19+ (InnoDB, utf8mb4_0900_ai_ci)
- Composer 2.x
- Extension: `pdo_mysql`, `gd` hoặc `imagick` (cho Intervention Image), `fileinfo`

## Cài đặt

```bash
composer install
cp config/autoload/local.php.dist config/autoload/local.php
# sửa DB, SMTP, captcha trong local.php

mysql -u root -p -e "CREATE DATABASE news_system CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p news_system < data/schema/schema.sql
mysql -u root -p news_system < data/schema/seed.sql

# Tạo tài khoản admin duy nhất (hỏi mật khẩu tương tác)
php bin/create-admin.php --email=admin@vanlang.vn --name="Quan tri Van Lang"
```

## Chạy dev

```bash
composer serve
# hoặc
php -S 0.0.0.0:8080 -t public
```

- Frontend: `http://localhost:8080/` — `http://localhost:8080/tin-tuc`, `/danh-muc/{slug}`, `/tag/{slug}`, `/tim-kiem`, `/dich-vu`, `/doi-ngu`, `/lien-he`
- Admin: `http://localhost:8080/admin` (cần đăng nhập) — bài viết, danh mục, tag, dịch vụ, banner, bố cục trang chủ, đội ngũ, liên hệ, media, cài đặt
- API admin: `http://localhost:8080/api/admin/*` (JSON, cần session)

## Cấu trúc

```
config/                 application + modules + autoload (db, session, cache, mail)
  autoload/global.php   DB, session, cache, mail mặc định (commit)
  autoload/local.php    DB/SMTP/captcha thật (gitignore, copy từ local.php.dist)
data/
  cache/                cache config + page (ttl 60s cho bài hẹn giờ)
  schema/schema.sql     DDL 16 bảng (không FK, không deletedAt, cột camelCase)
  schema/seed.sql       settings + home_sections mặc định
module/
  Application/          Skeleton (404/500) + dịch vụ dùng chung gộp từ Core 13/09/2026 (DbService, DateService, SlugService, HtmlPurifier, MediaService, MediaUrl, ContentConst, Filter)
  Frontend/             Home, Post, Category, Tag, Search, Service, Team, Contact, Sitemap
  Admin/                Auth, Dashboard, Post, Category, Tag, Service, Banner, HomeSection, Team, Contact, Media, Setting, Account, Api
public/
  assets/css/style.css  CSS Vạn Lang (từ assets/ gốc)
  uploads/              Upload media (theo năm/tháng, variants thumb/medium/large)
bin/create-admin.php    CLI tạo admin duy nhất (1 dòng trong users)
```

## Quy ước (theo docs v1.5)
- Bảng `snake_case`, cột `camelCase` (`categoryId`, `publishedAt`...).
- Không `FOREIGN KEY`, không `deletedAt` — xóa là `DELETE` thật, phải xóa bản ghi con cùng transaction (xem docs 4.6).
- `posts.status`: 0=draft, 1=published, 2=archived. Bài hẹn giờ = `status=1` + `publishedAt` tương lai, hiện tự động nhờ `publishedAt <= NOW()` + cache TTL ≤ 60s.
- Thời gian lưu UTC, `SET time_zone = '+00:00'` ở kết nối.
- `innodb_ft_min_token_size = 2` nếu dùng FULLTEXT tiếng Việt.

## Kiểm thử

```bash
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/psalm --stats
```

## Ghi chú
- Giao diện tĩnh ban đầu (`index.html`, `gioi-thieu.html`, `lien-he.html`, `assets/`) giữ nguyên ở thư mục gốc để tham chiếu, bản Laminas dùng `public/assets/css/style.css` và `module/Frontend/view`.
- Ảnh `public/uploads/` cần chặn thực thi script ở cấu hình web server.
- Sao lưu DB hằng ngày (không có thùng rác, chỉ khôi phục từ backup).
