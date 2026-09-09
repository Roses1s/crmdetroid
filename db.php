<?php
declare(strict_types=1);

/*
 * Слой данных CRM: подключение к MySQL (crm_pdo) и SQL-хелперы
 * (crm_*_by_id, crm_*_list, crm_touch_*, crm_purge_*, поиск, пароли).
 * Подключается из api.php после config.php; сам подключает остальные слои
 * (порядок require в api.php и tests/ не менялся):
 *   http.php       — out/ok/err/now_ms/crm_log_fail + CrmError (TODO #20 — выполнено)
 *   security.php   — IP/прокси, лимиты входа, валидация загрузок (TODO #18 — выполнено)
 *   files.php      — вложения uploads/, выдача файлов, уборка (TODO #18 — выполнено)
 *   migrations.php — crm_boot и миграции схемы (TODO #18 — выполнено)
 * Слой данных не завершает HTTP-запрос сам: crm_pdo() и crm_view_uid() бросают CrmError,
 * который api.php превращает в JSON-ответ. Поэтому db.php пригоден в CLI (cron-бэкапы,
 * CLI-миграции): require 'config.php'; require 'db.php'; crm_pdo().
 * Функции между файлами резолвятся в рантайме — взаимные вызовы (migrations → crm_password_hash
 * из db.php) безопасны, т.к. к моменту исполнения все require_once уже отработали.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/migrations.php';

/** @return list<array{0:string,1:int}> */
function crm_mysql_targets(string $host, int $port, bool $allowFallback = false): array {
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
    // Фолбэк на SpaceWeb MySQL 8 (127.0.0.1:3308) — только при явном включении.
    // Без этого флага молчаливое подключение к другому MySQL на том же сервере — риск утечки данных.
    if ($allowFallback) {
        $add('127.0.0.1', 3308);
        $add('localhost', 3308);
    }
    return $out;
}

function crm_mysql_connect_hint(PDOException $e): string {
    $msg = $e->getMessage();
    if (stripos($msg, 'could not find driver') !== false) {
        return 'На хостинге нет PHP-расширения pdo_mysql. В панели SpaceWeb включите PHP 8.1+ с MySQL.';
    }
    return 'Не удалось подключиться к базе. Проверьте CRM_DB_HOST, порт, имя, логин и пароль в config.php.';
}

/** @throws CrmError при отсутствии пароля/расширения или недоступности MySQL (TODO #20) */
function crm_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    // CrmError вместо err() (TODO #20): db.php не завершает HTTP-запрос сам —
    // api.php ловит CrmError и отвечает JSON; в CLI исключение видно как обычная ошибка.
    if (!defined('CRM_DB_PASS') || CRM_DB_PASS === '' || CRM_DB_PASS === 'CHANGE_ME' || CRM_DB_PASS === 'ВПИШИТЕ_ПАРОЛЬ') {
        throw new CrmError('В config.php не задан пароль базы (CRM_DB_PASS). Это не пароль от панели SpaceWeb, а пароль MySQL.');
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new CrmError('На хостинге нет расширения PHP pdo_mysql. Включите PHP 8.1+ с MySQL в панели сайта.');
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // rowCount() = число строк, ПОДОШЕДШИХ под WHERE, а не фактически изменённых.
        // Оптимистическая блокировка (`UPDATE ... WHERE updated_at = ?` → rowCount() === 0 значит
        // «карточку изменили в другом месте») иначе даёт ложный конфликт, если запись совпала по
        // условию, но новые значения оказались равны старым.
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
    ];
    $port = defined('CRM_DB_PORT') ? (int) CRM_DB_PORT : 0;
    // Фолбэк на 127.0.0.1:3308 / localhost:3308 — только если явно включён в config.php
    // (CRM_DB_FALLBACK=1). Без этого флага подключение идёт строго к сконфигурированному хосту:
    // раньше молчаливый фолбэк мог увести подключение в чужой MySQL на том же сервере.
    $allowFallback = defined('CRM_DB_FALLBACK') && CRM_DB_FALLBACK;
    $targets = crm_mysql_targets(CRM_DB_HOST, $port, $allowFallback);
    $last = null;
    $connectedTo = null;
    foreach ($targets as [$host, $p]) {
        try {
            $dsn = 'mysql:host=' . $host . ($p ? ';port=' . $p : '') . ';dbname=' . CRM_DB_NAME . ';charset=' . CRM_DB_CHARSET;
            $pdo = new PDO($dsn, CRM_DB_USER, CRM_DB_PASS, $opts);
            $connectedTo = $host . ':' . $p;
            break;
        } catch (PDOException $e) {
            $last = $e;
            $pdo = null;
        }
    }
    if (!$pdo instanceof PDO) {
        throw new CrmError(crm_mysql_connect_hint($last ?? new PDOException('unknown')));
    }
    // Если подключились не к сконфигурированному хосту — логируем (помогает найти проблемы конфигурации)
    $configured = trim(CRM_DB_HOST) . ':' . ($port ?: 3306);
    if ($connectedTo !== null && $connectedTo !== $configured) {
        error_log('CRM: подключились к ' . $connectedTo . ' вместо сконфигурированного ' . $configured);
    }
    crm_boot($pdo);
    return $pdo;
}

