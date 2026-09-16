# Quy ước đặt tên

> Áp dụng cho mọi dự án. Thống nhất một lần, áp dụng xuyên suốt — không đổi giữa chừng.

## 1. Nguyên tắc chung

| Loại | Quy tắc | Ví dụ |
|------|---------|-------|
| Biến, hàm | camelCase | `orderId`, `getUserById()` |
| Class | PascalCase | `OrderService`, `OrderMapper` |
| Hằng | UPPER_SNAKE | `STATUS_PENDING`, `TABLE_NAME` |
| File tài liệu | kebab-case, không dấu | `01-quy-uoc-dat-ten.md` |
| File view | kebab-case | `danh-sach.phtml` |

- Tên phải **rõ nghĩa, đọc là hiểu** — tránh `data`, `tmp`, `info`, `proc()`, `handle()`.
- Không viết tắt tùy tiện: `eData` → `extraData`, `usr` → `user`.
- Một khái niệm — một tên: đã chọn `order` thì không lúc `order` lúc `bill`.

---

## 2. Biến (variable)

| Quy tắc | Đúng | Sai |
|---------|------|-----|
| camelCase, danh từ rõ nghĩa | `customerPhone`, `totalPrice` | `customer_phone`, `totalprice` |
| Boolean bắt đầu bằng `is/has/should/can` | `isPaid`, `hasStock` | `paid`, `checkStock` |
| Mảng/danh sách ở dạng số nhiều | `orderIds`, `productList` | `orderIdList`, `data` |
| Không dùng tên chung chung | `extraData` | `data`, `tmp` |
| Hằng dùng UPPER_SNAKE | `MAX_RETRY = 3` | `maxRetry` |

```php
// Đúng
$pendingOrders = $orderMapper->listByStatus('pending');
$isReadyToPush = $order->getStatus() === 'paid';

// Sai
$data = $mapper->list('pending');
$check = $order->status == 'paid';
```

---

## 3. Hàm / Method

- camelCase, bắt đầu bằng **động từ**: `get`, `set`, `create`, `update`, `delete`, `list`, `count`, `check`, `sync`.
- Tên nói rõ **làm gì + trên gì**: `getOrderById()`, `syncProductsFromSource()`.

| Đúng | Sai | Vì sao sai |
|------|-----|------------|
| `getOrderById()` | `get_order()` | snake_case, thiếu ById |
| `createOrder()` | `create()` | không rõ tạo gì |
| `isOrderPaid()` | `checkPaid()` | không rõ kiểu trả về |

---

## 4. Mapper (Repository)

> Một mapper = một bảng chính. Đặt tên theo entity, không theo bảng.

| Quy ước | Ví dụ |
|---------|-------|
| Class | `{Entity}Mapper` → `OrderMapper`, `ProductMapper` |
| File | `module/{Module}/src/Model/OrderMapper.php` |
| Namespace | `Module\Model\OrderMapper` |
| Hằng tên bảng | `const TABLE_NAME = 'orders'` |

### Đặt tên method trong Mapper

| Tiền tố | Dùng khi | Ví dụ |
|---------|----------|-------|
| `get*` | Lấy 1 bản ghi | `getOrderById(int $id)` |
| `list*` / `search*` | Lấy nhiều (có phân trang) | `listOrders(array $filter)`, `searchOrders()` |
| `create*` / `insert*` | Thêm mới | `createOrder(array $data)` |
| `update*` | Cập nhật | `updateOrderStatus(int $id, string $status)` |
| `delete*` | Xóa | `deleteOrder(int $id)` |
| `count*` | Đếm | `countOrders(array $filter)` |
| `upsert*` | Thêm hoặc cập nhật | `upsertOrder(array $data)` |

```php
// Đúng
class OrderMapper extends AppMapper
{
    const TABLE_NAME = 'orders';
    public function getOrderById(int $id): ?OrderModel { ... }
    public function listOrders(array $filter): array { ... }
    public function updateOrderStatus(int $id, string $status): bool { ... }
}

// Sai
class OrderTable { ... }           // không theo {Entity}Mapper
class OrderMapper {
    public function get($id) { ... }      // thiếu ById, không rõ entity
    public function save($data) { ... }   // gộp create+update, không rõ
    public function fetchAll() { ... }    // tên chung chung
}
```

> Ranh giới: mapper chỉ query bảng của mình. Cần dữ liệu bảng khác → gọi mapper sở hữu bảng đó, không JOIN chéo. Xem `03-quy-chuan-code.md`.

---

## 5. Service

