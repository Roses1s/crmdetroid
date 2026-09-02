<?php
/**
 * CRM «Детроид» — API на MySQL.
 * Контракт: api.php?action=...
 */
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Нет config.php. Скопируйте config.example.php в config.php и впишите доступы к MySQL.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

// Старые config.php без новых констант
if (!defined('CRM_TRUSTED_PROXIES')) define('CRM_TRUSTED_PROXIES', getenv('CRM_TRUSTED_PROXIES') ?: '');

@header_remove('X-Powered-By');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

/**
 * Верить ли X-Forwarded-Proto от этого адреса.
 * Явный список CRM_TRUSTED_PROXIES — приоритет; без него — loopback и приватные сети
 * (типичная схема shared-хостинга: nginx на том же сервере перед Apache/PHP).
 * Подделка X-Forwarded-Proto здесь влияет лишь на флаг Secure у cookie и HSTS — не на доступ.
 */
function crm_ip_is_trusted_proxy(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    $list = crm_trusted_proxies();
    if ($list) return crm_ip_in_list($ip, $list);
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function crm_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') return true;
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($fwd === 'https' && crm_ip_is_trusted_proxy((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) return true;
    return false;
}

if (crm_is_https()) {
    header('Strict-Transport-Security: max-age=15552000');
}

if (!is_dir(CRM_UPLOAD_DIR)) mkdir(CRM_UPLOAD_DIR, 0775, true);

const CRM_IDLE_SEC = 8 * 3600;

function crm_session_opts(): array {
    return [
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_path' => '/',
        'cookie_secure' => crm_is_https(),
    ];
}
/**
 * Собственный каталог для файлов сессий (data/sessions, закрыт .htaccess и лежит вне uploads).
 * На shared-хостинге общий session.save_path чистится сборщиком мусора с чужим gc_maxlifetime
 * (обычно 24 минуты) — наш 8-часовой лимит там не действует, и пользователей выкидывало бы
 * посреди работы. В своём каталоге GC видит только наши файлы и наш срок жизни.
 */
function crm_session_dir(): ?string {
    static $dir = null;
    if ($dir !== null) return $dir === '' ? null : $dir;
    $d = __DIR__ . '/data/sessions';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    $dir = (is_dir($d) && is_writable($d)) ? $d : '';
    return $dir === '' ? null : $dir;
}
function crm_session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string) CRM_IDLE_SEC);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    if (($dir = crm_session_dir()) !== null && ini_get('session.save_handler') === 'files') {
        session_save_path($dir);
        // GC в своём каталоге выполняем сами (см. crm_session_gc): вероятностный GC PHP
        // на некоторых хостингах отключён (gc_probability=0), а старые файлы копились бы вечно.
        ini_set('session.gc_probability', '0');
    }
    session_name(CRM_SESSION_NAME);
    session_start(crm_session_opts());
    crm_session_gc();
}
/** Раз в ~100 запросов удаляет файлы сессий старше CRM_IDLE_SEC в своём каталоге. */
function crm_session_gc(): void {
    $dir = crm_session_dir();
    if ($dir === null || random_int(1, 100) !== 1) return;
    $limit = time() - CRM_IDLE_SEC - 60;
    foreach (glob($dir . '/sess_*') ?: [] as $f) {
        if (@filemtime($f) < $limit) @unlink($f);
    }
}
function crm_session_kill(string $msg = 'Сессия истекла'): never {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }
    err($msg, true);
}
function crm_session_touch(bool $touch = true): void {
    $last = (int) ($_SESSION['last'] ?? 0);
    if ($last > 0 && (time() - $last) > CRM_IDLE_SEC) crm_session_kill();
    if ($touch) {
        $_SESSION['last'] = time();
        $ip = crm_client_ip();
        if ($ip !== '') $_SESSION['ip'] = $ip;
    }
}
function crm_csrf_secret(): string {
    $dir = __DIR__ . '/data';
    $f = $dir . '/.csrf_secret';
    $raw = is_file($f) ? (string) file_get_contents($f) : '';
    if (strlen($raw) < 32) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $raw = bin2hex(random_bytes(32));
        @file_put_contents($f, $raw, LOCK_EX);
        $check = is_file($f) ? (string) file_get_contents($f) : '';
        if (strlen($check) >= 32) $raw = $check;
    }
    if (strlen($raw) < 32) {
        $raw = CRM_SESSION_NAME . '|' . CRM_DB_NAME . '|' . CRM_DB_USER . '|' . (defined('CRM_DB_PASS') ? CRM_DB_PASS : '');
    }
    return hash('sha256', $raw, true);
}
function crm_login_csrf_issue(PDO $pdo): string {
    $ts = (string) time();
    $rnd = bin2hex(random_bytes(16));
    $mac = hash_hmac('sha256', $ts . '.' . $rnd, crm_csrf_secret());
    $token = $ts . '.' . $rnd . '.' . $mac;
    try {
        $pdo->prepare('INSERT INTO crm_login_nonces (h, created_at) VALUES (?, ?)')->execute([hash('sha256', $token), now_ms()]);
        $pdo->prepare('DELETE FROM crm_login_nonces WHERE created_at < ?')->execute([now_ms() - 20 * 60 * 1000]);
    } catch (Throwable $e) { /* table may appear on next boot */ }
    return $token;
}
function crm_login_csrf_ok(PDO $pdo, string $sent): bool {
    $parts = explode('.', $sent, 3);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_xdigit($parts[1]) || strlen($parts[1]) !== 32) return false;
    if (abs(time() - (int) $parts[0]) > 900) return false;
    $expect = hash_hmac('sha256', $parts[0] . '.' . $parts[1], crm_csrf_secret());
    if (!hash_equals($expect, $parts[2])) return false;
    try {
        $st = $pdo->prepare('DELETE FROM crm_login_nonces WHERE h = ?');
        $st->execute([hash('sha256', $sent)]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function crm_pass_ok(string $pass): ?string {
    if (strlen($pass) < 8) return 'Пароль мин. 8 символов';
    if (defined('CRM_DEFAULT_ADMIN_PASS') && $pass === CRM_DEFAULT_ADMIN_PASS) return 'Придумайте другой пароль';
    return null;
}
function crm_want_json(): bool {
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    return str_starts_with($ct, 'application/json');
}

// out() / ok() / err() / now_ms() объявлены в db.php (он подключается первым и сам ими пользуется).
function body_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function strv($v, int $max = 300, string $fallback = ''): string {
    $s = trim(str_replace("\0", '', (string) ($v ?? '')));
    if (function_exists('mb_strlen') && mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    elseif (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s !== '' ? $s : $fallback;
}
function require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if ($sent === '' || $have === '' || strlen((string) $sent) !== strlen((string) $have) || !hash_equals((string) $have, (string) $sent)) err('CSRF');
}
function require_user(PDO $pdo, bool $touch = true): array {
    if (session_status() !== PHP_SESSION_ACTIVE) err('Сессия истекла', true);
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$id) err('Сессия истекла', true);
    crm_session_touch($touch);
    $u = crm_user_by_id($pdo, $id);
    if (!$u) crm_session_kill();
    return $u;
}
function require_admin(array $u): void {
    if (($u['role'] ?? '') !== 'admin') err('Нет прав');
}
/**
 * Лимит запросов на сессию. Отдельная корзина ($bucket) на API и на отдачу файлов:
 * страница с сотней картинок в логе не должна «съедать» лимит основного API.
 */
function crm_session_throttled(int $max = 90, int $window = 60, string $bucket = 'api'): bool {
    $kT = '_rl_' . $bucket . '_t';
    $kN = '_rl_' . $bucket . '_n';
    $now = time();
    $win = (int) ($_SESSION[$kT] ?? 0);
    $n = (int) ($_SESSION[$kN] ?? 0);
    if ($win === 0 || ($now - $win) >= $window) {
        $_SESSION[$kT] = $now;
        $_SESSION[$kN] = 1;
        return false;
    }
    $_SESSION[$kN] = $n + 1;
    return $_SESSION[$kN] > $max;
}
function crm_anon_throttled(PDO $pdo, string $ip, string $key, int $max, int $windowMs): bool {
    if ($ip === '') $ip = '0.0.0.0';
    $since = now_ms() - $windowMs;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE ip = ? AND email = ? AND attempted_at > ?');
        $st->execute([$ip, $key, $since]);
        if ((int) $st->fetchColumn() >= $max) return true;
        $pdo->prepare('INSERT INTO crm_login_attempts (email, ip, attempted_at) VALUES (?,?,?)')->execute([$key, $ip, now_ms()]);
    } catch (Throwable $e) {
        return false;
    }
    return false;
}
function can_edit_comment(array $user, array $c): bool {
    if (crm_is_sys_comment($c)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    $uid = (int) ($c['user_id'] ?? 0);
    if ($uid > 0) return $uid === (int) $user['id'];
    return ($c['author'] ?? '') === ($user['name'] ?? '');
}
function can_delete_comment(array $user, array $c): bool {
    if (crm_is_sys_comment($c)) return true;
    return can_edit_comment($user, $c);
}
function crm_discard_uploads(array $atts): void {
    foreach ($atts as $a) {
        if (!empty($a['dataUrl'])) crm_unlink_upload((string) $a['dataUrl']);
    }
}
function crm_take_uploads(int $max): array {
    $atts = [];
    if ($max <= 0) return $atts;
    $files = $_FILES['files'] ?? null;
    if (!is_array($files) || empty($files['name'])) return $atts;
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];
    $types = is_array($files['type']) ? $files['type'] : [$files['type']];
    $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
    foreach ($names as $i => $name) {
        if (count($atts) >= $max) break;
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $size = (int) ($sizes[$i] ?? 0);
        if ($size > CRM_MAX_UPLOAD) { crm_discard_uploads($atts); err('Файл больше 5 МБ'); }
        $ext = crm_allowed_upload((string) $name);
        if ($ext === null) { crm_discard_uploads($atts); err('Этот тип файла не разрешён'); }
        if (!crm_upload_magic_ok((string) ($tmps[$i] ?? ''), $ext)) { crm_discard_uploads($atts); err('Файл не соответствует типу'); }
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($tmps[$i], CRM_UPLOAD_DIR . '/' . $fname)) { crm_discard_uploads($atts); err('Не удалось сохранить файл'); }
        $mime = crm_image_mime($ext) ?? (string) ($types[$i] ?? '');
        $atts[] = ['name' => basename((string) $name), 'size' => $size, 'type' => $mime, 'dataUrl' => 'uploads/' . $fname];
    }
    return $atts;
}
function crm_edit_comment_input(): array {
    if (crm_want_json()) {
        $in = body_json();
        return [strv($in['id'] ?? '', 80), strv($in['text'] ?? '', 20000)];
    }
    return [strv($_POST['id'] ?? '', 80), strv($_POST['text'] ?? '', 20000)];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$hasSess = isset($_COOKIE[CRM_SESSION_NAME]) && (string) $_COOKIE[CRM_SESSION_NAME] !== '';

try {

if ($hasSess) crm_session_boot();

$pdo = crm_pdo();

if ($action === 'csrf') {
    // Один токен = одна попытка входа. Лимит с запасом на офисный NAT (несколько человек за одним IP);
    // сам вход защищён отдельно: 8 попыток на (email, IP) и 80 на IP за 15 минут.
    if (crm_anon_throttled($pdo, crm_client_ip(), '#csrf', 120, 15 * 60 * 1000)) {
        err('Слишком много запросов. Подождите минуту');
    }
    ok(['csrf' => crm_login_csrf_issue($pdo)]);
}

if ($action === 'file') {
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$hasSess || !$id || !crm_user_by_id($pdo, $id)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Need login';
        exit;
    }
    crm_session_touch();
    if (crm_session_throttled(600, 60, 'file')) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many requests';
        exit;
    }
    $uFile = crm_user_by_id($pdo, $id);
    if (!$uFile) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Need login';
        exit;
    }
    crm_serve_file($pdo, (string) ($_GET['f'] ?? ''), $uFile);
}

