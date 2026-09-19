# 02 — Thiết kế

> Thiết kế **Van Lang — News CMS** (một admin, không phân quyền, không cronjob, xoá cứng). Mọi hàng mục dưới đây lấy từ [docs phân tích v1.5](../../phan-tich-he-thong-website-tin-tuc.md) và đối chiếu [`data/schema/schema.sql`](../../../data/schema/schema.sql) / `module/*`.

| File | Nội dung | Tham chiếu chính |
|------|----------|------------------|
| [`01-yeu-cau-chuc-nang.md`](01-yeu-cau-chuc-nang.md) | Danh sách yêu cầu chức năng FR-01…FR-nn theo module (frontend + admin) | docs §2, §3 |
| [`02-yeu-cau-phi-chuc-nang.md`](02-yeu-cau-phi-chuc-nang.md) | SEO, hiệu năng, bảo mật, vận hành & sao lưu | docs §7 |
| [`03-mo-hinh-du-lieu.md`](03-mo-hinh-du-lieu.md) | 18 bảng, mục đích, cột khoá, quan hệ (ERD rút gọn) | docs §4.2–§4.3 |
| [`04-luong-nghiep-vu.md`](04-luong-nghiep-vu.md) | Luồng chính: đăng/hẹn giờ bài, liên hệ, upload media, tìm kiếm, tính view, xoá cứng, cắt revision | docs §3, §5 |
| [`05-may-trang-thai.md`](05-may-trang-thai.md) | Máy trạng thái bài viết, banner (cửa sổ hiệu lực), liên hệ | docs §3.3, §3.9 |

**Đọc kèm:** quy chuẩn ở [`../01-quy-chuan/`](../01-quy-chuan/00-readme.md) — [`02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md) (ràng buộc DB), [`03-quy-chuan-code.md`](../01-quy-chuan/03-quy-chuan-code.md) (tầng Service/Mapper), [`04-checklist-review.md`](../01-quy-chuan/04-checklist-review.md).

> API (route frontend + REST `/api/admin/*`) không có file riêng trong thư mục này — xem trực tiếp docs §6 và `module/Frontend/config/module.config.php`, `module/Admin/config/module.config.php`.
