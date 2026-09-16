<?php

declare(strict_types=1);

namespace Admin\Exception;

use RuntimeException;

/**
 * Không tìm thấy bản ghi theo id/slug → HTTP 404 (docs-dev/01-quy-chuan/05 §3).
 */
final class NotFoundException extends RuntimeException implements ExceptionInterface
{
    public static function forEntity(string $entityLabel, int|string $id): self
    {
        return new self(sprintf('Không tìm thấy %s "%s".', $entityLabel, (string) $id));
    }
}
