-- CRM «Детроид» — схема MySQL (utf8mb4 / InnoDB)
-- На SpaceWeb таблицы создаются сами при первом запросе к api.php.
-- Этот файл совпадает с миграциями в db.php (schema version 6).
-- Импорт вручную не обязателен.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS crm_meta (
  k VARCHAR(32) NOT NULL,
  v VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'user',
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_stages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  position INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_stage (user_id, name),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_leads (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  inn VARCHAR(12) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(120) NOT NULL DEFAULT '',
  manager VARCHAR(80) NOT NULL DEFAULT '',
  logist_name VARCHAR(80) NOT NULL DEFAULT '',
  logist_phone VARCHAR(40) NOT NULL DEFAULT '',
  cargo VARCHAR(300) NOT NULL DEFAULT '',
  format VARCHAR(300) NOT NULL DEFAULT '',
  payment VARCHAR(300) NOT NULL DEFAULT '',
  ati VARCHAR(300) NOT NULL DEFAULT '',
  applications_count INT NOT NULL DEFAULT 0,
  stage VARCHAR(80) NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_stage (stage),
  KEY idx_user (user_id),
  KEY idx_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_comments (
  id VARCHAR(80) NOT NULL,
  lead_id VARCHAR(80) NOT NULL,
  text MEDIUMTEXT NOT NULL,
  author VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  time BIGINT NOT NULL,
  edited_at BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_lead (lead_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(120) NOT NULL DEFAULT '',
  data_url VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(120) NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  attempted_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_email_time (email, attempted_at),
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_directions (
  id VARCHAR(80) NOT NULL,
  city_from VARCHAR(80) NOT NULL,
  city_to VARCHAR(80) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dir (city_from, city_to),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_carriers (
  id VARCHAR(80) NOT NULL,
  direction_id VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  company VARCHAR(200) NOT NULL DEFAULT '',
  note VARCHAR(2000) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_dir (direction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_carrier_comments (
  id VARCHAR(80) NOT NULL,
  carrier_id VARCHAR(80) NOT NULL,
  text MEDIUMTEXT NOT NULL,
  author VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  time BIGINT NOT NULL,
  edited_at BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_carrier (carrier_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_carrier_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(120) NOT NULL DEFAULT '',
  data_url VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_login_nonces (
  h CHAR(64) NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (h),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
