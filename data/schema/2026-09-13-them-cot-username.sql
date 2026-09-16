-- 2026-09-13 — Thêm cột `username` cho bảng `users` (đăng nhập bằng email HOẶC username).
-- Chạy incremental này TRƯỚC khi deploy code dùng cột mới (02-migration-db.md §3, mục "bẫy" CREATE TABLE IF NOT EXISTS).
-- Collation utf8mb4_0900_ai_ci: unique không phân biệt hoa/thường/dấu — chặn bằng regex ở tầng filter (UserConst::USERNAME_PATTERN).

ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER email;
ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);

-- Backfill username cho tài khoản admin hiện hữu (hệ thống chỉ 1 dòng users).
UPDATE users SET username = 'admin' WHERE email = 'admin@vanlang.vn' AND username IS NULL;
