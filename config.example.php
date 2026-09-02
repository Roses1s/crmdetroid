<?php
/**
 * Конфиг CRM «Детроид» — ШАБЛОН.
 *
 * Скопируйте этот файл в config.php рядом с api.php и впишите доступы к MySQL.
 * config.php в git не хранится (см. .gitignore) — в нём пароль базы.
 *
 * На SpaceWeb: Панель → Базы данных → создать БД (MySQL 8) → скопировать имя, логин, пароль сюда.
 * Любую константу можно переопределить переменной окружения с тем же именем.
 */
define('CRM_DB_HOST', getenv('CRM_DB_HOST') ?: '127.0.0.1');   // MySQL 8 на SpaceWeb слушает 127.0.0.1, не localhost
define('CRM_DB_PORT', getenv('CRM_DB_PORT') ?: '3308');        // SpaceWeb: 3308 (не стандартный 3306)
define('CRM_DB_NAME', getenv('CRM_DB_NAME') ?: 'uXXXX_crm');   // имя БД из панели
define('CRM_DB_USER', getenv('CRM_DB_USER') ?: 'uXXXX_crm');   // логин из панели
define('CRM_DB_PASS', getenv('CRM_DB_PASS') ?: 'CHANGE_ME');   // пароль MySQL (не от панели!)
define('CRM_DB_CHARSET', 'utf8mb4');

define('CRM_UPLOAD_DIR', __DIR__ . '/uploads');
define('CRM_MAX_UPLOAD', 5 * 1024 * 1024);
define('CRM_SESSION_NAME', 'CRMSESSID');

// Первый администратор (создаётся один раз, при пустой таблице crm_users).
// После первого входа CRM потребует сменить этот пароль.
define('CRM_DEFAULT_ADMIN_EMAIL', getenv('CRM_ADMIN_EMAIL') ?: 'admin@detroid.local');
define('CRM_DEFAULT_ADMIN_PASS', getenv('CRM_ADMIN_PASS') ?: 'admin123');
define('CRM_DEFAULT_ADMIN_NAME', getenv('CRM_ADMIN_NAME') ?: 'Администратор');

// Доверенные прокси (см. пункт «IP клиента» в README).
// Если PHP стоит за nginx/балансировщиком хостинга и REMOTE_ADDR всегда один и тот же —
// впишите сюда IP прокси (через запятую), чтобы лимиты на вход считались по реальному IP
// из X-Forwarded-For / X-Real-IP. Пусто = заголовкам не верить (безопасный вариант по умолчанию).
define('CRM_TRUSTED_PROXIES', getenv('CRM_TRUSTED_PROXIES') ?: '');
