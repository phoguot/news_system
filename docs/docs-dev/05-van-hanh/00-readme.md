# 05 — Vận hành

| File | Khi nào đọc |
|------|-------------|
| `01-cau-hinh-deploy.md` | Chuẩn bị production: docroot phải là `public/`, override secret trong `config/autoload/local.php` (không dùng `.env`), tắt `display_exceptions`, quyền ghi `data/cache` + `public/uploads`, **snippet chặn thực thi script trong uploads** (nginx/Apache/IIS), giải thích vì sao **không cần cron** cho bài hẹn giờ |
| `02-migration-db.md` | Thêm/sửa bảng: dự án **không có tool migration** → sửa `data/schema/schema.sql` + file `ALTER` tăng dần, luôn backup trước, không FK nên thứ tự là trách nhiệm tầng ứng dụng, rollback = restore backup |
| `03-xac-thuc-phan-quyen.md` | Đăng nhập & bảo vệ `/admin`: 1 tài khoản duy nhất (không role), bcrypt qua `password_hash` trong `bin/create-admin.php`, cấu hình session `VANLANG_SESS`, **guard `/admin` + `/api/admin` chưa triển khai**, CSRF/rate-limit còn thiếu |
| `04-log-giam-sat.md` | Log & giám sát: hiện **chưa có application logger** và không có `data/logs/`; error log PHP thuộc tầng web server; 5 kiểm tra giám sát tối thiểu + cron backup mysqldump |
| `05-checklist-go-live.md` | Trước khi bật cho người dùng thật: 12 nhóm checklist (DB utf8mb4_0900_ai_ci + 16 bảng, tìm kiếm tiếng Việt, hẹn giờ ≤ 60s, auth, secret, uploads không thực thi được, SMTP + captcha, media, SEO/sitemap/robots, backup, kiểm cuối) + 7 điểm cần chốt |

Tài liệu nghiệp vụ gốc: [`../../phan-tich-he-thong-website-tin-tuc.md`](../../phan-tich-he-thong-website-tin-tuc.md) (§7 yêu cầu phi chức năng). Lệnh cài đặt/chạy: [`../../../README.md`](../../../README.md) và [`../04-huong-dan/01-cai-dat-moi-truong.md`](../04-huong-dan/01-cai-dat-moi-truong.md).
