CREATE TABLE IF NOT EXISTS reviews (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  imageMediaId INT UNSIGNED NOT NULL,
  altText      VARCHAR(255) NULL,
  sortOrder    INT NOT NULL DEFAULT 0,
  isActive     TINYINT(1) NOT NULL DEFAULT 1,
  createdAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reviews_active_sort (isActive, sortOrder),
  KEY idx_reviews_image_media (imageMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO home_sections (type, title, config, sortOrder, isActive)
SELECT 10, 'Bình luận & đánh giá của khách hàng',
       JSON_OBJECT('average_rating', 4.9, 'total_reviews', 1519,
                   'star_5', 1474, 'star_4', 37, 'star_3', 5, 'star_2', 3, 'star_1', 0,
                   'limit', 6),
       COALESCE((SELECT MAX(hs.sortOrder) + 1 FROM home_sections hs), 1), 1
WHERE NOT EXISTS (SELECT 1 FROM home_sections WHERE type = 10);
