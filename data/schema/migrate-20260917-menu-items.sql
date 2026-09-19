-- Migrate 2026-09-17 — quản lý menu FE + gộp cấu hình Google Map về một ô địa chỉ

CREATE TABLE IF NOT EXISTS menu_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(120) NOT NULL,
  url        VARCHAR(255) NOT NULL,
  target     VARCHAR(20)  NOT NULL DEFAULT '_self',
  sortOrder  INT          NOT NULL DEFAULT 0,
  isActive   TINYINT(1)   NOT NULL DEFAULT 1,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_menu_items_active_sort (isActive, sortOrder),
  KEY idx_menu_items_sort (sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Trang chủ', '/', '_self', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/' AND label = 'Trang chủ');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Giới thiệu', '/gioi-thieu', '_self', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/gioi-thieu' AND label = 'Giới thiệu');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Dịch vụ', '/dich-vu', '_self', 2, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/dich-vu' AND label = 'Dịch vụ');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Bảng giá', '/bang-gia', '_self', 3, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/bang-gia' AND label = 'Bảng giá');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Tin tức', '/tin-tuc', '_self', 4, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/tin-tuc' AND label = 'Tin tức');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Đội ngũ', '/doi-ngu', '_self', 5, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/doi-ngu' AND label = 'Đội ngũ');

INSERT INTO menu_items (label, url, target, sortOrder, isActive)
SELECT 'Liên hệ', '/lien-he', '_self', 6, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url = '/lien-he' AND label = 'Liên hệ');

UPDATE settings
SET label = 'Địa chỉ Google Map'
WHERE settingKey = 'map_address';

UPDATE settings
SET settingValue = NULL
WHERE settingKey = 'map_embed_url';
