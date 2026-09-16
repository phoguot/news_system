<?php

declare(strict_types=1);

namespace Admin\Exception;

use RuntimeException;

/**
 * Hành động bị chặn bởi ràng buộc dữ liệu (xoá danh mục còn bài/con,
 * media đang dùng…) → HTTP 409 kèm `errors` mô tả nơi đang sử dụng
 * (docs §5.14–§5.15; docs-dev/01-quy-chuan/05 §3).
 */
final class ConflictException extends RuntimeException implements ExceptionInterface
{
    /** @var list<string> */
    private array $reasons;

    /**
     * @param list<string> $reasons mô tả từng ràng buộc đang vi phạm
     */
    public function __construct(array $reasons, string $message = 'Thao tác bị chặn bởi ràng buộc dữ liệu.')
    {
        parent::__construct($message);
        $this->reasons = $reasons;
    }

    /**
     * @return list<string>
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }
}
