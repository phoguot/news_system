<?php

declare(strict_types=1);

namespace Admin\Service;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Filter\Media\MediaActionFilter;
use Admin\Filter\Media\MediaAltFilter;
use Admin\Filter\Media\MediaUploadFilter;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Media\MediaConst;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\User\UserMapper;
use Application\Factory\AppServiceFactory;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Setting\SettingMapper;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Nghiệp vụ thư viện media (docs §3.10, §5.14; FR-36/37/38).
 * Luồng chuẩn 07 §2: Service chạy Filter trên raw, Mapper trả MediaModel.
 * DI nền 07 §4 (13/09/2026): dependency lấy từ container qua
 * getContainerEntry() — constructor không nhận gì.
 *
 * Upload: kích thước kiểm trước, MIME **thật** phát hiện bằng finfo so với
 * whitelist cấu hình (SVG/PHP giả đuôi ảnh bị loại kể cả khi đổi phần mở rộng),
 * tên file random `bin2hex(random_bytes(16))` — đường dẫn
 * `uploads/YYYY/MM/<random>.<ext>` (ext suy từ MIME, không tin tên gốc).
 * Biến thể thumb/medium/large chuẩn hoá WebP qua Intervention (driver GD), chỉ
 * thu nhỏ; GIF động bỏ qua biến thể vì encoder GD chỉ giữ frame đầu.
 *
 * Xoá (FR-38): chặn khi nội dung khác còn tham chiếu (docs §5.14 — posts ảnh
 * bìa/thu nhỏ/nội dung LIKE path, categories, banners, services, team,
 * users avatar, settings media); file trên đĩa được kiểm tra rồi gỡ từng cái —
 * hỏng bước nào dừng bước đó, bản ghi chỉ xoá khi đĩa sạch.
 */
class MediaService extends AppServiceFactory
{
    /** MIME được phép (khớp whitelist cấu hình app.allowed_mimes) → phần mở rộng ghi đĩa. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** MIME sinh được biến thể WebP — GIF loại vì có thể là ảnh động. */
    private const VARIANTS_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Typed accessor cho các mapper dùng lặp lại trong service (07 §4). */
    private function mediaMapper(): MediaMapper
    {
        /** @var MediaMapper */
        return $this->getContainerEntry(MediaMapper::class);
    }

    private function postsMapper(): PostMapper
    {
        /** @var PostMapper */
        return $this->getContainerEntry(PostMapper::class);
    }

    private function categoriesMapper(): CategoryMapper
    {
        /** @var CategoryMapper */
        return $this->getContainerEntry(CategoryMapper::class);
    }

    private function bannersMapper(): BannerMapper
    {
        /** @var BannerMapper */
        return $this->getContainerEntry(BannerMapper::class);
    }

    private function teamMembersMapper(): TeamMemberMapper
    {
        /** @var TeamMemberMapper */
        return $this->getContainerEntry(TeamMemberMapper::class);
    }

    private function usersMapper(): UserMapper
    {
        /** @var UserMapper */
        return $this->getContainerEntry(UserMapper::class);
    }

    private function servicesMapper(): ServiceMapper
    {
        /** @var ServiceMapper */
        return $this->getContainerEntry(ServiceMapper::class);
    }

    private function settingsMapper(): SettingMapper
    {
        /** @var SettingMapper */
        return $this->getContainerEntry(SettingMapper::class);
    }

    /**
     * Khối `app` trong config/autoload/global.php (upload_dir, upload_max_mb,
     * allowed_mimes, image_variants) — lấy từ entry `Config` đã merge của
     * container. Lưu ý: `ApplicationConfig` CHỈ chứa {modules,
     * module_listener_options} — không có khối `app` (bug latent của closure
     * cũ, sửa 13/09/2026).
     *
     * @return array<array-key, mixed>
     */
    private function appConfig(): array
    {
        /** @var array<array-key, mixed>|\Laminas\Config\Config $config */
        $config = $this->getContainer()->get('Config');
        /** @var array<array-key, mixed> $all */
        $all = is_array($config) ? $config : $config->toArray();

        /** @var array<array-key, mixed> $app */
        $app = is_array($all['app'] ?? null) ? $all['app'] : [];

        return $app;
    }

    /**
     * @return list<MediaModel>
     */
    public function listAll(): array
    {
        return $this->mediaMapper()->listAll();
    }

    public function findOrFail(int $id): MediaModel
    {
        $model = $this->mediaMapper()->findById($id);
        if ($model === null) {
            throw NotFoundException::forEntity('media', $id);
        }

        return $model;
    }

