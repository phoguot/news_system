# Quy chuẩn API

> Chuẩn request/response cho **API quản trị** (`/api/admin/*`, JSON). Chuẩn DB đi kèm: [`02-quy-chuan-db.md`](02-quy-chuan-db.md); tầng xử lý: [`03-quy-chuan-code.md`](03-quy-chuan-code.md). Chi tiết endpoint đầy đủ: docs phân tích [§6.2](../../phan-tich-he-thong-website-tin-tuc.md).

## 1. Nguyên tắc chung

| Quy tắc | Chi tiết |
|---|---|
| Tiền tố | Mọi endpoint quản trị nằm dưới `/api/admin` |
| Xác thực | Session `VANLANG_SESS` (đăng nhập admin). Chưa đăng nhập → **401**, không redirect |
| Method | REST: `GET` đọc, `POST` tạo/hành động, `PUT` thay thế, `PATCH` cập nhật một phần, `DELETE` **xoá vĩnh viễn** |
| Định dạng | `application/json` cả hai chiều (trừ `POST /media` dùng `multipart/form-data`) |
| Khóa JSON | **camelCase**, khớp tên cột DB. Ngoại lệ duy nhất: khoá trong `home_sections.config` giữ `snake_case` (docs §3.7) |
| Thời gian | ISO 8601 UTC (`2026-09-12T03:00:00Z`); frontend/admin tự đổi sang giờ VN |
| Phân trang | Query `?page=&perPage=`, trả trong `meta` (xem mục 2) |

## 2. Khung phản hồi (response envelope)

```json
{
  "success": true,
  "data": { },
  "meta": { "page": 1, "perPage": 20, "total": 135 },
  "errors": null
}
```

| Trường | Khi nào có |
|---|---|
| `success` | Luôn có; `false` khi HTTP ≠ 2xx |
| `data` | Body kết quả (object hoặc mảng) |
| `meta` | Chỉ khi phân trang/đếm: `page`, `perPage`, `total` |
| `errors`| Chỉ khi lỗi: map `{"truong": "thong bao"}` cho validate 422, hoặc mảng mô tả cho 409 |

Controller trả `JsonModel` (Admin bật `ViewJsonStrategy`); **không** tự `json_encode` tay trong action.

## 3. Mã lỗi HTTP

| HTTP | Khi nào | Ví dụ |
|---|---|---|
| 401 | Chưa đăng nhập / hết phiên | Mọi `/api/admin/*` không có session hợp lệ |
| 403 | Sai quyền (không dùng ở đây — hệ 1 admin) | — |
| 404 | Không tìm thấy bản ghi | `GET /api/admin/posts/999` |
| 409 | Bị chặn bởi ràng buộc nghiệp vụ khi xoá | Danh mục còn bài (docs §5.15), media đang được dùng (docs §5.14) — kèm `errors` liệt kê nơi đang sử dụng. **KHÔNG dùng 422 cho trường hợp này** |
| 422 | Validate input thất bại | Title trống, slug trùng — `errors` theo từng trường |
| 429 | Vượt rate limit | Gửi liên hệ lặp lại cùng IP (docs §5.10) |

Mọi `DELETE` là **xoá vĩnh viễn**, chạy trong MỘT transaction kèm dọn bản ghi con (docs §4.6) — xem [`../02-thiet-ke/04-luong-nghiep-vu.md`](../02-thiet-ke/04-luong-nghiep-vu.md).

## 4. Đặt tên endpoint

| Quy tắc | Đúng | Sai |
|---|---|---|
| Resource là danh từ số nhiều, kebab-case | `/home-sections`, `/team-members` | `/homeSection` |
| Hành động nghiệp vụ = `POST /{id}/{verb}` | `POST /posts/{id}/publish` | `POST /posts/{id}` với `?do=publish` |
| Reorder/ordering = `PUT /{resource}/reorder` | `PUT /banners/reorder` | `PUT /banners/{id}/order` từng cái |
| Đếm/đếm theo nhóm = `GET /{resource}/counts` | `GET /posts/counts` | `?counts=1` |
| Thao tác hàng loạt = `POST /{resource}/bulk` | `POST /posts/bulk` (body: `action`, `ids[]`) | `DELETE /posts?id=1&id=2` |

Nhóm endpoint đầy đủ (auth, posts, categories, tags, services, banners, home-sections, team-members, contacts, media, account, settings, dashboard): docs §6.2. Frontend route (`/tin-tuc`, `/danh-muc/{slug}`, ..., `POST /api/contact`) không theo chuẩn envelope này — xem docs §6.1.

## 5. Trạng thái triển khai trong repo (kiểm chứng 12/09/2026 — sau đợt CRUD)

