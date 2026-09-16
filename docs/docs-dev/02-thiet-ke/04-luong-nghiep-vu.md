# Luồng nghiệp vụ

> Các luồng chính của **Van Lang — News CMS**. Mỗi bước ghi rõ **chạm gì → ghi đâu**. SQL tham chiếu [docs §5](../../phan-tich-he-thong-website-tin-tuc.md), chuẩn DB ở [`../01-quy-chuan/02-quy-chuan-db.md`](../01-quy-chuan/02-quy-chuan-db.md).
> **Trạng thái code (rà 13/09/2026 đến batch 16/17; bổ sung 14/09: FR-13 · FR-41 · kéo thả FR-26/29/30/32/34 · FR-31):** code thật phủ gần hết các luồng dưới đây — đăng nhập admin (`AdminAuthService`/`AuthGuard`/`LoginFilter`), liên hệ §2 (form công khai + **bước 6 hộp thư admin FR-35**), toàn bộ CRUD nội dung + API `/api/admin/*`, media §3 (FR-36/37/38), bài viết §1/§6/§7, trang chủ §8 (batch 9 — ghi chú cuối §8), tìm kiếm khách §4 (batch 15), và **chuỗi trang đọc công khai FR-02…09**: `/tin-tuc` + phân trang/lọc (batch 10), chi tiết + preview token (batch 11–12), `/danh-muc` (batch 13), `/tag` (batch 14), **`/dich-vu` + `/dich-vu/{slug}` (FR-08 — batch 16), `/doi-ngu` ẩn email/SĐT theo `showContact` (FR-09 — batch 17)** — chi tiết từng batch ở [`../README.md`](../README.md). **§5 — ghi lượt xem (FR-41) đã có code từ 14/09** (`PostViewService::tryRecord` dedup cookie/UA 30', bỏ preview + `PostViewDailyMapper::increment` upsert + `posts.viewCount`) — mọi luồng liệt kê dưới đây đều đã có code thật. Tầng `Table/`/`Form/`/`Guard/`/`*Factory.php` cũ **đã xoá khỏi repo**, refactor theo [`../00-tong-quan/05-cau-truc-thu-muc.md`](../00-tong-quan/05-cau-truc-thu-muc.md) §3; code mới theo [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md). Luồng dưới đây mô tả **hành vi đích**; code đã viết đang bám đúng các bước + ràng buộc này (lệch đâu là lỗi, sửa code hoặc báo docs).

---

## 1. Đăng bài & hẹn giờ xuất bản

**Điều kiện hiển thị công khai:** `status = 1 AND publishedAt <= NOW()` (docs §5.7). Không có trạng thái `scheduled`, không cronjob.

```
Soạn (draft, status=0) ──▶ Xuất bản ngay ─▶ status=1, publishedAt = NOW()  → hiện tức thì
                        └▶ Hẹn giờ      ─▶ status=1, publishedAt = tương lai → TỰ hiện khi tới giờ
```

| Bước | Chạm gì | Ghi vào đâu |
|---|---|---|
| 1. Lưu bản nháp | `SlugService` sinh `slug`; `HtmlPurifierService` lọc `content`; `readingMinutes = ceil(words/200)` | `INSERT posts (status=0)`; tạo revision `type=0` |
| 2. Gắn tag | chuẩn hoá/ tạo tag mới | `INSERT post_tags` |
| 3a. Xuất bản ngay | kiểm tra **banner bắt buộc** (§3.3.4.3); revision `type=2` trước khi XP | `UPDATE posts SET status=1, publishedAt=NOW()` |
| 3b. Hẹn giờ | cùng `status=1`, đặt `publishedAt` tương lai | `UPDATE posts SET status=1, publishedAt=<future>` |
| 4. Bài hiện | cache trang chủ/danh mục TTL ≤ 60s để bài tự xuất hiện | `page_cache` (global.php `ttl=60`) |

> Huỷ hẹn giờ → trở về `status=0` + `publishedAt=NULL` (docs §3.3.3). Đổi slug bài đã XP làm mất URL cũ — **không** có module redirect (§3.2).

---

## 2. Nhận tin liên hệ (`/lien-he` → `POST /api/contact`)

Theo docs §3.9. Hệ **không** có job nền: email gửi **đồng bộ** sau khi lưu.

| Bước | Chạm gì | Ghi vào đâu / kết quả |
|---|---|---|
| 1 | Form submit (GET `/lien-he` → route `contact`) | hiển thị form + CSRF token |
| 2 | Validate server + honeypot + captcha (`recaptcha`, `local.php`) | fail → 422 kèm `errors` |
| 3 | Rate-limit theo IP: `COUNT(*) WHERE ipAddress=INET6_ATON(:ip) AND createdAt >= NOW()-INTERVAL 10 MINUTE` (§5.10) | ≥3 → **HTTP 429** |
| 4 | Ghi liên hệ, `status=0` (new), `consentAt=NOW()`, `ipAddress` VARBINARY | `INSERT contact_submissions` |
| 5 | Gửi email **đồng bộ** tới `notify_emails` (settings), timeout ngắn | lỗi mail → log để gửi lại, **vẫn** báo thành công cho khách |
| 6 | Admin xử lý: đổi `status` (1 processing/2 done/3 spam), `adminNote`, `handledAt` khi `done` | `UPDATE contact_submissions` |

> Không có trạng thái "đã đọc" riêng trong DB; phân loại bằng `contact_submissions.status` (mã 0–3, docs §4.4.6). Xem [`05-may-trang-thai.md`](05-may-trang-thai.md).

---

## 3. Upload media (`module/Admin/.../MediaController` + `Admin/Service/MediaService` — trước 13/09 tài liệu ghi `Core/Service/MediaService`)

Theo docs §3.10. Dependency: `intervention/image`, `fileinfo`.

| Bước | Chạm gì | Ghi vào đâu |
|---|---|---|
| 1 | Kéo thả nhiều file; chặn **SVG**; kiểm **MIME thật** qua `finfo` | — (từ chối nếu ngoài `allowed_mimes`) |
| 2 | Kiểm dung lượng ≤ `upload_max_mb` (5MB, global.php `app`) | — |
| 3 | Đổi tên ngẫu nhiên, lưu theo `YYYY/MM/` trong `public/uploads/` | file vật lý |
| 4 | Sinh biến thể `thumb=400 / medium=800 / large=1600` + WebP (global.php `image_variants`) | file vật lý + JSON `variants` |
| 5 | Ghi metadata (đường dẫn **tương đối**, không URL tuyệt đối) | `INSERT media (disk,path,mimeType,sizeBytes,width,height,variants,uploadedBy)` |
| 6 | Admin sửa `altText` | `UPDATE media SET altText` |

---

## 4. Tìm kiếm toàn văn (`/tim-kiem?q=`)

Theo docs §5.11. Index FULLTEXT `ft_posts_title_excerpt (title, excerpt)`.

```
q → MATCH(title,excerpt) AGAINST(:q IN NATURAL LANGUAGE MODE)
     + status=1 AND publishedAt<=NOW() → ORDER BY score DESC, publishedAt DESC → LIMIT 20
```

> Yêu cầu `innodb_ft_min_token_size=2` (DB §6). Trang kết quả gắn `noindex` (§7.1). **Đã code thật 13/09 batch 15 (FR-07):** `SearchService::search` + `PostMapper::searchPublished` — từ khoá rỗng/<2 ký tự chặn trước DB, `LIMIT 20`.

---

## 5. Tính lượt xem (docs §5.8 — ghi trực tiếp, không job)

```
Request chi tiết hợp lệ → { INSERT post_view_daily ... ON DUPLICATE KEY UPDATE views+1
                           UPDATE posts SET viewCount = viewCount + 1  }  (cùng transaction)
```

| Điều kiện "lượt hợp lệ" | Cách |
|---|---|
| Không trùng cookie/phiên trong 30 phút | guard ở tầng ứng dụng |
| Không phải bot | check UA |
| `viewDate` | theo `UTC_DATE()` (§5.8) — khớp quy ước UTC |

---

## 6. Xoá cứng trong transaction (docs §4.6 / §5.16)

Vì **không FK**, MySQL không cascade → Service phải dọn con trước, rồi mới cha, **cùng 1 transaction**.

```
START TRANSACTION;
  DELETE FROM post_tags          WHERE postId = :postId;
  DELETE FROM post_revisions     WHERE postId = :postId;
  DELETE FROM post_view_daily    WHERE postId = :postId;
  DELETE FROM home_section_items WHERE itemType = 1 AND itemId = :postId;
  DELETE FROM posts              WHERE id  = :postId;
COMMIT;
```

| Đối tượng | Chặn trước khi xoá | Dọn kèm |
|---|---|---|
| `posts` | — | 4 bảng con như trên |
| `categories` | còn con HOẶC còn bài → **chặn** (§5.15) | — |
| `tags` | — | `post_tags` của tag |
| `services` | — | `home_section_items(itemType=2)`; liên hệ cũ thành mồ côi → `LEFT JOIN` "(dịch vụ đã xoá)" |
| `team_members` | — | `home_section_items(itemType=3)` |
| `home_sections` | — | `home_section_items` của nó |
| `media` | còn dùng theo §5.14 → **chặn** | file gốc + mọi biến thể trên đĩa; lỗi xoá file → rollback |
| `banners`, `contact_submissions` | — | — (spam chỉ `status=3`) |

> API trả **HTTP 409** khi bị chặn ràng buộc (§6.2). Slug giải phóng ngay sau khi xoá. Không hoàn tác — chỉ khôi phục từ backup (§7.4).

---

## 7. Cắt revision thừa (docs §5.13 — giữ 20 bản, không job)

Chạy ngay sau khi tạo revision thủ công/trước-xuất-bản:

```
DELETE FROM post_revisions
 WHERE postId=:postId AND type<>1
   AND id NOT IN (SELECT id FROM (SELECT id FROM post_revisions
                  WHERE postId=:postId AND type<>1
                  ORDER BY createdAt DESC, id DESC LIMIT 20) keep);
```

> Autosave (`type=1`) chỉ giữ 1 bản mới nhất: trước khi ghi bản autosave mới thì xoá bản autosave cũ cùng `postId`.

---

## 8. Đọc trang chủ theo `home_sections`

| Bước | Chạm gì | Nguồn |
|---|---|---|
| 1 | Lấy sections `isActive=1 ORDER BY sortOrder` (cache) | `home_sections` |
| 2 | Mỗi section render theo `type` (hero/featured/latest/category/services/team/cta) | §3.7 |
| 3 | `mode=auto` lọc theo `isFeatured`/`sortOrder`; `mode=manual` đọc `home_section_items ORDER BY sortOrder` rồi `JOIN` bài đang công khai (§5.2) | `home_section_items` |
| 4 | Mục trỏ tới bài/banner đã ẩn/xoá → **tự bỏ qua** | §3.7 |
| 5 | Xoá cache khi bài/banner/dịch vụ/nhân sự/section thay đổi | §7.2 |

> Luồng banner theo thời hạn (`isActive` + `[startAt,endAt]`) xem [`05-may-trang-thai.md`](05-may-trang-thai.md) và §5.5.

> **Trạng thái 13/09 (batch 9): ✅ triển khai đủ 5 bước** trong `Frontend\Service\HomeService::sections()` — bước 1 `HomeSectionMapper::listActiveOrdered` bọc `PageCacheService::remember('home-v1')` (TTL 60s); bước 3 nói "JOIN" nhưng **không JOIN chéo bảng** (luật 1 mapper 1 bảng): manual lấy `itemId` theo thứ tự `home_section_items` rồi gọi `listPublishedByIds`/`listActiveByIds` của mapper chủ; bước 5: 5 service ghi Admin `forget('home-v1')` sau mỗi write.