    /**
     * Entry điểm form tải file lên (controller đưa thẳng `getFiles()` vào).
     * Service tự đếm số mục upload còn hiệu lực ghép vào raw rồi chạy
     * MediaUploadFilter (csrf + "có file"). Mỗi file xử lý độc lập: lỗi file nào
     * ghi nhận riêng file đó, các file còn lại vẫn lưu.
     *
     * @param array<array-key, mixed> $raw        POST thô
     * @param array<array-key, mixed> $files      getFiles() của request
     * @param int|null                $uploadedBy id tài khoản đăng nhập
     *
     * @return array{uploaded: int, errors: list<string>}
     *
     * @throws ValidationException khi thiếu csrf / không có file nào
     */
    public function uploadForm(array $raw, array $files, ?int $uploadedBy): array
    {
        $items            = $this->extractUploads($files);
        $raw['fileCount'] = (string) count($items);

        $filter = new MediaUploadFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $maxBytes = $this->maxBytes();
        $allowed  = $this->allowedMimes();
        $uploaded = 0;
        $errors   = [];
        foreach ($items as $item) {
            try {
                $this->storeUpload($item, $maxBytes, $allowed, $uploadedBy);
                $uploaded++;
            } catch (ValidationException $e) {
                $errors[] = $item['name'] . ': ' . implode(' ', $e->getErrors());
            }
        }

        return ['uploaded' => $uploaded, 'errors' => $errors];
    }

    /**
     * Sửa alt text: chạy MediaAltFilter (id + altText + csrf) rồi ghi.
     *
     * @param array<array-key, mixed> $raw
     *
     * @throws ValidationException mang lỗi từng trường
     */
    public function saveAltForm(array $raw): void
    {
        $filter = new MediaAltFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            throw new ValidationException($filter->fieldErrors());
        }

        $id = $filter->idValue();
        if ($id === null) {
            throw new ValidationException(['id' => MediaConst::ERROR_NOT_FOUND]);
        }

