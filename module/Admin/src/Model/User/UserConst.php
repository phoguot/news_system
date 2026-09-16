<?php

declare(strict_types=1);

namespace Admin\Model\User;

/**
 * Hằng entity `users` (06-quy-uoc-const): giới hạn trường, chính sách mật khẩu
 * (docs §3.11 — tối thiểu 8 ký tự, băm bằng password_hash) + flag PRG / lỗi
 * màn "Tài khoản của tôi" (FR-14).
 */
final class UserConst
{
    public const MAX_LENGTH_FULL_NAME = 150;
    public const MAX_LENGTH_EMAIL     = 255;
    public const MAX_LENGTH_USERNAME  = 50;
    public const MAX_LENGTH_PHONE     = 20;
    public const MAX_LENGTH_PASSWORD  = 100;

    public const PASSWORD_MIN_LENGTH = 8;

    /** SĐT VN/nhập tay: số, khoảng trắng, + - . (5–20 ký tự). */
    public const PHONE_PATTERN = '#^[0-9+.\- ]{5,20}$#';

    /**
     * Username đăng nhập: 3–32 ký tự ASCII chữ thường, bắt đầu bằng chữ,
     * cho phép số `.` `_` `-`. Collation utf8mb4_0900_ai_ci không phân biệt
     * hoa/thường/dấu nên regex chặn thẳng chữ hoa + có dấu ngay từ input.
     */
    public const USERNAME_PATTERN      = '#^[a-z][a-z0-9._\-]{2,31}$#';
    public const ERROR_USERNAME_FORMAT = 'Tên đăng nhập 3–32 ký tự, chữ thường không dấu, '
        . 'bắt đầu bằng chữ cái, chỉ gồm a–z, 0–9, . _ -.';
    public const ERROR_USERNAME_TAKEN  = 'Tên đăng nhập này đã được sử dụng.';

    /** Flag PRG sau khi lưu hồ sơ thành công (?flag=profile). */
    public const FLAG_PROFILE  = 'profile';
    /** Flag PRG sau khi đổi mật khẩu thành công (?flag=password). */
    public const FLAG_PASSWORD = 'password';
    /** Flag PRG khi CSRF fail ở cả hai form. */
    public const FLAG_CSRF     = 'csrf';
    /** Flag PRG khi không tìm thấy dòng tài khoản (hiếm — bảng 1 dòng). */
    public const FLAG_NOT_FOUND = 'notfound';

    public const ERROR_PASSWORD_CURRENT = 'Mật khẩu hiện tại không đúng.';
    public const ERROR_PASSWORD_MISMATCH = 'Mật khẩu nhập lại không khớp.';
    public const ERROR_PASSWORD_SHORT    = 'Mật khẩu mới tối thiểu %d ký tự.';
    public const ERROR_NOT_FOUND         = 'Không tìm thấy tài khoản.';
}
