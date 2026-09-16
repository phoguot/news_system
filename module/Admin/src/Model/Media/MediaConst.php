<?php

declare(strict_types=1);

namespace Admin\Model\Media;

/**
 * Hằng entity `media` (docs §3.10, §5.14; quy ước 06-quy-uoc-const).
 * Giới hạn kỹ thuật (dung lượng, MIME, kích thước biến thể) lấy từ cấu hình
 * `app` trong config/autoload/global.php — không hard-code ở đây.
 */
final class MediaConst
{
    /** Giá trị cột disk — hiện chỉ hỗ trợ đĩa nội bộ. */
    public const DISK_LOCAL = 'local';

    /** Độ dài tối đa theo VARCHAR trong schema.sql. */
    public const MAX_LENGTH_PATH           = 500;
    public const MAX_LENGTH_ORIGINAL_NAME  = 255;
    public const MAX_LENGTH_MIME           = 100;
    public const MAX_LENGTH_ALT            = 255;

    /** Cờ flash qua query sau PRG (view dịch sang thông báo). */
    public const FLAG_UPLOADED   = 'uploaded';
    public const FLAG_UPDATED    = 'updated';
    public const FLAG_DELETED    = 'deleted';
    public const FLAG_BLOCKED    = 'blocked';
    public const FLAG_CSRF       = 'csrf';
    public const FLAG_NOT_FOUND  = 'notfound';

    /** Thông báo lỗi nghiệp vụ. */
    public const ERROR_NOT_FOUND    = 'Không tìm thấy media.';
    public const ERROR_NO_FILE      = 'Chưa chọn file ảnh để tải lên.';
    /** %d = giới hạn MB từ cấu hình app.upload_max_mb. */
    public const ERROR_TOO_LARGE    = 'File vượt quá giới hạn %d MB cho phép.';
    /** %s = MIME thật phát hiện được bằng finfo. */
    public const ERROR_TYPE         = 'Kiểu file không được phép (phát hiện: %s).';
    public const ERROR_MOVE         = 'Không lưu được file lên máy chủ — hãy thử lại.';
    public const ERROR_IN_USE       = 'Media đang được nội dung khác sử dụng — không thể xoá.';
    public const ERROR_FILE_DELETE  = 'Không xoá được file trên đĩa — bản ghi đã được giữ nguyên.';
}