        $this->findOrFail($id);
        $this->mediaMapper()->updateAltText($id, $filter->altValue());
    }

    /**
     * Xoá từ form: kiểm csrf/id, chặn nếu còn được dùng (FR-38), gỡ file rồi mới
     * xoá dòng. Trả query flag PRG: deleted | blocked | csrf | notfound.
     *
     * @param array<array-key, mixed> $raw phải có `id` (controller ghép từ route) + `csrf`
     */
    public function deleteForm(array $raw): string
    {
        $filter = new MediaActionFilter();
        $filter->setData($raw);
        if (! $filter->isValid()) {
            $errors = $filter->fieldErrors();

            return isset($errors['csrf']) ? MediaConst::FLAG_CSRF : MediaConst::FLAG_NOT_FOUND;
        }

        $id = $filter->idValue();
        if ($id === null) {
            return MediaConst::FLAG_NOT_FOUND;
        }

        try {
            $this->delete($id);

            return MediaConst::FLAG_DELETED;
        } catch (NotFoundException) {
            return MediaConst::FLAG_NOT_FOUND;
        } catch (ConflictException) {
            return MediaConst::FLAG_BLOCKED;
        }
    }

    /**
     * Xoá media: chặn khi còn tham chiếu (§5.14); đĩa phải gỡ sạch file gốc +
     * biến thể rồi mới bỏ dòng DB.
     *
     * @throws NotFoundException id không tồn tại
     * @throws ConflictException  còn được dùng hoặc không gỡ được file trên đĩa
     */
    public function delete(int $id): void
    {
        $model = $this->findOrFail($id);

        if ($this->hasUsages($this->collectUsages($model))) {
            throw new ConflictException([MediaConst::ERROR_IN_USE]);
        }

        $paths = array_merge([$model->path], array_values($model->variantsArray()));
        foreach ($paths as $rel) {
            if (! is_file($this->uploadsPath($rel))) {
                throw new ConflictException([MediaConst::ERROR_FILE_DELETE]);
            }
        }

        foreach ($paths as $rel) {
            if (! @unlink($this->uploadsPath($rel))) {
                throw new ConflictException([MediaConst::ERROR_FILE_DELETE]);
            }
        }

        $this->mediaMapper()->delete($id);
    }

    /**
     * Mọi nơi đang tham chiếu một media (docs §5.14) — trang /admin/media/usages.
     *
     * @return array{posts: list<int>, categories: list<int>, banners: list<int>,
     *               services: list<int>, teamMembers: list<int>, users: int, settings: list<string>}
     */
    public function usages(int $mediaId): array
    {
        return $this->collectUsages($this->findOrFail($mediaId));
    }

    /** Hash CSRF cho form tải file lên. */
    public function uploadFormCsrfHash(): string
    {
        return (new MediaUploadFilter())->csrfHash();
    }

    /** Hash CSRF cho form sửa alt. */
    public function altFormCsrfHash(): string
    {
        return (new MediaAltFilter())->csrfHash();
    }

    /** Hash CSRF cho form xoá trên danh sách. */
    public function deleteFormCsrfHash(): string
    {
        return (new MediaActionFilter())->csrfHash();
    }

    /**
     * @return array{posts: list<int>, categories: list<int>, banners: list<int>,
     *               services: list<int>, teamMembers: list<int>, users: int, settings: list<string>}
     */
    private function collectUsages(MediaModel $model): array
    {
        $posts   = $this->postsMapper();
        $postIds = array_merge(
            $posts->findIdsByMedia($model->id),
            $posts->findIdsByContentPath($model->path)
        );

        return [
            'posts'       => array_values(array_unique(array_map('intval', $postIds))),
            'categories'  => $this->categoriesMapper()->findIdsByMedia($model->id),
            'banners'     => $this->bannersMapper()->findIdsByMedia($model->id),
            'services'    => $this->servicesMapper()->findIdsByMedia($model->id),
            'teamMembers' => $this->teamMembersMapper()->findIdsByMedia($model->id),
            'users'       => $this->usersMapper()->countByMedia($model->id),
            'settings'    => $this->settingsMapper()->findKeysByMedia($model->id),
        ];
    }

    /**
     * @param array{posts: list<int>, categories: list<int>, banners: list<int>,
     *              services: list<int>, teamMembers: list<int>, users: int, settings: list<string>} $usages
     */
    private function hasUsages(array $usages): bool
    {
        return $usages['posts'] !== []
            || $usages['categories'] !== []
            || $usages['banners'] !== []
            || $usages['services'] !== []
            || $usages['teamMembers'] !== []
            || $usages['users'] > 0
            || $usages['settings'] !== [];
    }

    /**
     * Chuyển `getFiles()` (dạng cột: name[]/tmp_name[]/…) thành danh sách mục
     * upload còn hiệu lực; bỏ mục lỗi PHP upload hoặc tên/tmp rỗng.
     *
     * @param array<array-key, mixed> $files
     *
     * @return list<array{name: string, tmp_name: string, size: int}>
     */
    private function extractUploads(array $files): array
    {
        /** @var mixed $set */
        $set = $files['files'] ?? null;
        if (! is_array($set) || ! isset($set['name'])) {
            return [];
        }

        $names    = (array) $set['name'];
        $tmpNames = (array) ($set['tmp_name'] ?? []);
        $errors   = (array) ($set['error'] ?? []);
        $sizes    = (array) ($set['size'] ?? []);

        $items = [];
        foreach (array_keys($names) as $offset) {
            $name    = $names[$offset] ?? null;
            $tmpName = $tmpNames[$offset] ?? null;
            $error   = (int) ($errors[$offset] ?? UPLOAD_ERR_NO_FILE);
            if (! is_string($name) || $name === '' || ! is_string($tmpName) || $tmpName === '') {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $items[] = [
                'name'     => basename($name),
                'tmp_name' => $tmpName,
                'size'     => (int) ($sizes[$offset] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Lưu một file: dung lượng → MIME thật (finfo) → đường dẫn random → move →
     * kích thước thật → biến thể → ghi dòng `media`.
     *
     * @param array{name: string, tmp_name: string, size: int} $item
     * @param list<string>                                      $allowed
     * @param int|null                                          $uploadedBy
     *
     * @throws ValidationException từng bước từ chối (thông báo gắn vào trường `files`)
     */
    private function storeUpload(array $item, int $maxBytes, array $allowed, ?int $uploadedBy): void
    {
        if ($item['size'] <= 0 || $item['size'] > $maxBytes) {
            $mb      = intdiv($maxBytes, 1024 * 1024);
            $message = $item['size'] <= 0
                ? MediaConst::ERROR_NO_FILE
                : sprintf(MediaConst::ERROR_TOO_LARGE, $mb);

            throw new ValidationException(['files' => $message]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($item['tmp_name']);
        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            $detected = is_string($mime) ? $mime : 'không xác định';

            throw new ValidationException(['files' => sprintf(MediaConst::ERROR_TYPE, $detected)]);
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $stem      = bin2hex(random_bytes(16));
        $base      = date('Y/m') . '/' . $stem;
        $relative  = $base . '.' . $extension;
        $absolute  = $this->uploadsPath($relative);

        $targetDirectory = dirname($absolute);
        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
            throw new ValidationException(['files' => MediaConst::ERROR_MOVE]);
        }

        $moved = is_uploaded_file($item['tmp_name'])
            ? move_uploaded_file($item['tmp_name'], $absolute)
            : rename($item['tmp_name'], $absolute);
        if (! $moved) {
            throw new ValidationException(['files' => MediaConst::ERROR_MOVE]);
        }

        $info     = @getimagesize($absolute);
        $variants = [];
        if (in_array($mime, self::VARIANTS_MIMES, true) && is_array($info)) {
            try {
                $variants = $this->makeVariants($absolute, $base);
            } catch (Throwable) {
                // Biến thể chỉ để tối ưu — hỏng bước này vẫn giữ ảnh gốc đã lưu.
                $variants = [];
            }
        }

        $stored = filesize($absolute);

        $this->mediaMapper()->insert([
            'disk'         => MediaConst::DISK_LOCAL,
            'path'         => $relative,
            'originalName' => mb_substr($item['name'], 0, MediaConst::MAX_LENGTH_ORIGINAL_NAME),
            'mimeType'     => $mime,
            'sizeBytes'    => $stored === false ? $item['size'] : $stored,
            'width'        => is_array($info) ? $info[0] : null,
            'height'       => is_array($info) ? $info[1] : null,
            'altText'      => null,
            'variants'     => MediaModel::encodeVariants($variants),
            'uploadedBy'   => $uploadedBy,
        ]);
    }

    /**
     * Sinh WebP thumb/medium/large từ ảnh gốc theo cấu hình app.image_variants;
     * chỉ thu nhỏ — ảnh gốc đã hẹp hơn ngưỡng thì bỏ qua biến thể đó.
     *
     * @param string $absolute đường dẫn tuyệt đối ảnh gốc trên đĩa
     * @param string $base     đường dẫn tương đối chưa có phần mở rộng
     *
     * @return array<string, string> tên biến thể => path tương đối
     */
    private function makeVariants(string $absolute, string $base): array
    {
        $app = $this->appConfig();
        /** @var mixed $configured */
        $configured = $app['image_variants'] ?? [];
        if (! is_array($configured)) {
            return [];
        }

        $size = getimagesize($absolute);
        if ($size === false) {
            return [];
        }

        $manager  = new ImageManager(new Driver());
        $variants = [];
        /** @psalm-suppress MixedAssignment — mảng cấu hình trộn kiểu từ config */
        foreach ($configured as $name => $targetWidth) {
            $targetWidth = (int) $targetWidth;
            if ($targetWidth <= 0 || $targetWidth >= $size[0] || ! is_string($name)) {
                continue;
            }

            $variantRelative = $base . '-' . $name . '.webp';
            $image           = $manager->read($absolute);
            $image->scaleDown(width: $targetWidth);
            $saved = file_put_contents($this->uploadsPath($variantRelative), (string) $image->toWebp());
            if ($saved !== false) {
                $variants[$name] = $variantRelative;
            }
        }

        return $variants;
    }

    /** Thư mục gốc uploads — đường dẫn trong cấu hình tương đối so với gốc dự án. */
    private function uploadsRoot(): string
    {
        $app = $this->appConfig();
        /** @var mixed $configured */
        $configured = $app['upload_dir'] ?? 'public/uploads';
        $directory  = is_string($configured) && $configured !== '' ? $configured : 'public/uploads';

        if (preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $directory) === 1) {
            return rtrim($directory, '\\/');
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    }

    /** Đường dẫn tuyệt đối trên đĩa cho một `path` tương đối trong bảng media. */
    private function uploadsPath(string $relative): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

        return $this->uploadsRoot() . DIRECTORY_SEPARATOR . trim($normalized, '\\/');
    }

    private function maxBytes(): int
    {
        $app = $this->appConfig();
        /** @var mixed $maxMb */
        $maxMb = $app['upload_max_mb'] ?? 5;

        return max(1, (int) $maxMb) * 1024 * 1024;
    }

    /**
     * Whitelist MIME từ cấu hình, giao với các kiểu mapper biết xử lý —
     * cấu hình thêm MIME lạ sẽ bị bỏ qua thay vì upload không có phần mở rộng.
     *
     * @return list<string>
     */
    private function allowedMimes(): array
    {
        $app = $this->appConfig();
        /** @var mixed $configured */
        $configured = $app['allowed_mimes'] ?? [];
        if (! is_array($configured) || $configured === []) {
            return array_keys(self::MIME_EXTENSIONS);
        }

        $allowed = [];
        /** @psalm-suppress MixedAssignment — mảng cấu hình trộn kiểu từ config */
        foreach ($configured as $mime) {
            if (is_string($mime) && isset(self::MIME_EXTENSIONS[$mime])) {
                $allowed[] = $mime;
            }
        }

        return $allowed === [] ? array_keys(self::MIME_EXTENSIONS) : $allowed;
    }
}
