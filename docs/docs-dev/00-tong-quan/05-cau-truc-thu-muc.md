# Cấu trúc thư mục

Cây thư mục **chuẩn** của repo theo quy ước EnglishTrain-style (duyệt 12/09/2026 — nguồn sự thật cho tầng module: [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md)). **Refactor đã thực hiện 12/09/2026** cho toàn bộ code hiện có (DI, Admin auth, Frontend contact) — xem trạng thái ✅ ở §3. **Cùng ngày 12/09/2026: CRUD categories/tags/posts + API `/api/admin/*` đã code thật** đủ tầng — xem cây `Admin/` §2. **Mốc 13/09/2026: gộp `module/Core` về `module/Application` — 13 file `Core\src\*` dời sang `Application\src\*` và đổi namespace `Core\` → `Application\` (Application giờ là skeleton + dịch vụ dùng chung).** **Mốc 14/09/2026: kéo thả đổi thứ tự nhóm FR-26/29/30/32/34 — `Filter/Reorder/ReorderFilter.php` dùng chung 5 module + `POST /admin/{module}/reorder` (XHR → JSON, PRG fallback) + JS chung `tbody.js-sortable`; riêng categories có thêm `PUT /api/admin/categories/reorder`.** Ký hiệu: ✏️ = dev sửa thường xuyên · ⚙️ = cấu hình ít đổi · ✅ = đã ở dạng chuẩn mới · 🟡 = stub / chưa có, sẽ bổ sung khi triển khai · ❌ = đã xoá theo bản đồ §3.

## 1. Gốc repo

```
News/
├── AGENTS.md                  ⚙️  Luật AI agent: thứ tự đọc skill > docs > code + ràng buộc kỹ thuật/bảo mật
├── bin/                       ⚙️  CLI không có route
│   ├── create-admin.php          Tạo tài khoản admin duy nhất (password_hash)
│   └── clear-config-cache.php    Xoá config cache (gọi sau composer install/update)
├── config/                    ⚙️  Cấu hình ứng dụng + module
│   ├── application.config.php    Danh sách module, bật config/module cache
│   ├── modules.config.php        ✏️  Nạp Laminas component + 3 module app (Application/Frontend/Admin)
│   ├── container.php             Bootstrap ServiceManager
│   ├── development.config.php    Chồng config dev (khi development-enable)
│   └── autoload/
│       ├── global.php            ✏️  ⚙️ DB(Pdo_Mysql)/session/cache/mail/app
│       ├── local.php.dist        Mẫu secrets → copy thành local.php (gitignore)
│       └── development.local.php(.dist)
├── data/
│   ├── cache/                  Config cache + `page/` (TTL 60s) — runtime, gitignore
│   └── schema/
│       ├── schema.sql          ⚙️  18 bảng (không FK, không deletedAt) — 16 gốc + `pricing_items` (16/09) + `menu_items` (17/09)
│       ├── seed.sql            settings (`map_address` là ô Google Map chung, `map_embed_url` legacy) + home_sections (+ type 8 process) + services cha-con + pricing mẫu + menu mặc định
│       ├── migrate-20260916-ui-overhaul.sql  idempotent: services.parentId + pricing_items + map_address + seed 2 cha 7 con + 12 pricing + section process
│       └── migrate-20260917-menu-items.sql   idempotent: menu_items + seed menu mặc định + ẩn fallback map_embed_url khỏi form Admin
├── module/                    ✏️  3 module Laminas — cấu trúc chuẩn xem §2 (từ 13/09 gộp Core về Application)
├── public/                    Document root
│   ├── index.php               Điểm vào duy nhất
│   ├── web.config              Rules IIS (uploads chặn thực thi)
│   ├── assets/css/style.css    CSS Vạn Lang (bản dùng cho Laminas)
│   ├── uploads/                Media theo YYYY/MM (runtime)
│   └── css/ · js/ · img/       Asset thừa của Laminas skeleton
├── assets/                    Prototype tĩnh (đối chiếu, không phục vụ)
├── docs/                      docs-dev/ (bạn đang ở đây) + phân tích v1.5
├── Image/                     Ảnh minh hoạ docs
└── composer.json · composer.lock · phpcs.xml · phpunit.xml.dist · psalm.xml
```

## 2. `module/` — khung chuẩn cho MỌI module (EnglishTrain-style)

```
module/<Module>/
├── CLAUDE.md                  🟡  Luật riêng module: ranh giới, bảng sở hữu, lỗi hay gặp
├── config/module.config.php   ✏️  Route + DI: Service dùng `AppInvokableFactory` (nền 07 §4, 13/09) · Controller/Mapper closure inline
├── src/
│   ├── Module.php                getConfig() [+ init() listener — ngoại lệ Admin/Frontend]
│   ├── Controller/
│   │   └── <Entity>Controller.php   MỎNG: nhận request → gọi Service → render. KHÔNG new Filter, KHÔNG nhận Mapper. Không có Controller/Factory/
│   ├── Service/
│   │   ├── <Entity>Service.php      Chạy Filter validate input mỗi request, business logic, gọi Mapper, transaction
│   │   └── <X>Guard.php / <X>Builder.php  Guard/builder nằm TRONG Service/, không có thư mục riêng
│   ├── Model/
│   │   └── <Entity>/                1 thư mục = 1 bảng entity sở hữu
│   │       ├── <Entity>Mapper.php      SQL — chỉ đụng đúng bảng của nó
│   │       ├── <Entity>Const.php       Constants riêng entity
│   │       └── <Entity>Model.php       POPO — Mapper đọc TRẢ Model hydrate qua fromRow(); phép chiếu tổng hợp giữ mảng scalar
│   ├── Filter/
│   │   └── <Entity>/                   InputFilter — Service `new` trực tiếp mỗi request, không vào container
│   │       ├── <Entity>SaveFilter.php  dùng chung create + update (id optional)
│   │       └── <Entity>ListFilter.php  Lọc tham số list/search
│   ├── Exception/                    Lỗi nghiệp vụ module — thực tế Admin: ExceptionInterface + Validation/NotFound/Conflict
│   └── View/Helper/                  (chỉ module có helper — hiện tại: Application)
└── view/{controller}/{action}.phtml + view/layout/
```

### Tree cụ thể 3 module (từ 13/09/2026 gộp Core về Application)

```
module/
├── Application/               Skeleton + dịch vụ dùng chung — KHÔNG có route, KHÔNG có Model/ (từ 13/09 gộp Core — namespace Core\ → Application\)
│   ├── config/module.config.php  ✏️  factories dạng closure + alias ('DbAdapter', 'mediaUrl') ✅ (gộp từ Core)
│   └── src/
│       ├── Controller/IndexController.php · Module.php  (skeleton)
│       ├── Service/  DbService ✅ · MailService ✅ · CaptchaService ✅ (13/09 batch 7 — bỏ constructor
│       │        nhận config, đọc lazy `getContainerEntry('Config')`, đăng ký AppInvokableFactory;
│       │        sau sửa registration PHẢI `php bin/clear-config-cache.php`)
│       │        · SlugService ✅ · HtmlPurifierService ✅
│       │        · DateService ✅ (13/09 — helper tĩnh mốc giờ UTC tập trung, chuẩn 08 §4)
│       │        · PageCacheService ✅ (13/09 batch 8 FR-39 — remember/forget bọc storage 'page_cache',
│       │          payload tự serialize bọc mảng 1 phần tử; storage thiếu → producer chạy thẳng)
│       │        (MediaService KHÔNG nằm ở Application — 13/09 đặt tại Admin/src/Service/ vì chỉ admin upload)
│       ├── Filter/   AppInputFilter ✅ · CommonFieldFilters ✅ · HtmlPurifierFilter ✅ (13/09 — `AppInputFilter` = nền tảng mọi filter Admin/Frontend kế thừa: CSRF + csrfHash + fieldErrors + container + helper field, chuẩn 07 §6; CommonFieldFilters/HtmlPurifierFilter dời từ Application/src/{Filter,Factory} về đây cho đúng chuẩn "dùng chung → Application"; 13/09 gộp Core\ → Application\ — 07 §7)
│       ├── Factory/  AppServiceFactory + AppInvokableFactory ✅ (13/09 — NỀN DI tầng Service theo yêu cầu user; 2 file factory DUY NHẤT được duyệt, chuẩn 07 §4)
│       ├── Constant/ContentConst.php · CacheConst.php ✅  hằng dùng chung 2 module
│       │          (posts.status, section item type; CacheConst 13/09 batch 8 FR-39:
│       │          tên service 'page_cache' + key 'settings-v1'; batch 9 13/09 dùng tiếp
│       │          KEY_HOME 'home-v1' — cache payload dữ liệu trang chủ; 17/09 thêm KEY_MENU 'menu-v1')
│       ├── Session/SessionBootstrap.php
│       └── View/Helper/MediaUrl.php · SelectField.php ✅ 13/09 — select box mọi
│           field "chọn bản ghi" thay ô nhập ID, chuẩn 07 §9 (alias `selectField`)
│       ❌ Service/Factory/* · View/Helper/Factory/* → closure trong config (đã xong)
│   └── view/  application/ · error/404.phtml · error/index.phtml · layout/
│
├── Frontend/                  Trang đọc cho khách — chỉ ĐỌC bảng, không ghi (trừ contact)
│   ├── config/module.config.php  ✏️  Route /tin-tuc /danh-muc /tag /gioi-thieu /bang-gia /dich-vu ... + /api/contact + /sitemap.xml ✅ (16/09 thêm about+pricing, ServiceController factory 3 deps)
│   ├── src/
│   │   ├── Controller/  Home ✅ (13/09 batch 9 FR-32 — code thật, mỏng, closure DI nhận
│   │   │                HomeService) · Post ✅ (13/09 batch 10 FR-02 — listAction phân trang
│   │   │                + lọc, closure DI nhận PostListService; detail ✅ 13/09 batch 11 FR-03 — slug route → `PostDetailService::detail`, null → `notFoundAction()` + template `error/404`; closure DI nhận thêm PostDetailService; batch 12 FR-04 + nhận `?previewToken=` từ query (trim) truyền xuống detail(slug, token) — GET không cần InputFilter, bind prepared dưới Mapper)
│   │   │                · Category ✅ (13/09 batch 13 FR-05 — route default `view`: slug +
│   │   │                `?page=` → `CategoryListService::page`, null (lạ/TẮT) → `notFoundAction()`
│   │   │                + `error/404` khuôn batch 11; closure DI; 4 action stub không route bị xoá)
│   │   │                · Tag ✅ (13/09 batch 14 FR-06 — route default `view`: slug +
│   │   │                `?page=` → `TagListService::page`, null (lạ) → `notFoundAction()`
│   │   │                + `error/404` khuôn batch 11; closure DI; 4 action stub hết route xoá)
│   │   │                · Search ✅ (13/09 batch 15 FR-07 — route `search` `/tim-kiem?q=`:
│   │   │                `q` → `SearchService::search`, closure DI; 4 action stub hết route xoá)
│   │   │                · Service ✅ (13/09 batch 16 FR-08 — route `services` `/dich-vu` default
│   │   │                action `list` + `service-detail` `/dich-vu/:slug` action `detail`: slug →
│   │   │                `ServiceViewService::detail`, null (lạ/tắt) → `notFoundAction()` +
│   │   │                `error/404` khuôn batch 11; closure DI; stub cũ index/view/submit xoá)
│   │   │                · Team ✅ (13/09 batch 17 FR-09 — route `team` `/doi-ngu` default action
│   │   │                `list` → `TeamViewService::list`; closure DI; stub cũ index/detail/view/submit xoá)
│   │   │                · Contact ✅ (13/09 — mỏng, closure DI nhận ContactService; 16/09 factory thêm SettingService, view bỏ form POST chỉ SĐT/Zalo/map)
│   │   │                · About ✅ (16/09 — AboutController::indexAction route /gioi-thieu, payload settings+mapUrl, closure DI)
│   │   │                · Pricing ✅ (16/09 — PricingController::indexAction ?nhom=&q=&page= → PricingViewService::list, cache pricing-v1, closure DI; 17/09 bảng public phân trang 20 dòng/trang)
│   │   │                · Sitemap ✅ (13/09 batch 18 FR-11 — route `sitemap` /sitemap.xml default
│   │   │                action `index`: Content-Type XML (guard `instanceof Http\Response`),
│   │   │                `SitemapService::urls()` → view `setTerminal(true)` KHÔNG bọc layout;
│   │   │                closure DI; stub cũ list/detail/view/submit xoá)
│   │   ├── Service/     ContactService ✅ (13/09 DI nền — extends AppServiceFactory)
│   │   │        · SettingService ✅ (13/09 batch 8 FR-39 — tầng đọc settings + cache map
│   │   │          qua PageCacheService TTL 60s; Admin saveForm invalidate qua container gộp;
│   │   │          16/09 thêm mapEmbedUrl() — ưu tiên map_address/address để đổi địa chỉ là FE đổi map, fallback map_embed_url)
│   │   │        · HomeService ✅ (13/09 batch 9 FR-32/39 — dựng payload mảng thuần cho 7 loại
│   │   │          section (hero/posts/services/team/cta) + 16/09 TYPE_PROCESS=8 (processBlock steps 3-6, kind=process), đọc mapper chủ bảng qua container gộp
│   │   │          (chỉ SELECT — §4), ảnh resolve 1 query IN `mapCardsByIds`, section rỗng tự bỏ,
│   │   │          cả payload bọc `remember('home-v1')` TTL 60s)
│   │   │        · PostListService ✅ (13/09 batch 10 FR-02 — `paginate()` 12 bài/trang
│   │   │          `?page=` + lọc `?danh-muc=<slug>` (resolveFilter: slug lạ/ẩn → bỏ lọc),
│   │   │          clamp trang cuối, card + `getNamesByIds`/`mapCardsByIds` batch chống N+1,
│   │   │          ngày giờ VN; `filterCategories()` cache `category-menu-v1` TTL 60s —
│   │   │          danh sách bài KHÔNG cache (key nổ theo trang×danh mục — docblock))
│   │   │        · PostDetailService ✅ (13/09 batch 11 FR-03 — `detail(slug, previewToken='')` payload
│   │   │          mảng thuần đủ khối §3.3.2; bài liên quan §5.4 = sharedTagCounts (bảng post_tags) →
│   │   │          listPublishedByIds + pool danh mục, sort điểm/ngày/id DESC cắt 4 — KHÔNG JOIN
│   │   │          chéo (luật 1 mapper 1 bảng); card reuse `PostListService::buildCards` (nay là
│   │   │          public); không cache — §7.2 không liệt kê trang chi tiết;
│   │   │          batch 12 FR-04: token không rỗng ⇒ flag `noindex` trong payload (mọi request kèm
│   │   │          `?previewToken=`, kể cả bài publish); bài chưa công khai chỉ về khi
│   │   │          `findBySlugPreviewToken` khớp slug+token — chỉ tra khi lookup public MISS)
│   │   │        · CategoryListService ✅ (13/09 batch 13 FR-05 — `page(slug, requestedPage)`
│   │   │          trang /danh-muc/{slug}: TẮT/lạ → null → 404 (KHÁC semantics bỏ-lọc của
│   │   │          /tin-tuc); tập bài = chính nó + `activeChildIds` con BẬT (§5.3, không JOIN);
│   │   │          phân trang + clamp khuôn FR-02 (`FrontendConst::NEWS_PAGE_SIZE`), card reuse
│   │   │          `buildCards`; meta fallback tên/mô tả §3.2; không cache — key nổ slug×trang)
│   │   │        · TagListService ✅ (13/09 batch 14 FR-06 — `page(slug, requestedPage)` trang
│   │   │          /tag/{slug}: tag lạ/rỗng → null → 404; id bài từ `postIdsByTag` (post_tags)
│   │   │          đưa nguyên viên vào `countPublishedByIds`/`listPublishedPageByIds` (IN +
│   │   │          scope public một query, 1 bảng 1 mapper); tag 0 bài = 200 rỗng (tag không có
│   │   │          bật/tắt); phân trang + clamp + card reuse khuôn FR-02/05; không cache)
│   │   │        · SearchService ✅ (13/09 batch 15 FR-07 — `search(q)`: FULLTEXT
│   │   │          `ft_posts_title_excerpt` qua `PostMapper::searchPublished` (`MATCH...AGAINST` +
│   │   │          scope public + order `score DESC, publishedAt DESC, id DESC` + `LIMIT 20`);
│   │   │          từ khoá rỗng/<2 ký tự chặn trước DB; card reuse `PostListService::buildCards`;
│   │   │          cờ `noindex => true` luôn hiện diện theo NFR-SEO-5; không cache)
│   │   │        · ServiceViewService ✅ (13/09 batch 16 FR-08 — `list()`/`detail(slug)`: dịch vụ
│   │   │          active sortOrder/id ASC qua cặp mapper mới `ServiceMapper::listActiveAll`/
│   │   │          `findActiveBySlug` (slug+isActive+LIMIT 1, bind prepared; slug rỗng chặn trước DB);
│   │   │          icon/ảnh resolve MỘT query `MediaMapper::mapCardsByIds` chống N+1; meta fallback
│   │   │          name/shortDescription (§3.5); không cache — §7.2 không liệt kê;
│   │   │          16/09 batch UI — `grouped()` cha-con `parentId` cho /dich-vu 5 box: cha `parentId IS NULL`,
│   │   │          con theo `parentId`, media gom 1 query, fallback phẳng khi DB cũ chưa có cha)
│   │   │        · TeamViewService ✅ (13/09 batch 17 FR-09 — `list()`: thành viên active sortOrder/
│   │   │          id ASC qua `TeamMemberMapper::listAllActive` mới (mapper Admin-sở-hữu — precedent
│   │   │          batch 9, chỉ SELECT); avatar `mapCardsByIds`; **luật §3.8 chốt ở service**:
│   │   │          `showContact=0` → email/phone = null trong payload (`TeamMemberConst::
│   │   │          SHOW_CONTACT/HIDE_CONTACT`) — view không thể làm lộ; không cache)
│   │   │        · SitemapService ✅ (13/09 batch 18 FR-11 — `urls()`: 5 đường dẫn tĩnh
│   │   │          (/ · /tin-tuc · /dich-vu · /doi-ngu · /lien-he) + bài `/tin-tuc/{slug}`
│   │   │          (`PostMapper::sitemapPublished` — `publicScope` → NHÁP/hẹn-giờ-tương-lai/
│   │   │          archived tự loại, lastmod = `updatedAt` cắt YYYY-MM-DD) + danh mục
│   │   │          `/danh-muc/{slug}` (`CategoryMapper::sitemapActiveSlugs`) + dịch vụ
│   │   │          `/dich-vu/{slug}` (`ServiceMapper::sitemapActiveSlugs` — 2 cặp method mới
│   │   │          isActive=1 sort/id ASC); `/tim-kiem` loại theo NFR-SEO-5 noindex; payload
│   │   │          {path,lastmod} KHÔNG gắn host → cache nguyên khối `remember('sitemap-v1')`
│   │   │          TTL 60s; hook forget ở Admin Post/Category/Service service)
│   │   │        · PricingViewService ✅ (16/09 — cache một key pricing-v1 TTL 60s; 17/09 list(?group,?q,?page) filter + phân trang 20 dòng/trang, price null→"Liên hệ")
│   │   │        · MenuService ✅ (17/09 — `publicItems()` đọc `menu_items` active, cache `menu-v1`; layout helper fallback menu mặc định nếu DB chưa migrate)
│   │   ├── Constant/FrontendConst.php ✅ (13/09 batch 10 — hằng KỸ THUẬT module:
│   │   │          NEWS_PAGE_SIZE 12 · SERVICE_PAGE_SIZE 12 · PRICING_PAGE_SIZE 20 · PAGER_WINDOW 3; hằng entity không ở đây — 06 §)
│   │   │        (+ các Service khác khi triển khai)
│   │   ├── Model/
│   │   │   ├── Contact/{ContactMapper,ContactConst,ContactModel}.php ✅ (từ Table/ContactTable;
│   │   │   │        từ 13/09 batch 3 mapper này GHI HỘ hộp thư admin — xem §4)
│   │   │   ├── Service/{ServiceMapper,ServiceConst,ServiceModel}.php ✅ (từ Table/ServiceTable;
│   │   │   │        từ 12/09 batch 2 mapper này CẢ GHI HỘ Admin — xem §4; 16/09 thêm parentId + helpers cha-con)
│   │   │   ├── Pricing/{PricingMapper,PricingConst,PricingModel}.php ✅ (16/09 — bảng `pricing_items`: groupCode/name/slug/price/unit/note, Frontend sở hữu, Admin reuse; `listActive(?group,?q)` Where equalTo/like)
│   │   │   ├── Menu/{MenuMapper,MenuConst,MenuModel}.php ✅ (17/09 — bảng `menu_items`: label/url/target/sortOrder/isActive, Frontend sở hữu, Admin reuse)
│   │   │   └── Setting/{SettingMapper,SettingConst,SettingModel}.php ✅ (từ Table/SettingTable;
│   │   │            từ 13/09 batch 3 listAll/updateValue ghi hộ trang /admin/settings — xem §4;
│   │   │            batch 8 FR-39: đọc giá trị qua Frontend\SettingService (cache),
│   │   │            getValue bị xoá vì hết caller — precedent mapper-method-not-used)
│   │   ├── Filter/Contact/ContactSaveFilter.php             ✅ (từ Form/ContactForm)
│   │   └── Exception/                                       🟡
│   │   ❌ Table/ · Form/ · Controller/Factory/ · Service/Factory/ (Constant/FrontendConst.php dựng LẠI 13/09 batch 10 làm hằng kỹ thuật module — hợp chuẩn §2, khác bản cũ chứa hằng entity đã xoá)
│   └── view/frontend/  home ✅ (13/09 batch 9 — `home/index.phtml` render 5 loại block theo
│       `kind`, hero có `public/assets/js/home-slider.js`; style dùng `.hero*`/`.slide*`/
│       `.cta-bottom` trong `public/assets/css/style.css` — layout đã link sẵn; không control
│       structure PHP trong body; 16/09 thêm nhánh kind=process (.process-grid 6→3→1, .process-num))
│       · post ✅ (13/09 batch 10 — `post/list.phtml`: toolbar select auto-submit + nút
│         Lọc dự phòng, thẻ bài `.post-card`, pager cửa sổ ±3 GIỮ `danh-muc` mọi link;
│         CSS `.news-*`/`.pager*` trong style.css;
│         batch 11 FR-03 `post/detail.phtml`: breadcrumb · lede · meta (tác giả·ngày·phút) ·
│         banner · content echo THÔ (đã HtmlPurifier lúc lưu — §7.3) · tag chips · share
│         FB/X/Email (URL tuyệt đối serverUrl) · related `.post-grid` reuse card · headTitle +
│         description + og:*; CSS `.breadcrumb`/`.article-*`/`.tag-chip`/`.share-*`/`.related`;
│         batch 12 FR-04: `noindex` trong payload ⇒ `headMeta()->appendName('robots','noindex')`)
│         · category ✅ (13/09 batch 13 FR-05 — `category/view.phtml`: breadcrumb + h1 tên +
│           `.cat-desc` mô tả đầu trang §3.2 + card/pager nguyên khuôn FR-02 (link giữ
│           `/danh-muc/{slug}`), headTitle/meta fallback đã tính ở service; CSS +`.cat-desc`)
│         · tag ✅ (13/09 batch 14 FR-06 — `tag/view.phtml`: breadcrumb "Trang chủ › Tin tức ›
│           Tag: tên" + h1 + card/pager nguyên khuôn category (link `/tag/{slug}?page=N`),
│           tag rỗng bài = 200 thông báo, CSS không thêm gì mới)
│         · search ✅ (13/09 batch 15 FR-07 — `search/index.phtml`: breadcrumb "Trang chủ ›
│           Tìm kiếm" + form search `.search-box` + thông báo/tổng số + `.post-grid`, `noindex`,
│           CSS `.search-box`/`.search-input` trong style.css)
│         · service ✅ (13/09 batch 16 FR-08 — `service/list.phtml`: breadcrumb + grid
│           `.service-card` (icon/ảnh `mediaUrl`), link `/dich-vu/{slug}`; 16/09 rewrite list.phtml 5 box: Box1 banner + Box2/3 grouped cha-con + Box4 pricing rút gọn 10 dòng + Box5 CTA map; `service/detail.phtml`:
│           breadcrumb + lede + `.article-body` echo THÔ content (đã Purifier lúc lưu — §7.3) +
│           headTitle/description fallback; CSS block FR-08)
│         · team ✅ (13/09 batch 17 FR-09 — `team/list.phtml`: breadcrumb + `.team-grid` cards
│           (avatar thumb — thiếu avatar fallback chữ cái đầu `.team-avatar-placeholder` ·
│           tên · chức vụ · bio · `mailto:`/`tel:` chỉ render khi service cho — null đã lọc ở
│           payload); CSS block FR-09)
│         · sitemap ✅ (13/09 batch 18 FR-11 — `sitemap/index.phtml`: MỘT block PHP đầu file
│           dựng chuỗi XML (không control structure trong body — luật phpcs); `<urlset>` +
│           mỗi `<url><loc>` absolute qua `serverUrl()` escapeHtml, `<lastmod>` YYYY-MM-DD
│           khi có; terminal — không layout, header do controller gắn)
│         · contact ✅ (13/09 — contact/index.phtml form; 16/09 rewrite bỏ form POST, hiện hotline/zalo/address/working_hours + iframe mapEmbedUrl, chuỗi-based)
│         · about ✅ (16/09 — about/index.phtml: page-banner + about-article 4 feat + sidebar video/why-grid/contact-mini, chuỗi-based)
│         · pricing ✅ (16/09 — pricing/index.phtml; 17/09 rewrite Medlatec-style: filter GET nhom+q+page, table 4 cột STT/Tên dịch vụ/Giá dịch vụ/Giá BHYT, phân trang 20 dòng/trang, price null="Liên hệ", chuỗi-based)
│       + layout/frontend.phtml (16/09 nav thêm Giới thiệu/Bảng giá — 7 link, footer thêm cột Liên kết, hotline tel:)
│
└── Admin/                     CMS (một quản trị viên) — module GHI mọi bảng qua Mapper của nó
    ├── config/module.config.php  ✏️  Route /admin/* + /api/admin/:resource + DI closure ✅
    ├── src/
    │   ├── Controller/  Auth · Dashboard · Post · Category · Tag · Service · Banner · HomeSection
    │   │                · Team · Contact · Media · Setting · Account · Api   (KHÔNG có Factory/ ✅)
    │   │                   Post/Category/Tag/Api = code thật (CRUD + dispatcher API);
    │   │                   Service/Banner/Team/Contact/Setting/HomeSection/Media = code thật theo
    │   │                   chuẩn mới (batch 12/09 + 13/09); Dashboard/Account = code thật (FR-14, §3.1).
    │   ├── Service/     AdminAuthService · AuthGuard ✅ · CategoryService · TagService · PostService ✅
    │   │                   · ServiceService · BannerService · TeamMemberService ✅ (batch 12/09; 16/09 ServiceService thêm parentId + parentOptions() + invalidatePublicCaches home+sitemap)
│   │   │                   · PricingService ✅ (16/09 — CRUD pricing_items + formReorder, invalidatePublicCaches pricing-v1+home-v1)
    │   │                   · ContactService · SettingService · HomeSectionService ✅ (batch 13/09)
    │   │                   · MediaService ✅ (batch 13/09 — upload finfo + biến thể WebP GD,
    │   │                   gom usages điều phối qua 8 mapper chủ bảng)
    │   │                   · Api/{ApiResultModel,ApiResponseModel}.php ✅ (13/09 — luồng API:
    │   │                   Service trả trọn envelope 4 khoá + HTTP status, ApiController chỉ router)
│   │                   (điều phối nhiều Mapper + transaction; guard/builder cũng nằm ở Service/)
│   │                   TOÀN BỘ service: extends AppServiceFactory, DI nền 07 §4 (13/09)
│   │                   · 13/09 batch 9: 5 service ghi (Post · Banner · Service · TeamMember ·
│   │                     HomeSection cả itemsForm) gọi forget 'home-v1' qua PageCacheService
│   │                     (accessor instanceof-guard — dependency mềm, thiếu key → no-op)
│   │                     sau MỌI lần ghi thành công (FR-39 §7.2)
│   │                   · 13/09 batch 10: CategoryService (create/update/delete) forget thêm
│   │                     'category-menu-v1' VÀ 'home-v1' — đóng lỗ batch 9: khối category_posts
│   │                     trang chủ đọc tên danh mục lúc dựng payload
    │   ├── Model/
    │   │   ├── User/{UserMapper,UserModel,UserConst}.php         ✅ (từ Table/UserTable; 13/09 batch 5)
    │   │   ├── Category/{CategoryMapper,CategoryConst}.php  ✅   |  Tag/{TagMapper,TagConst}.php  ✅
│   │   │       (batch 10: CategoryMapper +findBySlug; getNamesByIds 2 mapper SỬA BUG latent
│   │   │        `['id IN' => …]` → `Where::in()` — 500 thật trên /tin-tuc có dữ liệu; namesSelect
│   │   │        tách public cho SQL test — CategoryMapperSqlTest/TagMapperSqlTest;
│   │   │        batch 11: TagMapper +listByIds — tag trang chi tiết FR-03, Where::in đúng khuôn;
│   │   │        batch 13 FR-05 +activeChildIds(parentId) — nhánh §5.3 `parentId=? AND isActive=1`
│   │   │        một bảng không JOIN (cây chặn 2 cấp ở FR-26 nên 1 lớp là đủ);
│   │   │        activeChildIdsSelect public cho CategoryMapperSqlTest render)
    │   │   ├── Post/{PostMapper,PostConst}.php  ✅   ·  PostTag/PostTagMapper.php  ✅
│   │   │       (PostMapper 13/09 batch 9: + scope đọc công khai cho trang chủ —
│   │   │        listLatestPublished/listFeaturedPublished/listPublishedByCategory/
│   │   │        listPublishedByIds qua publicList (status=PUBLISHED +
│   │   │        publishedAt<=UTC_TIMESTAMP(), đúng cột card, order publishedAt/id DESC);
│   │   │        batch 10 + listPublishedPage(?categoryIds,limit,offset)/countPublished(?categoryIds)
│   │   │        cùng scope — builder publicSelect/countPublishedSelect tách public để SQL
│   │   │        regression test render `buildSqlString` không cần DB (khuôn mới batch 10);
│   │   │        batch 11 +findPublishedBySlug(slug) — full cột kể content, publicScope + limit(1),
│   │   │        null = nháp/hẹn giờ/lạ; batch 12 FR-04 +findBySlugPreviewToken(slug, token) —
│   │   │        BỎ scope, chỉ slug+previewToken khớp, token rỗng tự null không chạm DB;
│   │   │        slugPreviewTokenSelect public cho PostMapperPreviewSqlTest (khuôn batch 10);
│   │   │        batch 14 FR-06 +listPublishedPageByIds(ids,limit,offset)+countPublishedByIds(ids)
│   │   │        — trang /tag: IN(id) từ post_tags + scope public MỘT query (1 bảng 1 mapper),
│   │   │        tập id rỗng chặn trước mọi query; countPublishedByIdsSelect public cho SQL test)
│   │   │        (PostTagMapper batch 11 +sharedTagCounts(excludePostId, tagIds) — §5.4 đếm tag
│   │   │        chung single-table GROUP BY postId; sharedTagCountsSelect public cho
│   │   │        PostTagMapperSqlTest — khuôn batch 10; postIdsByTag có caller FE
│   │   │        thật từ batch 14 FR-06 — TagListService đưa vào listPublishedPageByIds)
    │   │   ├── PostRevision/PostRevisionMapper.php  ✅   ·  PostViewDaily/PostViewDailyMapper.php  ✅
│   │   ├── Banner/{BannerMapper,BannerConst,BannerModel}.php    ✅ (batch 12/09; batch 9
│   │   │        + listActiveHomeHero — isActive+position home_hero + cửa sổ [startAt,endAt]
│   │   │        so UTC_TIMESTAMP(), nested Where `isNull ... or->`; import Predicate\Expression)
│   │   ├── TeamMember/{TeamMemberMapper,TeamMemberConst,TeamMemberModel}.php ✅ (batch 12/09;
│   │   │        batch 9 + listFeaturedActive/listActiveByIds cho khối team trang chủ)
│   │   ├── HomeSection/{HomeSectionMapper,HomeSectionConst,HomeSectionModel}.php ✅ (batch 13/09;
│   │   │        batch 9 + listActiveOrdered (isActive=1, sortOrder,id) cho HomeService)
│   │   ├── Media/{MediaMapper,MediaConst,MediaModel}.php ✅ (batch 13/09;
│   │   │        batch 9 + mapCardsByIds — 1 query IN, id => {path, alt, thumb=variants.thumb ?? path})
    │   │   └── HomeSectionItem/{HomeSectionItemMapper,HomeSectionItemModel}.php ✅
    │   │                                                    (1 Mapper = 1 bảng; 13/09 cascade deleteBySectionId
    │   │                                                    + FR-33: listBySectionId/insert/existsItem/
    │   │                                                    deleteByIdAndSection/updateSortOrder — mục manual)
    │   ├── Filter/
    │   │   ├── Pricing/{PricingSaveFilter,PricingActionFilter}.php ✅ (16/09 extends AppInputFilter)
    │   │   ├── Auth/LoginFilter.php                         ✅ (từ Form/LoginForm)
    │   │   ├── Category/CategorySaveFilter.php · Tag/TagSaveFilter.php                       ✅
    │   │   ├── Post/{PostSaveFilter,PostListFilter}.php                                      ✅
    │   │   ├── Service/Banner/TeamMember/Contact/Setting/HomeSection — {Save|Update,Action}Filter ✅
    │   │   │     (+ HomeSection/HomeSectionItemsFilter.php ✅ 13/09 — FR-33)
    │   │   ├── Media/{MediaUpload,MediaAlt,MediaAction}Filter.php ✅ (batch 13/09)
    │   │   ├── Account/{AccountProfile,AccountPassword}Filter.php ✅ (batch 13/09 — FR-14)
    │   │   └── Reorder/ReorderFilter.php                             ✅ (14/09 — FR-26/29/30/32/34:
    │   │                 một filter dùng chung mọi POST reorder — `ids` CSV/mảng + CSRF bật/tắt qua
    │   │                 ctor, `idList()` lọc id dương giữ thứ tự; form page true, API false)
    │   ├── Constant/AdminConst.php                          hằng kỹ thuật module (session ns, khoá đăng nhập) — giữ
    │   ├── Exception/                                       ✅  Validation/NotFound/Conflict (+ ExceptionInterface)
    │   ❌ Table/ · Form/ · Guard/ · Controller/Factory/ · Service/Factory/ (đã xoá)
    └── view/admin/  ✅ dashboard · post · category · tag · service (16/09 thêm select parentId) · banner · home-section (16/09 hint TYPE_PROCESS steps JSON 3-6) · team · pricing (16/09 table drag + form group/price) · media
                    · contact · setting · account · auth  + layout/admin.phtml
                    (post/category/tag/service/banner/team/home-section/media + contact index·view
                     + setting index là form/bảng thật; dashboard/account vẫn là khung)
```

**Resource chưa có code** (accounts — posts/categories/tags **đã xong 12/09/2026**, services/banners/teams **đã xong batch 12/09**, contacts/settings/home-sections **đã xong batch 13/09**, media library **đã xong batch 13/09** — FR-33 chọn mục manual **đã xong server-side 13/09 (batch 6)**; kéo thả thứ tự bằng JS **đã xong 14/09** cho categories/services/banners/home-sections/team (FR-26/29/30/32/34 — `ReorderFilter` + `POST /admin/{module}/reorder` + `tbody.js-sortable`) — ô chọn ảnh/bản ghi **đã xong 13/09** bằng helper `selectField` thay nhập ID, áp dụng mọi form media-id + `itemId` home-section + avatar FR-14, chuẩn 07 §9): khi triển khai, **sinh đúng khung** `Model/<Entity>/{Mapper,Model,Const}` + `Service/<Entity>Service` + `Filter/<Entity>/` — không tự do sáng tác vị trí mới.

## 3. Đối chiếu hiện trạng → chuẩn mới (bản đồ refactor)

Toàn bộ các dòng "code cũ" đã chuyển xong 12/09/2026 (trừ CLAUDE.md chưa viết). **Mốc 12/09/2026 (chuẩn luồng):** dòng Controller→Service→Mapper đã refactor về luồng `Controller (mỏng) → Service chạy Filter → Mapper hydrate Model → trả view/JSON` cho toàn bộ code thật (Admin Post/Category/Tag/Auth/Api + Frontend Contact) — chi tiết [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md) §2–3, §6. **Batch 12/09 (mở rộng CRUD):** services (mapper Frontend sở hữu, Admin dùng qua container gộp) + banners + team viết mới FULL theo đúng chuẩn trên (Model/Const/Mapper hydrate + Filter chạy trong Service + Controller mỏng + PRG flag + lịch banner giờ VN↔UTC). **Batch 13/09 (CRUD admin còn lại):** contacts (hộp thư FR-35 — lọc status/dịch vụ/ngày VN→UTC, updateHandler gắn handledAt khi "Đã xong", xoá; ghi qua ContactMapper của Frontend), settings (FR-39 — form tổng hợp `setting_<id>` validate theo valueType, chỉ ghi dòng đổi, ghi qua SettingMapper của Frontend), home-sections (FR-32 — Const type 1–7, validate `config` JSON theo type, xoá cascade home_section_items trong transaction qua `DbService::transactional`). **Batch 13/09 (thư viện media, FR-36/37/38):** upload nhiều file chạy qua `MediaUploadFilter` + cổng an toàn `finfo` MIME (danh sách trắng `app.allowed_mimes` — chặn php giả png, chặn svg), kiểm `app.upload_max_mb` **trước** khi ghi đĩa, lưu tên ngẫu nhiên `YYYY/MM/<32 hex>.<ext>` trong `public/uploads`, sinh biến thể WebP `thumb/medium/large` bằng GD (chỉ hạ kích thước; gif bỏ qua để giữ frame); sửa `altText` qua form riêng; `usages` gom id từ 8 mapper chủ bảng (post quét thêm ảnh nhúng trong `content` theo LIKE; setting theo `valueType` media); **xoá bị chặn khi còn tham chiếu** và kiểm mọi file tồn tại trên đĩa trước khi `unlink` cả gốc + biến thể rồi mới xoá dòng. Kiểm chứng bằng `vendor/bin/phpunit` (**143/143** — 18 test nền + 45 test CRUD + 4 test luồng Service chạy Filter (2 auth + 2 contact) + 29 test CRUD batch 12/09 (9 service + 11 banner + 9 team) + 33 test batch 13/09 (11 contact + 8 setting + 14 home-section) + 14 test media (13/09)), `phpcs` sạch trên các file touched, `psalm` 112 ≤ baseline 162. **Batch 13/09 (áp dụng pattern webapp-be — chỉ phần phù hợp, đối chiếu [`../01-quy-chuan/08-vi-du-sai-dung.md`](../01-quy-chuan/08-vi-du-sai-dung.md) §5):** `Application/Service/DateService` (helper tĩnh giờ UTC tập trung — `nowUtc/timestampUtc/plusMinutesUtc/isoToUtc`; thay `gmdate`/`time()` rải rác ở PostService, AdminAuthService, ContactService Frontend); `Admin/Service/Api/{ApiResultModel,ApiResponseModel}` (Service trả trọn envelope + HTTP status cho luồng API JSON; `ApiController` còn router thuần + `fromThrowable` map 422/409/404; serialize `*At`→ISO và `buildTree` categories dời vào CategoryService); hàm update 1–2 cột chuyên biệt theo 08 §3 — `PostMapper::updatePublished/updateArchived/updateDrafted/updatePreviewToken`, `UserMapper::updatePasswordHash`, `MediaMapper::updateAltText` (xoá `MediaMapper::update` chung hết caller); bỏ chuỗi mệnh đề SQL theo 07 §5.1 toàn bộ `Category/Post/Media/User` Mapper. Không áp dụng: businessId multi-tenant, scoped filter bases, DI `AppInvokableFactory`/`getServiceConfig`, cursor LastIdPaginator, extraContent, CommonFieldFilters (thống nhất sau — xem báo cáo 13/09). Nghiệm thu cả batch: **phpunit 164/164** (+5 test DateService), phpcs sạch trên file đụng vào, psalm **103 ≤ 112** (dọn thêm 9 lỗi tồn dư). **Batch 13/09 (nền tảng Filter):** toàn bộ 25 InputFilter của Admin + Frontend chuyển sang kế thừa `Application\Filter\AppInputFilter` — CSRF/`csrfHash()`/`fieldErrors()`/container/helper khai báo field gom một mối theo yêu cầu user; các block trùng (18× `withCsrf`, 23× `fieldErrors`, 24× `csrfHash`, validator `Csrf` name `csrf`) bị xoá khỏi filter riêng; nghiệm thu phpunit 164/164, phpcs sạch, psalm **103** (`--stats` — 26 file filter 0 lỗi; refactor không tăng, dọn 2 suppress chết), smoke `/admin/login` + `/lien-he` 200 kèm hidden input `csrf`. **Cũng 13/09 — sửa lỗi tồn batch service-locator:** 6 service Admin (`AdminAuthService`/`CategoryService`/`BannerService`/`TeamMemberService`/`SettingService`/`AccountService`) đã kế thừa `AppServiceFactory` nhưng `module/Admin/config/module.config.php` vẫn đăng ký closure constructor-cũ (thừa số bị PHP bỏ qua → container không mồi → 500 `/admin/login`); đổi sang `AppInvokableFactory::class` (bằng chứng nhận "Không áp dụng AppInvokableFactory" ở batch webapp-be nay được hoàn tất cho các service này), 5 test chuyển sang `setContainer(new TestContainer(...))`, `composer dump-autoload` lại vì classmap cũ che hỏng; psalm từ 115 xuống 103. **Batch 8 13/09 (FR-39 — cache settings + nền page_cache):** sửa bug latent schema `caches.page_cache` trong `global.php` về **dạng v3** (laminas-cache 3.14 từ chối `adapter.name` lồng kiểu v2 → dịch vụ sẽ fatal khi dựng; `adapter` giờ là chuỗi class + `options`/`plugins` top-level, plugin `ExceptionHandler throw_exceptions=false`). Nền cache mới: `Application\Service\PageCacheService` (`remember(key, producer)`/`forget(key)`, payload **tự serialize bọc mảng 1 phần tử** vì plugin `serializer` cần package `laminas-serializer` chưa cài; guard `a:1:` chống payload rác; storage thiếu → producer chạy thẳng — cache không phải dependency cứng) + `Application\Constant\CacheConst` (SERVICE_PAGE_CACHE, KEY_SETTINGS 'settings-v1', dành chỗ 'home-v1'). Tầng đọc settings phía khách: `Frontend\Service\SettingService` (map cache TTL 60s, `stringOrNull`, `invalidate`); `ContactService` notify-email nay đọc qua SettingService thay vì `SettingMapper::getValue` — method đó hết caller nên **xoá** (precedent MediaMapper::update); `Admin\Service\SettingService::saveForm()` gọi `Frontend\SettingService::invalidate()` sau mọi lần ghi (reuse chéo module qua container gộp — §4). Filesystem adapter không tự tạo `cache_dir` → `data/cache/page/.gitkeep` commit vào repo (gitignore re-include 3 dòng). Cache dữ liệu trang chủ để batch homepage (HomeController còn placeholder). Nghiệm thu: phpunit **186/186** (+12: 7 PageCache + 5 Frontend Setting; rewiring 2 test cũ Contact/Admin-Settings), phpcs sạch, psalm **98 ≤ 100** (`--no-cache` — 99 của batch 5 +1 batch 6 −2 nhờ xoá getValue), DI smoke container thật: resolve 2 service mới, remember miss→hit (producer 1 lần), forget→tính lại, ttl=60 OK. **Batch 9 13/09 (FR-32 render trang chủ + FR-39 cache `home-v1`):** `HomeController` + `Frontend\Service\HomeService` + `view/frontend/home/index.phtml` hết placeholder — trang chủ render **đủ 7 loại section** theo luồng §8: `listActiveOrdered` → mỗi type đọc từ method công khai mới trên mapper chủ bảng (`PostMapper::listLatestPublished/listFeaturedPublished/listPublishedByCategory/listPublishedByIds` với scope `status=PUBLISHED AND publishedAt<=UTC_TIMESTAMP()` — bài hẹn giờ tự lộ diện sau ≤ một TTL, `BannerMapper::listActiveHomeHero` kèm cửa sổ `[startAt,endAt]`, `ServiceMapper::listActive/listActiveByIds`, `TeamMemberMapper::listFeaturedActive/listActiveByIds`), `mode=manual` đọc `home_section_items` rồi giữ nguyên thứ tự id (mục chết tự vắng mặt — không JOIN chéo bảng, luật 1 mapper 1 bảng); heading section trống tự fallback tên danh mục; ảnh mọi card resolve bằng **một** query `MediaMapper::mapCardsByIds` (chống N+1 — thumb ưu tiên biến thể). Payload là **mảng thuần** (không model, không object) nên cache được nguyên khối qua `remember(CacheConst::KEY_HOME)` TTL 60s; hook `forget('home-v1')` gắn vào **5 service ghi** Admin (Post/Banner/Service/TeamMember/HomeSection-cả-itemsForm) sau mọi write thành công — PageCacheService là dependency mềm qua accessor instanceof-guard nên toàn bộ test cũ giữ nguyên không phải rewire. Frontend đọc mapper Admin-owned qua container gộp = **precedent ngược** của chiều Admin-dùng-Frontend-mapper (ghi §4). Slider hero: `public/assets/js/home-slider.js` (autoplay + dots theo `config.autoplay/interval_ms`), style trong `public/assets/css/style.css` có sẵn. Bắt được 1 bug luồng khi viết test: block `category_posts` rơi nhầm về featured — sửa bằng tham số `categoryId` đổ đúng `listPublishedByCategory`. Nghiệm thu: phpunit **204/204** (batch 9 +11: 6 test HomeService gồm hit/miss/soft-dep/thứ-tự-manual/rỗng-category-fallback + 5 test `forget home-v1` ở 5 service; gộp +8 test đăng nhập username của worker cùng phiên), phpcs sạch, psalm **90 ≤ 98** (`--no-cache` — số 118 ở milestone username là file batch 9 dở dang, đúng lời "cần rà lại" đã ghi), smoke HTTP `php -S` thật: `/` 200 + miss→ghi `data/cache/page/.../laminascache-home-v1.dat`→hit 2 request giống hệt→`PageCacheService::forget` qua container gỡ file→GET dựng lại OK. **Batch 10 13/09 (FR-02 — /tin-tuc phân trang + lọc danh mục + sửa latent WHERE IN):** `Frontend\Service\PostListService` (AppInvokableFactory, đọc mapper Admin qua container gộp — precedent batch 9) dựng `paginate()`: `countPublished` → pages → clamp trang vượt → `listPublishedPage` (12 bài/trang — `FrontendConst::NEWS_PAGE_SIZE`), lọc `?danh-muc=<slug>` qua `CategoryMapper::findBySlug` (slug lạ/ẩn → bỏ lọc, 404 dành FR-05), card batch `getNamesByIds` + `mapCardsByIds`, ngày giờ VN; menu `filterCategories()` cache `category-menu-v1` TTL 60s (danh sách bài không cache — lý do docblock); `CategoryService` ghi xong forget cả `category-menu-v1` + `home-v1`. View `post/list.phtml` + CSS `.news-*`/`.pager*`. **Bug latent lớp mới bắt được khi smoke dữ liệu thật:** `->where(['id IN' => $ids])` không được laminas-db parse ra toán tử (`id IN` thành tên cột → SQL hỏng, 500 trên trang có bài) — `CategoryMapper/TagMapper::getNamesByIds` chuyển `Where::in()`; grep sạch key dạng này toàn repo, `MediaMapper` đã đúng. **Khuôn test mapper-read mới:** builder Select tách public + `buildSqlString` platform Mysql driverless (override `quoteValue`→`quoteTrustedValue` khỏi notice) — 3 file SQL regression, không mock Driver/Statement/ResultSet. Nghiệm thu: phpunit **221/221**, phpcs sạch (sửa nốt đóng tag `?>` tồn dư `home/index.phtml`), psalm **82 ≤ 90** `--no-cache`, smoke `php -S` seed `fr02-*` 15 bài/4 danh mục: 12+3 card, lọc 12+1, inactive bỏ lọc, `?page=99` clamp, option loại inactive, file `laminascache-category-menu-v1.dat` sinh đúng — seed + file tạm đã dọn. **Batch 11 13/09 (FR-03 — chi tiết bài viết /tin-tuc/{slug}):** `PostMapper::findPublishedBySlug` (full cột, cùng scope public — nháp/hẹn giờ/slug lạ = null) + `Frontend\Service\PostDetailService` (AppInvokableFactory — 7 accessor qua container gộp) dựng payload mảng thuần đủ khối §3.3.2: banner (MediaMapper::findById — path/alt), **tên tác giả dạng string** (UserModel mang passwordHash — cấm model xuống view), ngày d/m/Y giờ VN, phút đọc, danh mục breadcrumb (tắt/xoá → ẩn riêng cấp danh mục, 404 vẫn dành FR-05), tag chips (`tagIdsForPost` + `TagMapper::listByIds`), SEO meta fallback title/excerpt. **Bài liên quan §5.4:** SQL mẫu của spec JOIN chéo 3 bảng — trái luật 1 mapper 1 bảng; tương đương có chủ đích 2 bước: `PostTagMapper::sharedTagCounts` (COUNT group by postId, single-table) → `listPublishedByIds` + pool `listPublishedByCategory(cat,8)`; sort điểm chung DESC → publishedAt DESC → id DESC, loại chính nó, cắt 4; card reuse `PostListService::buildCards` (nay là public). Controller `detailAction`: null → `notFoundAction()` — phát hiện template suy ra `frontend/post/not-found` không tồn tại nên phải `setTemplate('error/404')` tường minh (`not_found_template` chỉ ăn cho route-404). KHÔNG cache — §7.2 chỉ liệt kê home/menu/settings. View `post/detail.phtml` + CSS `.article*`; `headMeta` laminas escape thuộc tính thành `&#x3A;/&#x20;` — HTML hợp lệ, crawler decode (kiểm bằng html_entity_decode trong smoke). Nghiệm thu: phpunit **227/227** (+9: 7 service test gồm thứ tự related/loại chính nó/meta fallback/passwordHash không lộ + 2 `PostTagMapperSqlTest` — notEqualTo render thành `!=` chứ không `<>`), phpcs sạch, psalm **82** = baseline (tự sửa guard `is_array($row)` thừa của chính batch này trên type đã hydrate — +6 mà milestone "rà giao diện" ghi nhận), smoke `php -S` seed `fr03-*` (1 bài chính banner 2 tag + 2 bài shared tag + 2 pool + 1 draft): 200 đủ 19 khối, related đúng thứ tự a(2tag)→b(1tag)→pool DESC, share URL tuyệt đối, draft + slug lạ 404, bản tin cũ 200 — seed + uploads/fr03 + cache page + server đã dọn. **Batch 12 13/09 (FR-04 — xem trước `?previewToken=`):** FE tiêu thụ token Admin đã sinh sẵn: `PostMapper::findBySlugPreviewToken(slug, token)` dựng `WHERE slug=? AND previewToken=? LIMIT 1` **không publicScope** (unique `uq_posts_preview_token` → tối đa 1 dòng; token rỗng trả null trước khi chạm DB; bind prepared — không nội suy); `PostDetailService::detail(slug, token)` chỉ tra nhánh này khi lookup công khai MISS (bài publish vào thẳng bằng slug thường, không vấn gì token). `PostController::detailAction` đọc `?previewToken=` từ query + trim — GET không InputFilter (không ghi, không validate gì thêm). **Nghĩa đen NFR-SEO-5:** mọi request có token không rỗng đều gắn `noindex` — kể cả bài ĐÃ publish, vì URL kèm query là bản sao phải tránh index trùng; view `detail.phtml` render `<meta name="robots" content="noindex">` khi payload mang flag. Nghiệm thu: phpunit **233/233** (+6: 4 service — draft chỉ hiện khi khớp đúng token / không token không chạm mapper / published+ktoken vẫn canonical nhưng noindex / visit thường không flag — và 2 SQL render `PostMapperPreviewSqlTest`: có `slug`+`previewToken`, `LIMIT 1`, KHÔNG có `status`/`UTC_TIMESTAMP()`; token rỗng never getDriver), phpcs sạch (gộp 1 dòng >120 ký tự), psalm **82** = baseline, smoke `php -S` seed 1 draft `fr04-*` token cố định + bài publish thật: 12/12 — draft+token 200 noindex đủ title/content, token sai 404, không token 404, `?previewToken=` rỗng 404, published+token 200 vẫn noindex, published không token không robots meta, token đúng+slug lạ 404 — seed + server đã dọn. **Batch 13 13/09 (FR-05 — trang danh mục /danh-muc/{slug}):** `Frontend\Service\CategoryListService::page(slug, page)` + `CategoryController::viewAction` (route default `view` có sẵn — 4 action stub không trỏ route nào bị xoá theo precedent PostController) + view `category/view.phtml`. **404 semantics đúng dòng FR:** slug lạ HOẶC danh mục TẮT → null → `notFoundAction()` + `error/404` (khác /tin-tuc FR-02 ở đó là BỎ LỌC im lặng — hai ngữ cảnh khác nhau được ghi rõ trong docblock cả hai phía). Tập bài §5.3: chính nó + **con đang bật** — `CategoryMapper::activeChildIds` single-table `WHERE parentId=? AND isActive=1` ORDER sortOrder/id (một lớp là đủ vì cây đã chặn 2 cấp ở FR-26; không JOIN chéo, luật 07 §5); con TẮT chỉ vắng khỏi trang cha nhưng bài nó vẫn đọc được qua URL trực tiếp (§3.2 "Tắt danh mục"). Phân trang + clamp + `NEWS_PAGE_SIZE` + card `buildCards` + pager thuật toán FR-02 — link giữ `/danh-muc/{slug}?page=N`; đầu trang có breadcrumb + mô tả `.cat-desc` (§3.2 "Mô tả — hiển thị đầu trang danh mục"); meta fallback tên/mô tả tính sẵn trong service. Không cache (key nổ slug×trang; menu dropdown đã có `category-menu-v1`). Nghiệm thu: phpunit **242/242** (+9: 8 `CategoryListServiceTest` — lạ/rỗng/tắt → null không chạm PostMapper, scope [cha,...con], không con, clamp, meta fallback/explicit — và 1 render `activeChildIdsSelect`), phpcs sạch, psalm **74 ≤ 82** `--no-cache` (FR-05 files 0 lỗi scoped; số tụt thêm do phiên song song), smoke `php -S` seed 3 danh mục + 14 bài `fr05-*`: trang cha 200 13 bài/2 trang đủ 12 card trang 1 + summary, con TẮT + draft bị ẩn, `?page=99` clamp về trang 2 đúng 1 card + link pager, TẮT→404, lạ→404, trang con chỉ bài của mình, không toolbar lọc/không noindex — seed + server đã dọn. **Lưu ý repo song song:** DB dev đổi tên `vanlang_news` → `news_system` trong `config/autoload/global.php` giữa phiên (phiên khác); docs/AGENTS không hardcode tên DB cũ nên không drift. **Batch 14 13/09 (FR-06 — trang tag /tag/{slug}):** `Frontend\Service\TagListService::page` + `TagController::viewAction` thật (xoá 4 action stub hết route — route `tag` default action `view` có sẵn) + `tag/view.phtml`. Ghép đúng luật 1 mapper 1 bảng: id bài từ `PostTagMapper::postIdsByTag` (method đã khai sinh từ batch 11 với docblock dự phòng sẵn luồng này) → **MỘT** query của PostMapper qua cặp mới `listPublishedPageByIds(ids, limit, offset)` / `countPublishedByIds(ids)` (publicScope + `Where::in('id', …)`, sort publishedAt/id DESC; builder `countPublishedByIdsSelect` public cho SQL test — khuôn batch 10). **404 semantics:** tag LẠ → null → 404; tag tồn tại chưa có bài → 200 rỗng (khác danh mục — tags không có cột bật/tắt); tập id rỗng được guard chặn trước mọi query (IN () hỏng SQL). Phân trang 12 + clamp + card `buildCards` + pager nguyên khuôn FR-02/05, link `/tag/{slug}?page=N`; breadcrumb "Trang chủ › Tin tức › Tag: tên". Không cache (slug×trang). Nghiệm thu: phpunit **250/250** (+8: 6 `TagListServiceTest` — lạ/rỗng null không chạm post_tags, tag 0 bài 200, id-set chảy nguyên viên vào count+page, clamp, offset trang 2 — và 2 `PostMapperPublishedByIdsSqlTest`: IN THẬT + đủ scope + không `categoryId` lạc, empty-guard never getDriver), phpcs sạch, psalm **69 ≤ 74** `--no-cache` (file batch 14 scoped 0 lỗi — tổng tụt thêm do phiên song song), smoke `php -S` seed tag `fr06-*` + 13 bài publish + 1 draft gắn tag: 14/14 — 12 card trang 1, draft ẩn, `?page=99` clamp về trang 2 (1 card + pager link), tag rỗng bài 200 thông báo không pager, slug lạ 404, không robots meta — seed + server + file tạm đã dọn. **Batch 14/09 (kéo thả đổi thứ tự FR-26/29/30/32/34):** `Admin/Filter/Reorder/ReorderFilter.php` dùng chung 5 trang — nền CSRF của `AppInputFilter`, bật/tắt qua ctor (form `true`, API `false`), `idList()` nhận CSV hoặc mảng, lọc id dương, giữ thứ tự, dedupe. Mỗi service (Category/Banner/Service/HomeSection/TeamMember) thêm `reorderCsrfHash()` + `formReorder(raw)`: dựng dãy MỚI từ `listAll` (id nêu trước nhận sortOrder 0..n-1, dòng không nêu giữ cuối — không mất dòng), **chỉ ghi dòng đổi giá trị**, trong một `DbService::transactional`, rồi gọi hook invalidate cache sẵn có; `CategoryService` tách `applyReorder()` dùng cho cả form lẫn `reorderApi()` (nhánh mới `PUT /api/admin/categories/reorder` trong `ApiController`). Controller mỏng: `reorderAction` POST → XHR trả `JsonModel {flag, applied}`, không AJAX rơi PRG `?flag=...`. View: cột ⠿ đầu bảng + `tbody.js-sortable` (data-reorder-url + data-csrf); JS chung trong `admin.js` chỉ kéo từ cán, so điểm giữa để chèn, fetch form-urlencoded kèm `X-Requested-With`, lỗi → khôi phục thứ tự cũ + toast, thành công → đánh lại ô "Thứ tự" trên DOM. Nghiệm thu: phpunit **322/322** (+21 test reorder: 4–5 test/form each resource + 2 test API categories), phpcs sạch file đụng vào, psalm `--no-cache` **70** = baseline. **Batch 14/09 (FR-19 + FR-28 — hai dòng 🟡 cuối của nhóm kế hoạch):** FR-19: `PostListService::buildCards` trả `image.path` = **biến thể thumb** từ `MediaMapper::mapCardsByIds` (khuôn `HomeService::imageOf`) — mọi card danh sách (FR-02/05/06/07 + bài liên quan) dùng WebP thumb, banner trang chi tiết vẫn ảnh gốc; tham số `$variant` bị bỏ qua của `MediaUrl` **xoá** (path biến thể chỉ lấy được từ cột JSON `variants` — giải ở tầng dữ liệu), helper thành `final` không extends `AbstractHelper` deprecated (khuôn SelectField, hết luôn 1 lỗi psalm nền). FR-28: IIFE `#f-tags` trong `admin.js` — autocomplete tag gọi `GET {data-tag-search}?q=` (URL do view truyền), panel `.tag-ac` fixed dưới input, chọn bằng chuột/↑↓Enter → điền tên vào chuỗi comma-separated cũ, Filter/Service không đổi. Nghiệm thu: phpunit **323/323** (+1 test fallback biến thể; 1 assertion đổi sang thumb), phpcs sạch, psalm `--no-cache` **68** ≤ 70. **Batch 14/09 (FR-31 + rà FR-25):** `BannerMapper::listActiveHomeHero` tổng quát thành `listActiveByPosition(position, ?limit)` (builder `activeByPositionSelect` public — `BannerMapperSqlTest` 2 test render cửa sổ NULL-mở + LIMIT), `PostListService::topBanner()` đọc banner `news_top` active đầu tiên (LIMIT 1) + ảnh gốc resolve `mapCardsByIds`, `PostController::listAction` gắn payload, `post/list.phtml` dựng strip `.news-top-banner` (khuôn fragment chuỗi), CSS block FR-31 trong `style.css`. Rà lại bảng FR: dòng **FR-25 ghi ❌ là lệch code tồn dư** — nghiệp vụ đã đủ từ batch 11 (`PostDetailService::related` + test + view), lật ✅ kèm ghi chú. Nghiệm thu: phpunit **328/328** (+5: 3 topBanner + 2 SQL), phpcs sạch file đụng vào, psalm **68** = baseline.
**Batch 16/09 (UI overhaul — 7 box trang chủ + dịch vụ cha-con + bảng giá + map):** services.parentId nullable + KEY, pricing_items mới (groupCode/name/slug/price/unit/note/sort,isActive), hằng HomeSectionConst::TYPE_PROCESS=8 + CacheConst::KEY_PRICING, Pricing{Mapper,Model,Const} + PricingService (Admin) + PricingViewService (Frontend cache một key `pricing-v1`), ServiceMapper helpers listParents/listChildren/listParentOptions/hasChildren, ServiceModel::$parentId, ServiceSaveFilter parentId, ServiceService parent check, HomeSectionService validate steps 3-6, SettingService::mapEmbedUrl() ưu tiên map_address/address → embed URL để đổi địa chỉ là FE đổi map, routes /gioi-thieu + /bang-gia, controllers About/Pricing + Service/Contact factory patch, views about/index, pricing/index, rewrite service/list 5 box + contact/index bỏ form + home/index process + layout/frontend nav/footer, style.css process/pricing blocks, seed map_address + 2 cha 7 con + 12 pricing + section process; QA phpunit 334/334, psalm 140≤162. **Cập nhật 17/09:** `/bang-gia` hỗ trợ `?page=`, 20 dòng/trang, table STT/Tên/Giá dịch vụ/Giá BHYT.

| Đường dẫn cũ → đích theo chuẩn | Ghi chú | Trạng thái |
|---|---|:-:|
| `{Module}/src/Table/{Entity}Table.php` → `{Module}/src/Model/<Entity>/{Entity}Mapper.php` | Bỏ kế thừa TableGateway → dùng `Laminas\Db\Sql` + Adapter; method đọc trả `{Entity}Model` hydrate qua `fromRow()` (phép chiếu tổng hợp giữ mảng scalar) | ✅ |
| `{Module}/src/Table/Factory/*TableFactory.php` → closure inline trong `config/module.config.php` | xoá file | ✅ |
| `Admin/src/Controller/Factory/*` (14 file) · `Service/Factory/*` · `Frontend/src/*/Factory/*` · `Application/src/Service/Factory/*` · `View/Helper/Factory/*` (trước 13/09 thuộc Core, sau gộp về Application) → closure inline `static fn (ContainerInterface $c) => new X(...)` | xoá ~25 file | ✅ |
| `Admin/src/Form/LoginForm.php` → `Admin/src/Filter/Auth/LoginFilter.php` (InputFilter) | Bỏ `Laminas\Form` làm lớp validate; view render element HTML trực tiếp | ✅ |
| `Frontend/src/Form/ContactForm.php` → `Frontend/src/Filter/Contact/ContactSaveFilter.php` | Giữ CSRF/captcha/honeypot — validate qua Filter, view HTML thuần | ✅ |
| `Admin/src/Guard/AuthGuard.php` (+ Factory) → `Admin/src/Service/AuthGuard.php` | Listener attach qua `Module::init()` giữ nguyên | ✅ |
| Tầng Service: constructor injection + closure đăng ký đối số dài → `extends AppServiceFactory` + accessor `getContainerEntry()` + đăng ký `AppInvokableFactory::class` (07 §4 bản 13/09) | 15 service (Admin 14 + Frontend 1) · 8 closure đăng ký đổi · 14 test dùng `TestContainer` · **batch 7 13/09: 4 service Application (`DbService`/`MailService`/`CaptchaService`/`HtmlPurifierService`) cũng bỏ constructor về nền → 19/19 service toàn hệ thống, không còn ngoại lệ** | ✅ 13/09 |
| `Admin/src/Constant/AdminConst.php` · `Frontend/src/Constant/FrontendConst.php` | Hằng entity → `Model/<Entity>/<Entity>Const.php` (đã tách `ContactConst`, `SettingConst` + `CategoryConst`, `TagConst`, `PostConst` khi làm CRUD; `FrontendConst` xoá); hằng kỹ thuật module (session ns, khoá đăng nhập) giữ ở `Admin/Constant/AdminConst.php`; hằng dùng chung 2 module → `Application/Constant/ContentConst.php` **đã tạo** cùng CRUD post (12/09/2026). Chi tiết: [`../01-quy-chuan/06-quy-uoc-const.md`](../01-quy-chuan/06-quy-uoc-const.md) | ✅ |
| `Application/src/{Filter,Factory}` (7 file thêm ngoài chuẩn — `AppFilter`, `CommonFieldFilters`, `HTMLPurifier*`, `App*Factory`) → `Application/src/Filter/{CommonFieldFilters,HtmlPurifierFilter}.php` (dời 2 file có giá trị, fix import gãy `BusinessFileTypeMap`/`BASE_PATH`) + xóa 5 file vi phạm cấm `*Factory.php`/`AppFilter` base | Xóa `Factory/` + `AppFilter`/`HTMLPurifierFactory`/`HTMLSpecialCharacter`; dời & sửa `CommonFieldFilters`+`HtmlPurifierFilter` về `Application/Filter` (13/09 — đúng chuẩn "dùng chung → Application", 07 §7) | ✅ |
| `module/Core/src/*` (13 file) → `module/Application/src/*` | Đổi namespace `Core\` → `Application\` (DbService, SlugService, HtmlPurifierService, MailService, CaptchaService, DateService, CommonFieldFilters, HtmlPurifierFilter, ContentConst, SessionBootstrap, MediaUrl…); `module/Application/config/module.config.php` gộp service_manager + view_helpers; `composer.json` + `modules.config.php` xóa Core | ✅ |
| 25 filter `extends InputFilter` (Admin 24 · Frontend 1) → `extends Application\Filter\AppInputFilter` | Nền tảng gom CSRF (validator + `csrfHash()`), `fieldErrors()`, `setContainer`/`getContainer`, helper field (`addIdField`/`addStringField`/`addSlugField`/`addIntCastField`/`addRawField`/`addDateTimeField`) và getter kiểu (`positiveIdValue`…); filter riêng chỉ khai báo field rồi `parent::__construct($withCsrf)` cuối ctor (chuẩn 07 §6 — 13/09). Ghi chú: khác `AppFilter` bị xoá dòng trên — bản cũ là code template ngoại lai chưa hoà nhập; `AppInputFilter` viết từ chính 25 filter hiện hữu theo yêu cầu user | ✅ |
| (chưa có) `module/<Module>/CLAUDE.md` | Mỗi module 1 file luật riêng | 🟡 |

**PSR-4 không đổi** (`Admin\` → `module/Admin/src/` …) — namespace theo vị trí mới: `Admin\Model\User\UserMapper`, `Frontend\Filter\Contact\ContactSaveFilter`.

## 4. Vai trò & sở hữu từng phần

| Đường dẫn | Vai trò | Route riêng? | Sở hữu bảng (Mapper)? |
|---|---|:-:|---|
| `module/Frontend/` | Hiển thị nội dung công khai | ✅ `/`, `/tin-tuc`… | `Setting`, `Service`, `Contact`, `Pricing`, `Menu` (mapper Frontend sở hữu; Admin ghi hộ CRUD qua container gộp với `PricingService`/`MenuService`). **Chiều ngược từ batch 9 (13/09):** `HomeService` đọc các mapper **Admin sở hữu** (`Post`/`Banner`/`TeamMember`/`HomeSection`/`HomeSectionItem`/`Category`/`Media`) qua container gộp — chỉ SELECT, không tạo mapper thứ hai |
| `module/Admin/` | CRUD nội dung + API JSON | ✅ `/admin/*`, `/api/admin/*` | `User`, `Category`, `Tag`, `Post`, `PostTag`, `PostRevision`, `PostViewDaily`, `HomeSection`, `HomeSectionItem`, `Banner`, `TeamMember`, `Media` (+ mọi bảng khi triển khai tiếp) — `Contact`/`Service`/`Setting`/`Pricing`/`Menu` thuộc Frontend sở hữu Mapper; Admin dùng lại qua container gộp, KHÔNG tạo mapper thứ hai; 13/09 thêm các method quét usages `findIdsByMedia`/`findKeysByMedia`/`countByMedia` vào đúng mapper chủ bảng tham chiếu media — vẫn giữ luật 1 bảng 1 Mapper |
| `module/Core/` | — (đã gộp về Application từ 13/09/2026) | — | — |
| `module/Application/` | Skeleton + dịch vụ dùng chung (từ 13/09 gộp Core) | (fallback) | — (không Model/, cấp `DbAdapter` qua `DbService`) |
| `config/` | Nạp module, DB/session/cache/mail/app | ❌ | — |
| `data/schema/` | DDL + seed 18 bảng | ❌ | Nguồn định nghĩa schema |
| `public/uploads/` | File media + biến thể | ❌ | File ứng với bảng `media` |
| `bin/` | CLI tạo admin, xoá config cache | ❌ | `create-admin.php` dùng PDO thô — không phụ thuộc tầng Mapper |

**Luật sở hữu bảng:** 1 bảng = 1 Mapper duy nhất; Mapper không join/select bảng của Mapper khác — Service điều phối, gom id theo batch (chống N+1). Bảng Admin cần mà module khác đang sở hữu (vd `services`) → **dùng lại chính class mapper đó** qua container gộp, không sao chép. Full rule: [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md).

## 5. Nơi dev sửa nhiều nhất

1. `module/Admin/src/Controller/` + `Service/` + `Model/` — viết CRUD thật cho các resource còn lại (categories/tags/posts + API đã xong 12/09/2026; services/banners/team batch 12/09; contacts/settings/home-sections + thư viện media batch 13/09; Account FR-14 + Dashboard §3.1 batch 13/09; FR-33 mục manual batch 13/09; kéo thả reorder FR-26/29/30/32/34 batch 14/09 — cùng chuẩn mới; picker ảnh FR-14 đã đóng 13/09 theo selectField 07 §9).
2. `config/module.config.php` từng module — route + DI closure cho Controller/Mapper khi thêm class mới; Service mới thì `extends AppServiceFactory` + `AppInvokableFactory::class` (07 §4).
3. `module/Frontend/src/Service/` + `Model/` — truy vấn phía khách.
4. `module/*/view/` — template `.phtml`.
5. `config/autoload/global.php` & `local.php` — nối DB/SMTP/cache.
6. `data/schema/schema.sql` — đổi bảng/cột (kèm cập nhật `Model/<Entity>/` + docs phân tích).

## Liên kết

- Quy ước tầng CRUD + DI closure: [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md) · Quy tắc const: [`../01-quy-chuan/06-quy-uoc-const.md`](../01-quy-chuan/06-quy-uoc-const.md)
- Kiến trúc & luồng: [03-kien-truc-tong-quan.md](03-kien-truc-tong-quan.md) · Stack: [04-cong-nghe-stack.md](04-cong-nghe-stack.md)
- Quy ước đặt tên: [`../01-quy-chuan/01-quy-uoc-dat-ten.md`](../01-quy-chuan/01-quy-uoc-dat-ten.md) · Mô hình dữ liệu: [`../02-thiet-ke/03-mo-hinh-du-lieu.md`](../02-thiet-ke/03-mo-hinh-du-lieu.md)
