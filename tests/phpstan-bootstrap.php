<?php
/**
 * Bootstrap для PHPStan: объявляет константы, которые в рантайме приходят из config.php
 * (config.php в git не хранится — в нём пароль БД). Файл только для статического анализа,
 * на прод не заливать.
 */
// Значения условны: настоящие приходят из config.php конкретной инсталляции.
// В phpstan.neon эти константы перечислены в dynamicConstantNames, чтобы анализатор
// не констант-фолдил их («CRM_DB_PASS === 'CHANGE_ME' всегда ложно» и т.п.).
define('CRM_DB_HOST', '127.0.0.1');
define('CRM_DB_PORT', '3306');
define('CRM_DB_NAME', 'crm');
define('CRM_DB_USER', 'crm');
define('CRM_DB_PASS', 'placeholder');
define('CRM_DB_CHARSET', 'utf8mb4');
define('CRM_DB_FALLBACK', false);
define('CRM_UPLOAD_DIR', __DIR__ . '/../uploads');
define('CRM_MAX_UPLOAD', 5 * 1024 * 1024);
define('CRM_SESSION_NAME', 'CRMSESSID');
define('CRM_DEFAULT_ADMIN_EMAIL', 'admin@example.com');
define('CRM_DEFAULT_ADMIN_PASS', 'placeholder');
define('CRM_DEFAULT_ADMIN_NAME', 'Admin');
define('CRM_TRUSTED_PROXIES', '');
