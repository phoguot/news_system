<?php

declare(strict_types=1);

namespace AdminTest\Service;

use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Banner\BannerMapper;
use Admin\Model\Category\CategoryMapper;
use Admin\Model\Media\MediaConst;
use Admin\Model\Media\MediaMapper;
use Admin\Model\Media\MediaModel;
use Admin\Model\Post\PostMapper;
use Admin\Model\TeamMember\TeamMemberMapper;
use Admin\Model\User\UserMapper;
use Admin\Service\MediaService;
use ApplicationTest\Helper\TestContainer;
use Frontend\Model\Service\ServiceMapper;
use Frontend\Model\Setting\SettingMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Thư viện media (FR-36/37/38): upload qua finfo thật + ảnh GD thật trong
 * var/media-test (dọn sạch ở tearDown); chặn xoá theo usages docs §5.14 mock
 * toàn bộ mapper bảng khác.
 */
final class MediaServiceTest extends TestCase
{
    private MediaMapper&MockObject $media;
    private PostMapper&MockObject $posts;
    private CategoryMapper&MockObject $categories;
    private BannerMapper&MockObject $banners;
    private TeamMemberMapper&MockObject $teamMembers;
    private UserMapper&MockObject $users;
    private ServiceMapper&MockObject $services;
    private SettingMapper&MockObject $settings;
    private MediaService $service;
    private string $uploadDir;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->uploadDir = dirname(__DIR__, 3) . '/var/media-test';
        if (! is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        $this->media       = $this->createMock(MediaMapper::class);
        $this->posts       = $this->createMock(PostMapper::class);
        $this->categories  = $this->createMock(CategoryMapper::class);
        $this->banners     = $this->createMock(BannerMapper::class);
        $this->teamMembers = $this->createMock(TeamMemberMapper::class);
        $this->users       = $this->createMock(UserMapper::class);
        $this->services    = $this->createMock(ServiceMapper::class);
        $this->settings    = $this->createMock(SettingMapper::class);

        $this->service = (new MediaService())->setContainer(new TestContainer([
            MediaMapper::class      => $this->media,
            PostMapper::class       => $this->posts,
            CategoryMapper::class   => $this->categories,
            BannerMapper::class     => $this->banners,
            TeamMemberMapper::class => $this->teamMembers,
            UserMapper::class       => $this->users,
            ServiceMapper::class    => $this->services,
            SettingMapper::class    => $this->settings,
            'Config' => [
                'app' => [
                    'upload_dir'     => $this->uploadDir,
                    'upload_max_mb'  => 1,
                    'allowed_mimes'  => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                    'image_variants' => ['thumb' => 100, 'medium' => 400],
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->rrmdir($this->uploadDir);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        /** @var list<string> $children */
        $one      = glob($dir . '/*');
        $two      = glob($dir . '/.*');
        $children = array_merge(is_array($one) ? $one : [], is_array($two) ? $two : []);
        foreach ($children as $child) {
            if (basename($child) === '.' || basename($child) === '..') {
                continue;
            }

            if (is_dir($child)) {
                $this->rrmdir($child);
                @rmdir($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($dir);
    }

    /** Ảnh PNG thật (GD) — trả [đường dẫn tmp, kích thước]. */
    private function makePng(int $width = 320, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        $path  = $this->uploadDir . '/src-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);
        imagedestroy($image);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function makeGif(): string
    {
        $image = imagecreatetruecolor(320, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 40, 50));
        $path  = $this->uploadDir . '/src-' . bin2hex(random_bytes(4)) . '.gif';
        imagegif($image, $path);
        imagedestroy($image);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function writeTmp(string $content, string $suffix = '.png'): string
    {
        $path = $this->uploadDir . '/fake-' . bin2hex(random_bytes(4)) . $suffix;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * Mảng getFiles() một mục upload hiệu lực.
     *
     * @return array<array-key, mixed>
     */
    private function files(string $tmpPath, string $name, ?int $size = null): array
    {
        return ['files' => [
            'name'     => [$name],
            'tmp_name' => [$tmpPath],
            'type'     => ['application/octet-stream'],
            'error'    => [UPLOAD_ERR_OK],
            'size'     => [$size ?? (int) filesize($tmpPath)],
        ]];
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private function raw(array $overrides = []): array
    {
        return $overrides + ['csrf' => $this->service->uploadFormCsrfHash()];
    }

    private function deleteRaw(int $id): array
    {
        return ['id' => (string) $id, 'csrf' => $this->service->deleteFormCsrfHash()];
    }

    /**
     * @param array<array-key, mixed> $overrides
     */
    private function model(array $overrides = []): MediaModel
    {
        return MediaModel::fromRow($overrides + [
            'id'           => 5,
            'path'         => '2026/09/abc.png',
            'originalName' => 'abc.png',
            'mimeType'     => 'image/png',
            'sizeBytes'    => 1000,
        ]);
    }

    public function testUploadStoresRandomPathWithThumbVariantOnly(): void
    {
        $captured = null;
        $this->media->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 77;
            }
        );

        $result = $this->service->uploadForm($this->raw(), $this->files($this->makePng(), 'banner.png'), 7);

        self::assertSame(1, $result['uploaded']);
        self::assertSame([], $result['errors']);
        self::assertIsArray($captured);
        $storedPath = (string) $captured['path'];
        self::assertSame(MediaConst::DISK_LOCAL, $captured['disk']);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.png$#', $storedPath);
        self::assertSame('banner.png', $captured['originalName']);
        self::assertSame('image/png', $captured['mimeType']);
        self::assertSame(320, $captured['width']);
        self::assertSame(200, $captured['height']);
        self::assertSame(7, $captured['uploadedBy']);
        self::assertIsInt($captured['sizeBytes']);

        /** @var array<string, string> $variants */
        $variants = json_decode((string) $captured['variants'], true);
        self::assertSame(['thumb'], array_keys($variants));
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}-thumb\.webp$#', $variants['thumb']);
        self::assertFileExists($this->uploadDir . '/' . $storedPath);
        self::assertFileExists($this->uploadDir . '/' . $variants['thumb']);
        self::assertStringStartsWith('RIFF', (string) file_get_contents($this->uploadDir . '/' . $variants['thumb']));
    }

    public function testUploadSkipsVariantsForGif(): void
    {
        $captured = null;
        $this->media->method('insert')->willReturnCallback(
            static function (array $values) use (&$captured): int {
                $captured = $values;

                return 78;
            }
        );

        $this->service->uploadForm($this->raw(), $this->files($this->makeGif(), 'logo.gif'), null);

        self::assertIsArray($captured);
        self::assertSame('image/gif', $captured['mimeType']);
        self::assertNull($captured['variants']);
    }

    public function testUploadRejectsOversizedBeforeMime(): void
    {
        $this->media->expects(self::never())->method('insert');
        $tmp = $this->makePng();

        $result = $this->service->uploadForm($this->raw(), $this->files($tmp, 'big.png', 2_000_000), 7);

        self::assertSame(0, $result['uploaded']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('big.png', $result['errors'][0]);
        self::assertStringContainsString('1 MB', $result['errors'][0]);
        self::assertFileExists($tmp);
    }

    public function testUploadRejectsPhpDisguisedAsPng(): void
    {
        $this->media->expects(self::never())->method('insert');
        $tmp = $this->writeTmp("<?php echo 'x';\n");

        $result = $this->service->uploadForm($this->raw(), $this->files($tmp, 'shell.png'), 7);

        self::assertSame(0, $result['uploaded']);
        self::assertStringContainsString('shell.png', $result['errors'][0]);
        self::assertStringContainsString('text/x-php', $result['errors'][0]);
    }

    public function testUploadRejectsSvgRegardlessOfExtension(): void
    {
        $this->media->expects(self::never())->method('insert');
        $tmp = $this->writeTmp('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');

        $result = $this->service->uploadForm($this->raw(), $this->files($tmp, 'icon.png'), 7);

        self::assertSame(0, $result['uploaded']);
        self::assertStringContainsString('image/svg+xml', $result['errors'][0]);
    }

    public function testUploadThrowsOnBadCsrf(): void
    {
        $this->media->expects(self::never())->method('insert');

        try {
            $this->service->uploadForm(['csrf' => 'bogus'], $this->files($this->makePng(), 'a.png'), 7);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }
    }

    public function testUploadThrowsWhenNoFiles(): void
    {
        try {
            $this->service->uploadForm(['csrf' => $this->service->uploadFormCsrfHash()], [], 7);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['fileCount' => MediaConst::ERROR_NO_FILE], $e->getErrors());
        }
    }

    public function testSaveAltFormUpdatesOnlyAltText(): void
    {
        $this->media->method('findById')->willReturn($this->model());
        $this->media->expects(self::once())->method('updateAltText')->with(
            5,
            'Ảnh bìa giới thiệu'
        );

        $this->service->saveAltForm([
            'id'      => '5',
            'altText' => '  Ảnh bìa giới thiệu  ',
            'csrf'    => $this->service->altFormCsrfHash(),
        ]);
    }

    public function testSaveAltFormThrowsOnCsrfAndReportsNotFoundId(): void
    {
        try {
            $this->service->saveAltForm(['id' => '5', 'altText' => 'x', 'csrf' => 'bogus']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('csrf', $e->getErrors());
        }

        $this->media->method('findById')->willReturn(null);
        $this->expectException(NotFoundException::class);
        $this->service->saveAltForm([
            'id'      => '5',
            'altText' => 'x',
            'csrf'    => $this->service->altFormCsrfHash(),
        ]);
    }

    public function testUsagesAggregatesAndDedupesPosts(): void
    {
        $this->media->method('findById')->willReturn($this->model());
        $this->posts->method('findIdsByMedia')->willReturn([1, 2]);
        $this->posts->method('findIdsByContentPath')->with('2026/09/abc.png')->willReturn([2, 3]);
        $this->categories->method('findIdsByMedia')->willReturn([4]);
        $this->banners->method('findIdsByMedia')->willReturn([]);
        $this->services->method('findIdsByMedia')->willReturn([5]);
        $this->teamMembers->method('findIdsByMedia')->willReturn([]);
        $this->users->method('countByMedia')->willReturn(0);
        $this->settings->method('findKeysByMedia')->willReturn(['site_logo']);

        $usages = $this->service->usages(5);

        self::assertSame([1, 2, 3], $usages['posts']);
        self::assertSame([4], $usages['categories']);
        self::assertSame([5], $usages['services']);
        self::assertSame(['site_logo'], $usages['settings']);
    }

    public function testDeleteFormBlockedWhenInUse(): void
    {
        $this->media->method('findById')->willReturn($this->model());
        $this->posts->method('findIdsByMedia')->willReturn([9]);
        $this->media->expects(self::never())->method('delete');

        self::assertSame(MediaConst::FLAG_BLOCKED, $this->service->deleteForm($this->deleteRaw(5)));
    }

    public function testDeleteFormRemovesFilesAndRow(): void
    {
        $base    = '2026/09/' . bin2hex(random_bytes(16)) . '.png';
        $variant = '2026/09/' . pathinfo($base, PATHINFO_FILENAME) . '-thumb.webp';
        mkdir(dirname($this->uploadDir . '/' . $base), 0775, true);
        file_put_contents($this->uploadDir . '/' . $base, 'png-bytes');
        file_put_contents($this->uploadDir . '/' . $variant, 'webp-bytes');

        $this->media->method('findById')->willReturn($this->model([
            'path'     => $base,
            'variants' => json_encode(['thumb' => $variant]),
        ]));
        $this->media->expects(self::once())->method('delete')->with(5);

        self::assertSame(
            MediaConst::FLAG_DELETED,
            $this->service->deleteForm($this->deleteRaw(5))
        );
        self::assertFileDoesNotExist($this->uploadDir . '/' . $base);
        self::assertFileDoesNotExist($this->uploadDir . '/' . $variant);
    }

    public function testDeleteFormBlockedWhenFileMissingOnDisk(): void
    {
        $this->media->method('findById')->willReturn($this->model());
        $this->media->expects(self::never())->method('delete');

        self::assertSame(MediaConst::FLAG_BLOCKED, $this->service->deleteForm($this->deleteRaw(5)));
    }

    public function testDeleteFormFlagsForCsrfAndNotFound(): void
    {
        self::assertSame(
            MediaConst::FLAG_CSRF,
            $this->service->deleteForm(['id' => '5', 'csrf' => 'bogus'])
        );

        $this->media->method('findById')->willReturn(null);
        self::assertSame(
            MediaConst::FLAG_NOT_FOUND,
            $this->service->deleteForm($this->deleteRaw(5))
        );
    }
}
