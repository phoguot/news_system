SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS site_visit_daily (
  visitDate DATE         NOT NULL,
  visitors  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (visitDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
