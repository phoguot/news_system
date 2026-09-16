# Xác thực & phân quyền

> **Mô hình đã chốt (docs v1.1 + §1.3):** hệ thống có **đúng 1 tài khoản quản trị**, **không** bảng `roles`/`permissions`, **không** phân quyền theo vai trò, **không** màn hình quản lý danh sách user. "Phân quyền" còn lại đúng một câu hỏi: *đã đăng nhập hay chưa*.
> ✅ **Trạng thái (12/09/2026):** Luồng đăng nhập/đăng xuất + guard `/admin` & `/api/admin` + khoá 5 lần sai **đã triển khai và smoke-test HTTP trực tiếp** (bối cảnh `php -S 127.0.0.1:8087 -t public`). **Form liên hệ** cũng đã xong CSRF + captcha + honeypot + rate-limit 429 (smoke đủ nhánh). Phần còn để ngỏ: quên mật khẩu.

## Đăng nhập

| Khía cạnh | Thực tế trong repo |
|---|---|
| Bảng | `users` — `id`, `fullName`, `email` (`UNIQUE uq_users_email`), `username VARCHAR(50) NULL` (`UNIQUE uq_users_username` — từ 13/09/2026, cho phép NULL: tài khoản chưa đặt username chỉ đăng nhập bằng email; MySQL cho nhiều NULL trong unique), `passwordHash VARCHAR(255)`, `phone`, `avatarMediaId`, `failedLoginCount TINYINT UNSIGNED DEFAULT 0`, `lockedUntil DATETIME NULL`, `lastLoginAt` (`data/schema/schema.sql`; incremental: `data/schema/2026-09-13-them-cot-username.sql` — chạy trước khi deploy code) |
| Số dòng cho phép | Tối đa **1**. `bin/create-admin.php` kiểm `SELECT COUNT(*) FROM users` và **từ chối** nếu ≥ 1 (`Refusing: users table already has N row(s)`) |
| Tạo tài khoản | `php bin/create-admin.php --email=... --name=... [--username=...] [--phone=...]` — mật khẩu nhập tương tác, **tối thiểu 8 ký tự**, có bước xác nhận lại; trên Windows mật khẩu **không ẩn** (nhánh `DIRECTORY_SEPARATOR === '\\'` dùng `fgets(STDIN)`, không gọi được `stty`); `--username` phải khớp `UserConst::USERNAME_PATTERN` (3–32 ký tự thường, bắt đầu bằng chữ) |
| Băm mật khẩu | `password_hash($password, PASSWORD_DEFAULT)` trong `bin/create-admin.php` → **bcrypt** trên PHP 8.1–8.3. docs §3.11 ghi "Argon2id hoặc bcrypt" — **thực tế đang là bcrypt**; muốn Argon2id phải đổi hằng số khi viết luồng đổi mật khẩu |
| Verify khi đăng nhập | ✅ `Admin\Service\AdminAuthService::login()` — đọc user bằng **email hoặc username** qua `Admin\Model\User\UserMapper::getUserByIdentifier()` (chuỗi chứa `@` → tra `email`, không → tra `username`; regex LoginFilter đảm bảo username không bao giờ có `@`), đối chiếu `password_verify($password, $user->passwordHash)` |
| Route | `/admin/login` → `Admin\Controller\AuthController::loginAction`; `/admin/logout` → `logoutAction()` (`module/Admin/config/module.config.php`) |
| Hành vi | `loginAction()`: đã đăng nhập → 302 `admin/dashboard`; POST → gọi `AdminAuthService::attemptLogin(raw)` — **service tạo `LoginFilter` mới mỗi request** (identity + password + **CSRF**) rồi chạy `login()`; thành công → 302 dashboard; thất bại → render lại `login.phtml` (terminal) kèm lỗi + `csrfHash()`. `logoutAction()`: `clearIdentity()` + regenerate session → 302 login. Controller KHÔNG `new` Filter (chuẩn 07 §2) |
| Chống dò tài khoản | Email/username không tồn tại và sai mật khẩu đều trả **cùng một thông điệp** `AdminConst::ERROR_INVALID_CREDENTIALS` ("Email, tên đăng nhập hoặc mật khẩu không đúng.") — không tiết lộ định danh nào có trong hệ thống |
| Session fixation | Khi thành công và khi logout: `session_regenerate_id(true)` nếu session đang active |
| Identity | `array{id: int, email: string, fullName: string, username: string|null}` lưu trong `Laminas\Authentication\Storage\Session` namespace `VANLANG_ADMIN_AUTH` (`Admin\Constant\AdminConst::AUTH_SESSION_NAMESPACE`); layout admin hiện `fullName (email)` + nút Đăng xuất; `AccountService::profileForm()` đổi hồ sơ xong gọi `refreshIdentity(email, fullName, username)` đồng bộ lại storage |
| Thư viện | `laminas/laminas-authentication` 2.19 — `AuthenticationService` + `Session` storage, đăng ký bằng **closure DI** trong `service_manager.factories` của `module/Admin/config/module.config.php` |
| Quên mật khẩu | ✅ đã triển khai 14/09 (FR-13) — `Admin\Service\PasswordResetService` + `PasswordResetController`, route **page-level** `/admin/forgot-password` + `/admin/reset-password` (`[/:action]` Segment + PRG; bản API `/api/admin/auth/forgot-password` §6.2 vẫn chưa có — lệch có chủ đích theo hướng page-first); ràng buộc đã xác minh trong code: chỉ lưu **hash SHA-256** (`tokenHash CHAR(64)`), hạn `PasswordResetTokenConst::EXPIRY_MINUTES` = 60', dùng 1 lần (`usedAt`), phát token mới trước hết `deleteByUserId` xoá token cũ cùng `userId` (docs §4.4.1); chống dò email qua `MailService` |
| 2FA/TOTP | docs §3.11 để ngỏ "tuỳ chọn mở rộng" → **chưa triển khai** |

