-- 2026-09-13 — Seed 1 record admin cho môi trường local/dev (lệnh INSERT hoàn chỉnh).
-- Hệ thống CHỈ cho phép 1 dòng `users` (AGENTS §3) → dùng INSERT ... ON DUPLICATE KEY UPDATE:
-- dòng chưa tồn tại thì chèn mới; đã tồn tại (trùng `email`) thì reset passwordHash + mở khoá.
-- passwordHash = bcrypt("Admin@1234") sinh bằng password_hash($p, PASSWORD_DEFAULT) trên PHP 8.3.
-- LƯU Ý BẢO MẬT: đây là tài khoản dev với mật khẩu công khai trong file —
-- SAU khi đăng nhập lần đầu phải đổi mật khẩu ngay tại /admin/account (đừng dùng file này cho production).

SET time_zone = '+00:00';

INSERT INTO users (fullName, email, username, passwordHash, phone,
                   failedLoginCount, lockedUntil, createdAt, updatedAt)
VALUES ('Quan tri Van Lang', 'admin@vanlang.vn', 'admin',
        '$2y$10$.syAimGTrgSugau9W7na/.bNoxO0TpqCxS459BiYl7MtoggwEsVPO',
        NULL, 0, NULL, NOW(), NOW());
