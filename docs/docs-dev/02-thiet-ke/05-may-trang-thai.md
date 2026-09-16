# Máy trạng thái

> Chỉ vẽ trạng thái **có thật** trong `schema.sql` / docs. Mã số `status` định nghĩa ở [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md) §4 và docs §4.4.

---

## 1. Bài viết — `posts.status` (docs §3.3.3)

Trạng thái lưu trong DB: `0`=draft · `1`=published · `2`=archived. **Không có** trạng thái `scheduled` hay `deleted` trong DB.

```
                 [tạo bài]
                     │
                     ▼
                 ┌────────┐   xuất bản ngay (publishedAt=NOW)   ┌───────────┐
                 │ draft  │───────────────────────────────────▶│ published │
                 │ (0)    │◀────────────────────────────────────│   (1)     │
                 └────────┘   huỷ hẹn giờ (publishedAt còn      └───────────┘
                     ▲          tương lai → về nháp, xoá publishedAt)   │  ▲
                     │                                                  │  │
                     │        chuyển về nháp                            │  │  xuất bản lại
                     └──────────────────────────────────────────────────┘  │
                                                                            ▼
                                                                       (về published)
                                                        gỡ khỏi website │
                                                        ┌───────────┐   │
                                                        │ archived  │◀──┘
                                                        │   (2)     │──▶ về draft cũng được
                                                        └───────────┘
```

**published (1) có 2 tiểu-trạng thái SUY DIỄN từ `publishedAt` (không phải cột riêng):**

```
published(1) ──┬── publishedAt <= NOW()   → ĐÃ hiển thị công khai  ✅
               └── publishedAt >  NOW()    → ĐANG HẸN GIỜ (chưa hiện) ⏳
```

| Chuyển | Ai kích hoạt | Điều kiện | Ghi DB |
|---|---|---|---|
| → draft | admin tạo bài | — | `status=0` |
| draft → published | admin bấm "Xuất bản ngay" | có ảnh banner (§3.3.4.3) | `status=1, publishedAt=NOW()` |
| draft → published | admin bấm "Hẹn giờ" | có ảnh banner | `status=1, publishedAt=<future>` |
| published → draft | admin huỷ hẹn giờ | `publishedAt` còn tương lai | `status=0, publishedAt=NULL` |
| published → archived | admin "Gỡ khỏi website" | — | `status=2` (giữ dữ liệu) |
| archived → published | admin "Xuất bản lại" | — | `status=1` |
| archived → draft | admin "Về nháp" | — | `status=0` |
| bất kỳ → (xoá) | admin "Xoá vĩnh viễn" | xác nhận cứng | `DELETE` + dọn con (§4.6) — **không phải trạng thái** |

> **Bài chỉ hiển thị ra website khi `status=1 AND publishedAt <= NOW()`** (§5.7). Bài hẹn giờ tự hiện đúng giờ nhờ điều kiện này + cache TTL ≤ 60s — **không** có job nền chuyển trạng thái. Xem luồng [`04-luong-nghiep-vu.md`](04-luong-nghiep-vu.md) §1.
> `isFeatured` là cờ độc lập (0/1), không phải trạng thái trong máy này — dùng cho khối "Tin nổi bật".

---

## 2. Banner — cửa sổ hiệu lực (docs §3.6, không phải cột `status`)

`banners` **không có cột status rời**; "trạng thái" suy ra từ 3 cột: `isActive` (0/1), `startAt` NULL, `endAt` NULL, so với `NOW()`.

```
                 isActive=0  ─────────────▶ TẮT (luôn ẩn)
                     │
                 isActive=1
                     ▼
   NOW < startAt ┌────────┐ startAt<=NOW<=endAt ┌────────┐  NOW > endAt ┌──────────┐
  ──────────────▶│ CHỜ    │────────────────────▶│ HIỆN   │─────────────▶│ HẾT HẠN  │
  (startAt NULL  └────────┘   (endAt NULL =     └────────┘  (endAt      └──────────┘
   = bỏ qua vế)               bỏ qua vế phải)                NULL = bỏ qua)
```

| Trạng thái suy ra | Điều kiện SQL (docs §5.5) |
|---|---|
| TẮT | `isActive = 0` |
| CHỜ (hẹn lịch) | `isActive=1 AND startAt > NOW()` |
| HIỆN | `isActive=1 AND (startAt IS NULL OR startAt<=NOW()) AND (endAt IS NULL OR endAt>=NOW())` |
| HẾT HẠN | `isActive=1 AND endAt < NOW()` |

> Không có auto-tắt khi hết hạn (không cronjob): truy vấn lọc `endAt >= NOW()` nên banner quá hạn **tự ẩn** khi đọc, dữ liệu vẫn còn.

---

## 3. Liên hệ — `contact_submissions.status` (docs §3.9)

Mã số: `0`=new · `1`=processing · `2`=done · `3`=spam.

```
   [khách gửi form]  (validate + captcha + rate-limit §5.10)
            │
            ▼
        ┌────────┐  admin nhận   ┌────────────┐  xử lý xong  ┌──────┐
        │  new   │──────────────▶│ processing │─────────────▶│ done │
        │  (0)   │               │    (1)     │  handledAt=  └──────┘
        └────────┘               └────────────┘   NOW()
             │                        │
             │  admin đánh dấu spam   │  đánh dấu spam
             ▼                        ▼
        ┌────────┐
        │ spam   │  (chỉ ẩn khỏi danh sách mặc định — KHÔNG xoá)
        │  (3)   │
        └────────┘
```

| Chuyển | Ai | Ghi chú |
|---|---|---|
| → new | khách | form hợp lệ; `consentAt=NOW()`, `status=0` |
| new → processing | admin | hộp thư CMS |
| processing → done | admin | ghi `handledAt=NOW()` |
| new/processing → spam | admin | chỉ đổi `status=3`, ẩn khỏi list mặc định |

> **Không có trạng thái "đã đọc"** riêng trong schema — mọi phân loại nằm ở `status` (0–3). Spam **không xoá**; khi cần dọn theo chính sách lưu trữ thì admin **xoá cứng/ẩn danh thủ công** (§3.9, không có job).

---

## 4. Token đặt lại mật khẩu — `password_reset_tokens` (docs §3.11, §4.4.1)

Không có cột `status`; vòng đời suy ra từ `usedAt` + `expiresAt`.

```
[request reset] ─▶ HỢP LỆ            ── dùng ──▶ ĐÃ DÙNG (usedAt!=NULL)
   tokenHash         (usedAt IS NULL
   lưu hash)          AND expiresAt > NOW(), 60')
                         │
                         └── quá hạn ──▶ HẾT HẠN (expiresAt <= NOW())
```
> Tạo token mới thì xoá token cũ của cùng `userId` (không có job dọn). Token **chỉ lưu hash**, không lưu bản gốc.

---

## 5. Không phải máy trạng thái (tránh hiểu nhầm)

- `post_revisions.type` (`0` manual · `1` autosave · `2` before_publish): **mã phân loại**, không có chuyển trạng thái; bản cũ bị `DELETE` cắt bớt khi vượt 20 (§5.13).
- `home_sections.isActive`, `categories.isActive`, `team_members.isActive`, `services.isActive`: **cờ bật/tắt** (0/1) dùng cho hiển thị, không có sơ đồ chuyển.
- **Không có thùng rác / khôi phục mềm** ở bất kỳ entity nào — xoá là `DELETE` cứng (§4.6).
