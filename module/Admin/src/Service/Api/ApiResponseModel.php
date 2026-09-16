<?php

declare(strict_types=1);

namespace Admin\Service\Api;

use Laminas\View\Model\JsonModel;

/**
 * JsonModel kèm mã HTTP — phương tiện để SERVICE trả trọn response API
 * (docs-dev/01-quy-chuan/08 §5: luồng API trả response từ Service, kế thừa
 * pattern ApiResultModel của webapp-be). Controller chỉ lấy statusCode gắn vào
 * HTTP response rồi render — không tự dựng envelope.
 *
 * Không override constructor: JsonModel::__construct là FINAL trong
 * laminas-view — trạng thái HTTP mang qua setter, dựng lên bởi ApiResultModel.
 *
 * @psalm-suppress DeprecatedClass JsonModel deprecated trong laminas-view 2.x
 * nhưng là chuẩn API của repo (docs-dev/01-quy-chuan/05 §2).
 */
class ApiResponseModel extends JsonModel
{
    private int $statusCode = 200;

    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
