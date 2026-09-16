# Log & giám sát

> **Sự thật cần nói trước:** dự án **chưa có** application logger. Không có `laminas/laminas-log`/Monolog trong `composer.json` (`vendor/` không có package nào), không có key `logger` trong `config/`, và **không có thư mục `data/logs/`** (`data/` chỉ gồm `cache/` và `schema/`). Lỗi PHP hiện đi vào error log của SAPI (FPM/Apache/`php -S`), tức là **thuộc tầng hạ tầng, không thuộc repo**.

## Log — hiện trạng

| Loại | Lưu ở đâu | Dung lượng/giữ bao lâu | Cách xem |
|---|---|---|---|
| Warning/Notice/Fatal PHP, exception chưa bắt | error log của PHP-FPM / Apache / output của `php -S` (đường dẫn do `php.ini` `error_log`, **repo không đặt**) | do logrotate của hệ thống | `tail -f /var/log/php8.3-fpm.log` (hoặc `journalctl -u php8.3-fpm`) |
| HTTP access (200/404/500, UA, IP) | access log nginx/Apache | 30–90 ngày tuỳ logrotate | `tail -f /var/log/nginx/access.log` |
| Lỗi hiển thị cho user | trang `error/404` / `error/index` (`module/Application/view/error/`) — chi tiết do `view_manager.display_exceptions` quyết định | không lưu | Mở URL lỗi |
| Slow query MySQL | **chưa bật** — cần `slow_query_log=1`, `long_query_time=1` trong `my.cnf` | tự quản | `mysqldumpslow` |
| Lỗi gửi mail (docs §3.9 yêu cầu "ghi log để gửi lại thủ công") | ✅ rà 14/09 — gửi SMTP thật đã có (`MailService::sendMany` từ 13/09, timeout 5s); fail log ở caller: `ContactService::notifyAdmins` + `PasswordResetService` (`error_log`) | không lưu | `grep 'email failed'` trong error log web server |
| Lỗi upload/resize ảnh | ✅ **đã triển khai 13/09** (`Admin\Service\MediaService` — trước 13/09 tài liệu ghi `Core\Service\MediaService`) | — | — |
| Nhật ký hoạt động admin | **Không tồn tại theo thiết kế** — docs v1.1 đã bỏ module `activity_logs` | — | — |
| Payload API / sync job | Không có — hệ thống **không có job nền** (docs ghi chú v1.2) | — | — |

> Debug toolbar: repo còn sót `config/autoload/laminas-developer-tools.local-development.php`, **nhưng** `laminas/laminas-developer-tools` **không** nằm trong `composer.json` và **không** có trong `modules.config.php` → file config đó **vô hiệu**. Muốn dùng toolbar phải `composer require --dev laminas/laminas-developer-tools` rồi khai báo module.

## Đề xuất tối thiểu (áp dụng ngay, không cần thư viện)

1. **Error log PHP có nơi nằm cố định** — trong FPM pool / `php.ini` prod:
   ```ini
   display_errors = Off
   log_errors = On
   error_log = /var/log/news/php-error.log
   ```
   Kết hợp `view_manager.display_exceptions => false` + `display_not_found_reason => false` trong `config/autoload/local.php` (xem [`01-cau-hinh-deploy.md`](01-cau-hinh-deploy.md)).
2. **Log mail fail** khi triển khai gửi mail: tạo `data/logs/` và ghi file theo ngày, rồi thêm `data/logs/*` vào `.gitignore`:
   ```php
   file_put_contents('data/logs/mail-' . date('Y-m-d') . '.log',
       date('c') . ' to=' . $to . ' err=' . $e->getMessage() . "\n", FILE_APPEND);
   ```
3. **Log lỗi upload** cùng thư mục `data/logs/upload-YYYY-MM-DD.log` (lý do: MIME, vượt 5 MB — theo `app.allowed_mimes`, `app.upload_max_mb`).
4. Nếu cần centralized: docs §7.4 gợi ý **Sentry** → **chưa triển khai**; đây là tích hợp ngoài thứ hai (sau Google Fonts) và cần thêm vào [`../03-tich-hop/01-tich-hop-ngoai.md`](../03-tich-hop/01-tich-hop-ngoai.md) khi làm.

