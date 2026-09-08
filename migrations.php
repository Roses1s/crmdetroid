<?php
declare(strict_types=1);

/*
 * Миграции схемы CRM (текущая версия — константа CRM_SCHEMA_VERSION ниже).
 * Запускаются автоматически первым запросом после обновления файлов, под
 * блокировкой GET_LOCK('crm_migrate') (TODO #18 — вынесено из db.php).
 * Подключается из db.php (require_once); использует его SQL-хелперы
 * (crm_password_hash, crm_ensure_user_stages, crm_parse_money) — они
 * резолвятся в рантайме, когда все файлы уже подключены.
 */

const CRM_SCHEMA_VERSION = 16;

function crm_schema_version(PDO $pdo): int {
    try {
        $v = $pdo->query("SELECT v FROM crm_meta WHERE k = 'schema'")->fetchColumn();
        return (int) $v;
    } catch (PDOException $e) {
        return 0;
    }
}

function crm_boot(PDO $pdo): void {
    if (crm_schema_version($pdo) >= CRM_SCHEMA_VERSION) return;
    // Миграции запускает первый же запрос после обновления файлов. Два одновременных первых запроса
    // раньше выполняли ALTER параллельно, и один из них падал с 500. Блокировка на уровне MySQL
    // (GET_LOCK) выстраивает их в очередь; второй после ожидания перечитывает версию и выходит.
    $locked = false;
    try {
        $locked = (int) $pdo->query("SELECT GET_LOCK('crm_migrate', 30)")->fetchColumn() === 1;
    } catch (PDOException $e) { /* без блокировки — как раньше */ }
    try {
        if ($locked && crm_schema_version($pdo) >= CRM_SCHEMA_VERSION) return;
        crm_run_migrations($pdo);
    } finally {
        if ($locked) {
            try { $pdo->query("SELECT RELEASE_LOCK('crm_migrate')"); } catch (PDOException $e) { /* ok */ }
        }
    }
}

