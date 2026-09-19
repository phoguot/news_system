# 07 — Quy ước tầng CRUD (chuẩn EnglishTrain-style)

> **NGUỒN SỰ THẬT về cấu trúc file CRUD**: tên class, tầng, thư mục, DI. Tạo class mới trái file này = sai quy ước dù code chạy được.
> Có hiệu lực từ 12/09/2026 (duyệt tổ chức lại `module/` theo EnglishTrain). Code cũ còn dạng `Table/`/`Form/`/`Guard/`/`*Factory.php` — xem bản đồ refactor ở [`../00-tong-quan/05-cau-truc-thu-muc.md`](../00-tong-quan/05-cau-truc-thu-muc.md) §3; **class mới viết theo chuẩn này ngay**, class cũ đổi chỗ nào tiện tay đổi theo.

## 1. Cấu trúc file bắt buộc cho một resource

```
module/<Module>/src/
├── Controller/
│   └── <Entity>Controller.php      # MỎNG: nhận request, gọi Service — không Filter, không Mapper, không SQL
├── Service/
│   └── <Entity>Service.php         # chạy Filter validate input, business logic, điều phối Mapper, transaction
├── Model/<Entity>/
│   ├── <Entity>Model.php           # POPO: getter/setter, hydrate từ row, trạng thái
│   ├── <Entity>Mapper.php          # SQL: search/get/save/delete — CHỈ đụng bảng của nó
│   └── <Entity>Const.php           # hằng riêng entity (STATUS_*, LABELS…)
└── Filter/<Entity>/
    ├── <Entity>SaveFilter.php      # InputFilter, dùng chung create + update (id optional)
    └── <Entity>ListFilter.php      # lọc tham số list/search/page
```

- Controller **không** có `Controller/Factory/` — DI khai closure trong `config/module.config.php` (§4).
- Không tạo thư mục `Guard/`, `Form/`, `Constant/` mới — guard/validator vào `Service/`, input vào `Filter/`, hằng vào entity (§3 `Const`) hoặc `Application/Constant/` (trước 13/09 là `Core/Constant/`) nếu dùng chung.
- Module có `CLAUDE.md` riêng: ranh giới, bảng sở hữu, bẫy hay gặp — đọc trước khi sửa module.

## 2. Luồng một request CRUD (chuẩn duyệt 12/09/2026 — phương án của user)

```
Request → Controller (MỎNG: nhận raw + route params, gọi 1 method Service)
        → Service  ─┬─ chạy Filter (validate input, CSRF) — Filter tạo tại đây, mỗi request một instance
                    ├─ gọi Mapper (đọc/ghi 1 bảng)
                    ├─ Mapper hydrate row → Model (POPO) trả ngược về Service
                    ├─ nghiệp vụ: transaction, ghép batch 2+ mapper, decorate trường hiển thị
                    └─ trả Model / array tổng hợp (rows, filters, flags PRG) về Controller
        → Controller render view / JsonModel (chỉ serialize, không validate)
```

- Controller **không** đụng Filter/Mapper/DB: không `new ...Filter`, không `setData`, không đọc `$filter->getValues()`; chỉ ghép `id` route vào raw (`$raw['id'] = (string) $id`) khi action có `{id}`.
- Service bắt lỗi validate bằng chính Filter mỗi lần được gọi qua entry point form (`saveForm`, `deleteForm`, `mergeForm`…) → trả query flag PRG; hoặc ném `ValidationException(fieldErrors)` → Controller bắt và render lại form với lỗi từng trường (API → 422 `{success,data,meta,errors}` — [`05-quy-chuan-api.md`](05-quy-chuan-api.md)).
- Validate **trước** khi ghi; nghiệp vụ xoá nhiều bảng: **1 transaction trong Service**, không dựa cascade (schema no-FK).
- PRG (Post/Redirect/Get) cho form trang; API JSON trả `JsonModel`.

## 3. Model / Const

