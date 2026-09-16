<?php

declare(strict_types=1);

namespace Admin\Model\PostRevision;

/**
 * POPO entity `post_revisions` (docs §4.4.3, §5.13).
 * Mỗi revision lưu snapshot title/excerpt/content của bài tại thời điểm tạo.
 * type: 0=manual · 1=autosave · 2=before_publish (hằng ở PostRevisionMapper).
 * final: không kế thừa — giữ `new static()` an toàn cho psalm.
 */
final class PostRevisionModel
{
    public int $id = 0;
    public int $postId = 0;
    public ?int $userId = null;
    public int $type = 0;
    public string $title = '';
    public ?string $excerpt = null;
    public string $content = '';
    public string $createdAt = '';

    public const TYPE_LABELS = [
        PostRevisionMapper::TYPE_MANUAL => 'Thủ công',
        PostRevisionMapper::TYPE_AUTOSAVE => 'Tự lưu',
        PostRevisionMapper::TYPE_BEFORE_PUBLISH => 'Trước xuất bản',
    ];

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $m = new static();
        $m->id = (int) ($row['id'] ?? 0);
        $m->postId = (int) ($row['postId'] ?? 0);
        $m->userId = self::intOrNull($row['userId'] ?? null);
        $m->type = (int) ($row['type'] ?? 0);
        $m->title = (string) ($row['title'] ?? '');
        $m->excerpt = self::strOrNull($row['excerpt'] ?? null);
        $m->content = (string) ($row['content'] ?? '');
        $m->createdAt = (string) ($row['createdAt'] ?? '');

        return $m;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'postId' => $this->postId,
            'userId' => $this->userId,
            'type' => $this->type,
            'typeLabel' => self::TYPE_LABELS[$this->type] ?? (string) $this->type,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'createdAt' => $this->createdAt,
        ];
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
