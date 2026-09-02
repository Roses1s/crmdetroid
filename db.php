<?php
declare(strict_types=1);

/** @return list<array{0:string,1:int}> */
function crm_mysql_targets(string $host, int $port): array {
    $host = trim($host);
    if (preg_match('/^([^:;]+)[:;](?:port=)?(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int) $m[2];
    }
    $out = [];
    $add = static function (string $h, int $p) use (&$out): void {
        $key = $h . ':' . $p;
        foreach ($out as $row) {
            if ($row[0] . ':' . $row[1] === $key) return;
        }
        $out[] = [$h, $p];
    };
    $add($host, $port);
    // SpaceWeb MySQL 8 слушает 127.0.0.1:3308, не стандартный 3306.
    $add('127.0.0.1', 3308);
    $add('localhost', 3308);
    return $out;
}

function crm_mysql_connect_hint(PDOException $e): string {
    $msg = $e->getMessage();
    if (stripos($msg, 'could not find driver') !== false) {
        return 'На хостинге нет PHP-расширения pdo_mysql. В панели SpaceWeb включите PHP 8.1+ с MySQL.';
    }
    return 'Не удалось подключиться к базе. Проверьте CRM_DB_HOST, порт, имя, логин и пароль в config.php.';
}

function crm_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!defined('CRM_DB_PASS') || CRM_DB_PASS === '' || CRM_DB_PASS === 'CHANGE_ME' || CRM_DB_PASS === 'ВПИШИТЕ_ПАРОЛЬ') {
        err('В config.php не задан пароль базы (CRM_DB_PASS). Это не пароль от панели SpaceWeb, а пароль MySQL.');
    }
    if (!extension_loaded('pdo_mysql')) {
        err('На хостинге нет расширения PHP pdo_mysql. Включите PHP 8.1+ с MySQL в панели сайта.');
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $port = defined('CRM_DB_PORT') ? (int) CRM_DB_PORT : 0;
    $targets = crm_mysql_targets(CRM_DB_HOST, $port);
    $last = null;
    foreach ($targets as [$host, $p]) {
        try {
            $dsn = 'mysql:host=' . $host . ($p ? ';port=' . $p : '') . ';dbname=' . CRM_DB_NAME . ';charset=' . CRM_DB_CHARSET;
            $pdo = new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, $opts);
            break;
        } catch (PDOException $e) {
            $last = $e;
            $pdo = null;
        }
    }
    if (!$pdo instanceof PDO) {
        err(crm_mysql_connect_hint($last ?? new PDOException('unknown')));
    }
    crm_boot($pdo);
    return $pdo;
}

const CRM_SCHEMA_VERSION = 9;

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
    crm_migrate($pdo);
    crm_migrate_owners($pdo);
    crm_migrate_routes($pdo);
    crm_migrate_v4($pdo);
    crm_migrate_v5($pdo);
    crm_migrate_v6($pdo);
    crm_migrate_v7($pdo);
    crm_migrate_v8($pdo);
    crm_migrate_v9($pdo);
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

function crm_meta_get(PDO $pdo, string $k): string {
    try {
        $st = $pdo->prepare('SELECT v FROM crm_meta WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? '0' : (string) $v;
    } catch (PDOException $e) {
        return '0';
    }
}

function crm_meta_bump(PDO $pdo, string $k): void {
    if ($k !== 'routes' && $k !== 'users') return;
    try {
        $pdo->prepare('INSERT INTO crm_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = CAST(v AS UNSIGNED) + 1')
            ->execute([$k, '1']);
    } catch (PDOException $e) { /* ok */ }
}

/** Список доверенных прокси из CRM_TRUSTED_PROXIES (IP или CIDR через запятую). */
function crm_trusted_proxies(): array {
    static $list = null;
    if ($list !== null) return $list;
    $raw = defined('CRM_TRUSTED_PROXIES') ? (string) CRM_TRUSTED_PROXIES : '';
    $list = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
        $p = trim($p);
        if ($p !== '') $list[] = $p;
    }
    return $list;
}

/** IP входит в список (точный IP или CIDR, IPv4/IPv6). */
function crm_ip_in_list(string $ip, array $list): bool {
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    foreach ($list as $entry) {
        $entry = strtolower(trim($entry));
        if ($entry === '') continue;
        if ($entry === 'loopback') {
            if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.')) return true;
            continue;
        }
        $mask = null;
        if (str_contains($entry, '/')) [$entry, $mask] = explode('/', $entry, 2);
        $nb = @inet_pton($entry);
        if ($nb === false || strlen($nb) !== strlen($bin)) continue;
        $bits = strlen($bin) * 8;
        $m = $mask === null ? $bits : (int) $mask;
        if ($m < 0 || $m > $bits) continue;
        $full = intdiv($m, 8);
        $rest = $m % 8;
        if ($full > 0 && substr($bin, 0, $full) !== substr($nb, 0, $full)) continue;
        if ($rest > 0) {
            $shift = 8 - $rest;
            if ((ord($bin[$full]) >> $shift) !== (ord($nb[$full]) >> $shift)) continue;
        }
        return true;
    }
    return false;
}

