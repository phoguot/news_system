# 04 — Hướng dẫn phát triển

| File | Khi nào đọc |
|------|-------------|
| `01-cai-dat-moi-truong.md` | Lần đầu setup máy: `composer install`, `local.php.dist` → `local.php`, import `schema.sql` + `seed.sql`, `bin/create-admin.php`, `composer serve`, checklist kiểm tra cài đặt |
| `02-quy-trinh-phat-trien.md` | Trước khi nhận task: vòng lặp branch → code theo tầng Controller/Filter/Service/Mapper → `composer test`/`cs-check`/`static-analysis` → checklist review |
| `03-huong-dan-test.md` | Trước khi gửi PR: cấu hình PHPUnit thực tế (suite skeleton + `--filter`), baseline phpcs/psalm, checklist test thủ công cho liên hệ / hẹn giờ / media / auth / xoá cứng |
| `04-import-export.md` | Khi cần nạp hoặc xuất dữ liệu: `schema.sql` + `seed.sql` + `create-admin` là import duy nhất hiện có; backup/restore bằng `mysqldump`; import CSV/Excel **chưa triển khai** |
