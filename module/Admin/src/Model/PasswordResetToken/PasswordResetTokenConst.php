<?php

declare(strict_types=1);

namespace Admin\Model\PasswordResetToken;

/**
 * Hằng entity password_reset_tokens (FR-13, docs §3.11/§4.4.1/§6.2).
 */
final class PasswordResetTokenConst
{
    /** Token gốc gửi qua email: 32 byte random hex → 64 chars. */
    public const TOKEN_BYTES = 32;

    /** Hạn dùng token tính từ lúc tạo (phút). */
    public const EXPIRY_MINUTES = 60;

    public const ERROR_EMAIL_REQUIRED = 'Vui lòng nhập email.';
    public const ERROR_EMAIL_INVALID = 'Email không hợp lệ.';
    public const ERROR_TOKEN_INVALID = 'Liên kết đặt lại không hợp lệ hoặc đã hết hạn.';
    public const ERROR_TOKEN_USED = 'Liên kết này đã được sử dụng.';
    public const ERROR_TOKEN_EXPIRED = 'Liên kết đã hết hạn. Vui lòng yêu cầu lại.';
    public const ERROR_PASSWORD_SHORT = 'Mật khẩu mới tối thiểu %d ký tự.';
    public const ERROR_PASSWORD_MISMATCH = 'Mật khẩu nhập lại không khớp.';

    public const FLAG_FORGOT_SENT = 'forgot-sent';
    public const FLAG_RESET_OK = 'reset-ok';
    public const FLAG_RESET_INVALID = 'reset-invalid';
}