if ($action === 'check_auth') {
    if (!$hasSess) err('Сессия истекла', true);
    $u = require_user($pdo);
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u), 'mustChangePassword' => !empty($_SESSION['must_change'])]);
}

if ($action === 'login') {
    if ($method !== 'POST' || !crm_want_json()) err('CSRF');
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !crm_login_csrf_ok($pdo, $sent)) err('CSRF');
    $in = body_json();
    $email = mb_strtolower(strv($in['email'] ?? '', 120));
    $password = (string) ($in['password'] ?? '');
    $ip = crm_client_ip();
    if ($email === '' || $password === '') err('Заполните поля');
    if (crm_login_throttled($pdo, $email, $ip)) err('Слишком много попыток. Подождите 15 минут');
    $u = crm_user_by_email($pdo, $email);
    $dummy = '$2y$10$ykv1D8WgrA05XNmayGz9Zed0GAmu7FJlclV24IoQpA8sgvCYrPxoK';
    $hash = is_array($u) ? (string) ($u['password'] ?? $dummy) : $dummy;
    if ($hash === '' || !preg_match('/^\$2[aby]\$/', $hash)) $hash = $dummy;
    $okPass = password_verify($password, $hash);
    if (!$u || !$okPass) {
        crm_login_fail($pdo, $email, $ip);
        err('Неверный e-mail или пароль');
    }
    crm_login_ok($pdo, $email);
    crm_session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['ip'] = $ip;
    $_SESSION['last'] = time();
    unset($_SESSION['csrf']);
    if (defined('CRM_DEFAULT_ADMIN_PASS') && $password === CRM_DEFAULT_ADMIN_PASS) {
        $_SESSION['must_change'] = 1;
    } else {
        unset($_SESSION['must_change']);
    }
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u), 'mustChangePassword' => !empty($_SESSION['must_change'])]);
}