- `Model` = POPO thuần, không query DB, không biết HTTP. Hydrate: `fromRow(array $row): static`.
- **Method đọc của Mapper trả Model đã hydrate** (`?Model`, `list<Model>`), không trả raw array; chỉ projection lấy ít cột (vd `getNamesByIds` batch id→name) được giữ dạng map scalar — không đủ cột để hydrate thì không ép làm Model.
- Model cung cấp `toArray()` (shape camelCase phục vụ API serialize) và `toFormValues()` (giá trị điền lại form); trường ghép hiển thị (`categoryName`, `postCount`) do **Service** decorate sau khi Mapper trả model.
- Bảng trạng thái ENUM/TINYINT → hằng `UPPER_SNAKE` + `const STATUS_LABELS` trong `<Entity>Const.php` cạnh Mapper. Chi tiết phạm vi đặt hằng chung: [`06-quy-uoc-const.md`](06-quy-uoc-const.md).
- JSON output của API: Mapper/Model **không** biết HTTP; Controller/API serialize từ `Model::toArray()`; Service/Controller ghép shape `{success,data,meta,errors}`.

## 4. DI — service dùng nền `AppServiceFactory`; controller/mapper dùng closure (cập nhật 13/09/2026)

**Service tầng nghiệp vụ (Admin/Frontend)**: `class XService extends AppServiceFactory`, constructor **không nhận gì**, mỗi dependency lấy qua typed accessor riêng gọi `getContainerEntry()` — đăng ký bằng `AppInvokableFactory::class`:

```php
// module/Admin/src/Service/PostService.php
class PostService extends AppServiceFactory
{
    private function postMapper(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    public function listAll(): array
    {
        return $this->postMapper()->listAll();   // filter vẫn `new` mỗi request (§6)
    }
}
```

```php
// module/Admin/config/module.config.php
'service_manager' => ['factories' => [
    Service\PostService::class => AppInvokableFactory::class,   // mồi container + setContainer()
    Model\Post\PostMapper::class => static fn (ContainerInterface $c): PostMapper
        => new PostMapper($c->get(DbService::class)->getAdapter()),  // mapper: GIỮ closure
]],
```

- `AppInvokableFactory`/`AppServiceFactory` là **2 file nền duy nhất** trong `Application/src/Factory/` (user duyệt 13/09/2026) — không tạo thêm file `*Factory.php` riêng cho từng service.
- **Không còn ngoại lệ (batch 7 — 13/09/2026):** 4 service nền `Application` từng nhận mảng config qua constructor — `DbService` (key `db`), `MailService` (`mail`), `CaptchaService` (`recaptcha`), `HtmlPurifierService` — đã bỏ constructor, đọc `Config` merge lazy trong accessor riêng (`getContainer()->get('Config')` + ép `is_array`/`toArray()` như `appConfig()`), đăng ký `Factory\AppInvokableFactory::class`. `DateService` static helper, không đăng ký; `MediaService`/`SlugService` (Application) không dependency nào, vẫn closure `static fn ()`. ⚠️ **Bẫy config cache:** `application.config.php` bật `config_cache_enabled => true` — sửa bất kỳ `module.config.php` nào phải `php bin/clear-config-cache.php`; nếu không, container chạy theo closure CŨ đã cache (`new DbService($db)` thừa số bị bỏ qua → instance không container → exception `getContainer()` ngay lần build adapter).
- **Controller:** giữ constructor mỏng + closure trong `controllers.factories` gọi `$c->get(XService::class)` (khác service — controller không kế thừa nền).
- Accessor đặt tên theo entry: mapper → `<entity>Mapper()`, service → `<entity>Service()`, config ứng dụng → `appConfig()` (MediaService đọc từ entry `Config` đã merge — `ApplicationConfig` không chứa khối `app`).
- Test: `(new XService())->setContainer(new TestContainer([PostMapper::class => $mock, ...]))` — helper `Application/test/Helper/TestContainer` (PSR-11, `get()` missing → exception → accessor trả null/type error rõ).
- InputFilter **không** bao giờ vào container (§6). Không dùng `AppServiceFactory` trực tiếp làm factory trong config — chỉ `AppInvokableFactory`.
- Controller không dependency → `InvokableFactory::class` vẫn hợp lệ. Alias ngắn (`'DbAdapter'`, `'mediaUrl'`) giữ như [`03-quy-chuan-code.md`](03-quy-chuan-code.md) §3.

## 5. Mapper — mỗi mapper chỉ đụng bảng của chính nó

Mapper nào sở hữu bảng thì chịu trách nhiệm **toàn bộ** đọc/ghi bảng đó. Toàn bộ SQL một bảng nằm trong đúng mapper của nó.

