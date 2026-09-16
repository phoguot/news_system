# Tổng hợp cấu trúc & cách khai báo các tầng trong `webapp-be/module`

> Khảo sát trực tiếp repo `E:\Working\WebApp\webapp-be\module` (Laminas MVC, PHP 8.x typed properties), ngày 13/09/2026.
> Ví dụ chuẩn được trích từ module **Content** (đủ cả 5 tầng) và **Business**; nền tảng chung nằm trong module **Application**.

---

## 1. Tổng quan repo

Repo là **backend multi-tenant** (mỗi doanh nghiệp — `businessId` — một vùng dữ liệu, adapter DB tách master/replica theo DN). Các module hiện có:

| Module | Vai trò |
|---|---|
| `Application` | Nền tảng chung: base class mọi tầng, filter chain, paginator, context, factory |
| `Admin`, `User`, `System`, `Settings` | Tài khoản, xác thực, cấu hình hệ thống |
| `Business` | Doanh nghiệp, store, webapp, app version/release, phân quyền theo app |
| `Content` | Bài viết (article), album, banner, menu, tags, dashboard |
| `Product`, `Order`, `Buyer`, `Crm`, `Interact`, `Media`, `Games`, `Integration`, `Zma`, `Logging` | Nghiệp vụ từng vực |

Mỗi module nghiệp vụ có cấu trúc thư mục **chuẩn như nhau**:

```
module/<Name>/
├── config/module.config.php      # routes + đăng ký controller + view_manager
└── src/
    ├── Module.php                # getConfig() + getServiceConfig() (đăng ký mapper/service)
    ├── Controller/               # <X>Controller.php — mỏng, chỉ gọi Service
    ├── Service/                  # <X>Service.php — điều phối filter → mapper → response
    ├── Filter/<Entity>/          # <Entity><Action>Filter.php — validate input + guard quyền
    ├── Model/<Entity>/           # <Entity>Mapper.php + <Entity>Model.php + <Entity>Const.php
    └── Constant/                 # (nếu có) hằng kỹ thuật của module
```

Quy tắc đặt tên Filter theo hành động: `<Entity>SaveFilter`, `<Entity>ListFilter`, `<Entity>DeleteFilter`, `<Entity>DetailFilter`, `<Entity>StatusFilter`, `<Entity>WebAppListFilter`… — **mỗi API một file filter riêng**.

---

## 2. Luồng dữ liệu chuẩn (1 request)

```
HTTP POST /content/album/save  (form hoặc JSON body)
   │
   ▼  route Segment → AlbumController::addAction()      [Controller mỏng]
getPostParamsApi() ── raw payload (merge POST + JSON)
   │
   ▼  getContainerEntry(AlbumService::class)->albumSave($payload)
   │
   ▼  Service: new AlbumSaveFilter($container)            [Filter = InputFilter stateful, NOT shared]
       setData($payload) → isValid()  ── fail → ApiResultModel::errorFromFilter()
       tryRequireBusinessId()          ── null → errorData403Response()
   │
   ▼  new AlbumModel() → exchangeArray($formData) → set BusinessId
   │
   ▼  getContainerEntry(AlbumMapper::class)->saveAlbum($model)   [1 mapper = 1 bảng]
       dùng Laminas Sql (Insert/Update/Select) + adapter master/replica theo businessId
   │
   ▼  Mapper trả về <Entity>Model đã hydrate (exchangeArray từ row)
Service: ApiResultModel::successResponse($data, $messages) → JsonResponse (ViewJsonStrategy)
```

Đặc trưng lớn nhất so với MVC cổ điển: **Controller không inject gì cả** — tự lấy service từ container qua `getContainerEntry()`; **Service mới là nơi `new` Filter và Model**; **Mapper nhận/trả Model object chứ không nhận mảng**.

---

## 3. Tầng Controller

### Kế thừa

| Bối cảnh | Base class |
|---|---|
| API admin/backend theo DN | `Application\Controller\AdminAppController` |
| API buyer/end-user | `module/Buyer/src/Controller/AppBuyerController extends AppController` |
| Chung | `Application\Controller\AppController extends AbstractActionController implements FactoryInterface` |

`AdminAppController::onDispatch()` là middleware guard: check identity (401) → ACL resource (đang shadow-log, `ACL_ENFORCE=false`) → `populateRequestContext()` gắn `businessId` vào `RequestContextService` **chỉ khi user thực sự quản lý DN** (deny-by-default) → audit ghi log khi system-admin ghi xuyên DN. Quyền theo business/app cụ thể **đẩy xuống tầng Filter**.