if ($action === 'logout') {
    if (!$hasSess) ok();
    if ($method !== 'POST') err('CSRF');
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = (string) ($_SESSION['csrf'] ?? '');
    if ($have !== '' && ($sent === '' || strlen($sent) !== strlen($have) || !hash_equals($have, $sent))) err('CSRF');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    ok();
}

if (!$hasSess && session_status() !== PHP_SESSION_ACTIVE) err('Сессия истекла', true);
$user = require_user($pdo);
if (!empty($_SESSION['must_change']) && $action !== 'change_password' && $action !== 'ui') {
    out(['success' => false, 'error' => 'Смените временный пароль', 'must_change_password' => true]);
}
if (crm_session_throttled()) err('Слишком много запросов. Подождите минуту');

// Только чтение — разрешён GET. Всё остальное меняет данные: строго POST + CSRF-токен.
// (Раньше мутация проходила и по GET без CSRF — например, GET save_lead создавал пустой лид.)
$readActions = ['ui', 'whoami', 'get_data', 'get_lead', 'get_comments', 'search_leads', 'get_directions', 'get_carriers', 'get_carrier', 'get_users'];
if (!in_array($action, $readActions, true)) {
    if ($method !== 'POST') err('Метод не поддерживается: нужен POST');
    require_csrf();
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    require_csrf();
}
$viewUid = crm_view_uid($pdo, $user);