- Mapper A **không** `select`/`join` bảng do Mapper B sở hữu — kể cả để lọc phụ.
- Cần dữ liệu bảng khác, chọn 1 trong 2:
  1. Thêm method ở mapper sở hữu, **Service điều phối** 2 mapper.
  2. Gom id → gọi mapper sở hữu lấy map theo batch (chống N+1), Service ghép kết quả bằng PHP.

Ví dụ với `posts` + `categories`: muốn list bài kèm tên danh mục — `PostMapper::listByCategoryIds()`, `CategoryMapper::getNameByIds()` (batch), `PostService` ghép. Không join raw `categories` trong `PostMapper`.

### 5.1 Khai báo tường minh từng mệnh đề — không chuỗi hóa (có hiệu lực 12/09/2026 — 🟡 align dần; rà 14/09/2026: tuyên bố "4 mapper đã align toàn bộ" 13/09 đã lệch lại — batch 10/11 thêm SELECT chain mới (`CategoryMapper` 1 · `MediaMapper` 1 · `PostMapper` 3), đã unchain lại cả 5; `BannerMapper` align theo batch FR-31 (5 select + 3 write ops tách mệnh đề); các mapper còn lại (`Tag` · `Service` · `TeamMember` · `Contact` · `HomeSection` · `Setting` · `PostTag`) align khi batch sau chạm tới)

Mọi query trong **Mapper/Service**: sau khi tạo statement object (`$sql->select/insert/update/delete`), phải **khai báo từng mệnh đề bằng một lệnh riêng** — `columns()`, `where()`, `order()`, `limit()`, `set()`, `values()` mỗi cái một dòng, tự đọc dòng nào ra lệnh đó. **Cấm** chuỗi (chain) nhiều mệnh đề nối `->` vào một biểu thức như ví dụ dưới — dạng chuỗi là "viết tắt", không nhìn ra câu truy vấn gồm những mệnh đề nào, khó thêm/bớt mệnh đề khi sửa.

❌ **Cấm** — chuỗi nhiều mệnh đề trong một biểu thức:

```php
$select = $sql->select(self::TABLE_NAME)
    ->columns(['id', 'name'])
    ->where(['isActive' => 1])
    ->order(['sortOrder' => 'ASC', 'id' => 'ASC']);
```

✅ **Bắt buộc** — mỗi mệnh đề một lệnh độc lập:

```php
$select = $sql->select(self::TABLE_NAME);
$select->columns(['id', 'name']);
$select->where(['isActive' => 1]);
$select->order(['sortOrder' => 'ASC', 'id' => 'ASC']);
```

Tương tự với các loại statement khác:

```php
$insert = $sql->insert(self::TABLE_NAME);
$insert->values($values);

$update = $sql->update(self::TABLE_NAME);
$update->set($values);
$update->where(['id' => $id]);

$delete = $sql->delete(self::TABLE_NAME);
$delete->where(['id' => $id]);
```

- `prepareStatementForSqlObject($select)->execute()` được phép giữ một dòng — đó là thực thi, không phải khai báo mệnh đề.
- Luật này chỉ chuẩn hoá **khai báo**; giá trị cột (`status`, cờ) vẫn theo quy tắc có sẵn của [`06-quy-uoc-const.md`](06-quy-uoc-const.md) — có hằng thì dùng hằng.
- **Cấm key mảng có kèm toán tử** trong `where([...])` (`'type !=' =>`, `'id IN' =>`, …): laminas-db 2.22 KHÔNG parse key kết hợp kiểu này — sinh SQL hỏng trên MySQL platform (`type` `!``=` → 1064, pattern phát hiện 13/09 ở `PostRevisionMapper`, ẩn sau bug `transientBegin` của `DbService`; ca thứ hai `id IN` nổ 500 trên `/tin-tuc` — batch 10 cùng ngày). Dùng object `Where` tường minh + predicate (`Operator`, `In`, `IsNull`, …) — vẫn prepared statement (AGENTS §3). Khuôn: `notAutosaveWhere()` của `PostRevisionMapper`, `Where::in()` của `Category/TagMapper`.

## 6. Filter (InputFilter) — kế thừa nền tảng `AppInputFilter`, thay cho Laminas Form làm lớp validate