| Hạng mục | Thực tế |
|---|---|
| Route | 1 route gộp `admin-api`: `/api/admin/:resource[/:id][/:sub]` → `ApiController::dispatchAction` (`module/Admin/config/module.config.php`) — dispatcher **đã code thật** cho `categories` · `tags` · `posts`; resource khác → nhánh fallback **404** `{"resource":"Endpoint không tồn tại."}` (§6.2 chưa tách từng endpoint) |
| Response | ✅ Đã đúng envelope 4 khoá `{success,data,meta,errors}` qua `JsonModel` (không `json_encode` tay). Khoá JSON `camelCase` khớp cột DB; mọi cột `*At` serialize từ `'Y-m-d H:i:s'` UTC → **ISO 8601 UTC `…Z`** (`serializeRow`). `publishedAt` đầu vào nhận ISO UTC → `isoToVnWallInput` đổi về giờ VN cho Filter/Service. `data` = array khi list (+`meta.page/perPage/total`), object khi 1 bản ghi |
| Codes đã dùng | **401** (AuthGuard — xem dòng dưới) · **404** (`NotFoundException` / resource không có) · **409** (`ConflictException` → `errors` = **mảng** lý do, vd xoá category còn con/còn bài) · **422** (`ValidationException` → `errors` = map `{"trường":"thông báo"}`) |
| CSRF | JSON mutation (POST/PUT/PATCH/DELETE) dựa **cookie session `SameSite=Lax`** — **không** gửi CSRF token; `SaveFilter` khởi tạo với `$withCsrf = false`. Form HTML trong trang admin vẫn dùng CSRF `csrfHash()` bình thường (docs bảo mật) |
| Auth guard | ✅ Đã có `Admin\Service\AuthGuard` gắn trên `EVENT_DISPATCH` (priority 100, attach qua SharedEventManager trong `Admin\Module::init()`) — `/api/admin/*` chưa đăng nhập trả **401** + envelope `errors.auth`; `/admin/*` trả 302 về login. Chi tiết: [`../05-van-hanh/03-xac-thuc-phan-quyen.md`](../05-van-hanh/03-xac-thuc-phan-quyen.md) |

### 5.1 Endpoint `categories` (`ApiController::categories`)

`GET /categories` (list phẳng) · `GET /categories/tree` (cây ≤ 2 cấp, node cấp 1 có `children`) · `PUT /categories/reorder` (FR-26 — body `{ids:[..]}` → `data {flag, applied}`; không CSRF, SameSite=Lax; core `CategoryService::reorderApi`) · `POST /categories` · `GET /categories/{id}` · `PUT`\|`PATCH /categories/{id}` · `DELETE /categories/{id}` (còn con hoặc còn bài → **409**).

### 5.2 Endpoint `tags` (`ApiController::tags`)

`GET /tags?q=` (mỗi tag kèm `postCount`; `q` là nguồn autocomplete tag trong form bài viết — FR-28) · `POST /tags` · `GET /tags/{id}` · `PUT`\|`PATCH /tags/{id}` · `DELETE /tags/{id}` · `POST /tags/merge` body `{sourceId,targetId}` (gộp tag, trả `{mergedInto}`).

### 5.3 Endpoint `posts` (`ApiController::posts` / `postAction`)

`GET /posts` (+`meta` phân trang; query `tab`=`all|draft|scheduled|published|archived`, `status`, `categoryId`, `tagId`, `q`, `featured`, `page`, `perPage`) · `GET /posts/counts` (đếm theo tab) · `POST /posts` (`intent`=`draft|publish|schedule` + `tags`) · `GET /posts/{id}` (kèm `tagIds`,`tagNames`) · `PUT`\|`PATCH /posts/{id}` · `DELETE /posts/{id}` (xoá cứng, dọn bảng con) · `POST /posts/{id}/publish` · `/archive` · `/draft` · `/preview-token` · `POST /posts/{id}/autosave` (FR-21 — body `{title,excerpt,content}`; chỉ giữ 1 bản type=1 — xoá autosave cũ trước insert; body rỗng → `{autosaved:false,reason:"empty"}`; content qua Purifier trước khi ghi) · `GET /posts/{id}/revisions` (danh sách `PostRevisionModel::toArray()`, mới nhất trước) · `POST /posts/{id}/revisions` body `{revisionId}` = **restore** — route gộp chỉ 3 segment nên `revisionId` nằm ở **body**, không phải URL §6.2 (lệch có chủ đích; luồng page dùng `POST /admin/posts/restore/{id}` + CSRF) · `POST /posts/bulk` (FR-22 — body `{action, ids[], categoryId?, confirmCount?}` → `data {applied, skipped:[{id,reason}]}`; xoá bắt buộc `confirmCount` = đúng số id).

### 5.4 Endpoint §6.2 CHƯA triển khai → trả 404 như route lạ

`PUT /{resource}/reorder` cho services/banners/home-section/team/contact/media/setting/account/dashboard (categories đã có — §5.1). Khi làm, bổ sung nhánh tương ứng trong `dispatchAction` — không thêm route rời.

> **Lệch có chủ đích 14/09 (kéo thả nhóm FR-26/29/30/32/34):** nơi nhận thao tác đổi thứ tự hiện tại là **page-level** `POST /admin/{module}/reorder` (categories/services/banners/home-sections/team). JS chung `tbody.js-sortable` (`public/js/admin.js`) gửi XHR kèm `X-Requested-With` → controller trả `JsonModel {flag, applied}`; POST trực tiếp không AJAX rơi về PRG (`?flag=...`). CSRF do `Admin\Filter\Reorder\ReorderFilter` gắn nền (`csrfHash()` render vào `data-csrf` của tbody). `ids` là CSV/mảng id theo thứ tự MỚI: id được nêu trước nhận sortOrder 0..n-1, dòng thiếu giữ thứ tự cũ ở cuối — chỉ ghi dòng đổi giá trị, trong một transaction, kèm hook invalidate cache của từng service.