/** Минимальная длина запроса (символов названия или цифр ИНН), при которой ищем пересечения с чужими лидами. */
const CRM_SEARCH_MIN_CHARS = 4;

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

/**
 * Записать событие в аудит-лог. Никогда не роняет основной запрос: аудит — вторичен.
 * Заодно раз в ~200 записей чистит события старше года.
 */
function crm_audit(PDO $pdo, array $actor, string $action, string $target = '', string $details = ''): void {
    try {
        $pdo->prepare('INSERT INTO crm_audit (actor_id, actor_name, action, target, details, ip, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                (int) ($actor['id'] ?? 0),
                mb_substr((string) ($actor['name'] ?? ''), 0, 80),
                mb_substr($action, 0, 40),
                mb_substr($target, 0, 200),
                mb_substr($details, 0, 500),
                crm_client_ip(),
                now_ms(),
            ]);
        if (random_int(1, 200) === 1) {
            $pdo->prepare('DELETE FROM crm_audit WHERE created_at < ?')->execute([now_ms() - 365 * 86400 * 1000]);
        }
    } catch (Throwable $e) { crm_log_fail('audit', $e); }
}

/**
 * Проверка ссылочной целостности (замена FOREIGN KEY, которых нет в схеме).
 * Возвращает список найденных orphan-записей: [таблица, id, описание].
 * Вызывается из ?action=integrity_check (только для админа) или из cron-скрипта.
 */
function crm_integrity_check(PDO $pdo): array {
    $issues = [];
    // Комментарии без лида
    $st = $pdo->query('SELECT c.id FROM crm_comments c LEFT JOIN crm_leads l ON l.id = c.lead_id WHERE l.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_comments', $id, 'комментарий без лида'];
    }
    // Комментарии перевозчиков без перевозчика
    $st = $pdo->query('SELECT c.id FROM crm_carrier_comments c LEFT JOIN crm_carriers k ON k.id = c.carrier_id WHERE k.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_carrier_comments', $id, 'комментарий без перевозчика'];
    }
    // Вложения без комментария
    $st = $pdo->query('SELECT a.id FROM crm_attachments a LEFT JOIN crm_comments c ON c.id = a.comment_id WHERE c.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_attachments', $id, 'вложение без комментария'];
    }
    $st = $pdo->query('SELECT a.id FROM crm_carrier_attachments a LEFT JOIN crm_carrier_comments c ON c.id = a.comment_id WHERE c.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_carrier_attachments', $id, 'вложение без комментария перевозчика'];
    }
    // Заявки без лида
    try {
        $st = $pdo->query('SELECT a.id FROM crm_lead_apps a LEFT JOIN crm_leads l ON l.id = a.lead_id WHERE l.id IS NULL LIMIT 100');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $issues[] = ['crm_lead_apps', $id, 'заявка без лида'];
        }
    } catch (PDOException $e) { /* v8 table may not exist */ }
    // Перевозчики без направления
    $st = $pdo->query('SELECT c.id FROM crm_carriers c LEFT JOIN crm_directions d ON d.id = c.direction_id WHERE d.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_carriers', $id, 'перевозчик без направления'];
    }
    // Лиды без владельца (user_id не в crm_users)
    $st = $pdo->query('SELECT l.id FROM crm_leads l LEFT JOIN crm_users u ON u.id = l.user_id WHERE u.id IS NULL LIMIT 100');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $issues[] = ['crm_leads', $id, 'лид без владельца'];
    }
    return $issues;
}

