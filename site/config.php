<?php
/**
 * Конфиг CRM «Детроид».
 * На SpaceWeb: Панель → Базы данных → создать БД → скопировать имя, логин, пароль сюда.
 */
define('CRM_DB_HOST', getenv('CRM_DB_HOST') ?: 'localhost');
define('CRM_DB_NAME', getenv('CRM_DB_NAME') ?: 'crm_detroid');
define('CRM_DB_USER', getenv('CRM_DB_USER') ?: 'crm_detroid');
define('CRM_DB_PASS', getenv('CRM_DB_PASS') ?: 'CHANGE_ME');
define('CRM_DB_CHARSET', 'utf8mb4');

define('CRM_UPLOAD_DIR', __DIR__ . '/uploads');
define('CRM_MAX_UPLOAD', 5 * 1024 * 1024);
define('CRM_SESSION_NAME', 'CRMSESSID');
define('CRM_DEFAULT_ADMIN_EMAIL', getenv('CRM_ADMIN_EMAIL') ?: 'admin@detroid.local');
define('CRM_DEFAULT_ADMIN_PASS', getenv('CRM_ADMIN_PASS') ?: 'admin123');
define('CRM_DEFAULT_ADMIN_NAME', getenv('CRM_ADMIN_NAME') ?: 'Администратор');
