# Template — Tính năng mới

> Copy file này → `02-thiet-ke/xx-ten-tinh-nang.md` rồi điền. Xóa dòng này sau khi điền.

## Tên tính năng



## Mục tiêu

> Người dùng làm gì, kết quả gì?

## Endpoint / Bảng

| Endpoint/Bảng | Việc |
|---------------|------|
| ... | ... |

## Service & Mapper

| Thành phần | Class |
|------------|-------|
| Service | ... |
| Mapper | ... |
| Filter | ... |
| View | ... |

## Quy tắc nghiệp vụ

- Idempotent như thế nào?
- Thiếu dữ liệu → quarantine hay lỗi?
- Có cần phân biệt theo loại/adapter không?

## Checklist

- [ ] Logic ở Service, Controller chỉ điều phối
- [ ] Migration + seed (nếu thêm bảng/cột)
- [ ] Cập nhật `02-thiet-ke/00-readme.md`