### Mẫu khai báo (Content/src/Controller/AlbumController.php)

```php
<?php
namespace Content\Controller;

use Application\Controller\AdminAppController;
use Application\Model\JsonResponse;
use Content\Service\AlbumService;

class AlbumController extends AdminAppController
{
    public function indexAction(): JsonResponse          // GET-style: danh sách
    {
        $service = $this->getContainerEntry(AlbumService::class);
        return $service->albumList($this->getPostParamsApi());
    }

    public function addAction(): JsonResponse            // POST: create + update (id null → create)
    {
        $service = $this->getContainerEntry(AlbumService::class);
        return $service->albumSave($this->getPostParamsApi());
    }
    // detailAction / deleteAction / categoryAction ... — cùng khuôn mẫu 2 dòng trên
}
```

Quy ước:
- Action **trả `JsonResponse`** (ViewJsonStrategy render), không render template.
- Mọi input đi vào service qua `$this->getPostParamsApi()` — gộp POST + files + JSON body (JSON override key trùng).
- Controller **không** validate, **không** `new` Filter/Mapper.
- Tên action dạng `index/detail/add/delete` + hậu tố entity ghép (`savecategoryAction`, `deletetagAction`).

---

## 4. Tầng Service

Kế thừa `Application\Factory\AppServiceFactory` (vừa là service vừa là `FactoryInterface` — `__invoke` set container rồi trả `$this`; nhờ vậy đăng ký `AppInvokableFactory::class` là dùng được ngay).

Mẫu chuẩn 1 API list + 1 API save (Content/src/Service/AlbumService.php):

```php
class AlbumService extends AppServiceFactory
{
    public function albumList(array $payload = []): JsonResponse
    {
        $apiResult = new ApiResultModel();

        // 1. Filter validate input (new trực tiếp — InputFilter stateful, không share)
        $filter = new AlbumListFilter($this->getContainer());
        $filter->setData($payload);
        if (!$filter->isValid()) {
            return $apiResult->errorFromFilter($filter);
        }
        $formData = $filter->getData();

        // 2. Scope business từ context (đã được AdminAppController gate quyền)
        $businessId = $this->tryRequireBusinessId();
        if (!$businessId) {
            return $apiResult->errorData403Response([AppMessage::CANT_MANAGE_STORE]);
        }

        // 3. Dựng Model làm "search query object", đưa mapper xử lý
        $model = new AlbumModel();
        $model->setBusinessId($businessId);
        $model->setId(...)->setCategoryId(...)->setStatus(...);
        $model->setOptions([            // params phụ: sort/dir/name LIKE/cursor...
            'sort' => $formData['sort'] ?? 'id',
            ApiConst::NEXT_LAST_ID   => ...,
            ApiConst::SIZE_NUMBER    => (int)(...),
        ]);

        $paginator = $this->getContainerEntry(AlbumMapper::class)->searchAlbum($model);

        // 4. Response chuẩn: map từng item qua getResp* của Model
        return $apiResult->responseLastIdPaginator($paginator, fn(AlbumModel $i) => $i->getRespAlbum());
    }

    public function albumSave(array $payload = []): JsonResponse
    {
        // ... filter + businessId như trên; nếu có id → getAlbum kiểm tra tồn tại/ownership, fail trả 404
        $model = (new AlbumModel())->exchangeArray($formData);
        $model->setBusinessId($businessId);
        $saved = $mapper->saveAlbum($model);
        return $apiResult->successResponse(['id' => $saved->getId()],
            [$id ? AppMessage::UPDATE_SUCCESSFULLY : AppMessage::ADD_SUCCESSFULLY]);
    }
}
```

Điểm nhấn:
- Service **sở hữu toàn bộ điều phối**: filter → guard 403 → existence-check 404 → mapper → response.
- Response luôn qua `ApiResultModel`: `successResponse / errorFromFilter / errorInvalidFormResponse / errorData403Response / errorData404Response / responsePaginator / responseLastIdPaginator`. JSON ra client có dạng `{code, errorCode, messages, data}` (code: 1 success, 0 fail, 10 server error).
- Lấy dependency khác bằng `$this->getContainerEntry(X::class)` — không constructor injection.

