-- Van Lang — News CMS — MySQL 8.0.19+ — utf8mb4_0900_ai_ci
-- Convention: tables snake_case, columns camelCase, no FK, no deletedAt
-- 16 tables per docs/phan-tich-he-thong-website-tin-tuc.md v1.5
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fullName         VARCHAR(150) NOT NULL,
  email            VARCHAR(255) NOT NULL,
  username         VARCHAR(50)  NULL,
  passwordHash     VARCHAR(255) NOT NULL,
  phone            VARCHAR(20)  NULL,
  avatarMediaId    INT UNSIGNED NULL,
  failedLoginCount TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lockedUntil      DATETIME NULL,
  lastLoginAt      DATETIME NULL,
  createdAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_avatar (avatarMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  userId     INT UNSIGNED NOT NULL,
  tokenHash  CHAR(64)     NOT NULL,
  expiresAt  DATETIME     NOT NULL,
  usedAt     DATETIME     NULL,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_tokens_hash (tokenHash),
  KEY idx_password_reset_tokens_user (userId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS media (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  disk         VARCHAR(20)  NOT NULL DEFAULT 'local',
  path         VARCHAR(500) NOT NULL,
  originalName VARCHAR(255) NOT NULL,
  mimeType     VARCHAR(100) NOT NULL,
  sizeBytes    INT UNSIGNED NOT NULL,
  width        SMALLINT UNSIGNED NULL,
  height       SMALLINT UNSIGNED NULL,
  altText      VARCHAR(255) NULL,
  variants     JSON NULL,
  uploadedBy   INT UNSIGNED NULL,
  createdAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_path (path),
  KEY idx_media_mime (mimeType),
  KEY idx_media_uploaded_by (uploadedBy),
  KEY idx_media_created (createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS categories (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parentId        INT UNSIGNED NULL,
  name            VARCHAR(150) NOT NULL,
  slug            VARCHAR(255) NOT NULL,
  description     VARCHAR(500) NULL,
  coverMediaId    INT UNSIGNED NULL,
  sortOrder       INT          NOT NULL DEFAULT 0,
  isActive        TINYINT(1)   NOT NULL DEFAULT 1,
  metaTitle       VARCHAR(255) NULL,
  metaDescription VARCHAR(500) NULL,
  createdAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent_sort (parentId, sortOrder),
  KEY idx_categories_cover (coverMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tags (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  slug       VARCHAR(120) NOT NULL,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS posts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  categoryId       INT UNSIGNED NOT NULL,
  authorId         INT UNSIGNED NULL,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(255) NOT NULL,
  excerpt          VARCHAR(500) NULL,
  content          MEDIUMTEXT   NOT NULL,
  bannerMediaId    INT UNSIGNED NULL,
  thumbnailMediaId INT UNSIGNED NULL,
  status           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  isFeatured       TINYINT(1)   NOT NULL DEFAULT 0,
  publishedAt      DATETIME     NULL,
  viewCount        INT UNSIGNED NOT NULL DEFAULT 0,
  readingMinutes   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  previewToken     CHAR(32)     NULL,
  metaTitle        VARCHAR(255) NULL,
  metaDescription  VARCHAR(500) NULL,
  createdAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug),
  UNIQUE KEY uq_posts_preview_token (previewToken),
  KEY idx_posts_status_published (status, publishedAt),
  KEY idx_posts_category_status_published (categoryId, status, publishedAt),
  KEY idx_posts_featured_status_published (isFeatured, status, publishedAt),
  KEY idx_posts_author (authorId),
  KEY idx_posts_banner_media (bannerMediaId),
  KEY idx_posts_thumbnail_media (thumbnailMediaId),
  FULLTEXT KEY ft_posts_title_excerpt (title, excerpt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS post_tags (
  postId INT UNSIGNED NOT NULL,
  tagId  INT UNSIGNED NOT NULL,
  PRIMARY KEY (postId, tagId),
  KEY idx_post_tags_tag (tagId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS post_revisions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  postId     INT UNSIGNED NOT NULL,
  userId     INT UNSIGNED NULL,
  type       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  title      VARCHAR(255) NOT NULL,
  excerpt    VARCHAR(500) NULL,
  content    MEDIUMTEXT   NOT NULL,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_revisions_post_created (postId, createdAt),
  KEY idx_post_revisions_post_type (postId, type),
  KEY idx_post_revisions_user (userId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS post_view_daily (
  postId   INT UNSIGNED NOT NULL,
  viewDate DATE         NOT NULL,
  views    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (postId, viewDate),
  KEY idx_post_view_daily_date (viewDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS services (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parentId         INT UNSIGNED NULL,
  name             VARCHAR(150) NOT NULL,
  slug             VARCHAR(255) NOT NULL,
  shortDescription VARCHAR(500) NULL,
  content          MEDIUMTEXT   NULL,
  iconMediaId      INT UNSIGNED NULL,
  imageMediaId     INT UNSIGNED NULL,
  sortOrder        INT          NOT NULL DEFAULT 0,
  isActive         TINYINT(1)   NOT NULL DEFAULT 1,
  metaTitle        VARCHAR(255) NULL,
  metaDescription  VARCHAR(500) NULL,
  createdAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_slug (slug),
  KEY idx_services_active_sort (isActive, sortOrder),
  KEY idx_services_parent_sort (parentId, sortOrder),
  KEY idx_services_icon_media (iconMediaId),
  KEY idx_services_image_media (imageMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS banners (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  position           VARCHAR(50)  NOT NULL DEFAULT 'home_hero',
  title              VARCHAR(255) NULL,
  subtitle           VARCHAR(500) NULL,
  imageMediaId       INT UNSIGNED NOT NULL,
  mobileImageMediaId INT UNSIGNED NULL,
  linkUrl            VARCHAR(500) NULL,
  openNewTab         TINYINT(1)   NOT NULL DEFAULT 0,
  buttonText         VARCHAR(50)  NULL,
  sortOrder          INT          NOT NULL DEFAULT 0,
  isActive           TINYINT(1)   NOT NULL DEFAULT 1,
  startAt            DATETIME     NULL,
  endAt              DATETIME     NULL,
  createdAt          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_banners_position_active_sort (position, isActive, sortOrder),
  KEY idx_banners_image_media (imageMediaId),
  KEY idx_banners_mobile_image_media (mobileImageMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS home_sections (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  title      VARCHAR(255) NULL,
  subtitle   VARCHAR(500) NULL,
  config     JSON         NULL,
  sortOrder  INT          NOT NULL DEFAULT 0,
  isActive   TINYINT(1)   NOT NULL DEFAULT 1,
  createdAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_home_sections_active_sort (isActive, sortOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS home_section_items (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sectionId INT UNSIGNED NOT NULL,
  itemType  TINYINT UNSIGNED NOT NULL,
  itemId    INT UNSIGNED NOT NULL,
  sortOrder INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_home_section_items (sectionId, itemType, itemId),
  KEY idx_home_section_items_section_sort (sectionId, sortOrder),
  KEY idx_home_section_items_item (itemType, itemId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS team_members (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  userId          INT UNSIGNED NULL,
  fullName        VARCHAR(150) NOT NULL,
  positionTitle   VARCHAR(150) NOT NULL,
  avatarMediaId   INT UNSIGNED NULL,
  bio             TEXT         NULL,
  email           VARCHAR(255) NULL,
  phone           VARCHAR(20)  NULL,
  showContact     TINYINT(1)   NOT NULL DEFAULT 0,
  socialLinks     JSON         NULL,
  sortOrder       INT          NOT NULL DEFAULT 0,
  isFeatured      TINYINT(1)   NOT NULL DEFAULT 0,
  isActive        TINYINT(1)   NOT NULL DEFAULT 1,
  createdAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_members_user (userId),
  KEY idx_team_members_featured (isFeatured, isActive, sortOrder),
  KEY idx_team_members_active_sort (isActive, sortOrder),
  KEY idx_team_members_avatar_media (avatarMediaId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS contact_submissions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fullName    VARCHAR(150) NOT NULL,
  email       VARCHAR(255) NOT NULL,
  phone       VARCHAR(20)  NULL,
  serviceId   INT UNSIGNED NULL,
  subject     VARCHAR(255) NULL,
  message     TEXT         NOT NULL,
  consentAt   DATETIME     NOT NULL,
  status      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  adminNote   TEXT         NULL,
  handledAt   DATETIME     NULL,
  ipAddress   VARBINARY(16) NULL,
  userAgent   VARCHAR(255) NULL,
  sourceUrl   VARCHAR(500) NULL,
  createdAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contact_submissions_status_created (status, createdAt),
  KEY idx_contact_submissions_email (email),
  KEY idx_contact_submissions_ip_created (ipAddress, createdAt),
  KEY idx_contact_submissions_service (serviceId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

CREATE TABLE IF NOT EXISTS settings (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  groupCode    VARCHAR(50)  NOT NULL,
  settingKey   VARCHAR(100) NOT NULL,
  settingValue TEXT         NULL,
  valueType    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  label        VARCHAR(150) NOT NULL,
  sortOrder    INT          NOT NULL DEFAULT 0,
  updatedBy    INT UNSIGNED NULL,
  createdAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (settingKey),
  KEY idx_settings_group_sort (groupCode, sortOrder),
  KEY idx_settings_updated_by (updatedBy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
