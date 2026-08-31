<?php
/**
 * CRM «Детроид» — API на MySQL.
 * Контракт: api.php?action=...
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!is_dir(CRM_UPLOAD_DIR)) mkdir(CRM_UPLOAD_DIR, 0775, true);

session_name(CRM_SESSION_NAME);
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_path' => '/',
]);

function out(array $data): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function ok(array $extra = []): never { out(['success' => true] + $extra); }
function err(string $message, bool $needLogin = false): never {
    $r = ['success' => false, 'error' => $message];
    if ($needLogin) $r['need_login'] = true;
    out($r);
}
function body_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function now_ms(): int { return (int) round(microtime(true) * 1000); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function strv($v, int $max = 300, string $fallback = ''): string {
    $s = trim(str_replace("\0", '', (string) ($v ?? '')));
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s !== '' ? $s : $fallback;
}
function require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if ($sent === '' || $have === '' || !hash_equals($have, $sent)) err('CSRF');
}
function require_user(PDO $pdo): array {
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$id) err('Сессия истекла', true);
    $u = crm_user_by_id($pdo, $id);
    if (!$u) err('Сессия истекла', true);
    return $u;
}
function require_admin(array $u): void {
    if (($u['role'] ?? '') !== 'admin') err('Нет прав');
}
function can_edit_comment(array $user, array $c): bool {
    if (($c['author'] ?? '') === 'Система') return false;
    return ($user['role'] ?? '') === 'admin' || ($c['author'] ?? '') === $user['name'];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = crm_pdo();

if ($action === 'check_auth') {
    $u = require_user($pdo);
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u)]);
}

if ($action === 'login') {
    $in = body_json();
    $email = mb_strtolower(strv($in['email'] ?? '', 120));
    $password = (string) ($in['password'] ?? '');
    if ($email === '' || $password === '') err('Заполните поля');
    $u = crm_user_by_email($pdo, $email);
    if (!$u || !password_verify($password, $u['password'])) err('Неверный e-mail или пароль');
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    unset($_SESSION['csrf']);
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u)]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    ok();
}

$user = require_user($pdo);
if ($method === 'POST') require_csrf();

switch ($action) {
    case 'get_data': {
        $hash = crm_data_hash($pdo);
        if (!empty($_GET['hash']) && hash_equals($hash, (string) $_GET['hash'])) {
            ok(['unchanged' => true, 'hash' => $hash]);
        }
        ok(['hash' => $hash, 'stages' => crm_stages($pdo), 'leads' => crm_leads_full($pdo), 'user' => crm_user_public($user)]);
    }

    case 'save_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        if ($id === '') $id = 'l_' . bin2hex(random_bytes(6));
        $stages = crm_stages($pdo);
        $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        $title = strv($in['title'] ?? ($row['title'] ?? ''), 200, 'Без названия');
        $inn = preg_replace('/\D/', '', strv($in['inn'] ?? ($row['inn'] ?? ''), 12));
        $phone = strv($in['phone'] ?? ($row['phone'] ?? ''), 40);
        $email = strv($in['email'] ?? ($row['email'] ?? ''), 120);
        $manager = strv($in['manager'] ?? ($row['manager'] ?? $user['name']), 80);
        $cargo = strv($in['cargo'] ?? ($row['cargo'] ?? ''), 300);
        $format = strv($in['format'] ?? ($row['format'] ?? ''), 300);
        $payment = strv($in['payment'] ?? ($row['payment'] ?? ''), 300);
        $ati = strv($in['ati'] ?? ($row['ati'] ?? ''), 300);
        $apps = isset($in['applicationsCount']) ? max(0, (int) $in['applicationsCount']) : (int) ($row['applications_count'] ?? 0);
        $stage = strv($in['stage'] ?? ($row['stage'] ?? ($stages[0] ?? 'Новый')), 80);
        if (!in_array($stage, $stages, true)) $stage = $row['stage'] ?? ($stages[0] ?? 'Новый');

        if (!$row) {
            $ins = $pdo->prepare('INSERT INTO crm_leads (id,title,inn,phone,email,manager,cargo,format,payment,ati,applications_count,stage,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$id, $title, $inn, $phone, $email, $manager, $cargo, $format, $payment, $ati, $apps, $stage, now_ms()]);
            crm_sys_comment($pdo, $id, 'Лид создан');
        } else {
            $upd = $pdo->prepare('UPDATE crm_leads SET title=?,inn=?,phone=?,email=?,manager=?,cargo=?,format=?,payment=?,ati=?,applications_count=?,stage=? WHERE id=?');
            $upd->execute([$title, $inn, $phone, $email, $manager, $cargo, $format, $payment, $ati, $apps, $stage, $id]);
        }
        ok(['id' => $id]);
    }

    case 'move_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $stage = strv($in['stage'] ?? '', 80);
        $stages = crm_stages($pdo);
        if ($stage === '' || !in_array($stage, $stages, true)) err('Нет такого этапа');
        $st = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) err('Лид не найден');
        if ($row['stage'] !== $stage) {
            $from = strv($in['from'] ?? '', 80) ?: $row['stage'];
            $pdo->prepare('UPDATE crm_leads SET stage = ? WHERE id = ?')->execute([$stage, $id]);
            crm_sys_comment($pdo, $id, "Статус изменен: {$from} ➔ {$stage}");
        }
        ok();
    }

    case 'delete_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $st = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) err('Лид не найден');
        $cids = $pdo->prepare('SELECT id FROM crm_comments WHERE lead_id = ?');
        $cids->execute([$id]);
        $ids = $cids->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $inQ = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM crm_attachments WHERE comment_id IN ($inQ)")->execute($ids);
            $pdo->prepare('DELETE FROM crm_comments WHERE lead_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM crm_leads WHERE id = ?')->execute([$id]);
        ok();
    }

    case 'add_comment': {
        $leadId = strv($_POST['lead_id'] ?? '', 80);
        $text = strv($_POST['text'] ?? '', 20000);
        $st = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
        $st->execute([$leadId]);
        if (!$st->fetch()) err('Лид не найден');
        $atts = [];
        $files = $_FILES['files'] ?? null;
        if (is_array($files) && !empty($files['name'])) {
            $names = is_array($files['name']) ? $files['name'] : [$files['name']];
            $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
            $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
            $types = is_array($files['type']) ? $files['type'] : [$files['type']];
            $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
            foreach ($names as $i => $name) {
                if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $size = (int) ($sizes[$i] ?? 0);
                if ($size > CRM_MAX_UPLOAD) err('Файл больше 5 МБ');
                $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename((string) $name)) ?: 'file';
                $fname = bin2hex(random_bytes(8)) . '_' . $safe;
                if (!move_uploaded_file($tmps[$i], CRM_UPLOAD_DIR . '/' . $fname)) err('Не удалось сохранить файл');
                $atts[] = ['name' => (string) $name, 'size' => $size, 'type' => (string) ($types[$i] ?? ''), 'dataUrl' => 'uploads/' . $fname];
            }
        }
        if ($text === '' && !$atts) err('Пусто');
        $cid = 'c_' . bin2hex(random_bytes(6));
        $pdo->prepare('INSERT INTO crm_comments (id, lead_id, text, author, time, edited_at) VALUES (?,?,?,?,?,NULL)')
            ->execute([$cid, $leadId, $text, $user['name'], now_ms()]);
        $insA = $pdo->prepare('INSERT INTO crm_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)');
        foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
        ok();
    }

    case 'edit_comment': {
        $in = body_json();
        $cid = strv($in['id'] ?? '', 80);
        $text = strv($in['text'] ?? '', 20000);
        if ($text === '') err('Пусто');
        $st = $pdo->prepare('SELECT * FROM crm_comments WHERE id = ?');
        $st->execute([$cid]);
        $c = $st->fetch();
        if (!$c) err('Комментарий не найден');
        if (!can_edit_comment($user, $c)) err('Нет прав');
        $pdo->prepare('UPDATE crm_comments SET text = ?, edited_at = ? WHERE id = ?')->execute([$text, now_ms(), $cid]);
        ok();
    }

    case 'delete_comment': {
        $in = body_json();
        $cid = strv($in['id'] ?? '', 80);
        $st = $pdo->prepare('SELECT * FROM crm_comments WHERE id = ?');
        $st->execute([$cid]);
        $c = $st->fetch();
        if (!$c) err('Комментарий не найден');
        if (!can_edit_comment($user, $c)) err('Нет прав');
        $pdo->prepare('DELETE FROM crm_attachments WHERE comment_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM crm_comments WHERE id = ?')->execute([$cid]);
        ok();
    }

    case 'save_stages': {
        require_admin($user);
        $in = body_json();
        $ns = $in['stages'] ?? null;
        if (!is_array($ns) || !$ns) err('Пустой список этапов');
        $ns = array_values(array_filter(array_map(fn($s) => strv($s, 80), $ns)));
        if (!$ns) err('Пустой список этапов');
        if (count($ns) !== count(array_unique($ns))) err('Имя занято');
        $old = crm_stages($pdo);
        $pdo->beginTransaction();
        try {
            if (count($old) === count($ns)) {
                $updL = $pdo->prepare('UPDATE crm_leads SET stage = ? WHERE stage = ?');
                foreach ($old as $i => $name) {
                    if ($name !== $ns[$i]) $updL->execute([$ns[$i], $name]);
                }
            }
            $pdo->exec('DELETE FROM crm_stages');
            $ins = $pdo->prepare('INSERT INTO crm_stages (name, position) VALUES (?,?)');
            foreach ($ns as $i => $name) $ins->execute([$name, $i]);
            $inQ = implode(',', array_fill(0, count($ns), '?'));
            $pdo->prepare("UPDATE crm_leads SET stage = ? WHERE stage NOT IN ($inQ)")->execute(array_merge([$ns[0]], $ns));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            err('Не удалось сохранить этапы');
        }
        ok();
    }

    case 'get_users': {
        require_admin($user);
        $rows = $pdo->query('SELECT id, name, email FROM crm_users ORDER BY id ASC')->fetchAll();
        foreach ($rows as &$r) $r['id'] = (int) $r['id'];
        ok(['users' => $rows]);
    }

    case 'register_user': {
        require_admin($user);
        $in = body_json();
        $name = strv($in['name'] ?? '', 80);
        $email = mb_strtolower(strv($in['email'] ?? '', 120));
        $pass = (string) ($in['password'] ?? '');
        if ($name === '' || $email === '' || $pass === '') err('Все поля');
        if (strlen($pass) < 6) err('Пароль мин. 6 символов');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        if (crm_user_by_email($pdo, $email)) err('E-mail уже занят');
        $pdo->prepare('INSERT INTO crm_users (name, email, password, role, created_at) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'user', now_ms()]);
        ok(['id' => (int) $pdo->lastInsertId()]);
    }

    case 'update_user': {
        require_admin($user);
        $in = body_json();
        $id = (int) ($in['id'] ?? 0);
        $name = strv($in['name'] ?? '', 80);
        $email = mb_strtolower(strv($in['email'] ?? '', 120));
        $pass = (string) ($in['password'] ?? '');
        if ($name === '' || $email === '') err('Обязательны Имя и Email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        $target = crm_user_by_id($pdo, $id);
        if (!$target) err('Сотрудник не найден');
        $other = crm_user_by_email($pdo, $email);
        if ($other && (int) $other['id'] !== $id) err('E-mail уже занят');
        $pdo->prepare('UPDATE crm_users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
        if ($pass !== '') {
            if (strlen($pass) < 6) err('Пароль мин. 6 символов');
            $pdo->prepare('UPDATE crm_users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
        ok();
    }

    case 'delete_user': {
        require_admin($user);
        $in = body_json();
        $id = (int) ($in['id'] ?? 0);
        if ($id === 1) err('Нельзя удалить основного администратора');
        if ($id === (int) $user['id']) err('Нельзя удалить себя');
        $st = $pdo->prepare('DELETE FROM crm_users WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) err('Сотрудник не найден');
        ok();
    }

    default:
        err('Неизвестное действие');
}