function crm_run_migrations(PDO $pdo): void {
    crm_migrate($pdo);
    crm_migrate_owners($pdo);
    crm_migrate_routes($pdo);
    crm_migrate_v4($pdo);
    crm_migrate_v5($pdo);
    crm_migrate_v6($pdo);
    crm_migrate_v7($pdo);
    crm_migrate_v8($pdo);
    crm_migrate_v9($pdo);
    crm_migrate_v10($pdo);
    crm_migrate_v11($pdo);
    crm_migrate_v12($pdo);
    crm_migrate_v13($pdo);
    crm_migrate_v14($pdo);
    crm_migrate_v15($pdo);
    crm_migrate_v16($pdo);
    crm_seed($pdo);
    try {
        $pdo->prepare('INSERT INTO crm_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute(['schema', (string) CRM_SCHEMA_VERSION]);
    } catch (PDOException $e) { /* first boot race */ }
}

function crm_migrate_v4(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_meta (
      k VARCHAR(32) NOT NULL,
      v VARCHAR(64) NOT NULL DEFAULT '',
      PRIMARY KEY (k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!crm_has_column($pdo, 'crm_leads', 'updated_at')) {
        $pdo->exec('ALTER TABLE crm_leads ADD COLUMN updated_at BIGINT NOT NULL DEFAULT 0 AFTER created_at');
        try { $pdo->exec('UPDATE crm_leads SET updated_at = created_at WHERE updated_at = 0'); } catch (PDOException $e) { /* ok */ }
        try { $pdo->exec('ALTER TABLE crm_leads ADD KEY idx_updated (user_id, updated_at)'); } catch (PDOException $e) { /* ok */ }
    }
    if (!crm_has_column($pdo, 'crm_comments', 'user_id')) {
        $pdo->exec('ALTER TABLE crm_comments ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER author');
        try {
            $pdo->exec("UPDATE crm_comments c INNER JOIN crm_users u ON u.name = c.author SET c.user_id = u.id WHERE c.author <> 'Система' AND c.user_id = 0");
        } catch (PDOException $e) { /* ok */ }
        try { $pdo->exec('ALTER TABLE crm_comments ADD KEY idx_user (user_id)'); } catch (PDOException $e) { /* ok */ }
    }
    if (!crm_has_column($pdo, 'crm_carrier_comments', 'user_id')) {
        $pdo->exec('ALTER TABLE crm_carrier_comments ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER author');
        try {
            $pdo->exec("UPDATE crm_carrier_comments c INNER JOIN crm_users u ON u.name = c.author SET c.user_id = u.id WHERE c.user_id = 0");
        } catch (PDOException $e) { /* ok */ }
        try { $pdo->exec('ALTER TABLE crm_carrier_comments ADD KEY idx_user (user_id)'); } catch (PDOException $e) { /* ok */ }
    }
}

function crm_migrate_v5(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_login_attempts', 'ip')) {
        $pdo->exec("ALTER TABLE crm_login_attempts ADD COLUMN ip VARCHAR(45) NOT NULL DEFAULT '' AFTER email");
        try { $pdo->exec('ALTER TABLE crm_login_attempts ADD KEY idx_ip_time (ip, attempted_at)'); } catch (PDOException $e) { /* ok */ }
    }
}

function crm_migrate_v6(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_login_nonces (
      h CHAR(64) NOT NULL,
      created_at BIGINT NOT NULL,
      PRIMARY KEY (h),
      KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!crm_has_column($pdo, 'crm_carriers', 'updated_at')) {
        $pdo->exec('ALTER TABLE crm_carriers ADD COLUMN updated_at BIGINT NOT NULL DEFAULT 0 AFTER created_at');
        try { $pdo->exec('UPDATE crm_carriers SET updated_at = created_at WHERE updated_at = 0'); } catch (PDOException $e) { /* ok */ }
    }
}

function crm_migrate_v7(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_leads', 'logist_name')) {
        $pdo->exec("ALTER TABLE crm_leads ADD COLUMN logist_name VARCHAR(80) NOT NULL DEFAULT '' AFTER manager");
    }
    if (!crm_has_column($pdo, 'crm_leads', 'logist_phone')) {
        $pdo->exec("ALTER TABLE crm_leads ADD COLUMN logist_phone VARCHAR(40) NOT NULL DEFAULT '' AFTER logist_name");
    }
}

