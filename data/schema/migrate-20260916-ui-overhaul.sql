-- Migrate 2026-09-16 — UI overhaul: services cha-con, pricing_items, settings map_address
-- Chạy sau schema.sql: mysql -u root -p news_system < data/schema/migrate-20260916-ui-overhaul.sql
-- Idempotent: mỗi khối kiểm tra tồn tại trước khi chèn/cập nhật.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) services.parentId (nếu schema.sql cũ chưa có cột)
SET @has_parent := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'parentId');
SET @ddl := IF(@has_parent = 0, 'ALTER TABLE services ADD COLUMN parentId INT UNSIGNED NULL AFTER id, ADD KEY idx_services_parent_sort (parentId, sortOrder)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) pricing_items
CREATE TABLE IF NOT EXISTS pricing_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  groupCode  VARCHAR(50)  NOT NULL DEFAULT 'general',
  name       VARCHAR(255) NOT NULL,
  slug       VARCHAR(255) NOT NULL,
  price      INT UNSIGNED NULL,
  unit       VARCHAR(100) NULL,
  note       VARCHAR(500) NULL,
  sortOrder  INT          NOT NULL DEFAULT 0,
  isActive   TINYINT(1)   NOT NULL DEFAULT 1,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pricing_items_slug (slug),
  KEY idx_pricing_items_group_sort (groupCode, sortOrder),
  KEY idx_pricing_items_active_sort (isActive, sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3) settings: map_address + map_embed_url đã có — chỉ thêm map_address nếu thiếu
INSERT IGNORE INTO settings (groupCode, settingKey, settingValue, valueType, label, sortOrder)
VALUES ('contact', 'map_address', NULL, 2, 'Địa chỉ riêng cho Google Maps (để trống sẽ dùng ô Địa chỉ)', 7);

-- Đồng bộ nhãn để Admin hiểu địa chỉ là nguồn ưu tiên của bản đồ FE.
UPDATE settings SET label = 'URL nhúng Google Maps (chỉ dùng khi không nhập địa chỉ)' WHERE settingKey = 'map_embed_url';
UPDATE settings SET label = 'Địa chỉ riêng cho Google Maps (để trống sẽ dùng ô Địa chỉ)' WHERE settingKey = 'map_address';

-- Đẩy notify_emails xuống sortOrder 8 nếu đang 7 (để map_address chiếm 7)
UPDATE settings SET sortOrder = 8 WHERE settingKey = 'notify_emails' AND sortOrder = 7;

-- 4) Seed 2 dịch vụ cha (id cố định 900/901 để con tham chiếu ổn định)
INSERT IGNORE INTO services (id, parentId, name, slug, shortDescription, sortOrder, isActive) VALUES
  (900, NULL, 'Chăm sóc tại viện', 'cham-soc-tai-vien', 'Dịch vụ chăm sóc người bệnh tại bệnh viện: tắm gội tại giường, chăm sóc theo giờ.', 10, 1),
  (901, NULL, 'Chăm sóc tại nhà',  'cham-soc-tai-nha',  'Dịch vụ chăm sóc y tế tại nhà: tiêm truyền, hút đờm, đặt sonde, thay băng cắt chỉ.', 20, 1);

-- 5) Seed 7 dịch vụ con (id 910..916, parentId trỏ 900/901)
INSERT IGNORE INTO services (id, parentId, name, slug, shortDescription, sortOrder, isActive) VALUES
  -- Tại viện (900)
  (910, 900, 'Tắm gội tại giường',   'tam-goi-tai-giuong',   'Vệ sinh, tắm gội cho người bệnh ngay tại giường bệnh.', 1, 1),
  (911, 900, 'Chăm sóc theo giờ',    'cham-soc-theo-gio',    'Điều dưỡng túc trực theo ca/giờ tại viện.', 2, 1),
  -- Tại nhà (901)
  (912, 901, 'Tiêm truyền theo y lệnh', 'tiem-truyen-theo-y-lenh', 'Tiêm bắp, tiêm tĩnh mạch, truyền dịch theo chỉ định bác sĩ.', 1, 1),
  (913, 901, 'Hút đờm rửa mũi',          'hut-dom-rua-mui',          'Hút đờm, rửa mũi cho trẻ và người lớn.', 2, 1),
  (914, 901, 'Đặt sonde tiểu',           'dat-sonde-tieu',           'Đặt và chăm sóc sonde tiểu tại nhà.', 3, 1),
  (915, 901, 'Đặt sonde dạ dày',         'dat-sonde-da-day',         'Đặt và chăm sóc sonde dạ dày tại nhà.', 4, 1),
  (916, 901, 'Thay băng / cắt chỉ',      'thay-bang-cat-chi',        'Thay băng vết thương, cắt chỉ sau phẫu thuật.', 5, 1);

