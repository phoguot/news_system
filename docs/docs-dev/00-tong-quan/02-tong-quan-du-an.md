# Tổng quan dự án

Website tin tức doanh nghiệp **Vạn Lang** trên Laminas MVC: một CMS nhỏ để một quản trị viên duy nhất tự quản toàn bộ nội dung (bài viết, danh mục, tag, dịch vụ, banner, bố cục trang chủ, đội ngũ, liên hệ, media, cài đặt) mà không cần can thiệp code. Chi tiết nghiệp vụ & CSDL xem [phân tích hệ thống](../../phan-tich-he-thong-website-tin-tuc.md) (v1.5).

## Mục tiêu

- Cung cấp kênh tin tức doanh nghiệp: đăng bài theo danh mục, giới thiệu dịch vụ & đội ngũ, tiếp nhận liên hệ.
- Mọi nội dung, kể cả banner và thứ tự khối trang chủ, đều chỉnh được qua CMS.
- Đơn giản tối đa: **một quản trị viên, một ngôn ngữ (tiếng Việt), không cronjob, không phân quyền**.

## Phạm vi

| Có | Không có |
|----|----------|
| Frontend đọc tin (trang chủ, danh mục, tag, tìm kiếm, chi tiết, dịch vụ, đội ngũ, liên hệ) | Đăng ký / đăng nhập người đọc, bình luận |
| CMS một quản trị viên (đăng nhập theo phiên) | Phân quyền nhiều vai trò (`roles` / `permissions`) |
| Bài viết: nháp / xuất bản / lưu trữ, hẹn giờ bằng `publishedAt` | Trạng thái "chờ duyệt" / nhiều cấp duyệt |
| Tag, gộp tag (merge) | Nhật ký hoạt động (`activity_logs`) |
| Danh mục cây tối đa 2 cấp | Module chuyển hướng URL (`redirects`) |
| Dịch vụ, Banner, Bố cục trang chủ, Đội ngũ, Liên hệ, Media, Cài đặt | Phòng ban riêng cho nhân sự (`departments`) |
| Xoá cứng (`DELETE`) kèm xoá bản ghi con | Xoá mềm / thùng rác / khôi phục (`deletedAt`) |
| Cron-less: bài hẹn giờ tự hiển thị nhờ `publishedAt <= NOW()` + cache TTL ≤ 60s | Cronjob / hàng đợi / job nền |

## Đối tượng sử dụng

| Đối tượng | Mô tả | Truy cập |
|---|---|---|
| Khách truy cập | Đọc tin, xem dịch vụ/đội ngũ, gửi form liên hệ | Frontend, không cần đăng nhập |
| Quản trị viên | Người dùng **duy nhất**; toàn quyền với mọi module | `/admin` (cần đăng nhập) |

## Thuật ngữ

| Từ | Nghĩa trong dự án này |
|----|-----------------------|
| post / bài viết | Bản ghi `posts`; một bài thuộc **1 danh mục chính**, gắn **nhiều tag** |
| banner | Ảnh slider ở `home_hero` (bảng `banners`), có thể đặt lịch `startAt`/`endAt` |
| home section / khối trang chủ | Một khu vực trên trang chủ, cấu hình bật/tắt + thứ tự (`home_sections`), mục chọn tay ở `home_section_items` |
| revision | Bản lưu lịch sử nội dung bài (`post_revisions`), giữ 20 bản gần nhất |
| slug | Định danh URL không dấu, `UNIQUE` toàn bảng; bài viết **không** chứa slug danh mục trong URL |
| hẹn giờ | Bài `status = published` với `publishedAt` ở tương lai; **không** có trạng thái `scheduled` riêng |
| sapo / excerpt | Tóm tắt bài (`posts.excerpt`), dùng cho danh sách & meta description mặc định |
| previewToken | Chuỗi 32 ký tự cho link xem trước bài chưa xuất bản (`/tin-tuc/{slug}?previewToken=...`) |
| biến thể (variant) | Ảnh resize tự động `thumb` (400px) / `medium` (800px) / `large` (1600px) |
| lưu trữ (archived) | `status = 2`: gỡ bài khỏi website nhưng **giữ dữ liệu** — cách duy nhất ẩn bài mà không mất |

## Liên kết nhanh

- [Kiến trúc tổng quan](03-kien-truc-tong-quan.md) · [Công nghệ & stack](04-cong-nghe-stack.md) · [Cấu trúc thư mục](05-cau-truc-thu-muc.md)
- [Mô hình dữ liệu](../02-thiet-ke/03-mo-hinh-du-lieu.md) · [Yêu cầu chức năng](../02-thiet-ke/01-yeu-cau-chuc-nang.md)