---

## 5. Tầng Filter (InputFilter + quyền)

### Hệ thống base class trong `Application/src/Filter`

| Class | Dùng khi | Tự inject/kiểm tra gì |
|---|---|---|
| `AppFilter extends Laminas\InputFilter\InputFilter` | nền mọi filter | nhận `$container` qua constructor; helper `initRequiredFilterNumbers`, `initInputPaging/getPaging` (pageSize max 200, default 50), `initSorting/getSorting`, `setError($field,$msg,$errorCode)` + `getAuthErrorCode()` |
| `AuthScopedFilter` | API **admin** theo DN | input `businessId` bắt buộc; `isValid()` check DN tồn tại + `UserService::canManageThisBusiness` — hết quyền gắn lỗi 403 (`ERR_DATA_403`), không phải lỗi form |
| `WebAppScopedFilter` | API gắn 1 webapp | input `webAppId` + `businessId` bắt buộc, businessId bơm từ context |
| `EndUserScopedFilter` (← WebAppScoped) | API buyer | input `endUserId` **luôn ghi đè từ context**, xóa `zaloUserId` client gửi lên (chặn đọc/ghi dữ liệu người khác) |
| `ZaloUserScopedFilter`, `GuestAllowedFilter` | kênh Zalo / API khách vãng lai | tương tự theo scope |
| `CommonFieldFilters` | **không kế thừa** — helper `static` sinh cấu hình field | xem dưới |

### Mẫu khai báo SaveFilter (Content/src/Filter/Album/AlbumSaveFilter.php)

```php
class AlbumSaveFilter extends AuthScopedFilter
{
    public function __construct($container, $options = [])
    {
        parent::__construct($container, $options);

        foreach (['id','publishedAt','categoryId','parentId','fileId','displayOrder','status'] as $f) {
            $this->add(CommonFieldFilters::intField($f));          // số nguyên optional
        }
        foreach (['name' => CommonFieldFilters::LEN_TITLE,
                  'metaTitle' => CommonFieldFilters::LEN_META_TITLE, ...] as $f => $maxLen) {
            $this->add(CommonFieldFilters::dynamicField($f, [
                'type' => CommonFieldFilters::TYPE_TEXT,           // Trim + StripTags + HTMLPurifier + StringLength
                'required' => $f === 'name',
                'maxLength' => $maxLen,
            ]));
        }
        $this->add($this->richTextField('content'));               // HTML rich text: StringTrim + HTMLPurifierFilter
    }

    // Flatten payload FE nested → input phẳng
    public function setData($data)
    {
        $info = $data['info'] ?? [];  $seo = $data['seo'] ?? [];  $media = $data['media'] ?? $data['image'] ?? [];
        return parent::setData([
            'id'   => $data['id']   ?? $info['id']   ?? null,
            'name' => $data['name'] ?? $info['name'] ?? '',
            'metaTitle' => $data['metaTitle'] ?? $seo['metaTitle'] ?? '',
            ...
        ]);
    }
}
```

Các khuôn khác:
- **DeleteFilter**: chỉ `$this->add(CommonFieldFilters::intField('id', true));` (required).
- **ListFilter**: int fields + text fields (`sort`, `dir`, `nextLastId`, `reqTotalCount`, `fromDate`, `toDate`…) — toàn bộ optional.
- **Filter có guard quyền app**: override `isValid()` sau `parent::isValid()` rồi gọi helper của `AuthScopedFilter` — vd `ArticleSaveFilter`:

```php
public function isValid($context = null): bool
{
    if (!parent::isValid($context)) return false;
    // gom mọi webAppId định ghi → check phạm vi role của user
    if (!$this->canWriteWebApps($this->getData()['webAppIds'] ?? [], true)) {
        $this->setError('webAppIds', AppMessage::CANT_MANAGE_APP, AppConst::ERR_DATA_403);
        return false;
    }
    return true;
}
```

  (guard đơn app: `canWebApp()`; `canWriteWebApps` phân biệt ROLE_MANAGER toàn DN vs ROLE_MANAGER_APP theo danh sách app được gán.)