## Giám sát — 5 kiểm tra tối thiểu

| # | Kiểm tra | Cách làm | Ngưỡng báo động |
|---|---|---|---|
| 1 | **Uptime** `https://<host>/` | UptimeRobot / `curl -o /dev/null -w "%{http_code}"`; `GET /` là route `home` (`module/Frontend/config/module.config.php`) | != 200 trong 2 lần liên tiếp |
| 2 | **Backup DB hằng ngày** (docs §7.4: giữ 30 bản, **lưu khác máy chủ chính**) | cron 02:00 `mysqldump ... > /backup/news-$(date +%F).sql`, xoá bản > 30 ngày; alert nếu file hôm nay không tồn tại hoặc dung lượng < 50% mức trung bình 7 ngày | thiếu file > 24h |
| 3 | **Backup `public/uploads`** | `tar czf /backup/uploads-$(date +%F).tar.gz -C public uploads` kèm lịch với DB (hai thứ phải là **một cặp** — xem [`../04-huong-dan/04-import-export.md`](../04-huong-dan/04-import-export.md)) | thiếu file > 24h |
| 4 | **Ổ đĩa cho uploads + cache** | `df -h /var/www/news/public/uploads`; `du -sh public/uploads data/cache` | ổ đĩa > 80% |
| 5 | **Lỗi 5xx tăng** | đếm trong access log: `grep -c ' 5[0-9][0-9] ' /var/log/nginx/access.log` theo ca | > 10 lần/giờ |

Mẫu cron vận hành (đây là cron **của OS**, không phải của ứng dụng — hệ thống không cần cron nghiệp vụ, xem [`01-cau-hinh-deploy.md`](01-cau-hinh-deploy.md)):

```cron
0 2 * * *  mysqldump -u vanlang_app -p"$DB_PASS" --single-transaction news_system | gzip > /backup/news/news-$(date +\%F).sql.gz
10 2 * * * tar czf /backup/news/uploads-$(date +\%F).tar.gz -C /var/www/news/public uploads
20 2 * * * find /backup/news -name 'news-*.sql.gz' -mtime +30 -delete
30 2 * * * rsync -a /backup/news/ backup-server:/backup/news/
```

## Cảnh báo — khi nào bắn, bắn đi đâu

| Biến cố | Phát hiện bằng | Kênh cảnh báo | Hành động |
|---|---|---|---|
| Site chết (`/` != 200) | uptime check | email/Telegram tới quản trị viên (đang là **1 người** — docs §1.2) | Xem `02-xu-ly-loi.md`, tail error log |
| Backup không chạy | kiểm tra file trong cron sau | email | Chạy tay `mysqldump`, sửa cron |
| Ổ đĩa sắp đầy uploads | `df` check | email | Dọn bản sao lưu cũ; cân nhắc đưa media sang S3 (docs §3.10, chưa triển khai) |
| SMTP chậm/hỏng (request treo) | timeout ở tầng web + log mail | email | docs §7.4: cân nhắc đưa việc gửi mail sang job nền |
| Cache không ghi được (`data/cache` full/perm sai) | 500 hàng loạt hoặc page chậm | email | `composer clear-config-cache`, kiểm `chown` web user |
| FULLTEXT không trả kết quả tiếng Việt | test `/tim-kiem?q=an` | — | Kiểm `innodb_ft_min_token_size = 2` + rebuild index ([`02-migration-db.md`](02-migration-db.md)) |

## Việc còn nợ

- [ ] Tạo `data/logs/` + gitignore, thống nhất định dạng log (mail/upload/auth-fail).
- [ ] Đưa cấu hình `error_log`/`display_errors` vào checklist deploy (đã có ở [`05-checklist-go-live.md`](05-checklist-go-live.md)).
- [ ] Cài Sentry hoặc tương đương (docs §7.4 gợi ý) — **chưa triển khai**.
- [ ] Diễn tập khôi phục từ backup **mỗi quý**, gồm khôi phục 1 bài viết đơn lẻ (docs §7.4).
- [ ] Kiểm `EXPLAIN` cho các query danh sách trước go-live (docs §7.2).
