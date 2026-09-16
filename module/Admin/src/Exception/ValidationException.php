<?php

declare(strict_types=1);

namespace Admin\Exception;

use RuntimeException;

/**
 * Lỗi validate/nghiệp vụ trên input → HTTP 422 kèm map `errors` theo trường
 * (docs-dev/01-quy-chuan/05 §2–§3). Controller/ApiController gom vào envelope.
 */
final class ValidationException extends RuntimeException implements ExceptionInterface
{
    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors map `truong => thong bao`
     */
    public function __construct(array $errors, string $message = 'Dữ liệu không hợp lệ.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
