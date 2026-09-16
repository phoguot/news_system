# 01 — Quy chuẩn

Đọc trước khi viết dòng code đầu tiên. Vi phạm = review không pass.

| File | Nội dung |
|------|----------|
| `01-quy-uoc-dat-ten.md` | Đặt tên file/biến/hàm/class + mapper/service/controller/filter |
| `02-quy-chuan-db.md` | Tên bảng/cột/index, UTC, no-FK, xoá cứng |
| `03-quy-chuan-code.md` | Routing, view, escape, session, quality gates (tầng CRUD → xem 07) |
| `04-checklist-review.md` | Checklist trước khi gửi review |
| `05-quy-chuan-api.md` | Chuẩn request/response/error cho `/api/admin/*` |
| `06-quy-uoc-const.md` | Const theo entity (`Model/<Entity>/<Entity>Const.php`) · chung → `Application/Constant/` (trước 13/09 là `Core/Constant/`) |
| `07-crud-convention.md` | **NGUỒN SỰ THẬT cấu trúc file CRUD**: Controller→Service→Model(Mapper/Model/Const)→Filter, 1-Mapper-1-bảng, DI closure |
| `08-vi-du-sai-dung.md` | **Sai ↔ Đúng** 4 luật thực thi tầng (controller mỏng, Service trọn flow, `updateAttributeXxx` riêng, timestamp tập trung) + ánh xạ khuôn EnglishTrain → News |