function crm_migrate_v8(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_lead_apps (
      id VARCHAR(80) NOT NULL,
      lead_id VARCHAR(80) NOT NULL,
      city_from VARCHAR(80) NOT NULL DEFAULT '',
      city_to VARCHAR(80) NOT NULL DEFAULT '',
      rate VARCHAR(40) NOT NULL DEFAULT '',
      margin VARCHAR(40) NOT NULL DEFAULT '',
      vat TINYINT NOT NULL DEFAULT 0,
      carrier_company VARCHAR(200) NOT NULL DEFAULT '',
      carrier_inn VARCHAR(12) NOT NULL DEFAULT '',
      carrier_name VARCHAR(80) NOT NULL DEFAULT '',
      carrier_phone VARCHAR(40) NOT NULL DEFAULT '',
      created_at BIGINT NOT NULL,
      updated_at BIGINT NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY idx_lead (lead_id),
      KEY idx_lead_created (lead_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function crm_migrate_v9(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_lead_apps', 'margin')) {
        try {
            $pdo->exec("ALTER TABLE crm_lead_apps ADD COLUMN margin VARCHAR(40) NOT NULL DEFAULT '' AFTER rate");
        } catch (PDOException $e) { /* ok */ }
    }
}

/**
 * v10: ставка и маржа заявки — DECIMAL(15,2) NULL вместо VARCHAR.
 * Раньше суммы считались через CAST(REPLACE(...)), а любой мусор в строке молча превращался в 0.
 * Исходные строки сохраняются в rate_raw / margin_raw (ничего не теряется, можно свериться и
 * поправить руками), в DECIMAL попадают только однозначно распознанные суммы (crm_legacy_money).
 *
 * Безопасность: DDL (ALTER TABLE MODIFY) в MySQL 8 не транзакционный. Если ALTER упадёт после
 * нормализации но до финального MODIFY — колонка останется VARCHAR с частично нормализованными
 * значениями. Исходник всегда доступен в *_raw; восстановление: UPDATE ... SET col = raw WHERE raw IS NOT NULL.
 * Поэтому перед обновлением — дамп БД (см. README).
 */
function crm_migrate_v10(PDO $pdo): void {
    foreach (['rate', 'margin'] as $col) {
        if (!crm_has_column($pdo, 'crm_lead_apps', $col)) continue;
        if (crm_column_type($pdo, 'crm_lead_apps', $col) === 'decimal') continue;
        $raw = $col . '_raw';
        if (!crm_has_column($pdo, 'crm_lead_apps', $raw)) {
            $pdo->exec("ALTER TABLE crm_lead_apps ADD COLUMN `$raw` VARCHAR(40) NULL DEFAULT NULL AFTER `$col`");
        }
        // 1) резервная копия исходника — до любых изменений значения
        $pdo->exec("UPDATE crm_lead_apps SET `$raw` = `$col` WHERE `$col` <> '' AND `$raw` IS NULL");
        // 2) нормализация в PHP; нераспознанное → '' (станет NULL ниже)
        $st = $pdo->query("SELECT id, `$col` AS v FROM crm_lead_apps WHERE `$col` <> ''");
        $upd = $pdo->prepare("UPDATE crm_lead_apps SET `$col` = ? WHERE id = ?");
        foreach ($st->fetchAll() as $r) {
            $upd->execute([crm_legacy_money((string) $r['v']) ?? '', $r['id']]);
        }
        // 3) пустые строки → NULL до смены типа (иначе ALTER упадёт в strict-режиме на '' → DECIMAL)
        $pdo->exec("ALTER TABLE crm_lead_apps MODIFY `$col` VARCHAR(40) NULL DEFAULT NULL");
        $pdo->exec("UPDATE crm_lead_apps SET `$col` = NULL WHERE `$col` = ''");
        $pdo->exec("ALTER TABLE crm_lead_apps MODIFY `$col` DECIMAL(15,2) NULL DEFAULT NULL");
    }
}

/**
 * v11: индексы под реальные запросы.
 * - crm_leads(stage) не использовался: доска, перенос этапов и save_stages фильтруют по user_id + stage;
 * - статистика «заявок по клиенту» ищет по user_id + inn.
 */
function crm_migrate_v11(PDO $pdo): void {
    // Единая схема: колонки *_raw есть всегда (у баз, прошедших v10 старым кодом, они пустые)
    foreach (['rate', 'margin'] as $col) {
        if (!crm_has_column($pdo, 'crm_lead_apps', $col . '_raw')) {
            $pdo->exec("ALTER TABLE crm_lead_apps ADD COLUMN `{$col}_raw` VARCHAR(40) NULL DEFAULT NULL AFTER `$col`");
        }
    }
    if (!crm_has_index($pdo, 'crm_leads', 'idx_user_stage')) {
        try { $pdo->exec('ALTER TABLE crm_leads ADD KEY idx_user_stage (user_id, stage)'); } catch (PDOException $e) { /* ok */ }
    }
    if (!crm_has_index($pdo, 'crm_leads', 'idx_user_inn')) {
        try { $pdo->exec('ALTER TABLE crm_leads ADD KEY idx_user_inn (user_id, inn)'); } catch (PDOException $e) { /* ok */ }
    }
    if (crm_has_index($pdo, 'crm_leads', 'idx_stage') && crm_has_index($pdo, 'crm_leads', 'idx_user_stage')) {
        try { $pdo->exec('ALTER TABLE crm_leads DROP INDEX idx_stage'); } catch (PDOException $e) { /* ok */ }
    }
}

/**
 * v12: token_version в crm_users — счётчик инвалидации сессий.
 * Инкрементируется при смене роли (admin → user / user → admin): все существующие сессии
 * этого пользователя перестают действовать на следующем запросе (crm_pw_fingerprint
 * включает token_version, и отпечаток в сессии больше не совпадает).
 * Раньше снятый админ продолжал иметь полные права до истечения сессии (до 8 часов).
 */
function crm_migrate_v12(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_users', 'token_version')) {
        $pdo->exec("ALTER TABLE crm_users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER role");
    }
}

/**
 * v13: составные индексы под ORDER BY в реальных запросах.
 * - crm_comments(lead_id, time): выборка комментариев лида с сортировкой по времени;
 * - crm_carrier_comments(carrier_id, time): аналогично для перевозчиков;
 * - crm_lead_apps(lead_id, created_at): заявки лида с сортировкой по дате создания.
 * Без этих индексов MySQL делал filesort после фильтрации по первому столбцу.
 */
function crm_migrate_v13(PDO $pdo): void {
    if (!crm_has_index($pdo, 'crm_comments', 'idx_lead_time')) {
        try { $pdo->exec('ALTER TABLE crm_comments ADD KEY idx_lead_time (lead_id, time)'); } catch (PDOException $e) { /* ok */ }
    }
    if (!crm_has_index($pdo, 'crm_carrier_comments', 'idx_carrier_time')) {
        try { $pdo->exec('ALTER TABLE crm_carrier_comments ADD KEY idx_carrier_time (carrier_id, time)'); } catch (PDOException $e) { /* ok */ }
    }
    if (!crm_has_index($pdo, 'crm_lead_apps', 'idx_lead_created')) {
        try { $pdo->exec('ALTER TABLE crm_lead_apps ADD KEY idx_lead_created (lead_id, created_at)'); } catch (PDOException $e) { /* ok */ }
    }
}

/**
 * v14: аудит-лог чувствительных действий (код-ревью п. 3.5).
 * Пишутся события управления пользователями (создание, смена роли/пароля, удаление),
 * входы и передачи лидов — раньше при разборах инцидентов не было никакого следа.
 * Только запись; чтение — через ?action=get_audit (админ) или напрямую из БД.
 */
function crm_migrate_v14(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_audit (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * v15: FOREIGN KEY на все реальные связи (код-ревью п. 10.10) — то, что раньше
 * проверял только ?action=integrity_check, теперь гарантирует сама БД:
 * комментарии/вложения/заявки не могут осиротеть даже при сбое между запросами.
 *
 * Перед добавлением FK одноразово вычищаются уже осиротевшие записи (иначе ALTER
 * упадёт); файлы вложений, потерявшие записи, подчистит штатный crm_sweep_uploads.
 *
 * Осознанно НЕ сделано:
 * - stage_id вместо имени этапа: save_stages пересоздаёт список DELETE+INSERT в одной
 *   транзакции, а MySQL проверяет FK немедленно — FK на этап ломал бы сохранение
 *   этапов, на которых есть лиды. Целостность этапов держат UNIQUE(user_id, name)
 *   и атомарный перенос лидов в save_stages.
 * - FK на *.user_id авторов комментариев и crm_audit.actor_id: там 0 = «система» /
 *   «удалённый сотрудник» — это значение, а не ссылка.
 * Каждый ALTER — в try/catch: если хостинг не даст добавить FK (права, старый движок),
 * CRM продолжит работать как раньше (проверки останутся на integrity_check),
 * а причина попадёт в лог сервера.
 */
function crm_migrate_v15(PDO $pdo): void {
    // 1. Чистка сирот — в порядке «внуки → дети», чтобы не создать новых сирот.
    $pdo->exec('DELETE a FROM crm_attachments a LEFT JOIN crm_comments c ON c.id = a.comment_id WHERE c.id IS NULL');
    $pdo->exec('DELETE c FROM crm_comments c LEFT JOIN crm_leads l ON l.id = c.lead_id WHERE l.id IS NULL');
    try {
        $pdo->exec('DELETE a FROM crm_lead_apps a LEFT JOIN crm_leads l ON l.id = a.lead_id WHERE l.id IS NULL');
    } catch (PDOException $e) { /* таблицы может не быть до v8 */ }
    $pdo->exec('DELETE a FROM crm_carrier_attachments a LEFT JOIN crm_carrier_comments c ON c.id = a.comment_id WHERE c.id IS NULL');
    $pdo->exec('DELETE c FROM crm_carrier_comments c LEFT JOIN crm_carriers k ON k.id = c.carrier_id WHERE k.id IS NULL');
    $pdo->exec('DELETE k FROM crm_carriers k LEFT JOIN crm_directions d ON d.id = k.direction_id WHERE d.id IS NULL');
    $pdo->exec('DELETE s FROM crm_stages s LEFT JOIN crm_users u ON u.id = s.user_id WHERE u.id IS NULL');
    // Лиды без владельца: невидимы в UI навсегда (все запросы фильтруют по user_id
    // существующего пользователя) — удаляем вместе с потомками (их сироты вычищены выше
    // не будут, поэтому потомков удаляем явно и в правильном порядке).
    $pdo->exec('DELETE a FROM crm_attachments a JOIN crm_comments c ON c.id = a.comment_id JOIN crm_leads l ON l.id = c.lead_id LEFT JOIN crm_users u ON u.id = l.user_id WHERE u.id IS NULL');
    $pdo->exec('DELETE c FROM crm_comments c JOIN crm_leads l ON l.id = c.lead_id LEFT JOIN crm_users u ON u.id = l.user_id WHERE u.id IS NULL');
    try {
        $pdo->exec('DELETE a FROM crm_lead_apps a JOIN crm_leads l ON l.id = a.lead_id LEFT JOIN crm_users u ON u.id = l.user_id WHERE u.id IS NULL');
    } catch (PDOException $e) { /* v8 */ }
    $pdo->exec('DELETE l FROM crm_leads l LEFT JOIN crm_users u ON u.id = l.user_id WHERE u.id IS NULL');

    // 2. Сами FK. CASCADE у дочерних записей — страховка: код и дальше удаляет их явно
    // (ему нужно собрать URL файлов до удаления строк). RESTRICT на владельце лида:
    // удаление пользователя обязано идти через crm_purge_user (перенос/удаление лидов).
    $fks = [
        ['crm_comments', 'fk_comments_lead', 'FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE CASCADE'],
        ['crm_attachments', 'fk_attachments_comment', 'FOREIGN KEY (comment_id) REFERENCES crm_comments (id) ON DELETE CASCADE'],
        ['crm_lead_apps', 'fk_lead_apps_lead', 'FOREIGN KEY (lead_id) REFERENCES crm_leads (id) ON DELETE CASCADE'],
        ['crm_leads', 'fk_leads_user', 'FOREIGN KEY (user_id) REFERENCES crm_users (id) ON DELETE RESTRICT'],
        ['crm_stages', 'fk_stages_user', 'FOREIGN KEY (user_id) REFERENCES crm_users (id) ON DELETE CASCADE'],
        ['crm_carriers', 'fk_carriers_direction', 'FOREIGN KEY (direction_id) REFERENCES crm_directions (id) ON DELETE CASCADE'],
        ['crm_carrier_comments', 'fk_carrier_comments_carrier', 'FOREIGN KEY (carrier_id) REFERENCES crm_carriers (id) ON DELETE CASCADE'],
        ['crm_carrier_attachments', 'fk_carrier_atts_comment', 'FOREIGN KEY (comment_id) REFERENCES crm_carrier_comments (id) ON DELETE CASCADE'],
    ];
    foreach ($fks as [$table, $name, $def]) {
        if (crm_has_fk($pdo, $table, $name)) continue;
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` {$def}");
        } catch (PDOException $e) {
            crm_log_fail("migrate_v15 {$name}", $e);
        }
    }
}

/**
 * v16: поиск «по мере роста» (код-ревью п. 6.2).
 * Запрос «пересечений» (чужие лиды с тем же клиентом) искал LIKE '%...%' по ВСЕЙ таблице —
 * full scan на каждый ввод символа. Теперь:
 * - FULLTEXT-индекс ft_title по названию — поиск по началу слов через MATCH...AGAINST;
 * - идекс idx_inn + префиксный LIKE 'цифры%' по нормализованному ИНН (без ведущего %).
 * Поиск по СВОИМ лидам не трогаем: он ограничен user_id (индекс) и по определению мал.
 *
 * Если хостинг не даст создать FULLTEXT (старый MySQL/MariaDB < 10.0.5), поиск
 * автоматически откатывается на прежний LIKE (см. crm_search_leads) — ничего не ломается.
 */
function crm_migrate_v16(PDO $pdo): void {
    // ИНН давно приводится к цифрам при сохранении, но старые строки могли остаться
    // с форматированием («77-01…»). Нормализуем одноразово через PHP (портируемо,
    // REGEXP_REPLACE есть не везде; строк с мусором — единицы).
    $st = $pdo->query("SELECT id, inn FROM crm_leads WHERE inn <> '' AND inn NOT REGEXP '^[0-9]+$'");
    $upd = $pdo->prepare('UPDATE crm_leads SET inn = ? WHERE id = ?');
    foreach ($st as $r) {
        $upd->execute([(string) preg_replace('/\D/', '', (string) $r['inn']), (string) $r['id']]);
    }
    if (!crm_has_index($pdo, 'crm_leads', 'idx_inn')) {
        try {
            $pdo->exec('ALTER TABLE crm_leads ADD KEY idx_inn (inn)');
        } catch (PDOException $e) {
            crm_log_fail('migrate_v16 idx_inn', $e);
        }
    }
    if (!crm_has_index($pdo, 'crm_leads', 'ft_title')) {
        try {
            $pdo->exec('ALTER TABLE crm_leads ADD FULLTEXT KEY ft_title (title)');
        } catch (PDOException $e) {
            crm_log_fail('migrate_v16 ft_title', $e);
        }
    }
}

function crm_migrate_owners(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_stages', 'user_id')) {
        $pdo->exec('ALTER TABLE crm_stages ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id');
        try { $pdo->exec('ALTER TABLE crm_stages DROP INDEX uq_stage_name'); } catch (PDOException $e) { /* already dropped */ }
        try { $pdo->exec('ALTER TABLE crm_stages ADD UNIQUE KEY uq_user_stage (user_id, name)'); } catch (PDOException $e) { /* exists */ }
        try { $pdo->exec('ALTER TABLE crm_stages ADD KEY idx_user (user_id)'); } catch (PDOException $e) { /* exists */ }
    }
    if (!crm_has_column($pdo, 'crm_leads', 'user_id')) {
        $pdo->exec('ALTER TABLE crm_leads ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id');
        try { $pdo->exec('ALTER TABLE crm_leads ADD KEY idx_user (user_id)'); } catch (PDOException $e) { /* exists */ }
    }
}

function crm_migrate_routes(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_directions (
      id VARCHAR(80) NOT NULL,
      city_from VARCHAR(80) NOT NULL,
      city_to VARCHAR(80) NOT NULL,
      created_by INT UNSIGNED NOT NULL,
      created_at BIGINT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_dir (city_from, city_to),
      KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_carriers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_carrier_comments (
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
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_carrier_attachments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      comment_id VARCHAR(80) NOT NULL,
      name VARCHAR(255) NOT NULL,
      size INT UNSIGNED NOT NULL DEFAULT 0,
      type VARCHAR(120) NOT NULL DEFAULT '',
      data_url VARCHAR(255) NOT NULL,
      PRIMARY KEY (id),
      KEY idx_comment (comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function crm_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(80) NOT NULL,
      email VARCHAR(120) NOT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(16) NOT NULL DEFAULT 'user',
      created_at BIGINT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_stages (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      name VARCHAR(80) NOT NULL,
      position INT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_user_stage (user_id, name),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_leads (
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
      KEY idx_user (user_id),
      KEY idx_updated (user_id, updated_at),
      KEY idx_user_stage (user_id, stage),
      KEY idx_user_inn (user_id, inn)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_comments (
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
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_attachments (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      comment_id VARCHAR(80) NOT NULL,
      name VARCHAR(255) NOT NULL,
      size INT UNSIGNED NOT NULL DEFAULT 0,
      type VARCHAR(120) NOT NULL DEFAULT '',
      data_url VARCHAR(255) NOT NULL,
      PRIMARY KEY (id),
      KEY idx_comment (comment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_login_attempts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      email VARCHAR(120) NOT NULL,
      ip VARCHAR(45) NOT NULL DEFAULT '',
      attempted_at BIGINT NOT NULL,
      PRIMARY KEY (id),
      KEY idx_email_time (email, attempted_at),
      KEY idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function crm_seed(PDO $pdo): void {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM crm_users')->fetchColumn();
    if ($n === 0) {
        $st = $pdo->prepare('INSERT INTO crm_users (id, name, email, password, role, created_at) VALUES (1,?,?,?,?,?)');
        $st->execute([
            CRM_DEFAULT_ADMIN_NAME,
            mb_strtolower(CRM_DEFAULT_ADMIN_EMAIL),
            crm_password_hash(CRM_DEFAULT_ADMIN_PASS),
            'admin',
            now_ms(),
        ]);
        try { $pdo->exec('ALTER TABLE crm_users AUTO_INCREMENT = 2'); } catch (PDOException $e) { /* shared hosting */ }
    }
    $miss = $pdo->query('SELECT u.id FROM crm_users u LEFT JOIN crm_stages s ON s.user_id = u.id WHERE s.id IS NULL');
    foreach ($miss as $u) crm_ensure_user_stages($pdo, (int) $u['id']);
}

function crm_has_column(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
}

/** Есть ли FOREIGN KEY с таким именем у таблицы. */
function crm_has_fk(PDO $pdo, string $table, string $name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $st->execute([$table, $name]);
    return (int) $st->fetchColumn() > 0;
}

/** Есть ли индекс с таким именем у таблицы. */
function crm_has_index(PDO $pdo, string $table, string $index): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}

/** Тип колонки по information_schema (в нижнем регистре: 'varchar', 'decimal', ...). */
function crm_column_type(PDO $pdo, string $table, string $col): string {
    $st = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return strtolower((string) $st->fetchColumn());
}

/**
 * Старое текстовое значение ставки/маржи → DECIMAL-строка или NULL (не распознано).
 * Распознаётся только однозначная сумма: «45 000», «45000 руб.», «17608,65», «12 500 ₽».
 * Диапазоны, дроби через слэш, буквы кроме валютных подписей («12000-15000», «40-45 тыс»,
 * «1e5», «от 40 000») НЕ угадываются: раньше из них склеивались цифры (1200015000, 4045, 15),
 * а исходник терялся. Теперь такие значения остаются в *_raw, а в DECIMAL пишется NULL.
 */
function crm_legacy_money(string $v): ?string {
    $clean = crm_parse_money($v);
    if ($clean !== null) return $clean === '' ? null : $clean;
    $s = mb_strtolower(trim($v), 'UTF-8');
    // валютные подписи и слово «рублей» в любых формах — не информация о сумме
    $s = preg_replace('/(руб(лей|ля|ль|\.)?|р\.|₽|rub|rur)\s*$/u', '', $s) ?? $s;
    $s = trim($s);
    if ($s === '' || preg_match('/[^\d\s.,\x{00A0}]/u', $s)) return null;
    $clean = crm_parse_money($s);
    return ($clean === null || $clean === '') ? null : $clean;
}