`CommonFieldFilters` cung cấp: `textField`, `rawStringField`, `jsonObjectStringField`, `jsonPayloadField`, `intField`, `float`, `enum` (TYPE_* + `dynamicField`), `intArrayField`, `stringArrayField`, `objectArrayField`, `fileUploadField`, `uploadTypeField` + preset độ dài `LEN_TITLE=120, LEN_DESCRIPTION=255, LEN_META_TITLE=70, LEN_META_DESCRIPTION=160, LEN_CODE=50, LEN_CONTENT=5000, MAX_JSON_BYTES=2MB`.

---

## 6. Tầng Model (`<Entity>Model extends AppModel`)

`AppModel` (Application/src/Model/AppModel.php) có sẵn:
- `businessId` (scope tenant) + `options` map (params tìm kiếm/runtime: `addOption/getOption/setOptions`).
- `exchangeArray($row)`: map key → `set<Key>()` (chỉ gọi setter tồn tại), tự **normalizeValue** cast theo kiểu tham số setter qua Reflection (int/float/bool/string, `''`/null → null).
- Magic `__get/__set`, cơ chế **extraContent/extraFields**: cột DB `extraContent` (JSON), decode thành array; `addExtraField($k,$v)` validate key + cast theo Const class của entity qua `getConstClass()`.

### Mẫu khai báo (Content/src/Model/Album/AlbumModel.php)

```php
class AlbumModel extends AppModel
{
    const int STATUS_ACTIVE = 1;
    const int STATUS_INACTIVE = 2;

    // --- DB columns: typed properties, default null ---
    protected ?int $id = null;
    protected ?int $categoryId = null;
    protected ?string $name = null;
    protected ?int $status = null;
    ...
    // --- Runtime/display fields (không persist) ---
    protected ?string $categoryName = null;
    protected ?string $avatarUri = null;
    // --- Search helpers (không persist) ---
    protected ?string $fromDate = null;  protected ?string $toDate = null;

    public function getId(): ?int { return $this->id; }
    public function setId(?int $id): self { $this->id = $id; return $this; }   // fluent, trả self
    ...

    // Field nằm trong extraContent → đọc/ghi qua extraFields + Const key
    public function getMetaTitle(): ?string { return $this->extraFields[AlbumConst::EXT_META_TITLE] ?? null; }
    public function setMetaTitle(?string $v): self { return $this->addExtraField(AlbumConst::EXT_META_TITLE, $v); }

    protected function getConstClass(): ?string { return AlbumConst::class; }

    // Formatter ra API response — mọi field cast qua AppFormat::castIntOrNull / castStringOrNull
    public function getRespAlbum(): array
    {
        return [
            'id'    => AppFormat::castIntOrNull($this->id),
            'name'  => AppFormat::castStringOrNull($this->name),
            'category'  => ['id' => ..., 'name' => AppFormat::castStringOrNull($this->categoryName)],
            'createdBy' => ['id' => ..., 'name' => ...],   // user info do mapper enrich thêm
            'image'     => ['thumb' => $this->getOption('thumbnailUri') ?: null, 'original' => $this->getAvatarUri(), 'alt' => $this->getName()],
            ...
        ];
    }
}
```

Quy ước: property trùng tên cột DB (camelCase); nhóm comment phân block `DB columns / Runtime / Search helpers`; **model không query DB** — chỉ hydrate + format output (`getResp<Entity>()`).

### `<Entity>Const` (Content/src/Model/Album/AlbumConst.php)

```php
class AlbumConst extends AppConstModel   // Application\Model\Constant\AppConstModel
{
    const string EXT_META_TITLE = 'metaTitle';
    ...
    public static array $allowedExtraFields = [    // key => kiểu ('string', 'int', ...)
        self::EXT_META_TITLE => 'string', ...
    ];
}
```
Const class khai báo whitelist + kiểu cho `extraContent`; hằng trạng thái có thể nằm trực tiếp trên Model (`STATUS_ACTIVE`) hoặc Const.

---

## 7. Tầng Mapper (`<Entity>Mapper extends AppMapper`) — 1 mapper = 1 bảng

`AppMapper extends AppServiceFactory` → có `getContainerEntry()`; cung cấp:
- `getDbSql()` (Laminas `Sql`), `getDbAdapter()` (common), **`getBusinessMasterAdapter($businessId)` / `getBusinessReplicaAdapter($businessId)`** (tenant sharding — write đọc qua master, đọc qua replica), cluster POS adapters, `DbUserAdapter` (stores/users/depots).
- `preparePaginator($select, ['dbAdapter'=>..., page, pageSize], $objectPrototype)` — paginator offset-based (Laminas), hydrate trực tiếp thành Model qua `ResultSet::setArrayObjectPrototype`. **Bắt buộc truyền dbAdapter** (throw ngoài production nếu thiếu).
- `MAX_RECORD_FETCH_ALL = 2000` — trần fetch all.

