# Template — Bảng / Entity mới

> Copy file này → ghi vào `02-thiet-ke/03-mo-hinh-du-lieu.md` hoặc file riêng.

## Tên bảng



## Schema

```sql
CREATE TABLE ten_bang (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ...
  UNIQUE KEY uk... (...),
  KEY idx... (...)
) ENGINE=InnoDB;
```

## Cột

| Cột | Kiểu | Null | Mô tả |
|-----|------|------|-------|
| ... | ... | ... | ... |

## Quan hệ

> Trỏ tới bảng nào, qua cột nào.

## Lưu ý

- Có cần index không?
- Có cần seed không?
- Bảng này có khôi phục được từ API ngoài không?
