# Mô hình dữ liệu

> **18 bảng**, MySQL 8, InnoDB, `utf8mb4_0900_ai_ci`, cột `camelCase`. DDL đầy đủ: [`data/schema/schema.sql`](../../../data/schema/schema.sql) (KHÔNG lặp lại ở đây). Tóm tắt quan hệ theo [docs §4.2–§4.3](../../phan-tich-he-thong-website-tin-tuc.md).
> **Không có FOREIGN KEY** — quan hệ chỉ là cột `...Id` + index; suy diễn quan hệ do tầng Service chịu trách nhiệm (DB §6, docs §4.1).

## 1. Nhóm Hệ thống & Tài khoản

| Bảng | Mục đích | Cột khoá / chính | Quan hệ |
|---|---|---|---|
| `users` | Tài khoản quản trị **duy nhất** (không phân quyền) | `id`; `uq_users_email(email)`; `passwordHash`, `failedLoginCount`, `lockedUntil`, `avatarMediaId` | 1→N `password_reset_tokens`; 1→N `posts.authorId`; 1→N `media.uploadedBy`; 1↔1 `team_members.userId` |
| `password_reset_tokens` | Token đặt lại mật khẩu | `id`; `uq_..._hash(tokenHash)`; `expiresAt`, `usedAt` | N→1 `users.userId` |
| `settings` | Cài đặt chung theo 4 nhóm | `id`; `uq_settings_key(settingKey)`; `groupCode`, `valueType` | N→1 `users.updatedBy` (nullable) |

## 2. Nhóm Media

| Bảng | Mục đích | Cột khoá / chính | Quan hệ |
|---|---|---|---|
| `media` | Thư viện file ảnh (lưu đường dẫn **tương đối** + biến thể JSON) | `id`; `uq_media_path(path)`; `mimeType`, `variants`(JSON), `uploadedBy` | được nhiều bảng tham chiếu qua `*MediaId`; N→1 `users` |

## 3. Nhóm Tin tức

| Bảng | Mục đích | Cột khoá / chính | Quan hệ |
|---|---|---|---|
| `categories` | Danh mục, **cây tối đa 2 cấp** | `id`; `uq_categories_slug(slug)`; `parentId`, `coverMediaId`, `isActive`, `sortOrder` | tự tham chiếu `parentId`→`categories.id`; N→1 `media`; 1→N `posts` |
| `tags` | Nhãn bài viết | `id`; `uq_tags_slug(slug)` | N↔N `posts` qua `post_tags` |
| `posts` | Bài viết | `id`; `uq_posts_slug(slug)`, `uq_posts_preview_token`; `categoryId`, `authorId`, `bannerMediaId`, `thumbnailMediaId`, `status`, `publishedAt`, `viewCount`, `readingMinutes`; FULLTEXT `ft_posts_title_excerpt(title,excerpt)` | N→1 `categories`, `users`, `media(×2)` |
| `post_tags` | Bắc cầu bài ↔ tag (**PK tổ hợp**) | PK `(postId, tagId)`; `idx_post_tags_tag(tagId)` | N→1 `posts`, `tags` |
| `post_revisions` | Lịch sử phiên bản (manual/autosave/before_publish) | `id`(BIGINT); `postId`, `userId`, `type` | N→1 `posts`, `users` |
| `post_view_daily` | Lượt xem cộng dồn theo ngày (PK tổ hợp) | PK `(postId, viewDate)`; `idx_..._date` | N→1 `posts` |

## 4. Nhóm Dịch vụ & Giao diện trang chủ

| Bảng | Mục đích | Cột khoá / chính | Quan hệ |
|---|---|---|---|
| `services` | Dịch vụ | `id`; `uq_services_slug(slug)`; `iconMediaId`, `imageMediaId`, `isActive`, `sortOrder` | N→1 `media(×2)`; 1→N `contact_submissions.serviceId` |
| `banners` | Banner theo vị trí + thời hạn | `id`; `position`, `imageMediaId`, `mobileImageMediaId`, `isActive`, `startAt`, `endAt`, `sortOrder` | N→1 `media(×2)` |
| `home_sections` | Khối trang chủ (bố cục) | `id`; `type`(1–7), `config`(JSON), `isActive`, `sortOrder` | 1→N `home_section_items` |
| `home_section_items` | Mục **đa hình** chọn tay trong khối | `id`; `uq_...(sectionId,itemType,itemId)`; `itemType`,`itemId` | N→1 `home_sections`; `itemId` trỏ posts/services/team_members theo `itemType` (không FK) |
| `pricing_items` | Bảng giá công khai | `id`; `uq_pricing_items_slug(slug)`; `groupCode`, `price`, `isActive`, `sortOrder` | Độc lập; Frontend sở hữu mapper, Admin ghi hộ |
| `menu_items` | Menu header frontend | `id`; `label`, `url`, `target`, `isActive`, `sortOrder` | Độc lập; Frontend sở hữu mapper, Admin ghi hộ |