## Phiên làm việc (session) — đã cấu hình thật

Nguồn: `config/autoload/global.php` — laminas-session v2 đọc **3 key tách biệt** (không phải key `session` lồng nhau — bản cũ đó chưa factory nào tiêu thụ):

| Key config | Nội dung | Ý nghĩa |
|---|---|---|
| `session_config` | `name=VANLANG_SESS`, `cookie_httponly=true`, `cookie_secure=false`, `cookie_samesite=Lax`, `gc_maxlifetime=7200`, `remember_me_seconds=7200` | Do `Laminas\Session\Service\SessionConfigFactory` tiêu thụ (mảng **flat**, mỗi khoá là một option của `SessionConfig::setOptions`) |
| `session_storage` | `type=SessionArrayStorage` | `StorageFactory` yêu cầu key `type` |
| `session_manager` | `enable_default_container_manager=true` + `validators=[RemoteAddr, HttpUserAgent]` | `SessionManagerFactory`; flag default-manager **đã inject manager vào `Container::setDefaultManager` ngay khi tạo service** |

⚠ Prod **phải** bật `cookie_secure=true` khi có HTTPS (docs §7.3) → override `session_config` trong `local.php`, xem [`01-cau-hinh-deploy.md`](01-cau-hinh-deploy.md). Session **bị vô hiệu** khi IP hoặc User-Agent đổi giữa chừng (chống hijack cơ bản).

## Guard `/admin` và `/api/admin` — ✅ đã triển khai

`Admin\Service\AuthGuard::onDispatch(MvcEvent)` — gắn ở **priority 100** của `MvcEvent::EVENT_DISPATCH`:

| Hành vi | Kết quả (kiểm chứng HTTP 12/09/2026) |
|---|---|
| Route `admin*` chưa đăng nhập, không phải route công khai, **không** bắt đầu bằng `admin-api` | **302** → `/admin/login` |
| Route `admin-api*` chưa đăng nhập | **401** + envelope `{"success":false,"data":null,"meta":null,"errors":{"auth":"Chưa đăng nhập hoặc phiên đã hết hạn."}}` (`Content-Type: application/json`) |
| Route công khai (`PUBLIC_ROUTES` = `admin/login`, `admin/logout`) | Cho qua |
| Đã đăng nhập | `decorateAdminLayout()`: set template `layout/admin` + biến `adminIdentity` cho view |
| Route không bắt đầu bằng `admin` | Listener trả `null`, không can thiệp |

**Hai bẫy kỹ thuật đã xử lý (đừng rút gọn khi refactor):**

