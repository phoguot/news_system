<?php

declare(strict_types=1);

namespace Admin\Service\Api;

use Admin\Exception\ConflictException;
use Admin\Exception\NotFoundException;
use Admin\Exception\ValidationException;
use Admin\Model\Category\CategoryModel;
use Admin\Model\Post\PostModel;
use Admin\Model\Tag\TagModel;
use Throwable;

/**
 * Nhà máy envelope API `{success,data,meta,errors}` (docs-dev/01-quy-chuan/05 §2)
 * — áp pattern ApiResultModel của webapp-be (08 §5). Service gọi ApiResultModel
 * để trả ApiResponseModel; ApiController chỉ bắt exception qua fromThrowable và
 * gắn statusCode. Serialize thời gian `*At` → ISO 8601 UTC cũng tập trung đây.
 * Static factory toàn bộ — không instantiated.
 */
final class ApiResultModel
{
    /**
     * Thành công 200. $meta chỉ có khi phân trang/đếm (docs-dev/05 §2).
     *
     * @param array{page: int, perPage: int, total: int}|null $meta
     */
    public static function ok(mixed $data, ?array $meta = null): ApiResponseModel
    {
        $model = new ApiResponseModel([
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
            'errors'  => null,
        ]);
        $model->setStatusCode(200);

        return $model;
    }

    /**
     * Lỗi có chủ đích với mã HTTP tường minh (404 endpoint không tồn tại…).
     *
     * @param array<array-key, mixed> $errors
     */
    public static function error(int $code, array $errors): ApiResponseModel
    {
        $model = new ApiResponseModel([
            'success' => false,
            'data'    => null,
            'meta'    => null,
            'errors'  => $errors,
        ]);
        $model->setStatusCode($code);

        return $model;
    }

    /**
     * Map exception nghiệp vụ → envelope lỗi chuẩn (05 §3):
     * Validation 422 (map field), Conflict 409 (mảng lý do), NotFound 404.
     * Trả NULL khi không phải exception API — controller re-throw.
     */
    public static function fromThrowable(Throwable $e): ?ApiResponseModel
    {
        if ($e instanceof ValidationException) {
            return self::error(422, $e->getErrors());
        }

        if ($e instanceof ConflictException) {
            return self::error(409, $e->getReasons());
        }

        if ($e instanceof NotFoundException) {
            return self::error(404, ['resource' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Cổng serialize chung cho Model API: toArray() rồi chuẩn hoá cột thời gian.
     */
    public static function serializeModel(CategoryModel|PostModel|TagModel $model): array
    {
        return self::serializeRow($model->toArray());
    }

    /**
     * Giữ key camelCase khớp cột DB, đổi mọi cột `*At` dạng 'Y-m-d H:i[:s]' UTC
     * sang ISO 8601 UTC (`...Z`) — docs-dev 05 §1.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<array-key, mixed>
     */
    public static function serializeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            /** @psalm-suppress MixedAssignment — row là map cột => giá trị mixed từ Model */
            if (
                is_string($key)
                && str_ends_with($key, 'At')
                && is_string($value)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value) === 1
            ) {
                $row[$key] = str_replace(' ', 'T', $value) . 'Z';
            }
        }

        return $row;
    }
}