## 5. Nhóm Đội ngũ & Liên hệ

| Bảng | Mục đích | Cột khoá / chính | Quan hệ |
|---|---|---|---|
| `team_members` | Nhân sự hiển thị trên web (tách khỏi tài khoản CMS) | `id`; `uq_team_members_user(userId)`; `avatarMediaId`, `showContact`, `isFeatured`, `isActive`, `sortOrder`, `socialLinks`(JSON) | 1↔1 nullable `users`; N→1 `media` |
| `contact_submissions` | Liên hệ từ khách | `id`(BIGINT); `status`(0–3), `serviceId`, `ipAddress`(VARBINARY(16)), `consentAt`, `handledAt` | N→1 `services` (nullable, có thể mồ côi) |

## 6. Sơ đồ quan hệ (rút gọn từ ERD §4.3)

```
users ──< posts >── categories ──< categories (parentId, tự tham chiếu)
  │        │  │
  │        │  └──< post_tags >── tags
  │        └─────< post_revisions
  │        └─────< post_view_daily
  ├──< password_reset_tokens
  └─1:1 team_members
media ── được tham chiếu bởi: posts(banner/thumbnail), categories(cover),
        services(icon/image), banners(image/mobile), team_members(avatar), users(avatar)
home_sections ──< home_section_items ┄┄(đa hình)┄┄> posts | services | team_members
services ──< contact_submissions
```

## 7. Ngữ nghĩa bảng quan hệ

- **`post_tags`** — n-n bài↔tag, PK `(postId,tagId)` chặn trùng; `idx_post_tags_tag` phục vụ chiều "tag → các bài". Không có `id` riêng.
- **KHÔNG có `post_categories`** — mỗi bài **đúng 1 danh mục** qua `posts.categoryId`. Bảng `post_categories` chỉ là phương án mở rộng nếu sau này cho bài thuộc nhiều danh mục (docs §9 điểm 2), **hiện không tồn tại** trong `schema.sql`.
- **`home_section_items`** — quan hệ **đa hình**: cặp `(itemType, itemId)` với `itemType`: `1`=post · `2`=service · `3`=team_member. Vì đa hình nên không FK; tầng ứng dụng validate theo `itemType` và bỏ qua mục đã ẩn/xoá khi render.
- **`post_view_daily`** — PK tổ hợp `(postId, viewDate)`, `viewDate` theo UTC; ghi bằng upsert (`INSERT ... AS newRow ON DUPLICATE KEY UPDATE`, docs §5.8), `posts.viewCount` giữ bản tổng phi chuẩn hoá để sort nhanh.

## 8. Quy tắc kiểm tra media trước khi xoá (docs §5.14)

Xoá `media` là **xoá cứng** — phải chạy truy vấn "đang được dùng ở đâu" và **chặn xoá** nếu còn tham chiếu. Các cột phải quét (đều có trong `schema.sql`):

| Nguồn dùng | Cột |
|---|---|
| Bài viết | `posts.bannerMediaId`, `posts.thumbnailMediaId` |
| Danh mục | `categories.coverMediaId` |
| Dịch vụ | `services.iconMediaId`, `services.imageMediaId` |
| Banner | `banners.imageMediaId`, `banners.mobileImageMediaId` |
| Nhân sự | `team_members.avatarMediaId` |
| Tài khoản | `users.avatarMediaId` |

Ngoài ra ảnh **chèn trong nội dung bài** không có cột tham chiếu → quét thêm `posts.content LIKE %path%`; còn dùng thì chặn, chỉ cho xoá khi danh sách rỗng hoặc admin xác nhận ghi đè (docs §3.10, §4.6).

## 9. Ràng buộc toàn vẹn do tầng ứng dụng giữ (vì không FK)

| Tình huống | Xử lý | Nguồn |
|---|---|---|
| Cha bị xoá | xoá bản ghi con trong CÙNG transaction (không cascade DB) | §4.6, [`04-luong-nghiep-vu.md`](04-luong-nghiep-vu.md) |
| Con trỏ cha đã mất khi đọc | `LEFT JOIN` + bỏ qua / hiện "(dịch vụ đã xoá)" | §4.6, §3.5 |
| Thêm bản ghi có `...Id` | kiểm tra tồn tại trước khi ghi | §4.1 |
| Slug duy nhất | `UNIQUE` + giải phóng khi xoá cứng | §4.1 |

> Chuẩn cột/index/kiểu dữ liệu chi tiết: [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md). Danh sách tính năng gắn với mỗi bảng: [`01-yeu-cau-chuc-nang.md`](01-yeu-cau-chuc-nang.md).
