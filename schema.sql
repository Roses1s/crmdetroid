-- CRM «Детроид» — схема MySQL (utf8mb4 / InnoDB)
-- На SpaceWeb таблицы создаются сами при первом запросе к api.php.
-- Этот файл совпадает с миграциями в migrations.php (schema version 17).
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
  token_version INT UNSIGNED NOT NULL DEFAULT 0,
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
  KEY idx_user (user_id),
  CONSTRAINT fk_stages_user FOREIGN KEY (user_id) REFERENCES crm_users (id) ON DELETE CASCADE
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
  deleted_at BIGINT NOT NULL DEFAULT 0,
  deleted_by INT UNSIGNED NOT NULL DEFAULT 0,
  stage VARCHAR(80) NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_updated (user_id, updated_at),
  KEY idx_user_stage (user_id, stage),
  KEY idx_deleted_at (deleted_at),
  KEY idx_user_inn (user_id, inn),
  KEY idx_inn (inn),
  FULLTEXT KEY ft_title (title),
  CONSTRAINT fk_leads_user FOREIGN KEY (user_id) REFERENCES crm_users (id) ON DELETE RESTRICT
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
  KEY idx_lead_time (lead_id, time),
  KEY idx_user (user_id),
  CONSTRAINT fk_comments_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(120) NOT NULL DEFAULT '',
  data_url VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comment (comment_id),
  CONSTRAINT fk_attachments_comment FOREIGN KEY (comment_id) REFERENCES crm_comments (id) ON DELETE CASCADE
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
  inn VARCHAR(12) NOT NULL DEFAULT '',
  note VARCHAR(2000) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_dir (direction_id),
  KEY idx_dir_inn (direction_id, inn),
  CONSTRAINT fk_carriers_direction FOREIGN KEY (direction_id) REFERENCES crm_directions (id) ON DELETE CASCADE
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
  KEY idx_carrier_time (carrier_id, time),
  KEY idx_user (user_id),
  CONSTRAINT fk_carrier_comments_carrier FOREIGN KEY (carrier_id) REFERENCES crm_carriers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_carrier_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(120) NOT NULL DEFAULT '',
  data_url VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comment (comment_id),
  CONSTRAINT fk_carrier_atts_comment FOREIGN KEY (comment_id) REFERENCES crm_carrier_comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_login_nonces (
  h CHAR(64) NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (h),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_lead_apps (
  id VARCHAR(80) NOT NULL,
  lead_id VARCHAR(80) NOT NULL,
  number VARCHAR(40) NOT NULL DEFAULT '',   -- v18: внутренний номер заявки («125», «А-2026-031»), не уникальный
  city_from VARCHAR(80) NOT NULL DEFAULT '',
  city_to VARCHAR(80) NOT NULL DEFAULT '',
  rate DECIMAL(15,2) NULL DEFAULT NULL,
  rate_raw VARCHAR(40) NULL DEFAULT NULL,      -- исходная строка до миграции v10 (только у старых записей)
  margin DECIMAL(15,2) NULL DEFAULT NULL,
  margin_raw VARCHAR(40) NULL DEFAULT NULL,
  vat TINYINT NULL DEFAULT NULL,   -- v19: режим NULL/0/5/7/22 (NULL — без НДС), раньше флаг 0/1
  carrier_rate DECIMAL(15,2) NULL DEFAULT NULL,   -- v19: сколько платим перевозчику
  carrier_vat TINYINT NULL DEFAULT NULL,          -- v19: налог перевозчика, режимы как у vat
  carrier_company VARCHAR(200) NOT NULL DEFAULT '',
  carrier_inn VARCHAR(12) NOT NULL DEFAULT '',
  carrier_name VARCHAR(80) NOT NULL DEFAULT '',
  carrier_phone VARCHAR(40) NOT NULL DEFAULT '',
  status TINYINT NOT NULL DEFAULT 0,   -- v20: 0 «В работе», 1 «Машина загрузилась», 2 «Машина выгрузилась»
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  load_address VARCHAR(300) NOT NULL DEFAULT '',
  load_contact VARCHAR(120) NOT NULL DEFAULT '',
  load_date_from VARCHAR(10) NOT NULL DEFAULT '',
  load_date_to VARCHAR(10) NOT NULL DEFAULT '',
  load_time VARCHAR(120) NOT NULL DEFAULT '',
  unload_address VARCHAR(300) NOT NULL DEFAULT '',
  unload_contact VARCHAR(120) NOT NULL DEFAULT '',
  unload_date_from VARCHAR(10) NOT NULL DEFAULT '',
  unload_date_to VARCHAR(10) NOT NULL DEFAULT '',
  unload_time VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_lead (lead_id),
  KEY idx_lead_created (lead_id, created_at),
  KEY idx_status (status),
  CONSTRAINT fk_lead_apps_lead FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v20: лог заявки — копия схемы лога лида (владелец app_id, id комментариев с префиксом 'ac_').
CREATE TABLE IF NOT EXISTS crm_app_comments (
  id VARCHAR(80) NOT NULL,
  app_id VARCHAR(80) NOT NULL,
  text MEDIUMTEXT NOT NULL,
  author VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  time BIGINT NOT NULL,
  edited_at BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_app (app_id),
  KEY idx_app_time (app_id, time),
  KEY idx_user (user_id),
  CONSTRAINT fk_app_comments_app FOREIGN KEY (app_id) REFERENCES crm_lead_apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_app_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(120) NOT NULL DEFAULT '',
  data_url VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_comment (comment_id),
  CONSTRAINT fk_app_atts_comment FOREIGN KEY (comment_id) REFERENCES crm_app_comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v17: личные теги лидов. Справочник у каждого сотрудника свой (user_id);
-- цвет — hex из фиксированной палитры (валидируется сервером, CRM_TAG_COLORS в actions/tags.php).
CREATE TABLE IF NOT EXISTS crm_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Привязка тегов к лидам (многие-ко-многим). Без FK — каскадные удаления в коде
-- (crm_purge_lead, crm_purge_user, delete_tag), как и у остальных таблиц (см. TODO ниже).
CREATE TABLE IF NOT EXISTS crm_lead_tags (
  lead_id VARCHAR(80) NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (lead_id, tag_id),
  KEY idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v14: аудит-лог чувствительных действий (входы, управление сотрудниками, передачи лидов)
CREATE TABLE IF NOT EXISTS crm_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id INT UNSIGNED NOT NULL DEFAULT 0,
  actor_name VARCHAR(80) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  target VARCHAR(200) NOT NULL DEFAULT '',
  details VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created (created_at),
  KEY idx_actor (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Целостность (схема v15): внешние ключи стоят на всех связях выше (CASCADE у дочерних
-- записей, RESTRICT у crm_leads.user_id — удаление сотрудника идёт через crm_purge_user).
-- Код тоже удаляет каскады явно в транзакциях — ему нужно собрать URL файлов до удаления
-- строк. Без FK только crm_tags/crm_lead_tags (личные теги) и этапы (stage хранится именем,
-- а не id — см. докблок crm_migrate_v15): их держат код и ?action=integrity_check.

SET FOREIGN_KEY_CHECKS = 1;
