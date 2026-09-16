# 03 — Tích hợp

| File | Nội dung |
|------|----------|
| `01-tich-hop-ngoai.md` | Kiểm kê tích hợp thực tế của dự án: MySQL, SMTP (`mail`), captcha (`recaptcha`), Intervention Image, HTMLPurifier, Google Fonts, Google Analytics — kèm trạng thái "đã chạy / mới cấu hình / chưa triển khai" |
| `02-xu-ly-loi.md` | Trang lỗi 404/500 module Application, công tắc `display_exceptions`, những gì được log, và bảng lỗi nghiệp vụ theo docs §3.9/§6.2 |

Dự án này **không gọi API bên thứ ba** (không thanh toán, không OAuth, không webhook CRM — docs §9 mục 8 để ngỏ), nên chưa cần các file `03-ten-he-thong.md` riêng; nếu sau này thêm (vd Sentry theo docs §7.4), copy `_templates/template-api.md`.
