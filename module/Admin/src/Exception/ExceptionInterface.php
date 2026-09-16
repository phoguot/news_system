<?php

declare(strict_types=1);

namespace Admin\Exception;

use Throwable;

/**
 * Marker cho mọi exception nghiệp vụ tầng Admin — ApiController/View dựa vào
 * loại cụ thể để map sang HTTP 404/409/422 (docs-dev/01-quy-chuan/05-quy-chuan-api.md §3).
 */
interface ExceptionInterface extends Throwable
{
}