1. **Service `EventManager` NON-SHARED** (`Laminas\Mvc\Service\ServiceManagerConfig`: `'EventManager' => false`) — instance mà `ModuleManager` nhận ở `Module::init()` **KHÁC** instance `Application` dùng khi dispatch; `attach()` trực tiếp lên nó không bao giờ chạy. Phải attach vào **SharedEventManager** (service shared, được gắn vào mọi EventManager qua `EventManagerFactory`) với identifier `Laminas\Mvc\Application::class`: xem `Admin\Module::init()`.
2. **`Container::getDefaultManager()` tự tạo SessionManager "trần"** (PHPSESSID, thiếu validator) và cache nó nếu chưa set. Listener trong `Module::init()` gọi `SessionContainer::setDefaultManager($services->get(SessionManager::class))` **trước mọi thao tác session** (idempotent), đảm bảo CSRF token và identity nằm trong session `VANLANG_SESS` đúng cấu hình.

`Application::dispatch()` dùng `triggerEventUntil($r => $r instanceof ResponseInterface)` — listener trả `Response` sẽ **dừng chain** và trở thành response luôn; guard nhân đó để 302/401 trước khi controller kịp chạy.

## Chống lạm dụng & CSRF

| Biện pháp (docs §7.3, §3.9, §3.11) | Hiện trạng |
|---|---|
| Khoá tài khoản 15 phút sau 5 lần sai | ✅ `AdminAuthService`: mỗi lần sai `failedLoginCount++`; đủ `AdminConst::MAX_FAILED_LOGINS=5` → set `lockedUntil = now + LOCKOUT_MINUTES=15` (UTC), thông báo `ERROR_JUST_LOCKED`; tài khoản đang khoá → từ chối **trước khi** check mật khẩu với `ERROR_ACCOUNT_LOCKED` ("Thử lại sau %d phút"); đăng nhập thành công → reset `failedLoginCount=0`, `lockedUntil=NULL`, ghi `lastLoginAt` (`UserMapper::registerSuccessfulLogin`) |
| Rate limit form liên hệ 3 lần / IP / 10 phút → **429** | ✅ `Frontend\Service\ContactService` + `Frontend\Model\Contact\ContactMapper::countRecentSubmissionsFromIp()` (`INET6_ATON(:ip)` + `createdAt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)`, tận dụng `idx_contact_submissions_ip_created`); `ContactController::submitAction()` set status 429 |
| CSRF token cho form | ✅ Form đăng nhập: validator `Csrf` (tên input `csrf`) gắn trong `Admin\Filter\Auth\LoginFilter`; view render `<input type="hidden" name="csrf">` bằng `csrfHash()`. ✅ Form liên hệ: cùng mẫu với `Frontend\Filter\Contact\ContactSaveFilter` (tokenList lưu session container `VANLANG_SESS` — cần `SessionBootstrap` để frontend không bootstrap manager trần PHPSESSID) |
| Honeypot ẩn cho form liên hệ | ✅ Trường `website` (`Frontend\Model\Contact\ContactConst::HONEYPOT_FIELD`) render trong `div` ẩn tuyệt đối; bot điền vào → `ContactService` trả OK giả, không lưu, không mail |
| Validate server-side toàn bộ form | ✅ Đăng nhập: `LoginFilter` (InputFilter, identity + password + CSRF). Liên hệ: `ContactSaveFilter`. Các form CRUD admin đã qua `AppInputFilter` nền (CSRF + field errors). |
| Prepared statement mọi query | ✅ `UserMapper`/`ContactMapper`/`SettingMapper`/`ServiceMapper` qua `Laminas\Db\Sql` + `prepareStatementForSqlObject`; `bin/create-admin.php` dùng `prepare`/`execute` — đạt |
| Escape output trong template | `login.phtml` + `layout/admin.phtml` dùng `escapeHtml`; mọi view mới phải escape dữ liệu động khi in ra (docs §7.3) |

## Phân quyền: không có — và chủ đích không có

