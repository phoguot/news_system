<?php

declare(strict_types=1);

namespace Admin\Model\Post;

use Application\Constant\ContentConst;
use DateTimeImmutable;
use DateTimeZone;

/**
 * POPO entity `posts` (chuẩn 07 §3): Mapper fill từng row DB vào model qua
 * `fromRow()` — model KHÔNG query DB, không biết HTTP. `categoryName` là trường
 * hiển thị ghép thêm bởi PostService (batch CategoryMapper — 07 §5), NULL khi
 * model chưa được ghép (detail/API) hoặc chuyên mục đã xoá.
 *
 * Mốc giờ lưu theo chuỗi UTC 'Y-m-d H:i:s' (docs §4.1); việc đổi sang giờ VN
 * tường minh chỉ nằm ở `toFormValues()` phục vụ input datetime-local.
 */
class PostModel
{
    public int $id = 0;
    public int $categoryId = 0;
    public ?int $authorId = null;
    public string $title = '';
    public string $slug = '';
    public ?string $excerpt = null;
    public string $content = '';
    public ?int $bannerMediaId = null;
    public ?int $thumbnailMediaId = null;
    public int $status = ContentConst::STATUS_DRAFT;
    public int $isFeatured = 0;
    public ?string $publishedAt = null;
    public int $viewCount = 0;
    public int $readingMinutes = 1;
    public ?string $previewToken = null;
    public ?string $metaTitle = null;
    public ?string $metaDescription = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** Trường hiển thị do Service ghép (không phải cột DB). */
    public ?string $categoryName = null;

    /**
     * Hydrate từ row DB (giá trị driver có thể là string — cast theo cột schema).
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m              = new static();
        $m->id          = (int) ($row['id'] ?? 0);
        $m->categoryId  = (int) ($row['categoryId'] ?? 0);
        $m->authorId    = self::intOrNull($row['authorId'] ?? null);
        $m->title       = (string) ($row['title'] ?? '');
        $m->slug        = (string) ($row['slug'] ?? '');
        $m->excerpt     = self::strOrNull($row['excerpt'] ?? null);
        $m->content     = (string) ($row['content'] ?? '');
        $m->bannerMediaId    = self::intOrNull($row['bannerMediaId'] ?? null);
        $m->thumbnailMediaId = self::intOrNull($row['thumbnailMediaId'] ?? null);
        $m->status      = (int) ($row['status'] ?? ContentConst::STATUS_DRAFT);
        $m->isFeatured  = (int) ($row['isFeatured'] ?? 0);
        $m->publishedAt = self::strOrNull($row['publishedAt'] ?? null);
        $m->viewCount   = (int) ($row['viewCount'] ?? 0);
        $m->readingMinutes = (int) ($row['readingMinutes'] ?? 1);
        $m->previewToken   = self::strOrNull($row['previewToken'] ?? null);
        $m->metaTitle      = self::strOrNull($row['metaTitle'] ?? null);
        $m->metaDescription = self::strOrNull($row['metaDescription'] ?? null);
        $m->createdAt   = (string) ($row['createdAt'] ?? '');
        $m->updatedAt   = (string) ($row['updatedAt'] ?? '');

        return $m;
    }

    /**
     * Mảng key camelCase khớp cột DB — phục vụ serialize JSON API; thêm
     * `categoryName` khi đã được Service ghép.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id'               => $this->id,
            'categoryId'       => $this->categoryId,
            'authorId'         => $this->authorId,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'excerpt'          => $this->excerpt,
            'content'          => $this->content,
            'bannerMediaId'    => $this->bannerMediaId,
            'thumbnailMediaId' => $this->thumbnailMediaId,
            'status'           => $this->status,
            'isFeatured'       => $this->isFeatured,
            'publishedAt'      => $this->publishedAt,
            'viewCount'        => $this->viewCount,
            'readingMinutes'   => $this->readingMinutes,
            'previewToken'     => $this->previewToken,
            'metaTitle'        => $this->metaTitle,
            'metaDescription'  => $this->metaDescription,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
        ];

        if ($this->categoryName !== null) {
            $out['categoryName'] = $this->categoryName;
        }

        return $out;
    }

    /**
     * Giá trị điền lại form soạn bài (input name => value). publishedAt UTC
     * đổi sang giờ VN tường minh cho <input type="datetime-local">.
     *
     * @return array<array-key, mixed>
     */
    public function toFormValues(): array
    {
        return [
            'title'            => $this->title,
            'slug'             => $this->slug,
            'categoryId'       => $this->categoryId,
            'excerpt'          => $this->excerpt ?? '',
            'content'          => $this->content,
            'bannerMediaId'    => $this->bannerMediaId ?? '',
            'thumbnailMediaId' => $this->thumbnailMediaId ?? '',
            'isFeatured'       => $this->isFeatured,
            'publishedAt'      => self::utcToVnWallInput($this->publishedAt),
            'metaTitle'        => $this->metaTitle ?? '',
            'metaDescription'  => $this->metaDescription ?? '',
        ];
    }

    /** Chuỗi UTC 'Y-m-d H:i[:s]' → giá trị 'Y-m-d\TH:i' giờ VN; null/rỗng → ''. */
    public static function utcToVnWallInput(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        $normalized = str_contains($utc, 'T') ? $utc : str_replace(' ', 'T', $utc) . 'Z';
        $dt = new DateTimeImmutable($normalized, new DateTimeZone('UTC'));

        return $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d\TH:i');
    }

    private static function intOrNull(mixed $raw): ?int
    {
        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    private static function strOrNull(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
