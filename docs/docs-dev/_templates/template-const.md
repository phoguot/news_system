# Template — File const (theo entity / dùng chung)

> Copy khi tạo entity mới hoặc hằng dùng chung. **Chuẩn mới (12/09/2026, xem [`../01-quy-chuan/06-quy-uoc-const.md`](../01-quy-chuan/06-quy-uoc-const.md)):**
> - Hằng của 1 entity → `module/{Module}/src/Model/{Entity}/{Entity}Const.php`, class `{Entity}Const`.
> - Hằng dùng chung ≥ 2 module → `module/Application/src/Constant/{Chung}Const.php` (trước 13/09 là `module/Core/...`).
> - Hằng kỹ thuật module (session ns, key route…) không gắn entity → vẫn dùng `module/{Module}/src/Constant/{Module}Const.php`.
>
> `class` + namespace `use ...Model\{Entity}` / `use ...Constant`:

```php
<?php
namespace {Module}\Model\{Entity};   // hoặc Application\Constant (trước 13/09 là Core\Constant) cho hằng dùng chung

class {Entity}Const
{
    // Trạng thái
    const STATUS_PENDING = 1;
    const STATUS_DONE    = 2;

    // Loại
    const TYPE_DEFAULT = 1;

    // Lỗi
    const ERROR_NOT_FOUND = '{MODULE}_NOT_FOUND';

    // Map hiển thị
    const STATUS_LABELS = [
        self::STATUS_PENDING => 'Chờ xử lý',
        self::STATUS_DONE    => 'Hoàn thành',
    ];
}
```

## Checklist

- [ ] File đặt ở `Model/{Entity}/{Entity}Const.php` (hằng entity) hoặc `Application/Constant/` (dùng chung — trước 13/09 là `Core/Constant/`)
- [ ] Tên class `{Entity}Const`, hằng UPPER_SNAKE có prefix (`STATUS_`, `TYPE_`, `ERROR_`)
- [ ] Không hard-code magic string/number trong Service — luôn qua Const
- [ ] Không trùng tên hằng với module khác — import `use` từ module gốc