| Việc | Xử lý |
|---|---|
| Bảng `roles`, `permissions`, `role_permissions` | **Không tồn tại** — đã bỏ từ docs v1.1 (ghi chú đầu docs §4.4.1) |
| Bảng `activity_logs` | **Không tồn tại** — 1 người vận hành nên không truy vết (§1.3) |
| "Tài khoản của tôi" | ✅ Đã triển khai (batch 5 + 13/09/2026): `AccountService` + `Filter/Account/Account{Profile,Password}Filter` — sửa họ tên/email/**username (tuỳ chọn, unique, pattern `UserConst::USERNAME_PATTERN`)**/SĐT/avatar + đổi mật khẩu trực tiếp, PRG flag, refresh identity sau lưu |
| Nhân sự ↔ tài khoản | `team_members.userId` liên kết **tuỳ chọn** hồ sơ nhân sự với tài khoản duy nhất (`UNIQUE uq_team_members_user`, docs §3.8/§4.4.5) → **chưa triển khai** |
| Nếu sau này cần nhiều người dùng | docs §9 mục 9: phải thêm lại `roles`/`permissions` + `activity_logs` + kiểm tra quyền sở hữu ở API, và **bỏ** ràng buộc "1 dòng `users`" trong `bin/create-admin.php` |

## File liên quan (luồng auth)

> **Đường dẫn sau refactor 12/09/2026** theo chuẩn [`../01-quy-chuan/07-crud-convention.md`](../01-quy-chuan/07-crud-convention.md) — không còn `Table/`/`Form/`/`Guard/`/`*Factory.php` (bản đồ: [`../00-tong-quan/05-cau-truc-thu-muc.md`](../00-tong-quan/05-cau-truc-thu-muc.md) §3).

```
module/Admin/src/
├── Constant/AdminConst.php            # namespace session, MAX_FAILED_LOGINS=5, LOCKOUT_MINUTES=15, thông báo lỗi
├── Model/User/UserMapper.php          # getUserByIdentifier (email|username) / isUsernameTaken / registerFailedLogin / registerSuccessfulLogin
├── Service/AdminAuthService.php       # login/logout/hasIdentity/getIdentity + lockout
├── Service/AuthGuard.php              # 302 trang / 401 JSON / decorate layout
├── Filter/Auth/LoginFilter.php        # InputFilter: identity (email|username) + password + Csrf (csrfHash() cho view)
├── Controller/AuthController.php      # loginAction/logoutAction — mỏng; LoginFilter chạy trong AdminAuthService::attemptLogin()
└── Module.php                         # init(): attach guard qua SharedEventManager

DI: service_manager + controllers khai báo closure inline trong module/Admin/config/module.config.php.
```

Unit test: `module/Admin/test/Service/AdminAuthServiceTest.php` (13 test: thành công bằng email, thành công bằng username, sai mk, lần 5 khoá, đang khoá thì từ chối trước verify, email lạ, username lạ — cùng thông điệp, tài khoản không username, logout, attemptLogin raw email/username/block ký tự lạ).

## Việc còn lại trước go-live

- [x] `loginAction` verify thật (`password_verify` + đọc `users`), `logoutAction` clear identity.
- [x] Guard chặn `/admin/*` và `/api/admin/*` (401 cho JSON, redirect cho HTML).
- [x] CSRF cho form đăng nhập **và** form liên hệ (`LoginFilter`/`ContactSaveFilter` + validator `Csrf`).
- [x] Khoá 15 phút sau 5 lần sai (`failedLoginCount` / `lockedUntil`).
- [x] Rate limit `POST /api/contact`: 3 lần / IP / 10 phút → 429 (đã smoke: POST thứ 4 trả 429, không thêm dòng).
- [ ] Bật `session_config.cookie_secure = true` khi prod chạy HTTPS.
- [ ] Quên mật khẩu qua `password_reset_tokens` (route + mail + token hash một lần dùng).
- [x] Đổi mật khẩu admin qua màn hình `/admin/account` (`AccountService::passwordForm`); `bin/create-admin.php` nằm ngoài docroot nên không thể gọi qua HTTP.

Checklist tổng: [`05-checklist-go-live.md`](05-checklist-go-live.md). Liên quan 401/lỗi: [`../03-tich-hop/02-xu-ly-loi.md`](../03-tich-hop/02-xu-ly-loi.md). Test thủ công luồng auth: [`../04-huong-dan/03-huong-dan-test.md`](../04-huong-dan/03-huong-dan-test.md).