### Khuôn CRUD chuẩn (Content/src/Model/Album/AlbumMapper.php)

```php
class AlbumMapper extends AppMapper
{
    const string TABLE_NAME = 'albums';

    // INSERT nếu model chưa có id (bơm createdById/createdAt), UPDATE nếu có (updatedById/updatedAt)
    public function saveAlbum(AlbumModel $item): AlbumModel|false
    {
        $businessId = $item->getBusinessId();
        if (!$businessId) return false;                          // tenant guard, luôn check đầu hàm

        $dbAdapter = $this->getBusinessMasterAdapter($businessId);
        $dbSql = $this->getDbSql();
        $data = ['categoryId' => $item->getCategoryId(), ...,     // map field → cột
                 'extraContent' => $extra ? json_encode($extra) : null];

        if (!$item->getId()) {
            $insert = $dbSql->insert(self::TABLE_NAME); $insert->values($data);
            $result = $dbAdapter->query($dbSql->buildSqlString($insert), $dbAdapter::QUERY_MODE_EXECUTE);
            $item->setId((int)$result->getGeneratedValue());
            return $item;
        }
        $update = $dbSql->update(self::TABLE_NAME); $update->set($data);
        $update->where(['id' => (int)$item->getId()]);
        $dbAdapter->query($dbSql->buildSqlString($update), $dbAdapter::QUERY_MODE_EXECUTE);
        return $item;
    }

    // get: where `a.id = ?` (placeholder trong Sql where — không nội suy chuỗi), limit(1), hydrate bằng exchangeArray
    public function getAlbum(AlbumModel $item): ?AlbumModel { ... }

    // search admin: cursor-based — LastIdPaginator, tham số phụ đọc từ $item->getOption(...)
    public function searchAlbum(AlbumModel $item, ?array $options = null): mixed
    {
        $select = $dbSql->select(['a' => self::TABLE_NAME]);
        $select->columns([...]);                                  // liệt kê cột tường minh
        if ($item->getId() !== null) $select->where(['a.id = ?' => (int)$item->getId()]);
        if ($item->getStatus() !== null) $select->where(['a.status = ?' => $item->getStatus()]);
        if ($item->getOption('name')) $select->where->like('a.name', '%' . $item->getOption('name') . '%');
        // sort whitelist: in_array($sort, ['id','displayOrder','publishedDate'], true) — chống SQL injection qua order
        $paginator = (new LastIdPaginator($select, $dbAdapter, $dbSql, $item))
            ->withPrimaryKey('id')->withObjectPrototype(new AlbumModel())->paginate();
        return $this->getInforAlbum($paginator->getItems(), $businessId);   // enrich trước khi trả
    }

    // search buyer: offset paginator — trả Laminas Paginator để FE hiển thị totalPages/totalItems
    public function searchAlbumPaginator(AlbumModel $item, array $paging): Paginator|false { ... }

    public function deleteAlbum(AlbumModel $item): bool { /* master adapter + delete where id */ }
}
```

### Enrich thay vì JOIN chéo bảng

`getInforAlbum()` (private): gom `createdById/updatedById`, `categoryId`, `fileId` của danh sách → gọi **mapper sở hữu bảng đó qua container** (`AlbumCategoryMapper::getAlbumCategoryNameMapByIds`, `BusinessUserMapper::getBusinessUserFullNameMapByIds`) + `StorageService::getImgLinkBucket` cho URL ảnh/thumbnail → set ngược vào từng Model (`setUser`, `setCategoryName`, `setAvatarUri`, `addOption('thumbnailUri', …)`). Đây là cách giữ luật *1 mapper = 1 bảng, không JOIN xuyên bảng*.

---

## 8. Đăng ký DI — Module.php & module.config.php

Hai điểm **khác** với cách làm closure-inline (điểm khác biệt lớn so với repo News):

1. **Routes + controllers** khai trong `config/module.config.php`; mọi controller dùng `AppInvokableFactory::class`. Route dạng Segment mở `[/:action]` với constraints action — một route phủ mọi action của controller:

```php
'content_album' => [
    'type' => Segment::class,
    'options' => [
        'route' => '/content/album[/:action]',
        'constraints' => ['action' => '[a-zA-Z][a-zA-Z0-9_-]*'],
        'defaults' => ['__NAMESPACE__' => 'Content\Controller',
                        'controller' => Controller\AlbumController::class,
                        'action' => 'index'],
    ],
],
// ...
'controllers' => ['factories' => [
    Controller\AlbumController::class => AppInvokableFactory::class,   // không có factory riêng
]],
'view_manager' => ['strategies' => ['ViewJsonStrategy'], ...],
```

2. **Mapper + Service đăng ký trong `src/Module.php::getServiceConfig()`** — không nằm ở module.config.php; gần như tất cả trỏ về `AppInvokableFactory::class` (factory này `new $requestedName` rồi `setContainer($container)` — vì `AppServiceFactory`/`AppController` đều implements `FactoryInterface::__invoke` + có `setContainer`):

```php
public function getServiceConfig()
{
    return [
        'invokables' => [ AlbumModel::class => AlbumModel::class, ... ],   // prototype cho ResultSet hydrate
        'factories'  => [
            AlbumMapper::class  => AppInvokableFactory::class,
            AlbumService::class => AppInvokableFactory::class,
            ...
        ],
    ];
}
```

**Không có closure DI, không có `*Factory.php` riêng cho service/mapper/controller** trong các module này — toàn bộ đi qua `AppInvokableFactory` chung. Filter **không** đăng ký DI (Service `new` trực tiếp với `$container` truyền vào constructor).

---

## 9. Bảng tra nhanh: file nào nằm đâu, extends gì

| Tầng | Đường dẫn | Kế thừa | Đặt tên |
|---|---|---|---|
| Controller | `src/Controller/XController.php` | `AdminAppController` (admin) / `AppBuyerController` (buyer) / `AppController` | action trả `JsonResponse` |
| Service | `src/Service/XService.php` | `AppServiceFactory` | method 1-1 với action, nhận `array $payload`, trả `JsonResponse` |
| Filter | `src/Filter/<Entity>/X<Verb>Filter.php` | `AuthScopedFilter` \| `WebAppScopedFilter` \| `EndUserScopedFilter` \| `AppFilter` | verb = Save/List/Delete/Detail/Status/… |
| Model | `src/Model/<Entity>/XModel.php` | `AppModel` | getter/setter fluent + `getRespX()` |
| Const | `src/Model/<Entity>/XConst.php` | `AppConstModel` | `EXT_*` + `$allowedExtraFields` |
| Mapper | `src/Model/<Entity>/XMapper.php` | `AppMapper` | `saveX/getX/searchX/deleteX`, `const TABLE_NAME` |
| DI service | `src/Module.php` | — | `getServiceConfig()['factories']` |
| Route + controller | `config/module.config.php` | — | Segment `[/:action]` |

## 10. Luật bảo mật/quy ước rút ra từ code

1. **Quyền 2 lớp**: controller chỉ gate scope (businessId vào context khi `canManageThisBusiness`, deny-by-default); filter check ownership/role từng request (`AuthScopedFilter::isValid`, `canWebApp`, `canWriteWebApps`); service gọi `tryRequireBusinessId()` làm scope bắt buộc.
2. Lỗi quyền trả **errorCode riêng** (`ERR_DATA_403`) nhờ `setError($f,$msg,$code)` + `ApiResultModel::errorFromFilter()` — lẫn với lỗi validate form.
3. Mọi input FE đi qua **InputFilter + HTMLPurifier** (text/rich-text); sort column trong mapper **whitelist bằng `in_array` strict**; where dùng placeholder `?` của Laminas Sql, `(int)` cast — không nội suy chuỗi vào SQL.
4. `endUserId`/`zaloUserId` **luôn bơm từ context, xóa giá trị client gửi** (`EndUserScopedFilter::setData`).
5. Tenant isolation: mapper nào cũng check `businessId` đầu hàm và lấy adapter theo businessId (master cho ghi, replica cho đọc).
6. `createdAt/updatedAt` là **timestamp int** (`DateModel::getTimeStampsCurrent()`), kèm `createdById/updatedById` lấy từ `UserService::getIdentity()`.
7. Trả lời FE luôn qua `getResp<Entity>()` với `AppFormat::cast*OrNull` — FE không bao giờ thấy raw row DB.
```