/** DECIMAL из БД → строка для API: '45000' / '1234.50' / '' (как вводил пользователь, без хвоста .00). */
function crm_money_out(mixed $v): string {
    if ($v === null || $v === '') return '';
    $s = (string) $v;
    if (!str_contains($s, '.')) return $s;
    $s = rtrim(rtrim($s, '0'), '.');
    if (str_contains($s, '.')) {
        [$a, $b] = explode('.', $s, 2);
        $s = $a . '.' . str_pad($b, 2, '0');
    }
    return $s === '' ? '0' : $s;
}

/** Значение для записи в DECIMAL-колонку: '' → NULL. */
function crm_money_in(string $v): ?string {
    return $v === '' ? null : $v;
}

function crm_default_stages(): array {
    return ['Новый', 'Вышел на ЛПР', 'Потенциальный клиент', 'Сделали просчет', 'Разместили заявку', 'Уехали, ждем заявку'];
}

function crm_ensure_user_stages(PDO $pdo, int $userId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_stages WHERE user_id = ?');
    $st->execute([$userId]);
    if ((int) $st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO crm_stages (user_id, name, position) VALUES (?,?,?)');
    foreach (crm_default_stages() as $i => $name) $ins->execute([$userId, $name, $i]);
}

/**
 * Новый id с префиксом ('l_', 'a_', 'd_', 'k_', 'c_', 'cc_') с проверкой на коллизию.
 * 48 бит случайности — коллизия почти невероятна, но раньше при её наступлении INSERT
 * падал duplicate key → пользователь получал «Не удалось сохранить» без повтора.
 * Проверка по PK дешёвая; гонка двух одновременных вставок с одинаковым id прикрыта
 * самим PK (вторая упадёт), вероятность этого пренебрежима.
 */
function crm_new_id(PDO $pdo, string $prefix, string $table): string {
    static $tables = ['crm_leads', 'crm_lead_apps', 'crm_directions', 'crm_carriers', 'crm_comments', 'crm_carrier_comments'];
    if (!in_array($table, $tables, true)) return $prefix . bin2hex(random_bytes(6));
    for ($i = 0; $i < 3; $i++) {
        $id = $prefix . bin2hex(random_bytes(6));
        try {
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ?");
            $st->execute([$id]);
            if ($st->fetch() === false) return $id;
        } catch (PDOException $e) {
            return $id; // таблицы ещё нет (первый boot) — id заведомо свободен
        }
    }
    return $prefix . bin2hex(random_bytes(6));
}

function crm_norm_city(string $s): string {
    // preg_replace с /u возвращает null на невалидном UTF-8 — раньше trim(null) кидал TypeError (500)
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
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

function crm_carriers_list(PDO $pdo, string $directionId, array $user = []): array {
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
            'canManage' => $user ? can_manage_ref($user, $r) : false,
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
        $isSys = crm_is_sys_comment($c);
        $item = [
            'id' => $c['id'],
            'text' => $c['text'],
            'author' => (trim((string) ($c['live_name'] ?? '')) !== '' ? $c['live_name'] : $c['author']),
            'userId' => (int) ($c['user_id'] ?? 0),
            'time' => (int) $c['time'],
            'isSystem' => $isSys,
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

/**
 * Передать один лид от $fromUid к $toId (внутри уже открытой транзакции).
 * Этап сохраняется, если такой есть у получателя, иначе — первый этап получателя.
 * $viaName — кто фактически передал (админ через ?as=), пусто если сам владелец.
 * Возвращает имя получателя или null при ошибке.
 */
function crm_transfer_lead(PDO $pdo, string $leadId, int $fromUid, int $toId, string $fromName, string $stage, string $viaName = '', int $updatedAt = 0): ?string {
    $to = crm_user_by_id($pdo, $toId);
    if (!$to) return null;
    $toName = (string) $to['name'];
    $toStages = crm_stages($pdo, $toId);
    $newStage = in_array($stage, $toStages, true) ? $stage : ($toStages[0] ?? $stage);
    $now = now_ms();
    $tr = $pdo->prepare('UPDATE crm_leads SET user_id = ?, stage = ?, manager = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ?');
    $tr->execute([$toId, $newStage, $toName, $now, $leadId, $fromUid, $updatedAt]);
    if ($tr->rowCount() === 0) return null;
    $via = $viaName !== '' ? ' (передал ' . $viaName . ')' : '';
    crm_sys_comment($pdo, $leadId, 'Лид передан: ' . $fromName . ' → ' . $toName . $via);
    return $toName;
}

/**
 * Передать все лиды сотрудника $from сотруднику $to (внутри уже открытой транзакции).
 * Этап сохраняется, если такой есть у получателя, иначе — первый этап получателя.
 * Возвращает число переданных лидов.
 */
function crm_transfer_user_leads(PDO $pdo, int $from, int $to, string $fromName, string $toName): int {
    $toStages = crm_stages($pdo, $to);
    $fallback = $toStages[0] ?? 'Новый';
    $st = $pdo->prepare('SELECT id, stage FROM crm_leads WHERE user_id = ?');
    $st->execute([$from]);
    $rows = $st->fetchAll();
    if (!$rows) return 0;
    $upd = $pdo->prepare('UPDATE crm_leads SET user_id = ?, stage = ?, manager = ?, updated_at = ? WHERE id = ? AND user_id = ?');
    $n = 0;
    foreach ($rows as $r) {
        $stage = in_array((string) $r['stage'], $toStages, true) ? (string) $r['stage'] : $fallback;
        $upd->execute([$to, $stage, $toName, now_ms(), (string) $r['id'], $from]);
        crm_sys_comment($pdo, (string) $r['id'], 'Лид передан: ' . $fromName . ' → ' . $toName . ' (сотрудник удалён)');
        $n++;
    }
    return $n;
}

/**
 * Удалить сотрудника. $transferTo > 0 — его лиды (с логом, вложениями и заявками) уходят
 * другому сотруднику; 0 — лиды и их файлы удаляются безвозвратно.
 * Возвращает число переданных лидов.
 */
function crm_purge_user(PDO $pdo, int $id, int $transferTo = 0): int {
    $urls = [];
    $moved = 0;
    $pdo->beginTransaction();
    try {
        if ($transferTo > 0) {
            $from = crm_user_by_id($pdo, $id);
            $to = crm_user_by_id($pdo, $transferTo);
            if (!$to || $transferTo === $id) throw new RuntimeException('bad transfer target');
            $moved = crm_transfer_user_leads($pdo, $id, $transferTo, (string) ($from['name'] ?? ''), (string) $to['name']);
        } else {
            $st = $pdo->prepare('SELECT id FROM crm_leads WHERE user_id = ?');
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                $urls = array_merge($urls, crm_purge_lead($pdo, (string) $lid, false));
            }
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
    return $moved;
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

/**
 * Алгоритм хэширования паролей (код-ревью п. 3.4): Argon2id, если PHP собран с ним
 * (на SpaceWeb PHP 8.1+ обычно да), иначе bcrypt. Существующие bcrypt-хэши продолжают
 * работать; при успешном входе password_needs_rehash() перекладывает их на новый
 * алгоритм — плавная миграция без сброса паролей.
 */
function crm_password_algo(): string {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}
function crm_password_hash(string $pass): string {
    return password_hash($pass, crm_password_algo());
}
/** Хэш выглядит как валидный (bcrypt или argon2) — для подмены на dummy при защите от тайминга. */
function crm_hash_looks_valid(string $hash): bool {
    return (bool) preg_match('/^\$(2[aby]|argon2id?)\$/', $hash);
}
/**
 * Dummy-хэш для защиты от перечисления пользователей по таймингу (ревью, п. 12.2).
 * Обязан быть создан ТЕМ ЖЕ алгоритмом, что и боевые хэши (crm_password_algo):
 * раньше это была bcrypt-константа, и после миграции паролей на Argon2id время
 * password_verify() для несуществующего e-mail (bcrypt, ~100 мс) отличалось от
 * существующего (argon2id) — e-mail снова можно было перечислять по времени ответа.
 * Считается один раз на процесс; пароль в нём случайный — verify всегда провалится.
 */
function crm_dummy_hash(): string {
    static $dummy = null;
    if ($dummy === null) $dummy = crm_password_hash(bin2hex(random_bytes(16)));
    return $dummy;
}

function crm_user_public(array $u): array {
    return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
}

/** @throws CrmError если ?as= использует не-админ или сотрудник не существует (TODO #20) */
function crm_view_uid(PDO $pdo, array $user): int {
    $as = is_scalar($_GET['as'] ?? null) ? (int) $_GET['as'] : 0;
    if ($as <= 0) return (int) $user['id'];
    // CrmError вместо err() (TODO #20) — тексты те же, crm_err_status даст те же 403/404
    if (($user['role'] ?? '') !== 'admin') throw new CrmError('Нет прав');
    if ($as === (int) $user['id']) return $as;
    if (!crm_user_by_id($pdo, $as)) throw new CrmError('Сотрудник не найден');
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

function crm_lead_app_to_api(array $r): array {
    return [
        'id' => $r['id'],
        'leadId' => $r['lead_id'],
        'cityFrom' => $r['city_from'],
        'cityTo' => $r['city_to'],
        'rate' => crm_money_out($r['rate'] ?? null),
        'margin' => crm_money_out($r['margin'] ?? null),
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

function crm_parse_money(mixed $v): ?string {
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
    $sumSql = 'COUNT(*) AS c, COALESCE(SUM(margin), 0) AS m';
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
        // Телефон логиста нужен и в лёгкой выборке: карточка на доске показывает его
        // вместо телефона лида (телефон лида из карточки убран — заменён на Код АТИ).
        'logistPhone' => $r['logist_phone'] ?? '',
    ];
    if ($full) {
        $out['email'] = $r['email'];
        $out['ati'] = $r['ati'] ?? '';
        $out['logistName'] = $r['logist_name'] ?? '';
    }
    return $out;
}

function crm_leads_full(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT id, title, inn, phone, logist_phone, manager, applications_count, stage, created_at, updated_at FROM crm_leads WHERE user_id = ? ORDER BY created_at ASC');
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
        $isSys = crm_is_sys_comment($c);
        $item = [
            'id' => $c['id'],
            'text' => $c['text'],
            'author' => (trim((string) ($c['live_name'] ?? '')) !== '' ? $c['live_name'] : $c['author']),
            'userId' => (int) ($c['user_id'] ?? 0),
            'time' => (int) $c['time'],
            'isSystem' => $isSys,
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

function crm_sys_comment(PDO $pdo, string $leadId, string $text): void {
    $st = $pdo->prepare('INSERT INTO crm_comments (id, lead_id, text, author, user_id, time, edited_at) VALUES (?,?,?,?,0,?,NULL)');
    $st->execute([crm_new_id($pdo, 'c_', 'crm_comments'), $leadId, $text, 'Система', now_ms()]);
}

/**
 * Обобщённая вставка комментария (#19): одна функция для лидов и перевозчиков.
 * $commentTable: 'crm_comments' | 'crm_carrier_comments'
 * $attTable: 'crm_attachments' | 'crm_carrier_attachments'
 * $fkColumn: 'lead_id' | 'carrier_id'
 * $prefix: 'c_' | 'cc_' — префикс id комментария
 * Возвращает id созданного комментария.
 */
function crm_insert_comment(PDO $pdo, string $commentTable, string $attTable, string $fkColumn, string $fkId, string $text, array $user, array $atts, string $prefix = 'c_'): string {
    $cid = crm_new_id($pdo, $prefix, $commentTable);
    $pdo->prepare("INSERT INTO {$commentTable} (id, {$fkColumn}, text, author, user_id, time, edited_at) VALUES (?,?,?,?,?,?,NULL)")
        ->execute([$cid, $fkId, $text, $user['name'], (int) $user['id'], now_ms()]);
    if ($atts) {
        $insA = $pdo->prepare("INSERT INTO {$attTable} (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)");
        foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
    }
    return $cid;
}

/**
 * Переименования стадий: не трогать лиды при обычной перестановке колонок.
 * Переименование определяется только если ровно один старый этап исчез и ровно один новый появился
 * (остальные — те же, возможно в другом порядке). Во всех остальных случаях (добавление, удаление,
 * массовая замена) — пустой список: лиды с неизвестным этапом ниже ловит UPDATE ... NOT IN.
 */
function crm_stage_renames(array $old, array $ns): array {
    $removed = array_diff($old, $ns);
    $added = array_diff($ns, $old);
    if (count($removed) === 1 && count($added) === 1) {
        return [[array_values($removed)[0], array_values($added)[0]]];
    }
    return [];
}

function crm_like_pat(string $s): string {
    $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    return '%' . $s . '%';
}

/**
 * Запрос для MATCH...AGAINST (BOOLEAN MODE) из пользовательской строки: каждое слово
 * длиной от 3 символов (короче не попадают в FULLTEXT-индекс InnoDB при дефолтном
 * innodb_ft_min_token_size=3) становится обязательным префиксом («+слово*»).
 * Спецоператоры BOOLEAN MODE служат разделителями и в запрос не попадают.
 * Пустой результат — строка для FULLTEXT непригодна, ищем прежним LIKE.
 */
function crm_ft_query(string $q): string {
    $words = preg_split('/[\s+\-><()~*"@]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = [];
    foreach ($words as $w) {
        if (mb_strlen($w, 'UTF-8') < 3) continue;
        $parts[] = '+' . $w . '*';
    }
    return implode(' ', $parts);
}

function crm_search_leads(PDO $pdo, int $userId, string $q): array {
    $q = trim($q);
    if ($q === '') return ['leads' => [], 'intersections' => []];
    $digits = preg_replace('/\D/', '', $q);
    $titlePat = crm_like_pat($q);
    $ownSql = 'SELECT id, title, inn, stage, phone FROM crm_leads WHERE user_id = ? AND (title LIKE ?';
    $ownParams = [$userId, $titlePat];
    // Свой поиск по ИНН — тоже от 4 цифр (раньше было 2): по двузначным фрагментам
    // перебирались все лиды с похожим ИНН, что при большом объёме замедляло поиск.
    if (strlen($digits) >= CRM_SEARCH_MIN_CHARS) {
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

    // «Пересечения» — чужие лиды с тем же клиентом. Показываем их только по достаточно точному
    // запросу (≥4 символа названия или ≥4 цифры ИНН) и не больше 20 за раз: по двузначным
    // фрагментам ИНН («77», «78», …) раньше можно было выгрузить всю клиентскую базу компании.
    //
    // Этот запрос — единственный, который ищет по ВСЕЙ таблице (ревью, п. 6.2), поэтому
    // с v16 он ходит по индексам: название — FULLTEXT (MATCH по началу слов), ИНН —
    // префиксный LIKE 'цифры%' по idx_inn (ИНН нормализован миграцией и при сохранении).
    // Если FULLTEXT-индекс на хостинге не создался (v16 это молча переживает) или запрос
    // для него непригоден (все слова короче 3 символов) — прежний LIKE '%...%'.
    $byTitle = mb_strlen($q, 'UTF-8') >= CRM_SEARCH_MIN_CHARS;
    $byInn = strlen($digits) >= CRM_SEARCH_MIN_CHARS;
    if (!$byTitle && !$byInn) return ['leads' => $leads, 'intersections' => []];
    $othSelect = 'SELECT l.title, l.inn, u.name AS owner FROM crm_leads l INNER JOIN crm_users u ON u.id = l.user_id WHERE l.user_id <> ? AND (';
    $ftQuery = $byTitle ? crm_ft_query($q) : '';
    $st = null;
    if ($ftQuery !== '') {
        $conds = ['MATCH(l.title) AGAINST(? IN BOOLEAN MODE)'];
        $othParams = [$userId, $ftQuery];
        if ($byInn) { $conds[] = 'l.inn LIKE ?'; $othParams[] = $digits . '%'; /* только цифры, экранировать нечего */ }
        try {
            $st = $pdo->prepare($othSelect . implode(' OR ', $conds) . ') LIMIT 20');
            $st->execute($othParams);
        } catch (PDOException $e) {
            $st = null; // нет FULLTEXT-индекса (хостинг не дал создать) — fallback ниже
        }
    }
    if ($st === null) {
        $othParams = [$userId];
        $conds = [];
        if ($byTitle) { $conds[] = 'l.title LIKE ?'; $othParams[] = $titlePat; }
        if ($byInn) { $conds[] = 'l.inn LIKE ?'; $othParams[] = crm_like_pat($digits); }
        $st = $pdo->prepare($othSelect . implode(' OR ', $conds) . ') LIMIT 20');
        $st->execute($othParams);
    }
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

function crm_name_key(string $name): string {
    $name = trim($name);
    if (function_exists('mb_strtolower')) return mb_strtolower($name, 'UTF-8');
    return strtolower($name);
}

function crm_reserved_user_name(string $name): bool {
    $n = crm_name_key($name);
    return $n === 'система' || $n === 'system';
}

/**
 * Активность клиентов по месяцам: для каждого ИНН возвращает массив месяцев,
 * в которых была хотя бы одна поездка (заявка). Группирует все лиды с одинаковым ИНН.
 * Включает лиды без заявок (они отображаются с пустыми месяцами).
 * Возвращает: [ { inn, title, months: { 1: count, 3: count, ... } } ]
 */
function crm_client_activity(PDO $pdo, int $userId, int $year): array {
    // 1) Все клиенты (по ИНН) — включая тех, у кого нет заявок
    $stClients = $pdo->prepare("SELECT inn, MAX(title) AS title FROM crm_leads WHERE user_id = ? AND inn <> '' GROUP BY inn ORDER BY inn");
    $stClients->execute([$userId]);
    $clients = [];
    foreach ($stClients->fetchAll() as $r) {
        $inn = (string) $r['inn'];
        $clients[$inn] = ['inn' => $inn, 'title' => $r['title'], 'months' => []];
    }
    if (!$clients) return [];
    // 2) Заявки за год — добавляем месяцы к существующим клиентам.
    // Год фильтруем диапазоном по created_at (миллисекунды), а не YEAR(FROM_UNIXTIME(...)):
    // функция от колонки не даёт использовать индекс и заставляла считать её для каждой строки.
    $from = (new DateTimeImmutable("$year-01-01 00:00:00"))->getTimestamp() * 1000;
    $to = (new DateTimeImmutable(($year + 1) . "-01-01 00:00:00"))->getTimestamp() * 1000;
    $stTrips = $pdo->prepare("SELECT l.inn, MONTH(FROM_UNIXTIME(a.created_at / 1000)) AS month, COUNT(*) AS trips
            FROM crm_lead_apps a
            INNER JOIN crm_leads l ON l.id = a.lead_id
            WHERE l.user_id = ?
              AND a.created_at >= ? AND a.created_at < ?
              AND l.inn <> ''
            GROUP BY l.inn, month");
    $stTrips->execute([$userId, $from, $to]);
    foreach ($stTrips->fetchAll() as $r) {
        $inn = (string) $r['inn'];
        if (isset($clients[$inn])) {
            $clients[$inn]['months'][(int) $r['month']] = (int) $r['trips'];
        }
    }
    return array_values($clients);
}

/**
 * Системный комментарий определяется по пересечению двух условий: user_id = 0 И author = 'Система'.
 * Только user_id = 0 — недостаточно: миграция v4 могла оставить user_id = 0 у старых пользовательских
 * комментариев, чей автор не нашёлся в crm_users. Только author — хрупко (ручная правка БД).
 * Пересечение исключает оба ложных срабатывания.
 */
function crm_is_sys_comment(array $c): bool {
    return (int) ($c['user_id'] ?? -1) === 0
        && trim((string) ($c['author'] ?? '')) === 'Система';
}