> Chứa toàn bộ logic nghiệp vụ. Controller chỉ gọi service, không xử lý trực tiếp.

| Quy ước | Ví dụ |
|---------|-------|
| Class | `{Entity}Service` hoặc `{NghiepVu}Service` → `OrderService`, `OrderSyncService` |
| File | `module/{Module}/src/Service/OrderService.php` |
| Method | động từ + nghiệp vụ → `createOrder()`, `syncOrders()`, `cancelOrder()` |

```php
// Đúng
class OrderService
{
    public function createOrder(array $payload): JsonResponse { ... }
    public function syncOrders(int $storeId): array { ... }
    public function cancelOrder(int $orderId, string $reason): bool { ... }
}

// Sai
class OrderLogic { ... }              // không theo *Service
class OrderService {
    public function process() { ... } // không rõ xử lý gì
    public function handle() { ... }  // tên chung chung
}
```

- Một service có thể gọi nhiều mapper, nhưng **không gọi controller**.
- Tên service phản ánh đúng phạm vi: `OrderSyncService` (đồng bộ đơn) khác `OrderService` (CRUD đơn).

---

## 6. Controller

> Mỏng — chỉ nhận request, gọi service, trả response.

| Quy ước | Ví dụ |
|---------|-------|
| Class | `{Entity}Controller` → `OrderController`, `ProductController` |
| File | `module/{Module}/src/Controller/OrderController.php` |
| Method | `{action}Action` → `listAction()`, `detailAction()`, `createAction()` |
| Route | kebab-case → `/order/list`, `/order/detail` |

```php
// Đúng
class OrderController extends AppController
{
    public function listAction(): JsonResponse
    {
        $service = $this->getService(OrderService::class);
        return $service->listOrders($this->getQueryParams());
    }

    public function createAction(): JsonResponse
    {
        return $this->getService(OrderService::class)->createOrder($this->getPostParams());
    }
}

// Sai
class OrderCtrl { ... }                    // viết tắt
class OrderController {
    public function getList() { ... }      // thiếu suffix Action, không rõ HTTP method
    public function doCreate() { ... }     // tiền tố do vô nghĩa
}
```

- Controller **không** validate chi tiết, không query DB trực tiếp — đẩy cho Service/Filter.

---

## 7. Filter (Validate)

> Kiểm tra dữ liệu đầu vào trước khi vào Service.

| Quy ước | Ví dụ |
|---------|-------|
| Class | `{Entity}{Action}Filter` → `OrderCreateFilter`, `ProductListFilter` |
| File | `module/{Module}/src/Filter/OrderCreateFilter.php` |
| Kế thừa | `extends AppFilter` (hoặc base filter chung của dự án) |

```php
// Đúng
class OrderCreateFilter extends AppFilter
{
    public function __construct($container = null)
    {
        parent::__construct($container);
        $this->add(CommonFieldFilters::dynamicField('customerPhone', [
            'required' => true, 'maxLength' => 20,
        ]));
        $this->add(CommonFieldFilters::dynamicField('totalPrice', [
            'required' => true,
        ]));
    }
}

// Sai
class OrderFilter { ... }              // thiếu Action, không rõ dùng cho create hay update
class OrderCreateValidator { ... }     // không theo *Filter
class OrderCreateFilter extends BaseOrderFilter { ... } // tự tạo base riêng, phải extends AppFilter
```

- Filter chỉ khai báo field của action đó, không khai báo lại `businessId` nếu base đã có.
- Không tạo `BaseXxxFilter` riêng trong module nghiệp vụ — dùng chung `AppFilter`.

---

## 8. File & thư mục

| Loại | Quy tắc | Ví dụ |
|------|---------|-------|
| Module | PascalCase | `module/Order/`, `module/Product/` |
| Controller | `{Entity}Controller.php` | `OrderController.php` |
| Service | `{Entity}Service.php` | `OrderService.php` |
| Mapper | `{Entity}Mapper.php` | `OrderMapper.php` |
| Model | `{Entity}Model.php` | `OrderModel.php` |
| Filter | `{Entity}{Action}Filter.php` | `OrderCreateFilter.php` |
| View | kebab-case | `danh-sach.phtml`, `chi-tiet.phtml` |
| Tài liệu | kebab-case, không dấu | `01-quy-uoc-dat-ten.md` |

```
module/Order/
├── src/
│   ├── Controller/OrderController.php
│   ├── Service/OrderService.php
│   ├── Model/OrderMapper.php
│   ├── Model/OrderModel.php
│   └── Filter/OrderCreateFilter.php
└── view/order/danh-sach.phtml
```