/** Обращение идёт напрямую с прокси из CRM_TRUSTED_PROXIES. */
function crm_behind_trusted_proxy(): bool {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') return false;
    $list = crm_trusted_proxies();
    return $list ? crm_ip_in_list($remote, $list) : false;
}

/**
 * IP клиента для лимитов на вход/CSRF.
 * По умолчанию — только REMOTE_ADDR: заголовкам X-Forwarded-For любой может написать что угодно.
 * Если REMOTE_ADDR — доверенный прокси (CRM_TRUSTED_PROXIES), берём последний адрес из
 * X-Forwarded-For, который прокси добавил сам (он же в X-Real-IP у nginx), — подделать его нельзя.
 */
function crm_client_ip(): string {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($remote, FILTER_VALIDATE_IP)) return '';
    if (!crm_behind_trusted_proxy()) return $remote;
    $trusted = crm_trusted_proxies();
    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $chain = array_map('trim', explode(',', $xff));
        // Идём с конца, пропуская адреса других доверенных прокси; первый «чужой» — клиент.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $c = $chain[$i];
            if (!filter_var($c, FILTER_VALIDATE_IP)) break;
            if (!crm_ip_in_list($c, $trusted)) return $c;
        }
    }
    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) return $real;
    return $remote;
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

function crm_migrate_v9(PDO $pdo): void {
    if (!crm_has_column($pdo, 'crm_lead_apps', 'margin')) {
        try {
            $pdo->exec("ALTER TABLE crm_lead_apps ADD COLUMN margin VARCHAR(40) NOT NULL DEFAULT '' AFTER rate");
        } catch (PDOException $e) { /* ok */ }
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
      KEY idx_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function crm_board_rev(PDO $pdo, int $userId): string {
    $st = $pdo->prepare('SELECT COUNT(*) c, COALESCE(MAX(updated_at),0) u, COALESCE(MAX(created_at),0) cr FROM crm_leads WHERE user_id = ?');
    $st->execute([$userId]);
    $L = $st->fetch() ?: ['c' => 0, 'u' => 0, 'cr' => 0];
    $st = $pdo->prepare('SELECT COUNT(*) c, COALESCE(MAX(c.time),0) t, COALESCE(MAX(c.edited_at),0) e, COALESCE(SUM(CRC32(c.id)),0) h
        FROM crm_comments c INNER JOIN crm_leads l ON l.id = c.lead_id WHERE l.user_id = ?');
    $st->execute([$userId]);
    $C = $st->fetch() ?: ['c' => 0, 't' => 0, 'e' => 0, 'h' => 0];
    $stages = implode("\n", crm_stages($pdo, $userId));
    return $userId . '|' . $L['c'] . '|' . $L['u'] . '|' . $L['cr'] . '|' . $C['c'] . '|' . $C['t'] . '|' . $C['e'] . '|' . $C['h']
        . '|' . crm_meta_get($pdo, 'users') . '|' . crm_meta_get($pdo, 'routes') . '|' . $stages;
}

function crm_default_stages(): array {
    return ['Новый', 'Вышел на ЛПР', 'Потенциальный клиент', 'Сделали просчет', 'Разместили заявку', 'Уехали, ждем заявку'];
}

function crm_has_column(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
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

function crm_ensure_user_stages(PDO $pdo, int $userId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_stages WHERE user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO crm_stages (user_id, name, position) VALUES (?,?,?)');
    foreach (crm_default_stages() as $i => $name) $ins->execute([$userId, $name, $i]);
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
      KEY idx_stage (stage),
      KEY idx_user (user_id),
      KEY idx_updated (user_id, updated_at)
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

function crm_norm_city(string $s): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return $s;
}

function crm_direction_by_id(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare('SELECT d.*, u.name AS creator FROM crm_directions d LEFT JOIN crm_users u ON u.id = d.created_by WHERE d.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_directions_list(PDO $pdo, string $q = ''): array {
    $sql = 'SELECT d.id, d.city_from, d.city_to, d.created_by, d.created_at, u.name AS creator,
            (SELECT COUNT(*) FROM crm_carriers c WHERE c.direction_id = d.id) AS carriers_count
            FROM crm_directions d
            LEFT JOIN crm_users u ON u.id = d.created_by';
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $sql .= ' WHERE d.city_from LIKE ? OR d.city_to LIKE ?';
        $pat = crm_like_pat($q);
        $params = [$pat, $pat];
    }
    $sql .= ' ORDER BY d.city_from ASC, d.city_to ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st as $r) {
        $out[] = [
            'id' => $r['id'],
            'cityFrom' => $r['city_from'],
            'cityTo' => $r['city_to'],
            'carriersCount' => (int) $r['carriers_count'],
            'createdByName' => $r['creator'] ?: '',
        ];
    }
    return $out;
}

function crm_carriers_list(PDO $pdo, string $directionId): array {
    $st = $pdo->prepare('SELECT c.*, u.name AS creator,
            (SELECT COUNT(*) FROM crm_carrier_comments x WHERE x.carrier_id = c.id) AS comments_count
            FROM crm_carriers c LEFT JOIN crm_users u ON u.id = c.created_by
            WHERE c.direction_id = ? ORDER BY c.created_at ASC');
    $st->execute([$directionId]);
    $out = [];
    foreach ($st as $r) {
        $out[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'phone' => $r['phone'],
            'company' => $r['company'],
            'note' => $r['note'],
            'commentsCount' => (int) $r['comments_count'],
            'createdByName' => $r['creator'] ?: '',
            'updatedAt' => (int) ($r['updated_at'] ?? $r['created_at'] ?? 0),
        ];
    }
    return $out;
}

function crm_carrier_by_id(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare('SELECT c.*, u.name AS creator FROM crm_carriers c LEFT JOIN crm_users u ON u.id = c.created_by WHERE c.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_carrier_comments(PDO $pdo, string $carrierId): array {
    $st = $pdo->prepare('SELECT c.*, u.name AS live_name FROM crm_carrier_comments c LEFT JOIN crm_users u ON u.id = c.user_id AND c.user_id > 0 WHERE c.carrier_id = ? ORDER BY c.time ASC');
    $st->execute([$carrierId]);
    $comments = [];
    $ids = [];
    foreach ($st as $c) {
        $item = [
            'id' => $c['id'],
            'text' => $c['text'],
            'author' => (trim((string) ($c['live_name'] ?? '')) !== '' ? $c['live_name'] : $c['author']),
            'userId' => (int) ($c['user_id'] ?? 0),
            'time' => (int) $c['time'],
            'attachments' => [],
        ];
        if ($c['edited_at'] !== null) $item['editedAt'] = (int) $c['edited_at'];
        $comments[$c['id']] = $item;
        $ids[] = $c['id'];
    }
    if ($ids) {
        $inQ = implode(',', array_fill(0, count($ids), '?'));
        $att = $pdo->prepare("SELECT * FROM crm_carrier_attachments WHERE comment_id IN ($inQ) ORDER BY id ASC");
        $att->execute($ids);
        foreach ($att as $a) {
            if (!isset($comments[$a['comment_id']])) continue;
            $comments[$a['comment_id']]['attachments'][] = [
                'id' => (int) $a['id'],
                'name' => $a['name'],
                'size' => (int) $a['size'],
                'type' => crm_att_mime((string) $a['type'], (string) $a['data_url'], (string) $a['name']),
                'dataUrl' => crm_file_url((string) $a['data_url']),
            ];
        }
    }
    return array_values($comments);
}

function crm_carrier_comment_by_id(PDO $pdo, string $cid): ?array {
    $st = $pdo->prepare('SELECT * FROM crm_carrier_comments WHERE id = ?');
    $st->execute([$cid]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_upload_name(string $dataUrl): ?string {
    $s = str_replace('\\', '/', trim($dataUrl));
    if ($s === '') return null;
    if (str_contains($s, 'action=file') && preg_match('#[?&]f=([^&]+)#', $s, $m)) {
        $s = 'uploads/' . rawurldecode(str_replace('+', ' ', $m[1]));
    }
    if (!preg_match('#(?:^|/)uploads/([^/]+)$#i', $s, $m)) return null;
    $name = $m[1];
    if (preg_match('/^[a-f0-9]{16}\.[a-z0-9]{1,8}$/i', $name)) return strtolower($name);
    if (preg_match('/^[a-f0-9]{16}_[A-Za-z0-9._-]{1,180}$/', $name)) return $name;
    return null;
}

function crm_unlink_upload(string $url): void {
    $name = crm_upload_name($url);
    if ($name === null) return;
    $path = CRM_UPLOAD_DIR . '/' . $name;
    if (is_file($path)) @unlink($path);
}

function crm_image_mime(string $ext): ?string {
    $map = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    ];
    $ext = strtolower($ext);
    return $map[$ext] ?? null;
}

function crm_att_mime(string $storedType, string $dataUrl, string $origName = ''): string {
    $ext = strtolower(pathinfo($dataUrl, PATHINFO_EXTENSION));
    if ($ext === '') $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $img = crm_image_mime($ext);
    if ($img !== null) return $img;
    $t = trim($storedType);
    return $t !== '' ? $t : 'application/octet-stream';
}

function crm_file_url(string $dataUrl): string {
    $name = crm_upload_name($dataUrl);
    if ($name === null) return '';
    return 'api.php?action=file&f=' . rawurlencode($name);
}

function crm_touch_lead(PDO $pdo, string $id): int {
    $now = now_ms();
    $pdo->prepare('UPDATE crm_leads SET updated_at = ? WHERE id = ?')->execute([$now, $id]);
    return $now;
}

function crm_touch_carrier(PDO $pdo, string $id): int {
    $now = now_ms();
    $pdo->prepare('UPDATE crm_carriers SET updated_at = ? WHERE id = ?')->execute([$now, $id]);
    return $now;
}

function crm_serve_file(PDO $pdo, string $name, array $user): never {
    $name = crm_upload_name('uploads/' . basename(str_replace('\\', '/', $name))) ?? '';
    if ($name === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $url = 'uploads/' . $name;
    $uid = (int) ($user['id'] ?? 0);
    $admin = (($user['role'] ?? '') === 'admin');
    $st = $pdo->prepare('SELECT a.name, l.user_id FROM crm_attachments a
        INNER JOIN crm_comments c ON c.id = a.comment_id
        INNER JOIN crm_leads l ON l.id = c.lead_id
        WHERE a.data_url = ? LIMIT 1');
    $st->execute([$url]);
    $row = $st->fetch();
    if ($row && !$admin && (int) $row['user_id'] !== $uid) {
        $row = null;
    }
    if (!$row) {
        $st = $pdo->prepare('SELECT name FROM crm_carrier_attachments WHERE data_url = ? LIMIT 1');
        $st->execute([$url]);
        $row = $st->fetch();
    }
    $dir = realpath(CRM_UPLOAD_DIR);
    $path = $dir !== false ? realpath(CRM_UPLOAD_DIR . '/' . $name) : false;
    if (!$row || $dir === false || $path === false || !is_file($path)
        || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mimes = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', '7z' => 'application/x-7z-compressed',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    $orig = preg_replace('/[\r\n"\\\\]/', '', basename((string) $row['name']));
    if ($orig === '') $orig = $name;
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $orig) ?: $name;
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . (string) filesize($path));
    if (str_starts_with($mime, 'image/')) {
        header('Content-Disposition: inline');
    } else {
        header('Content-Disposition: inline; filename="' . $ascii . '"');
    }
    readfile($path);
    exit;
}

function crm_find_attachment(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT a.id, a.comment_id, a.name, a.data_url, c.lead_id AS owner_id, c.author, c.user_id, \'lead\' AS kind
        FROM crm_attachments a INNER JOIN crm_comments c ON c.id = a.comment_id WHERE a.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) return $row;
    $st = $pdo->prepare('SELECT a.id, a.comment_id, a.name, a.data_url, c.carrier_id AS owner_id, c.author, c.user_id, \'carrier\' AS kind
        FROM crm_carrier_attachments a INNER JOIN crm_carrier_comments c ON c.id = a.comment_id WHERE a.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_att_urls(PDO $pdo, string $table, array $commentIds): array {
    if (!$commentIds) return [];
    if ($table !== 'crm_attachments' && $table !== 'crm_carrier_attachments') return [];
    $ids = array_values($commentIds);
    $inQ = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT data_url FROM {$table} WHERE comment_id IN ($inQ)");
    $st->execute($ids);
    $urls = [];
    foreach ($st as $a) $urls[] = (string) $a['data_url'];
    return $urls;
}
function crm_delete_att_rows(PDO $pdo, string $table, array $commentIds): void {
    if (!$commentIds) return;
    if ($table !== 'crm_attachments' && $table !== 'crm_carrier_attachments') return;
    $ids = array_values($commentIds);
    $inQ = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM {$table} WHERE comment_id IN ($inQ)")->execute($ids);
}
function crm_unlink_urls(array $urls): void {
    foreach ($urls as $u) crm_unlink_upload((string) $u);
}
function crm_delete_atts(PDO $pdo, string $table, array $commentIds): void {
    $urls = crm_att_urls($pdo, $table, $commentIds);
    crm_delete_att_rows($pdo, $table, $commentIds);
    crm_unlink_urls($urls);
}

function crm_purge_lead(PDO $pdo, string $id, bool $ownTxn = true): array {
    $cidsSt = $pdo->prepare('SELECT id FROM crm_comments WHERE lead_id = ?');
    $cidsSt->execute([$id]);
    $cids = $cidsSt->fetchAll(PDO::FETCH_COLUMN);
    $urls = crm_att_urls($pdo, 'crm_attachments', $cids);
    $start = $ownTxn && !$pdo->inTransaction();
    if ($start) $pdo->beginTransaction();
    try {
        crm_delete_att_rows($pdo, 'crm_attachments', $cids);
        $pdo->prepare('DELETE FROM crm_comments WHERE lead_id = ?')->execute([$id]);
        try { $pdo->prepare('DELETE FROM crm_lead_apps WHERE lead_id = ?')->execute([$id]); } catch (PDOException $e) { /* v8 */ }
        $pdo->prepare('DELETE FROM crm_leads WHERE id = ?')->execute([$id]);
        if ($start) $pdo->commit();
    } catch (Throwable $e) {
        if ($start && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($ownTxn) {
        crm_unlink_urls($urls);
        return [];
    }
    return $urls;
}

function crm_purge_user(PDO $pdo, int $id): void {
    $st = $pdo->prepare('SELECT id FROM crm_leads WHERE user_id = ?');
    $st->execute([$id]);
    $lids = $st->fetchAll(PDO::FETCH_COLUMN);
    $urls = [];
    $pdo->beginTransaction();
    try {
        foreach ($lids as $lid) {
            $urls = array_merge($urls, crm_purge_lead($pdo, (string) $lid, false));
        }
        $pdo->prepare('DELETE FROM crm_stages WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE crm_directions SET created_by = 0 WHERE created_by = ?')->execute([$id]);
        $pdo->prepare('UPDATE crm_carriers SET created_by = 0 WHERE created_by = ?')->execute([$id]);
        $pdo->prepare('UPDATE crm_carrier_comments SET user_id = 0 WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE crm_comments SET user_id = 0 WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_users WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    crm_unlink_urls($urls);
}

function crm_purge_carrier(PDO $pdo, string $id, bool $ownTxn = true): array {
    $cidsSt = $pdo->prepare('SELECT id FROM crm_carrier_comments WHERE carrier_id = ?');
    $cidsSt->execute([$id]);
    $ids = $cidsSt->fetchAll(PDO::FETCH_COLUMN);
    $urls = crm_att_urls($pdo, 'crm_carrier_attachments', $ids);
    $start = $ownTxn && !$pdo->inTransaction();
    if ($start) $pdo->beginTransaction();
    try {
        crm_delete_att_rows($pdo, 'crm_carrier_attachments', $ids);
        if ($ids) $pdo->prepare('DELETE FROM crm_carrier_comments WHERE carrier_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_carriers WHERE id = ?')->execute([$id]);
        if ($start) $pdo->commit();
    } catch (Throwable $e) {
        if ($start && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($ownTxn) {
        crm_unlink_urls($urls);
        return [];
    }
    return $urls;
}

function crm_seed(PDO $pdo): void {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM crm_users')->fetchColumn();
    if ($n === 0) {
        $st = $pdo->prepare('INSERT INTO crm_users (id, name, email, password, role, created_at) VALUES (1,?,?,?,?,?)');
        $st->execute([
            CRM_DEFAULT_ADMIN_NAME,
            mb_strtolower(CRM_DEFAULT_ADMIN_EMAIL),
            password_hash(CRM_DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT),
            'admin',
            now_ms(),
        ]);
        try { $pdo->exec('ALTER TABLE crm_users AUTO_INCREMENT = 2'); } catch (PDOException $e) { /* shared hosting */ }
    }
    $miss = $pdo->query('SELECT u.id FROM crm_users u LEFT JOIN crm_stages s ON s.user_id = u.id WHERE s.id IS NULL');
    foreach ($miss as $u) crm_ensure_user_stages($pdo, (int) $u['id']);
}

function crm_user_public(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
}

function crm_view_uid(PDO $pdo, array $user): int {
    $as = (int) ($_GET['as'] ?? 0);
    if ($as <= 0) return (int) $user['id'];
    if (($user['role'] ?? '') !== 'admin') err('Нет прав');
    if ($as === (int) $user['id']) return $as;
    if (!crm_user_by_id($pdo, $as)) err('Сотрудник не найден');
    return $as;
}

function crm_search_employees(PDO $pdo, string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $st = $pdo->prepare('SELECT id, name FROM crm_users WHERE name LIKE ? ORDER BY name ASC LIMIT 20');
    $st->execute([crm_like_pat($q)]);
    $out = [];
    foreach ($st as $r) $out[] = ['id' => (int) $r['id'], 'name' => $r['name']];
    return $out;
}

function crm_colleagues(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT id, name FROM crm_users ORDER BY name ASC') as $u) {
        $out[] = ['id' => (int) $u['id'], 'name' => $u['name']];
    }
    return $out;
}

/** @return array{id:int,name:string}|string|null  string = "ambiguous" */
function crm_match_employee(PDO $pdo, string $hint) {
    $hint = trim($hint);
    if ($hint === '' || mb_strlen($hint) < 2) return null;
    $h = mb_strtolower($hint);
    $exact = [];
    $word = [];
    foreach ($pdo->query('SELECT id, name FROM crm_users') as $u) {
        $name = trim((string) $u['name']);
        if ($name === '') continue;
        $ln = mb_strtolower($name);
        if ($ln === $h) { $exact[] = $u; continue; }
        foreach (preg_split('/\s+/u', $ln) as $part) {
            if ($part === $h) { $word[] = $u; break; }
        }
    }
    if (count($exact) === 1) return ['id' => (int) $exact[0]['id'], 'name' => $exact[0]['name']];
    if (count($exact) > 1) return 'ambiguous';
    if (count($word) === 1) return ['id' => (int) $word[0]['id'], 'name' => $word[0]['name']];
    if (count($word) > 1) return 'ambiguous';
    return null;
}

function crm_user_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM crm_users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

function crm_user_by_email(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare('SELECT * FROM crm_users WHERE email = ?');
    $st->execute([mb_strtolower($email)]);
    $u = $st->fetch();
    return $u ?: null;
}

function crm_stages(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT name FROM crm_stages WHERE user_id = ? ORDER BY position ASC, id ASC');
    $st->execute([$userId]);
    return array_map(fn($r) => $r['name'], $st->fetchAll());
}

function crm_lead_for_user(PDO $pdo, string $id, int $userId): ?array {
    $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_lead_visible(PDO $pdo, string $id, array $user, int $viewUid): ?array {
    $row = crm_lead_for_user($pdo, $id, $viewUid);
    if ($row) return $row;
    if (($user['role'] ?? '') === 'admin') {
        $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }
    return null;
}

function crm_lead_app_to_api(array $r): array {
    return [
        'id' => $r['id'],
        'leadId' => $r['lead_id'],
        'cityFrom' => $r['city_from'],
        'cityTo' => $r['city_to'],
        'rate' => $r['rate'],
        'margin' => $r['margin'] ?? '',
        'vat' => ((int) $r['vat']) ? 1 : 0,
        'carrierCompany' => $r['carrier_company'],
        'carrierInn' => $r['carrier_inn'],
        'carrierName' => $r['carrier_name'],
        'carrierPhone' => $r['carrier_phone'],
        'createdAt' => (int) $r['created_at'],
        'updatedAt' => (int) ($r['updated_at'] ?? $r['created_at'] ?? 0),
    ];
}

function crm_lead_apps(PDO $pdo, string $leadId): array {
    try {
        $st = $pdo->prepare('SELECT * FROM crm_lead_apps WHERE lead_id = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$leadId]);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($st as $r) $out[] = crm_lead_app_to_api($r);
    return $out;
}

function crm_lead_app_by_id(PDO $pdo, string $id): ?array {
    try {
        $st = $pdo->prepare('SELECT * FROM crm_lead_apps WHERE id = ?');
        $st->execute([$id]);
    } catch (PDOException $e) {
        return null;
    }
    $row = $st->fetch();
    return $row ?: null;
}

function crm_parse_money($v): ?string {
    $s = trim(str_replace(["\xC2\xA0", ' ', "\t"], '', (string) $v));
    if ($s === '') return '';
    $s = rtrim(str_replace(',', '.', $s), '.');
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $s)) return null;
    if (str_contains($s, '.')) {
        [$a, $b] = explode('.', $s, 2);
        $s = $a . '.' . str_pad($b, 2, '0');
    }
    return $s;
}

function crm_apps_stats(PDO $pdo, int $userId, string $leadId, string $inn = ''): array {
    $zero = ['count' => 0, 'margin' => 0, 'clientCount' => 0, 'clientMargin' => 0];
    $sumSql = "COUNT(*) AS c, COALESCE(SUM(CAST(REPLACE(REPLACE(NULLIF(margin, ''), ',', '.'), ' ', '') AS DECIMAL(15,2))), 0) AS m";
    try {
        $st = $pdo->prepare("SELECT $sumSql FROM crm_lead_apps WHERE lead_id = ?");
        $st->execute([$leadId]);
        $row = $st->fetch() ?: ['c' => 0, 'm' => 0];
    } catch (PDOException $e) {
        return $zero;
    }
    $count = (int) $row['c'];
    $margin = round((float) $row['m'], 2);
    $clientCount = $count;
    $clientMargin = $margin;
    $inn = preg_replace('/\D/', '', $inn) ?? '';
    if (strlen($inn) === 10 || strlen($inn) === 12) {
        try {
            $st = $pdo->prepare("SELECT $sumSql FROM crm_lead_apps a INNER JOIN crm_leads l ON l.id = a.lead_id WHERE l.user_id = ? AND l.inn = ?");
            $st->execute([$userId, $inn]);
            $all = $st->fetch() ?: ['c' => 0, 'm' => 0];
            $clientCount = (int) $all['c'];
            $clientMargin = round((float) $all['m'], 2);
        } catch (PDOException $e) { /* keep lead totals */ }
    }
    return ['count' => $count, 'margin' => $margin, 'clientCount' => $clientCount, 'clientMargin' => $clientMargin];
}

function crm_sync_lead_apps_count(PDO $pdo, string $leadId): int {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crm_lead_apps WHERE lead_id = ?');
        $st->execute([$leadId]);
        $n = (int) $st->fetchColumn();
    } catch (PDOException $e) {
        $n = 0;
    }
    $pdo->prepare('UPDATE crm_leads SET applications_count = ? WHERE id = ?')->execute([$n, $leadId]);
    return $n;
}

function crm_comment_for_user(PDO $pdo, string $cid, int $userId): ?array {
    $st = $pdo->prepare('SELECT c.* FROM crm_comments c INNER JOIN crm_leads l ON l.id = c.lead_id WHERE c.id = ? AND l.user_id = ?');
    $st->execute([$cid, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_lead_row_to_api(array $r, bool $full = true): array {
    $out = [
        'id' => $r['id'],
        'title' => $r['title'],
        'inn' => $r['inn'],
        'phone' => $r['phone'],
        'manager' => $r['manager'],
        'applicationsCount' => (int) $r['applications_count'],
        'stage' => $r['stage'],
        'createdAt' => (int) $r['created_at'],
        'updatedAt' => (int) ($r['updated_at'] ?? $r['created_at'] ?? 0),
    ];
    if ($full) {
        $out['email'] = $r['email'];
        $out['logistName'] = $r['logist_name'] ?? '';
        $out['logistPhone'] = $r['logist_phone'] ?? '';
        $out['cargo'] = $r['cargo'];
        $out['format'] = $r['format'];
        $out['payment'] = $r['payment'];
        $out['ati'] = $r['ati'];
    }
    return $out;
}

function crm_leads_full(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT id, title, inn, phone, manager, applications_count, stage, created_at, updated_at FROM crm_leads WHERE user_id = ? ORDER BY created_at ASC');
    $st->execute([$userId]);
    $leads = [];
    foreach ($st as $r) $leads[] = crm_lead_row_to_api($r, false);
    return $leads;
}

function crm_admin_count(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM crm_users WHERE role = 'admin'")->fetchColumn();
}

function crm_comments_payload(PDO $pdo, array $rows): array {
    $byComment = [];
    $order = [];
    foreach ($rows as $c) {
        $item = [
            'id' => $c['id'],
            'text' => $c['text'],
            'author' => (trim((string) ($c['live_name'] ?? '')) !== '' ? $c['live_name'] : $c['author']),
            'userId' => (int) ($c['user_id'] ?? 0),
            'time' => (int) $c['time'],
            'attachments' => [],
        ];
        if ($c['edited_at'] !== null) $item['editedAt'] = (int) $c['edited_at'];
        $byComment[$c['id']] = $item;
        $order[] = $c['id'];
    }
    if ($byComment) {
        $cids = array_keys($byComment);
        $inQ = implode(',', array_fill(0, count($cids), '?'));
        $st = $pdo->prepare("SELECT * FROM crm_attachments WHERE comment_id IN ($inQ) ORDER BY id ASC");
        $st->execute($cids);
        foreach ($st as $a) {
            if (!isset($byComment[$a['comment_id']])) continue;
            $byComment[$a['comment_id']]['attachments'][] = [
                'id' => (int) $a['id'],
                'name' => $a['name'],
                'size' => (int) $a['size'],
                'type' => crm_att_mime((string) $a['type'], (string) $a['data_url'], (string) $a['name']),
                'dataUrl' => crm_file_url((string) $a['data_url']),
            ];
        }
    }
    return array_map(fn($cid) => $byComment[$cid], $order);
}

function crm_lead_comments(PDO $pdo, string $leadId): array {
    $st = $pdo->prepare('SELECT c.*, u.name AS live_name FROM crm_comments c LEFT JOIN crm_users u ON u.id = c.user_id AND c.user_id > 0 WHERE c.lead_id = ? ORDER BY c.time ASC');
    $st->execute([$leadId]);
    return crm_comments_payload($pdo, $st->fetchAll());
}


function crm_data_hash(PDO $pdo, int $userId): string {
    $payload = json_encode(['s' => crm_stages($pdo, $userId), 'l' => crm_leads_full($pdo, $userId)], JSON_UNESCAPED_UNICODE);
    return substr(hash('sha256', $payload), 0, 32);
}

function crm_sys_comment(PDO $pdo, string $leadId, string $text): void {
    $st = $pdo->prepare('INSERT INTO crm_comments (id, lead_id, text, author, user_id, time, edited_at) VALUES (?,?,?,?,0,?,NULL)');
    $st->execute(['c_' . bin2hex(random_bytes(6)), $leadId, $text, 'Система', now_ms()]);
}

function crm_login_throttled(PDO $pdo, string $email, string $ip = ''): bool {
    $since = now_ms() - 15 * 60 * 1000;
    if ($ip !== '') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM crm_login_attempts WHERE ip = ? AND email NOT LIKE '#%' AND attempted_at > ?");
        $st->execute([$ip, $since]);
        if ((int) $st->fetchColumn() >= 80) return true;
        $st = $pdo->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?');
        $st->execute([$email, $ip, $since]);
        return (int) $st->fetchColumn() >= 8;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE email = ? AND attempted_at > ?');
    $st->execute([$email, $since]);
    return (int) $st->fetchColumn() >= 8;
}

function crm_login_fail(PDO $pdo, string $email, string $ip = ''): void {
    $pdo->prepare('INSERT INTO crm_login_attempts (email, ip, attempted_at) VALUES (?,?,?)')->execute([$email, $ip, now_ms()]);
    $pdo->prepare('DELETE FROM crm_login_attempts WHERE attempted_at < ?')->execute([now_ms() - 24 * 3600 * 1000]);
}

function crm_login_ok(PDO $pdo, string $email): void {
    $pdo->prepare('DELETE FROM crm_login_attempts WHERE email = ?')->execute([$email]);
}

/** Переименования стадий: не трогать лиды при обычной перестановке колонок. */
function crm_stage_renames(array $old, array $ns): array {
    $oldSorted = $old;
    $newSorted = $ns;
    sort($oldSorted, SORT_STRING);
    sort($newSorted, SORT_STRING);
    if ($oldSorted === $newSorted) return [];
    $renames = [];
    if (count($old) !== count($ns)) return $renames;
    foreach ($old as $i => $name) {
        $to = $ns[$i] ?? '';
        if ($to === '' || $to === $name) continue;
        if (!in_array($to, $old, true) && !in_array($name, $ns, true)) {
            $renames[] = [$name, $to];
        }
    }
    return $renames;
}

function crm_like_pat(string $s): string {
    $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    return '%' . $s . '%';
}

function crm_search_leads(PDO $pdo, int $userId, string $q): array {
    $q = trim($q);
    if ($q === '') return ['leads' => [], 'intersections' => []];
    $digits = preg_replace('/\D/', '', $q);
    $titlePat = crm_like_pat($q);
    $ownSql = 'SELECT id, title, inn, stage, phone FROM crm_leads WHERE user_id = ? AND (title LIKE ?';
    $ownParams = [$userId, $titlePat];
    if (strlen($digits) >= 2) {
        $ownSql .= ' OR inn LIKE ?';
        $ownParams[] = crm_like_pat($digits);
    }
    $ownSql .= ') ORDER BY title ASC LIMIT 40';
    $st = $pdo->prepare($ownSql);
    $st->execute($ownParams);
    $leads = [];
    foreach ($st as $r) {
        $leads[] = [
            'id' => $r['id'],
            'title' => $r['title'],
            'inn' => $r['inn'],
            'stage' => $r['stage'],
            'phone' => $r['phone'],
        ];
    }

    $othSql = 'SELECT l.title, l.inn, u.name AS owner FROM crm_leads l INNER JOIN crm_users u ON u.id = l.user_id WHERE l.user_id <> ? AND (l.title LIKE ?';
    $othParams = [$userId, $titlePat];
    if (strlen($digits) >= 2) {
        $othSql .= ' OR l.inn LIKE ?';
        $othParams[] = crm_like_pat($digits);
    }
    $othSql .= ')';
    $othSql .= ' LIMIT 60';
    $st = $pdo->prepare($othSql);
    $st->execute($othParams);
    $grouped = [];
    foreach ($st as $r) {
        $inn = preg_replace('/\D/', '', (string) $r['inn']);
        $key = $inn !== '' ? ('inn:' . $inn) : ('t:' . mb_strtolower((string) $r['title']));
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['title' => $r['title'], 'inn' => $r['inn'], 'users' => []];
        }
        $name = (string) $r['owner'];
        if ($name !== '' && !in_array($name, $grouped[$key]['users'], true)) {
            $grouped[$key]['users'][] = $name;
        }
    }
    return ['leads' => $leads, 'intersections' => array_values($grouped)];
}

function crm_allowed_upload(string $originalName): ?string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ok = ['png','jpg','jpeg','gif','webp','bmp','pdf','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip','7z'];
    if ($ext === '' || !in_array($ext, $ok, true)) return null;
    if (preg_match('/\.(php|phtml|phar|cgi|exe|js|htm|html|svg|shtml)(\.|$)/i', $originalName)) return null;
    return $ext;
}

function crm_upload_magic_ok(string $tmp, string $ext): bool {
    if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) return false;
    $ext = strtolower($ext);
    $fh = fopen($tmp, 'rb');
    if ($fh === false) return false;
    $head = fread($fh, 16);
    fclose($fh);
    if ($head === false) return false;
    if ($head === '') return $ext === 'txt' || $ext === 'csv';
    switch ($ext) {
        case 'png': return strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0;
        case 'jpg':
        case 'jpeg': return strncmp($head, "\xFF\xD8\xFF", 3) === 0;
        case 'gif': return strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0;
        case 'webp': return strlen($head) >= 12 && strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP';
        case 'bmp': return strncmp($head, 'BM', 2) === 0;
        case 'pdf': return strncmp($head, '%PDF', 4) === 0;
        case 'zip':
        case 'docx':
        case 'xlsx':
        case 'pptx': return strncmp($head, 'PK', 2) === 0;
        case '7z': return strncmp($head, "7z\xBC\xAF\x27\x1C", 6) === 0;
        case 'doc':
        case 'xls':
        case 'ppt': return strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0;
        case 'txt':
        case 'csv': return strpos($head, "\0") === false;
        default: return false;
    }
}

function crm_name_key(string $name): string {
    $name = trim($name);
    if (function_exists('mb_strtolower')) return mb_strtolower($name, 'UTF-8');
    return strtolower($name);
}

function crm_reserved_user_name(string $name): bool {
    $n = crm_name_key($name);
    return $n === 'система' || $n === 'system';
}

function crm_is_sys_comment(array $c): bool {
    return trim((string) ($c['author'] ?? '')) === 'Система';
}