-- 6) Seed pricing_items mẫu (nhóm theo 2 dịch vụ cha, id 1..12)
INSERT IGNORE INTO pricing_items (id, groupCode, name, slug, price, unit, note, sortOrder, isActive) VALUES
  (1,  'hospital', 'Tắm gội tại giường',        'tam-goi-tai-giuong', 150000, 'lần', 'Đã bao gồm vật tư cơ bản', 1, 1),
  (2,  'hospital', 'Chăm sóc theo giờ (ban ngày)', 'cham-soc-theo-gio-ngay', 80000, 'giờ', '07:00 - 19:00', 2, 1),
  (3,  'hospital', 'Chăm sóc theo giờ (ban đêm)',  'cham-soc-theo-gio-dem', 100000, 'giờ', '19:00 - 07:00', 3, 1),
  (4,  'home',     'Tiêm bắp / dưới da',        'tiem-bap-duoi-da', 80000, 'lần', NULL, 1, 1),
  (5,  'home',     'Tiêm tĩnh mạch',            'tiem-tinh-mach', 120000, 'lần', NULL, 2, 1),
  (6,  'home',     'Truyền dịch',               'truyen-dich', 200000, 'lần', 'Chưa bao gồm dịch truyền', 3, 1),
  (7,  'home',     'Hút đờm',                   'hut-dom', 100000, 'lần', NULL, 4, 1),
  (8,  'home',     'Rửa mũi',                   'rua-mui', 80000, 'lần', NULL, 5, 1),
  (9,  'home',     'Đặt sonde tiểu',            'dat-sonde-tieu-gia', 300000, 'lần', 'Đã bao gồm sonde', 6, 1),
  (10, 'home',     'Đặt sonde dạ dày',          'dat-sonde-da-day-gia', 350000, 'lần', 'Đã bao gồm sonde', 7, 1),
  (11, 'home',     'Thay băng',                 'thay-bang', 100000, 'lần', NULL, 8, 1),
  (12, 'home',     'Cắt chỉ',                   'cat-chi', 120000, 'lần', NULL, 9, 1);

-- 7) Home sections: thêm Quy trình nếu chưa có, và seed lại 7 box nếu DB trống hoàn toàn
-- (không ghi đè section người dùng đã có; chỉ chèn khi thiếu type)
INSERT IGNORE INTO home_sections (id, type, title, config, sortOrder, isActive) VALUES
  (100, 8, 'Quy trình chăm sóc chuyên nghiệp', JSON_OBJECT('steps', JSON_ARRAY(
    JSON_OBJECT('title', 'Tiếp nhận yêu cầu', 'desc', 'Ghi nhận thông tin và nhu cầu của gia đình.'),
    JSON_OBJECT('title', 'Khảo sát & tư vấn', 'desc', 'Điều dưỡng khảo sát và tư vấn gói phù hợp.'),
    JSON_OBJECT('title', 'Ký kết & phân công', 'desc', 'Ký hợp đồng, phân công nhân sự phù hợp.'),
    JSON_OBJECT('title', 'Chăm sóc & giám sát', 'desc', 'Thực hiện chăm sóc, báo cáo hằng ngày.'),
    JSON_OBJECT('title', 'Nghiệm thu & phản hồi', 'desc', 'Đánh giá kết quả, tiếp nhận phản hồi.')
  )), 5, 1);
