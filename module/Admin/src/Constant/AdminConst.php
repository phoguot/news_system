<?php

declare(strict_types=1);

namespace Admin\Constant;

/**
 * Hằng số module Admin (1 module = 1 file const — docs-dev/01-quy-chuan/06-quy-uoc-const.md).
 */
final class AdminConst
{
    /** Namespace container phiên lưu danh tính admin đã đăng nhập. */
    public const AUTH_SESSION_NAMESPACE = 'VANLANG_ADMIN_AUTH';

    /** Khoá tài khoản sau N lần đăng nhập sai (docs §3.11). */
    public const MAX_FAILED_LOGINS = 5;

    /** Thời gian khoá tạm khi vượt MAX_FAILED_LOGINS (phút). */
    public const LOCKOUT_MINUTES = 15;

    /** Thông báo chung — KHÔNG tiết lộ email/username có tồn tại hay không (chống dò account). */
    public const ERROR_INVALID_CREDENTIALS = 'Email, tên đăng nhập hoặc mật khẩu không đúng.';

    /** Thông báo khi tài khoản còn trong thời gian khoá. %d = phút còn lại. */
    public const ERROR_ACCOUNT_LOCKED = 'Tài khoản đang bị khóa tạm thời. Thử lại sau %d phút.';

    /** Thông báo bổ sung ngay lần khoá tài khoản. %1$d = số lần sai tối đa, %2$d = phút khoá. */
    public const ERROR_JUST_LOCKED = 'Sai mật khẩu %1$d lần liên tiếp, tài khoản bị khóa %2$d phút.';
}