> **Hiệu lực 13/09/2026:** MỌI filter của Admin + Frontend `extends`
> `Application\Filter\AppInputFilter`. CSRF, `csrfHash()`, `fieldErrors()`,
> container (`setContainer`/`getContainer`) và các helper khai báo field gom hết
> về nền tảng; filter riêng **chỉ khai báo field bằng hàm chung**.

Filter được **Service** khởi tạo `new` trực tiếp mỗi request (`setData` stateful → cấm đăng ký vào container; Controller không được `new` Filter — xem §2).

### 6.1 Nền tảng `AppInputFilter` bảo lãnh (filter riêng không viết lại)

| Nhóm | API | Nội dung |
|---|---|---|
| CSRF | `__construct(bool $withCsrf = true)`, `csrfHash()` | Sở hữu validator `Laminas\Validator\Csrf` (name `csrf`); `$withCsrf = true` → tự add input `csrf` required |
| Lỗi | `fieldErrors(): array<string,string>` | Lỗi đầu tiên từng trường cho view/JSON |
| Container | `setContainer()` / `getContainer()` | Tuỳ chọn; dependency chuẩn vẫn truyền trực tiếp qua constructor filter |
| Field builder | `addIdField()` `addStringField()` `addSlugField()` `addIntCastField()` `addRawField()` `addDateTimeField()` | Trim+Digits · trim+StringLength max · slug (format + unique qua callable) · ToInt · flag raw · Regex giờ VN (`DATETIME_PATTERN`) |
| Getter | `positiveIdValue()` `stringValue()` `nullableStringValue()` `flagValue()` | id > 0 · chuỗi trim (`trim: false` cho mật khẩu) · rỗng → null · 1/'1'/true |

### 6.2 Khuôn filter riêng

```php
final class CategoryActionFilter extends AppInputFilter
{
    public function __construct(bool $withCsrf = true)
    {
        $this->addIdField();              // id: required + trim + Digits
        parent::__construct($withCsrf);   // nền tảng thêm csrf cuối — LUÔN gọi
    }

    public function idValue(): ?int
    {
        return $this->positiveIdValue('id');
    }
}

final class CategorySaveFilter extends AppInputFilter
{
    public function __construct(
        CategoryMapper $categories,
        ?int $excludeId = null,
        bool $withCsrf = true,
    ) {
        // Rule đặc thù (Callback unique, InArray, enum…) — khai Input trực tiếp
        $this->addSlugField(                                   // format + unique bằng hàm chung
            CategoryConst::MAX_LENGTH_SLUG,
            static fn (string $v): bool => $categories->existsSlug($v, $excludeId),
        );
        $this->addIdField('parentId', required: false);
        $this->addStringField('metaTitle', false, CategoryConst::MAX_LENGTH_META_TITLE);
        $this->addIntCastField('sortOrder');
        $this->addRawField('isActive');
        parent::__construct($withCsrf);
    }
}
```

- Thân filter khai báo field **trước**, gọi `parent::__construct($withCsrf)` **cuối cùng** — thiếu lệnh này là fatal (property `readonly Csrf` không được khởi tạo, `csrfHash()` nổ `Error`).
- Filter danh sách/query không có form (vd `PostListFilter`): `parent::__construct(withCsrf: false)`.
- `Application\Filter\CommonFieldFilters` (spec mảng static, thiên về luồng JSON) vẫn giữ; đường CRUD chuẩn dùng helper của `AppInputFilter`.
- **Validate theo intent (`$draftMode` — chuẩn 13/09, "lưu nháp"):** khi một form có nhiều nút intent (nháp/hẹn giờ/xuất bản), filter nhận thêm `bool $draftMode` (tham số riêng, **trước** `$withCsrf` — Service gọi đủ vị trí để không lệch tham số). `draftMode` chỉ nới rule *nội dung* (title ngắn được, content trống được); rule *liên kết* vẫn bắt buộc nếu cột DB NOT NULL (`categoryId`). **Service** là nơi quyết định: `$draftMode = intent === DRAFT && (bài mới || bài đang ở status NHÁP)` — trên bài đã xuất bản/lưu trữ, lưu nháp KHÔNG được xoá trắng nội dung. Nút nháp trong template gắn `formnovalidate` để trình duyệt không chặn `required`. Khuôn: `PostSaveFilter` + `PostService::saveValidated()`.

