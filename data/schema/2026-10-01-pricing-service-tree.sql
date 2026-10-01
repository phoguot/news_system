-- Bảng giá hai cấp: nhóm cha và dòng giá con liên kết trực tiếp `services`.
-- Có thể chạy trên MySQL 8 / MariaDB 10.11 sau schema hiện hành.
-- Dùng utf8mb4_unicode_ci vì utf8mb4_0900_ai_ci chỉ tồn tại trên MySQL 8,
-- không được MariaDB 10.11 trên production hỗ trợ.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS pricing_groups (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  serviceId  INT UNSIGNED NOT NULL,
  sortOrder  INT          NOT NULL DEFAULT 0,
  isActive   TINYINT(1)   NOT NULL DEFAULT 1,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pricing_groups_service (serviceId),
  KEY idx_pricing_groups_active_sort (isActive, sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_service_id := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_items' AND COLUMN_NAME = 'serviceId'
);
SET @ddl := IF(
  @has_service_id = 0,
  'ALTER TABLE pricing_items ADD COLUMN serviceId INT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Chỉ tự nối dữ liệu cũ khi slug trùng chính xác slug dịch vụ con; các dòng
-- legacy không khớp được giữ nguyên để quản trị viên đối chiếu, ứng dụng mới
-- không đọc chúng nên không thể gắn nhầm giá sang một dịch vụ khác.
UPDATE pricing_items p
INNER JOIN services s ON s.slug = p.slug AND s.parentId IS NOT NULL
SET p.serviceId = s.id
WHERE p.serviceId IS NULL;

SET @has_service_unique := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_items'
    AND INDEX_NAME = 'uq_pricing_items_service'
);
SET @ddl := IF(
  @has_service_unique = 0,
  'ALTER TABLE pricing_items ADD UNIQUE KEY uq_pricing_items_service (serviceId)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mỗi dịch vụ cha tự có một cấu hình nhóm; mặc định kế thừa thứ tự hiện hành
-- lần đầu để giao diện không bị đảo sau khi triển khai.
INSERT INTO pricing_groups (serviceId, sortOrder, isActive)
SELECT s.id, s.sortOrder, 1
FROM services s
LEFT JOIN pricing_groups pg ON pg.serviceId = s.id
WHERE s.parentId IS NULL AND pg.id IS NULL;
