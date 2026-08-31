<?php
declare(strict_types=1);

function crm_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . CRM_DB_HOST . ';dbname=' . CRM_DB_NAME . ';charset=' . CRM_DB_CHARSET;
    try {
        $pdo = new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        err('Не удалось подключиться к MySQL. Проверьте CRM_DB_* в config.php');
    }
    crm_migrate($pdo);
    crm_migrate_owners($pdo);
    crm_seed($pdo);
    return $pdo;
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
      cargo VARCHAR(300) NOT NULL DEFAULT '',
      format VARCHAR(300) NOT NULL DEFAULT '',
      payment VARCHAR(300) NOT NULL DEFAULT '',
      ati VARCHAR(300) NOT NULL DEFAULT '',
      applications_count INT NOT NULL DEFAULT 0,
      stage VARCHAR(80) NOT NULL,
      created_at BIGINT NOT NULL,
      PRIMARY KEY (id),
      KEY idx_stage (stage),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_comments (
      id VARCHAR(80) NOT NULL,
      lead_id VARCHAR(80) NOT NULL,
      text MEDIUMTEXT NOT NULL,
      author VARCHAR(80) NOT NULL,
      time BIGINT NOT NULL,
      edited_at BIGINT NULL,
      PRIMARY KEY (id),
      KEY idx_lead (lead_id)
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
      attempted_at BIGINT NOT NULL,
      PRIMARY KEY (id),
      KEY idx_email_time (email, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    foreach ($pdo->query('SELECT id FROM crm_users') as $u) {
        crm_ensure_user_stages($pdo, (int) $u['id']);
    }
}

function crm_user_public(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
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

function crm_comment_for_user(PDO $pdo, string $cid, int $userId): ?array {
    $st = $pdo->prepare('SELECT c.* FROM crm_comments c INNER JOIN crm_leads l ON l.id = c.lead_id WHERE c.id = ? AND l.user_id = ?');
    $st->execute([$cid, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function crm_lead_row_to_api(array $r): array {
    return [
        'id' => $r['id'],
        'title' => $r['title'],
        'inn' => $r['inn'],
        'phone' => $r['phone'],
        'email' => $r['email'],
        'manager' => $r['manager'],
        'cargo' => $r['cargo'],
        'format' => $r['format'],
        'payment' => $r['payment'],
        'ati' => $r['ati'],
        'applicationsCount' => (int) $r['applications_count'],
        'stage' => $r['stage'],
        'createdAt' => (int) $r['created_at'],
        'comments' => [],
    ];
}

function crm_leads_full(PDO $pdo, int $userId): array {
    $leads = [];
    $st = $pdo->prepare('SELECT * FROM crm_leads WHERE user_id = ? ORDER BY created_at ASC');
    $st->execute([$userId]);
    foreach ($st as $r) {
        $leads[$r['id']] = crm_lead_row_to_api($r);
    }
    $byComment = [];
    foreach ($pdo->query('SELECT * FROM crm_comments ORDER BY time ASC') as $c) {
        $item = [
            'id' => $c['id'],
            'text' => $c['text'],
            'author' => $c['author'],
            'time' => (int) $c['time'],
            'attachments' => [],
        ];
        if ($c['edited_at'] !== null) $item['editedAt'] = (int) $c['edited_at'];
        $byComment[$c['id']] = $item;
        if (isset($leads[$c['lead_id']])) {
            $leads[$c['lead_id']]['comments'][] = $c['id'];
        }
    }
    foreach ($pdo->query('SELECT * FROM crm_attachments ORDER BY id ASC') as $a) {
        if (!isset($byComment[$a['comment_id']])) continue;
        $byComment[$a['comment_id']]['attachments'][] = [
            'name' => $a['name'],
            'size' => (int) $a['size'],
            'type' => $a['type'],
            'dataUrl' => $a['data_url'],
        ];
    }
    foreach ($leads as &$lead) {
        $lead['comments'] = array_map(fn($cid) => $byComment[$cid], $lead['comments']);
    }
    unset($lead);
    return array_values($leads);
}

function crm_data_hash(PDO $pdo, int $userId): string {
    $payload = json_encode(['s' => crm_stages($pdo, $userId), 'l' => crm_leads_full($pdo, $userId)], JSON_UNESCAPED_UNICODE);
    return substr(hash('sha256', $payload), 0, 32);
}

function crm_sys_comment(PDO $pdo, string $leadId, string $text): void {
    $st = $pdo->prepare('INSERT INTO crm_comments (id, lead_id, text, author, time, edited_at) VALUES (?,?,?,?,?,NULL)');
    $st->execute(['c_' . bin2hex(random_bytes(6)), $leadId, $text, 'Система', now_ms()]);
}

function crm_login_throttled(PDO $pdo, string $email): bool {
    $since = now_ms() - 15 * 60 * 1000;
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE email = ? AND attempted_at > ?');
    $st->execute([$email, $since]);
    return (int) $st->fetchColumn() >= 8;
}

function crm_login_fail(PDO $pdo, string $email): void {
    $pdo->prepare('INSERT INTO crm_login_attempts (email, attempted_at) VALUES (?,?)')->execute([$email, now_ms()]);
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

function crm_allowed_upload(string $originalName): ?string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ok = ['png','jpg','jpeg','gif','webp','bmp','pdf','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip','7z'];
    if ($ext === '' || !in_array($ext, $ok, true)) return null;
    if (preg_match('/\.(php|phtml|phar|cgi|exe|js|htm|html|svg|shtml)(\.|$)/i', $originalName)) return null;
    return $ext;
}