### 6.3 Bất biến cũ (không đổi)

- Đặt tên `{Entity}SaveFilter` / `{Entity}ListFilter` / `{Entity}ActionFilter` (form hành động delete/publish/merge ở danh sách — chỉ id + csrf); **không** `{Entity}Form` mới, không `{Entity}Validator` lẻ.
- CSRF cho **mọi** form: token render bằng `csrfHash()` — method Service (`saveFormCsrfHash()`, `actionCsrfHash()`, `loginCsrfHash()`…). API JSON không token — SameSite=Lax — Service gọi filter với `$withCsrf = false` (`saveApi`/`mergeApi`).
- `ValidationException` mang đúng map `fieldErrors()`.
- Filter không giữ DB write; validator chỉ READ để check unique/existence.

## 7. Vị trí theo module (News-specific)

| Module | Được có | Không có |
|---|---|---|
| `Admin` | `Model/`, `Filter/`, `Service/` (kể cả `Service/Api/` — `ApiResultModel` + `ApiResponseModel`: luồng API JSON **Service trả trọn envelope**, controller chỉ router — 08 §5), `Exception/`, `View/Helper/` cho tất cả bảng ghi được | Route frontend |
| `Frontend` | `Model/` cho bảng đọc (Post/Category/Tag/Service/Team/Setting/Media), `Filter/` cho contact/search, `Service/` | Ghi trực tiếp trừ `contacts` |
| `Application` | `Service/` (kể cả `DateService` — helper tĩnh mốc giờ UTC tập trung, 08 §4), `Filter/` (**`AppInputFilter`** — nền tảng mọi filter hệ thống kế thừa, 13/09; `CommonFieldFilters` + `HtmlPurifierFilter` — helper static dùng chung), `View/Helper/`, `Constant/` (hằng dùng chung), `Session/` — **từ 13/09 gộp Core (`Core\` → `Application\`)**; skeleton error/index | `Model/`, `Controller/`, route (Model/ chỉ skeleton — không CRUD) |

Trùng tên `Service\` tầng vs `Service/` folder module Frontend: tầng `Frontend\Service` chứa *nghiep-vu* service (`PostReadService`…), không chứa entity `Service` (dich-vu) — entity đó là `Frontend\Model\Service\ServiceMapper` (thư mục tên `Service` trong `Model/` — hợp lệ, phân biệt theo đường dẫn đầy đủ).

## 8. Kiểm tra nhanh khi tạo file CRUD mới

- [ ] Đủ bộ `Controller/Service/Model/<Entity>/{Mapper,Model,Const}/Filter/<Entity>/`?
- [ ] Filter `extends AppInputFilter`, không tự viết Csrf/`csrfHash`/`fieldErrors` riêng; kết thúc constructor bằng `parent::__construct($withCsrf)` (§6)?
- [ ] Controller KHÔNG `new` Filter, KHÔNG nhận Mapper — validate nằm trong Service (§2)?
- [ ] `module.config.php`: Service đăng ký `AppInvokableFactory::class` (class `extends AppServiceFactory`, dependency qua `getContainerEntry()`); Controller/Mapper dùng closure inline; route đúng naming (§4, chuẩn cũ [`03`](03-quy-chuan-code.md) §4)?
- [ ] Mapper không chạm bảng khác? Service điều phối batch?
- [ ] Query khai báo từng mệnh đề một lệnh riêng, không chuỗi `->` nhiều mệnh đề (§5.1)?
- [ ] Field "chọn bản ghi" (media id, itemId…) render bằng `$this->selectField(...)` — KHÔNG ô nhập ID số; danh sách options do Service cung cấp (§9)?
- [ ] Tên class khớp [`01-quy-uoc-dat-ten.md`](01-quy-uoc-dat-ten.md)?
- [ ] psalm/phpcs/phpunit pass (`03-quy-chuan-code.md` §9)?

> Checklist review đầy đủ: [`04-checklist-review.md`](04-checklist-review.md). Sample chuẩn: mockup CRUD EnglishTrain (`crud-convention.md` gốc).

## 9. Field "chọn bản ghi" — helper `selectField`, cấm bắt người dùng gõ ID (chuẩn 13/09/2026)

User feedback 13/09/2026: "form cho điền ID không rõ điền thế nào cho đúng" → **mọi field tham chiếu bản ghi khác** (media id trong form banner/post/category/service/team/account; `itemId` form thêm mục home-section; **và 3 ô `valueType=media` trang Cài đặt — Logo/Favicon/Ảnh chia sẻ mặc định, bổ sung 15/09/2026** vì trang này tồn dư `<input type=number>` + hint "xem id ở trang Media") render bằng điều khiển chọn bản ghi, không phải `<input type=number>`.

> **Cập nhật 14–15/09/2026:** với **media**, khuôn select box dưới đây đã được thay bằng **khung preview `MediaPicker`** (`.media-pick-frame` + hidden `input.media-id` — click mở popup lưới ảnh, bỏ chọn bằng ×; mô tả trong docblock `Application\View\Helper\MediaPicker`). `selectField` vẫn dùng cho `itemId` home-section. Giá trị submit vẫn là id → Filter/Service không đổi.

- **Helper:** `Application\View\Helper\SelectField` (đăng ký `view_helpers` alias `selectField` trong `Application/config/module.config.php` — sửa config này nhớ `php bin/clear-config-cache.php`). Không extends `AbstractHelper` (deprecated trong laminas-view); escape bằng `htmlspecialchars` trực tiếp.
- **Chữ ký:** `selectField(string $name, array $options, string $current, bool $required = false, string $placeholder = '…')` — options là `list<array{id, label, path?}>`; giá trị submit vẫn là **id** → Filter/Service/luồng validate giữ nguyên, chỉ đổi tầng view + nguồn options.
- **Nguồn options = Service, không phải controller `new` Mapper:** mỗi entity thêm `Mapper::listOptions()` (phép chiếu scalar `id` + cột tên, `order` riêng — đúng §5, §5.1); Service sở hữu entity trả qua method riêng (`PostService::formOptions()` kèm khóa `media`). **Media** dùng bởi nhiều entity → mỗi Service thêm accessor `mediaMapper()` (container gộp — 05-cau-truc §4) + `mediaOptions()`; Controller mỏng chỉ truyền biến view (`mediaOptions` / `itemOptions`).
- **Option media có `path`** → helper gắn `data-src` + `.media-pick-preview` (img xem trước); `admin.js` đổi preview khi chọn. Bản chọn không còn trong danh sách → option fallback `(không còn trong danh sách)` để form cũ không bị mất giá trị.
- **Field media còn có nút "Chọn ảnh từ thư viện…"** (`.media-pick-btn`, helper render khi options có `path`) → mở popup lưới ảnh (cùng modal `rteOpenMediaModal` với editor, dữ liệu lấy từ `option[data-src]` của select — không call API); chọn ảnh xong set `select.value` + dispatch `change` để handler preview cập nhật. Giá trị submit vẫn là id → Filter/Service không đổi. **Bug CSS tồn phải tránh:** đừng ẩn preview bằng `:empty` — `img` là thẻ void nên `:empty` khớp MỌI ảnh (kể cả đã có `src`) làm ảnh ẩn vĩnh viễn; chỉ ẩn khi `[src=""]`.
- **home-section:** `HomeSectionService::itemOptions(?int $sectionType)` map loại section → bảng chủ theo `MANUAL_ITEM_TYPES` (featured_posts→PostMapper · services→ServiceMapper `listActiveOptions` · team→TeamMemberMapper) — vẫn 1 mapper 1 bảng, không JOIN.

### 9.1 Ô nội dung dùng text editor WYSIWYG (chuẩn 13/09/2026)

User feedback 13/09/2026: "các ô nội dung cần dùng text editor" → **mọi field nội dung HTML** (đổ thẳng vào `content` bài viết/dịch vụ, hiển thị thô ở Frontend sau khi purge) **không còn là textarea thường** mà render bằng **editor WYSIWYG**.

- **Khuôn:** textarea gốc giữ nguyên `name` + thêm `data-wysiwyg` (+ `placeholder`); `admin.js` `rteInit()` biến nó thành toolbar (đậm/nghiêng/gạch chân · H2/H3/¶/blockquote · ul/ol · link/unlink · chèn ảnh · undo/redo) + vùng `contenteditable`, **ẩn textarea** (`.rte-hidden`) và đồng bộ `innerHTML → value` mỗi `input` + trước `submit`. `required` client bị bỏ (điều khiển ẩn không focus được sẽ chặn form) — **Service vẫn validate** (intent publish → lỗi "không được để trống"; nút Lưu nháp `formnovalidate`). Luồng Filter/Service/Purifier **không đổi** — editor chỉ là tầng view; JS tắt thì textarea hiện nguyên vẹn, gõ HTML tay vẫn chạy.
- **Chèn ảnh từ thư viện media:** nút 📷 mở modal (`rteOpenMediaModal`) lọc `select.media-select option[data-src]` **ngay trong form** (không call API) → chèn `<img src alt loading=lazy>` vào vị trí con trỏ. Ảnh chỉ vào được nếu đã có trong media của form — không upload thẳng từ editor (thư viện media là cổng duy nhất).
- **Áp dụng:** `post/form.phtml` (`content`) + `service/form.phtml` (`content`). Ô text thuần (sapo/excerpt, metaDescription, shortDescription, subtitle…) **giữ textarea thường** — không phải chỗ dựng HTML.
- **CSS:** `.rte-toolbar` / `.rte-editor` / `.rte-modal*` trong `public/css/admin.css` (mục WYSIWYG cuối file).

### 9.2 Ô chọn file upload phải xem được ảnh (chuẩn 13/09/2026)

User feedback 13/09/2026: "nút select chọn ảnh chỉ thấy tên, không thấy ảnh" → `.upload-area` (thư viện media) sau khi chọn file **vẽ lưới ảnh preview thật** (`URL.createObjectURL`, mỗi file một `img` 88×64 + tên), không chỉ dòng chữ "N file: …". Chọn lại file → revoke URL cũ + vẽ lại. Bấm vào ảnh preview **không** mở lại hộp thoại chọn file (chặn `preventDefault` vì `.upload-area` là `<label>`). Handler trong `admin.js` (mục UPLOAD AREA), CSS `.upload-previews` / `.upload-thumb*`.

Test nền: `ApplicationTest\Helper\SelectFieldTest` (render + selected + fallback + escape).

### 9.3 Form thêm/sửa chia 2 cột (chuẩn 14/09/2026)

User feedback 14/09/2026: "ở các form thêm thì chia làm 2 cột nhìn cho trực quan" → **form thêm/sửa của resource Admin nhóm trường ngắn** (input/select/number) xếp bằng lưới `.form-row.cols-2` (pattern có sẵn từ `banner/form.phtml` — nay áp cho `tag`, `home-section`, `setting`; `cols-3` khi có bộ ba ngắn cạnh nhau). Field rộng (mô tả, ô TEXT/HTML/JSON ở trang Cài đặt…) đặt ngoài lưới để chiếm trọn hàng; nếu nằm trong lưới thì gắn class `.span-2` (`grid-column:1/-1` — utility mới trong `admin.css`). **Cập nhật 19/09/2026:** riêng form `home-section`, người quản trị **không gõ JSON thô** nữa; view hiển thị control theo từng `type` (select mode, number limit, dropdown danh mục, CTA text/url, các dòng steps), JS đóng gói vào hidden field `config` JSON trước submit, còn Service/Filter vẫn validate/lưu `config` theo chuẩn cũ. **Cập nhật 19/09/2026 (UI form panel):** các form Admin chính `category`, `home-section`, `post`, `banner`, `menu`, `service`, `pricing`, `team` dùng layout chung `.entry-form` + `.entry-form-grid` + `.form-panel`: breadcrumb/nút quay lại ở header, cột trái cho thông tin/nội dung/ảnh, cột phải cho tuỳ chọn hiển thị/SEO/lưu ý, action nằm cuối form; lưới tự sập 1 cột ≤1100px. `post/form.phtml` không còn ngoại lệ `create-post-layout`, nhưng vẫn giữ các hook nghiệp vụ cũ (`data-wysiwyg`, tag autocomplete, intent draft/schedule/publish, autosave/revision). **Ngoại lệ còn lại:** `account/index.phtml` giữ `.two-col` (hai card hồ sơ/mật khẩu); `media` chỉ một field. Form CRUD mới cứ theo khuôn `category/form.phtml` hoặc `banner/form.phtml` bản `entry-form`.