switch ($action) {
    case 'whoami': {
        // Диагностика для админа: какой IP видит сервер (нужно для настройки CRM_TRUSTED_PROXIES).
        require_admin($user);
        ok([
            'ip' => crm_client_ip(),
            'remoteAddr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'xForwardedFor' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'xRealIp' => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            'trustedProxies' => crm_trusted_proxies(),
            'behindTrustedProxy' => crm_behind_trusted_proxy(),
            'https' => crm_is_https(),
        ]);
    }

    case 'ui': {
        $path = __DIR__ . '/ui.html';
        if (!is_readable($path)) err('Нет интерфейса');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    case 'get_comments': {
        $id = strv($_GET['id'] ?? '', 80);
        if ($id === '' || !crm_lead_for_user($pdo, $id, $viewUid)) err('Лид не найден');
        ok(['comments' => crm_lead_comments($pdo, $id)]);
    }

    case 'get_lead': {
        $id = strv($_GET['id'] ?? '', 80);
        $row = $id === '' ? null : crm_lead_for_user($pdo, $id, $viewUid);
        if (!$row) err('Лид не найден');
        $lead = crm_lead_row_to_api($row, true);
        $lead['applications'] = crm_lead_apps($pdo, $id);
        $lead['applicationsCount'] = count($lead['applications']);
        $lead['appsStats'] = crm_apps_stats($pdo, $viewUid, $id, (string) ($row['inn'] ?? ''));
        ok(['lead' => $lead]);
    }

    case 'save_lead_app': {
        $in = body_json();
        $leadId = strv($in['leadId'] ?? '', 80);
        $row = $leadId === '' ? null : crm_lead_for_user($pdo, $leadId, $viewUid);
        if (!$row) err('Лид не найден');
        $from = crm_norm_city(strv($in['cityFrom'] ?? '', 80));
        $to = crm_norm_city(strv($in['cityTo'] ?? '', 80));
        if ($from === '' || $to === '') err('Укажите откуда и куда');
        $rate = crm_money_in(preg_replace('/\D/', '', strv($in['rate'] ?? '', 40)) ?? '');
        if ($rate !== null && strlen($rate) > 12) err('Ставка: не больше 12 цифр');
        $margin = crm_parse_money(strv($in['margin'] ?? '', 40));
        if ($margin === null) err('Маржа: число, копейки через запятую');
        $margin = crm_money_in($margin);
        $vat = !empty($in['vat']) ? 1 : 0;
        $company = strv($in['carrierCompany'] ?? '', 200);
        $inn = preg_replace('/\D/', '', strv($in['carrierInn'] ?? '', 12)) ?? '';
        if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) err('ИНН 10 или 12 цифр');
        $name = strv($in['carrierName'] ?? '', 80);
        $phone = strv($in['carrierPhone'] ?? '', 40);
        $id = strv($in['id'] ?? '', 80);
        $now = now_ms();
        $existing = $id !== '' ? crm_lead_app_by_id($pdo, $id) : null;
        if ($id !== '' && (!$existing || (string) $existing['lead_id'] !== $leadId)) err('Заявка не найдена');
        if ($id === '') $id = 'a_' . bin2hex(random_bytes(6));
        try {
            if (!$existing) {
                $pdo->prepare('INSERT INTO crm_lead_apps (id, lead_id, city_from, city_to, rate, margin, vat, carrier_company, carrier_inn, carrier_name, carrier_phone, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $leadId, $from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $now]);
            } else {
                $pdo->prepare('UPDATE crm_lead_apps SET city_from=?, city_to=?, rate=?, margin=?, vat=?, carrier_company=?, carrier_inn=?, carrier_name=?, carrier_phone=?, updated_at=? WHERE id=? AND lead_id=?')
                    ->execute([$from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $id, $leadId]);
            }
        } catch (PDOException $e) {
            err('Не удалось сохранить заявку');
        }
        $n = crm_sync_lead_apps_count($pdo, $leadId);
        $rev = crm_touch_lead($pdo, $leadId);
        $saved = crm_lead_app_by_id($pdo, $id);
        ok([
            'id' => $id,
            'application' => $saved ? crm_lead_app_to_api($saved) : null,
            'applicationsCount' => $n,
            'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($row['inn'] ?? '')),
            'updatedAt' => $rev,
        ]);
    }

    case 'delete_lead_app': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $app = $id === '' ? null : crm_lead_app_by_id($pdo, $id);
        if (!$app) err('Заявка не найдена');
        $leadId = (string) $app['lead_id'];
        if (!crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
        try {
            $pdo->prepare('DELETE FROM crm_lead_apps WHERE id = ? AND lead_id = ?')->execute([$id, $leadId]);
        } catch (PDOException $e) {
            err('Не удалось удалить');
        }
        $n = crm_sync_lead_apps_count($pdo, $leadId);
        $rev = crm_touch_lead($pdo, $leadId);
        $leadRow = crm_lead_for_user($pdo, $leadId, $viewUid);
        ok([
            'applicationsCount' => $n,
            'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($leadRow['inn'] ?? '')),
            'updatedAt' => $rev,
        ]);
    }

    case 'search_leads': {
        $q = strv($_GET['q'] ?? '', 120);
        $out = crm_search_leads($pdo, $viewUid, $q);
        if (($user['role'] ?? '') === 'admin') $out['employees'] = crm_search_employees($pdo, $q);
        ok($out);
    }

    case 'get_directions': {
        $q = strv($_GET['q'] ?? '', 80);
        ok(['directions' => crm_directions_list($pdo, $q)]);
    }

    case 'save_direction': {
        $in = body_json();
        $from = crm_norm_city(strv($in['cityFrom'] ?? '', 80));
        $to = crm_norm_city(strv($in['cityTo'] ?? '', 80));
        if ($from === '' || $to === '') err('Укажите города откуда и куда');
        $id = strv($in['id'] ?? '', 80);
        $uid = (int) $user['id'];
        if ($id === '') {
            $dup = $pdo->prepare('SELECT id FROM crm_directions WHERE city_from = ? AND city_to = ?');
            $dup->execute([$from, $to]);
            if ($dup->fetch()) err('Такое направление уже есть');
            $id = 'd_' . bin2hex(random_bytes(6));
            $pdo->prepare('INSERT INTO crm_directions (id, city_from, city_to, created_by, created_at) VALUES (?,?,?,?,?)')
                ->execute([$id, $from, $to, $uid, now_ms()]);
        } else {
            if (!crm_direction_by_id($pdo, $id)) err('Направление не найдено');
            $dup = $pdo->prepare('SELECT id FROM crm_directions WHERE city_from = ? AND city_to = ? AND id <> ?');
            $dup->execute([$from, $to, $id]);
            if ($dup->fetch()) err('Такое направление уже есть');
            $pdo->prepare('UPDATE crm_directions SET city_from = ?, city_to = ? WHERE id = ?')->execute([$from, $to, $id]);
        }
        crm_meta_bump($pdo, 'routes');
        ok(['id' => $id]);
    }

    case 'delete_direction': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        if (!crm_direction_by_id($pdo, $id)) err('Направление не найдено');
        $ids = $pdo->prepare('SELECT id FROM crm_carriers WHERE direction_id = ?');
        $ids->execute([$id]);
        $urls = [];
        $pdo->beginTransaction();
        try {
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $urls = array_merge($urls, crm_purge_carrier($pdo, (string) $cid, false));
            }
            $pdo->prepare('DELETE FROM crm_directions WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            err('Не удалось удалить');
        }
        crm_unlink_urls($urls);
        crm_meta_bump($pdo, 'routes');
        ok();
    }

    case 'get_carriers': {
        $id = strv($_GET['id'] ?? '', 80);
        $dir = crm_direction_by_id($pdo, $id);
        if (!$dir) err('Направление не найдено');
        ok([
            'direction' => [
                'id' => $dir['id'],
                'cityFrom' => $dir['city_from'],
                'cityTo' => $dir['city_to'],
                'createdByName' => $dir['creator'] ?: '',
            ],
            'carriers' => crm_carriers_list($pdo, $id),
        ]);
    }

    case 'save_carrier': {
        $in = body_json();
        $dirId = strv($in['directionId'] ?? '', 80);
        if (!crm_direction_by_id($pdo, $dirId)) err('Направление не найдено');
        $name = strv($in['name'] ?? '', 120);
        if ($name === '') err('Укажите имя или название');
        $phone = strv($in['phone'] ?? '', 40);
        $company = strv($in['company'] ?? '', 200);
        $id = strv($in['id'] ?? '', 80);
        $uid = (int) $user['id'];
        $now = now_ms();
        if ($id === '') {
            $note = strv($in['note'] ?? '', 2000);
            $id = 'k_' . bin2hex(random_bytes(6));
            $pdo->prepare('INSERT INTO crm_carriers (id, direction_id, name, phone, company, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $dirId, $name, $phone, $company, $note, $uid, $now, $now]);
        } else {
            $st = $pdo->prepare('SELECT * FROM crm_carriers WHERE id = ? AND direction_id = ?');
            $st->execute([$id, $dirId]);
            $cur = $st->fetch();
            if (!$cur) err('Контакт не найден');
            if (array_key_exists('updatedAt', $in) && (int) ($cur['updated_at'] ?? 0) !== (int) $in['updatedAt'] && (int) ($cur['updated_at'] ?? 0) !== 0) {
                err('Карточка изменена в другом месте');
            }
            $note = array_key_exists('note', $in) ? strv($in['note'] ?? '', 2000) : (string) ($cur['note'] ?? '');
            $rev = (int) ($cur['updated_at'] ?? 0);
            $upd = $pdo->prepare('UPDATE crm_carriers SET name = ?, phone = ?, company = ?, note = ?, updated_at = ? WHERE id = ? AND updated_at = ?');
            $upd->execute([$name, $phone, $company, $note, $now, $id, $rev]);
            if ($upd->rowCount() === 0 && $rev !== 0) err('Карточка изменена в другом месте');
            if ($upd->rowCount() === 0) {
                $pdo->prepare('UPDATE crm_carriers SET name = ?, phone = ?, company = ?, note = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, $phone, $company, $note, $now, $id]);
            }
        }
        crm_meta_bump($pdo, 'routes');
        ok(['id' => $id, 'updatedAt' => $now]);
    }

    case 'delete_carrier': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        if (!crm_carrier_by_id($pdo, $id)) err('Контакт не найден');
        crm_purge_carrier($pdo, $id);
        crm_meta_bump($pdo, 'routes');
        ok();
    }

    case 'get_carrier': {
        $id = strv($_GET['id'] ?? '', 80);
        $row = crm_carrier_by_id($pdo, $id);
        if (!$row) err('Контакт не найден');
        $dir = crm_direction_by_id($pdo, (string) $row['direction_id']);
        ok([
            'carrier' => [
                'id' => $row['id'],
                'directionId' => $row['direction_id'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'company' => $row['company'],
                'note' => $row['note'] ?? '',
                'createdByName' => $row['creator'] ?: '',
                'updatedAt' => (int) ($row['updated_at'] ?? $row['created_at'] ?? 0),
            ],
            'direction' => $dir ? [
                'id' => $dir['id'],
                'cityFrom' => $dir['city_from'],
                'cityTo' => $dir['city_to'],
            ] : null,
            'comments' => crm_carrier_comments($pdo, $id),
        ]);
    }

    case 'add_carrier_comment': {
        $carrierId = strv($_POST['carrier_id'] ?? '', 80);
        $text = strv($_POST['text'] ?? '', 20000);
        if (!crm_carrier_by_id($pdo, $carrierId)) err('Контакт не найден');
        $atts = crm_take_uploads(8);
        if ($text === '' && !$atts) err('Пусто');
        $cid = 'cc_' . bin2hex(random_bytes(6));
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO crm_carrier_comments (id, carrier_id, text, author, user_id, time, edited_at) VALUES (?,?,?,?,?,?,NULL)')
                ->execute([$cid, $carrierId, $text, $user['name'], (int) $user['id'], now_ms()]);
            $insA = $pdo->prepare('INSERT INTO crm_carrier_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)');
            foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            err('Не удалось сохранить');
        }
        $rev = crm_touch_carrier($pdo, $carrierId);
        crm_meta_bump($pdo, 'routes');
        ok(['updatedAt' => $rev]);
    }

    case 'edit_carrier_comment': {
        [$cid, $text] = crm_edit_comment_input();
        $c = crm_carrier_comment_by_id($pdo, $cid);
        if (!$c) err('Комментарий не найден');
        if (!can_edit_comment($user, $c)) err('Нет прав');
        $have = 0;
        try {
            $stN = $pdo->prepare('SELECT COUNT(*) FROM crm_carrier_attachments WHERE comment_id = ?');
            $stN->execute([$cid]);
            $have = (int) $stN->fetchColumn();
        } catch (PDOException $e) { $have = 0; }
        $atts = crm_take_uploads(max(0, 8 - $have));
        if ($text === '' && $have === 0 && !$atts) { crm_discard_uploads($atts); err('Пусто'); }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE crm_carrier_comments SET text = ?, edited_at = ? WHERE id = ?')->execute([$text, now_ms(), $cid]);
            if ($atts) {
                $insA = $pdo->prepare('INSERT INTO crm_carrier_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)');
                foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            err('Не удалось сохранить');
        }
        $rev = crm_touch_carrier($pdo, (string) $c['carrier_id']);
        crm_meta_bump($pdo, 'routes');
        ok(['updatedAt' => $rev]);
    }

    case 'delete_carrier_comment': {
        $in = body_json();
        $cid = strv($in['id'] ?? '', 80);
        $c = crm_carrier_comment_by_id($pdo, $cid);
        if (!$c) err('Комментарий не найден');
        if (!can_delete_comment($user, $c)) err('Нет прав');
        $urls = crm_att_urls($pdo, 'crm_carrier_attachments', [$cid]);
        $pdo->beginTransaction();
        try {
            crm_delete_att_rows($pdo, 'crm_carrier_attachments', [$cid]);
            $pdo->prepare('DELETE FROM crm_carrier_comments WHERE id = ?')->execute([$cid]);
            $rev = crm_touch_carrier($pdo, (string) $c['carrier_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            err('Не удалось удалить');
        }
        crm_unlink_urls($urls);
        crm_meta_bump($pdo, 'routes');
        ok(['updatedAt' => $rev]);
    }

    case 'get_data': {
        $uid = $viewUid;
        $hash = substr(hash('sha256', crm_board_rev($pdo, $uid)), 0, 32);
        $client = (string) ($_GET['hash'] ?? '');
        if ($client !== '' && strlen($client) === strlen($hash) && hash_equals($hash, $client)) {
            ok(['unchanged' => true, 'hash' => $hash]);
        }
        $stages = crm_stages($pdo, $uid);
        $leads = crm_leads_full($pdo, $uid);
        ok(['hash' => $hash, 'stages' => $stages, 'leads' => $leads, 'user' => crm_user_public($user), 'colleagues' => crm_colleagues($pdo)]);
    }

    case 'save_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        if ($id === '') $id = 'l_' . bin2hex(random_bytes(6));
        $uid = $viewUid;
        $stages = crm_stages($pdo, $uid);
        $row = crm_lead_for_user($pdo, $id, $uid);
        if (!$row) {
            $any = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
            $any->execute([$id]);
            if ($any->fetch()) err('Лид не найден');
        }

        $title = strv($in['title'] ?? ($row['title'] ?? ''), 200, 'Без названия');
        $inn = preg_replace('/\D/', '', strv($in['inn'] ?? ($row['inn'] ?? ''), 12)) ?? '';
        if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) err('ИНН 10 или 12 цифр');
        $phone = strv($in['phone'] ?? ($row['phone'] ?? ''), 40);
        $email = strv($in['email'] ?? ($row['email'] ?? ''), 120);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        $manager = strv($in['manager'] ?? ($row['manager'] ?? $user['name']), 80);
        $logistName = strv($in['logistName'] ?? ($row['logist_name'] ?? ''), 80);
        $logistPhone = strv($in['logistPhone'] ?? ($row['logist_phone'] ?? ''), 40);
        $cargo = strv($in['cargo'] ?? ($row['cargo'] ?? ''), 300);
        $format = strv($in['format'] ?? ($row['format'] ?? ''), 300);
        $payment = strv($in['payment'] ?? ($row['payment'] ?? ''), 300);
        $ati = strv($in['ati'] ?? ($row['ati'] ?? ''), 300);
        $apps = 0;
        $stage = strv($in['stage'] ?? ($row['stage'] ?? ($stages[0] ?? 'Новый')), 80);
        if (!in_array($stage, $stages, true)) $stage = $row['stage'] ?? ($stages[0] ?? 'Новый');

        if ($row && array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== (int) $in['updatedAt']) {
            err('Карточка изменена в другом месте');
        }

        $now = now_ms();
        $transferredTo = null;
        $pdo->beginTransaction();
        try {
            if (!$row) {
                $ins = $pdo->prepare('INSERT INTO crm_leads (id,user_id,title,inn,phone,email,manager,logist_name,logist_phone,cargo,format,payment,ati,applications_count,stage,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$id, $uid, $title, $inn, $phone, $email, $manager, $logistName, $logistPhone, $cargo, $format, $payment, $ati, $apps, $stage, $now, $now]);
                crm_sys_comment($pdo, $id, 'Лид создан');
            } else {
                $upd = $pdo->prepare('UPDATE crm_leads SET title=?,inn=?,phone=?,email=?,manager=?,logist_name=?,logist_phone=?,cargo=?,format=?,payment=?,ati=?,stage=?,updated_at=? WHERE id=? AND user_id=? AND updated_at=?');
                $upd->execute([$title, $inn, $phone, $email, $manager, $logistName, $logistPhone, $cargo, $format, $payment, $ati, $stage, $now, $id, $uid, (int) $row['updated_at']]);
                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    err('Карточка изменена в другом месте');
                }
            }
            if (!empty($in['transfer'])) {
                $match = crm_match_employee($pdo, $manager);
                if ($match === 'ambiguous') {
                    $pdo->rollBack();
                    err('Несколько сотрудников с такой фамилией — напишите имя полностью');
                }
                if (is_array($match) && (int) $match['id'] !== $uid) {
                    $toId = (int) $match['id'];
                    $toStages = crm_stages($pdo, $toId);
                    $newStage = in_array($stage, $toStages, true) ? $stage : ($toStages[0] ?? $stage);
                    $now2 = now_ms();
                    $tr = $pdo->prepare('UPDATE crm_leads SET user_id = ?, stage = ?, manager = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ?');
                    $tr->execute([$toId, $newStage, $match['name'], $now2, $id, $uid, $now]);
                    if ($tr->rowCount() === 0) {
                        $pdo->rollBack();
                        err('Карточка изменена в другом месте');
                    }
                    // Отправитель — владелец доски, а не тот, кто нажал (админ может работать с чужой доски через ?as=).
                    $fromName = $uid === (int) $user['id'] ? (string) $user['name'] : (string) ((crm_user_by_id($pdo, $uid)['name'] ?? '') ?: $user['name']);
                    $via = $uid === (int) $user['id'] ? '' : ' (передал ' . $user['name'] . ')';
                    crm_sys_comment($pdo, $id, 'Лид передан: ' . $fromName . ' → ' . $match['name'] . $via);
                    $transferredTo = $match['name'];
                    $now = $now2;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException) err('Не удалось сохранить');
            throw $e;
        }
        if ($transferredTo !== null) ok(['id' => $id, 'transferred' => true, 'to' => $transferredTo, 'updatedAt' => $now]);
        ok(['id' => $id, 'updatedAt' => $now]);
    }

    case 'move_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $stage = strv($in['stage'] ?? '', 80);
        $uid = $viewUid;
        $stages = crm_stages($pdo, $uid);
        if ($stage === '' || !in_array($stage, $stages, true)) err('Нет такого этапа');
        $row = crm_lead_for_user($pdo, $id, $uid);
        if (!$row) err('Лид не найден');
        if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== (int) $in['updatedAt']) {
            err('Карточка изменена в другом месте');
        }
        $now = now_ms();
        if ($row['stage'] !== $stage) {
            $from = (string) $row['stage'];
            $stU = $pdo->prepare('UPDATE crm_leads SET stage = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ?');
            $stU->execute([$stage, $now, $id, $uid, (int) $row['updated_at']]);
            if ($stU->rowCount() === 0) err('Карточка изменена в другом месте');
            crm_sys_comment($pdo, $id, "Статус изменен: {$from} ➔ {$stage}");
        }
        ok(['updatedAt' => $row['stage'] === $stage ? (int) $row['updated_at'] : $now]);
    }

    case 'delete_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $uid = $viewUid;
        if (!crm_lead_for_user($pdo, $id, $uid)) err('Лид не найден');
        crm_purge_lead($pdo, $id);
        ok();
    }

    case 'add_comment': {
        $leadId = strv($_POST['lead_id'] ?? '', 80);
        $text = strv($_POST['text'] ?? '', 20000);
        if (!crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
        $atts = crm_take_uploads(8);
        if ($text === '' && !$atts) err('Пусто');
        $cid = 'c_' . bin2hex(random_bytes(6));
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO crm_comments (id, lead_id, text, author, user_id, time, edited_at) VALUES (?,?,?,?,?,?,NULL)')
                ->execute([$cid, $leadId, $text, $user['name'], (int) $user['id'], now_ms()]);
            $insA = $pdo->prepare('INSERT INTO crm_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)');
            foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            err('Не удалось сохранить');
        }
        $rev = crm_touch_lead($pdo, $leadId);
        ok(['updatedAt' => $rev]);
    }

    case 'edit_comment': {
        [$cid, $text] = crm_edit_comment_input();
        $c = crm_comment_for_user($pdo, $cid, $viewUid);
        if (!$c) err('Комментарий не найден');
        if (!can_edit_comment($user, $c)) err('Нет прав');
        $have = 0;
        try {
            $stN = $pdo->prepare('SELECT COUNT(*) FROM crm_attachments WHERE comment_id = ?');
            $stN->execute([$cid]);
            $have = (int) $stN->fetchColumn();
        } catch (PDOException $e) { $have = 0; }
        $atts = crm_take_uploads(max(0, 8 - $have));
        if ($text === '' && $have === 0 && !$atts) { crm_discard_uploads($atts); err('Пусто'); }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE crm_comments SET text = ?, edited_at = ? WHERE id = ?')->execute([$text, now_ms(), $cid]);
            if ($atts) {
                $insA = $pdo->prepare('INSERT INTO crm_attachments (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)');
                foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            err('Не удалось сохранить');
        }
        $rev = crm_touch_lead($pdo, (string) $c['lead_id']);
        ok(['updatedAt' => $rev]);
    }

    case 'delete_comment': {
        $in = body_json();
        $cid = strv($in['id'] ?? '', 80);
        $c = crm_comment_for_user($pdo, $cid, $viewUid);
        if (!$c) err('Комментарий не найден');
        if (!can_delete_comment($user, $c)) err('Нет прав');
        $urls = crm_att_urls($pdo, 'crm_attachments', [$cid]);
        $pdo->beginTransaction();
        try {
            crm_delete_att_rows($pdo, 'crm_attachments', [$cid]);
            $pdo->prepare('DELETE FROM crm_comments WHERE id = ?')->execute([$cid]);
            $rev = crm_touch_lead($pdo, (string) $c['lead_id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            err('Не удалось удалить');
        }
        crm_unlink_urls($urls);
        ok(['updatedAt' => $rev]);
    }

    case 'delete_attachment': {
        $in = body_json();
        $id = (int) ($in['id'] ?? 0);
        $row = crm_find_attachment($pdo, $id);
        if (!$row) err('Вложение не найдено');
        if (!can_edit_comment($user, $row)) err('Нет прав');
        if (($row['kind'] ?? '') === 'lead') {
            if (!crm_lead_for_user($pdo, (string) $row['owner_id'], $viewUid)) err('Лид не найден');
            $table = 'crm_attachments';
        } else {
            if (!crm_carrier_by_id($pdo, (string) $row['owner_id'])) err('Контакт не найден');
            $table = 'crm_carrier_attachments';
        }
        try {
            $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
        } catch (PDOException $e) {
            err('Не удалось удалить');
        }
        if (($row['kind'] ?? '') === 'lead') {
            $rev = crm_touch_lead($pdo, (string) $row['owner_id']);
        } else {
            $rev = crm_touch_carrier($pdo, (string) $row['owner_id']);
            crm_meta_bump($pdo, 'routes');
        }
        crm_unlink_upload((string) ($row['data_url'] ?? ''));
        ok(['updatedAt' => $rev]);
    }

    case 'save_stages': {
        $in = body_json();
        $ns = $in['stages'] ?? null;
        if (!is_array($ns) || !$ns) err('Пустой список этапов');
        $ns = array_values(array_filter(array_map(fn($s) => strv($s, 80), $ns)));
        if (!$ns) err('Пустой список этапов');
        if (count($ns) !== count(array_unique($ns))) err('Имя занято');
        $uid = $viewUid;
        $old = crm_stages($pdo, $uid);
        $pdo->beginTransaction();
        try {
            $updL = $pdo->prepare('UPDATE crm_leads SET stage = ? WHERE stage = ? AND user_id = ?');
            foreach (crm_stage_renames($old, $ns) as [$from, $to]) {
                $updL->execute([$to, $from, $uid]);
            }
            $pdo->prepare('DELETE FROM crm_stages WHERE user_id = ?')->execute([$uid]);
            $ins = $pdo->prepare('INSERT INTO crm_stages (user_id, name, position) VALUES (?,?,?)');
            foreach ($ns as $i => $name) $ins->execute([$uid, $name, $i]);
            $inQ = implode(',', array_fill(0, count($ns), '?'));
            $pdo->prepare("UPDATE crm_leads SET stage = ? WHERE user_id = ? AND stage NOT IN ($inQ)")->execute(array_merge([$ns[0], $uid], $ns));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            err('Не удалось сохранить этапы');
        }
        ok();
    }

    case 'get_users': {
        require_admin($user);
        $rows = $pdo->query('SELECT u.id, u.name, u.email, u.role, (SELECT COUNT(*) FROM crm_leads l WHERE l.user_id = u.id) AS leads
            FROM crm_users u ORDER BY u.id ASC')->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['leads'] = (int) $r['leads']; }
        unset($r);
        ok(['users' => $rows]);
    }

    case 'register_user': {
        require_admin($user);
        $in = body_json();
        $name = strv($in['name'] ?? '', 80);
        $email = mb_strtolower(strv($in['email'] ?? '', 120));
        $pass = (string) ($in['password'] ?? '');
        if ($name === '' || $email === '' || $pass === '') err('Все поля');
        if (crm_reserved_user_name($name)) err('Это имя зарезервировано');
        $bad = crm_pass_ok($pass);
        if ($bad) err($bad);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        if (crm_user_by_email($pdo, $email)) err('E-mail уже занят');
        $role = strv($in['role'] ?? 'user', 16);
        if ($role !== 'admin') $role = 'user';
        $pdo->prepare('INSERT INTO crm_users (name, email, password, role, created_at) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, now_ms()]);
        $newId = (int) $pdo->lastInsertId();
        crm_ensure_user_stages($pdo, $newId);
        crm_meta_bump($pdo, 'users');
        ok(['id' => $newId]);
    }

    case 'update_user': {
        require_admin($user);
        $in = body_json();
        $id = (int) ($in['id'] ?? 0);
        $name = strv($in['name'] ?? '', 80);
        $email = mb_strtolower(strv($in['email'] ?? '', 120));
        $pass = (string) ($in['password'] ?? '');
        if ($name === '' || $email === '') err('Обязательны Имя и Email');
        if (crm_reserved_user_name($name)) err('Это имя зарезервировано');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        if ($pass !== '') {
            $bad = crm_pass_ok($pass);
            if ($bad) err($bad);
        }
        $target = crm_user_by_id($pdo, $id);
        if (!$target) err('Сотрудник не найден');
        $other = crm_user_by_email($pdo, $email);
        if ($other && (int) $other['id'] !== $id) err('E-mail уже занят');
        $role = strv($in['role'] ?? ($target['role'] ?? 'user'), 16);
        if ($role !== 'admin') $role = 'user';
        if (($target['role'] ?? '') === 'admin' && $role !== 'admin' && crm_admin_count($pdo) <= 1) {
            err('Нельзя снять роль с последнего администратора');
        }
        if ($id === (int) $user['id'] && ($target['role'] ?? '') === 'admin' && $role !== 'admin') {
            err('Нельзя снять роль с себя');
        }
        $pdo->prepare('UPDATE crm_users SET name = ?, email = ?, role = ? WHERE id = ?')->execute([$name, $email, $role, $id]);
        if ($name !== (string) $target['name']) {
            $pdo->prepare('UPDATE crm_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
            $pdo->prepare('UPDATE crm_carrier_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
        }
        if ($pass !== '') {
            $pdo->prepare('UPDATE crm_users SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
        crm_meta_bump($pdo, 'users');
        ok();
    }

    case 'delete_user': {
        require_admin($user);
        $in = body_json();
        $id = (int) ($in['id'] ?? 0);
        if ($id === (int) $user['id']) err('Нельзя удалить себя');
        $target = crm_user_by_id($pdo, $id);
        if (!$target) err('Сотрудник не найден');
        if (($target['role'] ?? '') === 'admin' && crm_admin_count($pdo) <= 1) err('Нельзя удалить последнего администратора');
        // transferTo: id сотрудника, которому уходят лиды удаляемого; 0/пусто — удалить лиды вместе с логами и файлами
        $transferTo = (int) ($in['transferTo'] ?? 0);
        if ($transferTo > 0) {
            if ($transferTo === $id) err('Нельзя передать лиды удаляемому сотруднику');
            if (!crm_user_by_id($pdo, $transferTo)) err('Получатель лидов не найден');
        }
        $moved = crm_purge_user($pdo, $id, $transferTo);
        crm_meta_bump($pdo, 'users');
        ok(['transferred' => $moved]);
    }

    case 'change_password': {
        $in = body_json();
        $new = (string) ($in['password'] ?? '');
        $old = (string) ($in['old'] ?? '');
        $bad = crm_pass_ok($new);
        if ($bad) err($bad);
        if (empty($_SESSION['must_change'])) {
            if ($old === '' || !password_verify($old, (string) ($user['password'] ?? ''))) err('Неверный пароль');
        }
        $pdo->prepare('UPDATE crm_users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        unset($_SESSION['must_change']);
        ok();
    }

    default:
        err('Неизвестное действие');
}
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
    exit;
}
