<?php
/**
 * CRM «Детроид» — API на MySQL.
 * Контракт: api.php?action=...
 *
 * TODO(архитектура #15): вынести actions в отдельные файлы.
 * План разделения (32 case → ~8 файлов):
 *   actions/lead.php    — save_lead, move_lead, delete_lead, get_lead, get_data, save_lead_app, delete_lead_app
 *   actions/comment.php — add_comment, edit_comment, delete_comment, delete_attachment, get_comments
 *   actions/user.php    — register_user, update_user, delete_user, get_users, change_password, check_auth
 *   actions/routes.php  — save_direction, delete_direction, get_directions, save_carrier, delete_carrier,
 *                         get_carriers, get_carrier, add_carrier_comment, edit_carrier_comment, delete_carrier_comment
 *   actions/search.php  — search_leads
 *   actions/admin.php   — whoami, sweep_uploads, integrity_check
 *   actions/auth.php    — login, logout, csrf
 *   actions/stages.php  — save_stages
 * Каждый action-файл экспортирует функцию crm_action_xxx(PDO $pdo, array $user, int $viewUid): never.
 * В api.php остаётся: require actions/*.php, middleware (auth/csrf/throttle), роутинг switch.
 *
 * TODO(архитектура #20): вынести out/ok/err в http.php — функции HTTP-ответа не принадлежат
 * слою данных (db.php). При этом crm_pdo() должен бросать исключения, а не вызывать err().
 */
declare(strict_types=1);

// Предупреждения PHP не должны попадать в тело ответа (ломают JSON и заголовки) — только в лог сервера.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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

if (!is_dir(CRM_UPLOAD_DIR)) @mkdir(CRM_UPLOAD_DIR, 0775, true);

const CRM_IDLE_SEC = 8 * 3600;        // простой: 8 часов без запросов — сессия закрывается
const CRM_SESSION_MAX_SEC = 14 * 86400; // абсолютный срок: раз в две недели вход заново, даже если вкладка открыта
const CRM_MAX_STAGES = 20;
const CRM_MAX_APPS_PER_LEAD = 200;

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
    // Heartbeat из вкладки продлевал бы сессию бесконечно — ограничиваем общий срок жизни
    $born = (int) ($_SESSION['born'] ?? 0);
    if ($born > 0 && (time() - $born) > CRM_SESSION_MAX_SEC) crm_session_kill('Сессия истекла, войдите заново');
    if ($touch) $_SESSION['last'] = time();
}
/**
 * Отпечаток пароля + token_version в сессии: после смены пароля (своей или админом)
 * все остальные сессии этого пользователя перестают действовать — забытая на чужом
 * компьютере или украденная сессия раньше жила ещё до 8 часов простоя.
 * token_version инкрементируется при смене роли — снятый админ теряет привилегии мгновенно,
 * а не через 8 часов (когда сессия истечёт сама).
 */
function crm_pw_fingerprint(array $u): string {
    $tv = (int) ($u['token_version'] ?? 0);
    return substr(hash('sha256', (string) ($u['password'] ?? '') . '|' . $tv), 0, 16);
}
/**
 * Ключ HMAC для одноразовых токенов формы входа. Хранится в data/.csrf_secret (0600).
 * Если каталог недоступен на запись — ошибка конфигурации, а не «запасной» ключ из пароля БД
 * (пароль БД однажды уже утёк в git, такой ключ был бы предсказуем).
 */
function crm_csrf_secret(): string {
    static $key = null;
    if ($key !== null) return $key;
    $dir = __DIR__ . '/data';
    $f = $dir . '/.csrf_secret';
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    if (strlen($raw) < 32) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $new = bin2hex(random_bytes(32));
        // O_EXCL: два первых запроса не перезапишут ключ друг другу
        $fh = @fopen($f, 'x');
        if ($fh !== false) {
            fwrite($fh, $new);
            fclose($fh);
            @chmod($f, 0600);
        }
        $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    }
    if (strlen($raw) < 32) {
        error_log('CRM: каталог data/ недоступен для записи — не могу сохранить ключ CSRF');
        err('Каталог data/ недоступен для записи. Дайте права на запись (chmod 755/775) и повторите.');
    }
    @chmod($f, 0600);
    $key = hash('sha256', $raw, true);
    return $key;
}
function crm_login_csrf_issue(PDO $pdo): string {
    $ts = (string) time();
    $rnd = bin2hex(random_bytes(16));
    $mac = hash_hmac('sha256', $ts . '.' . $rnd, crm_csrf_secret());
    $token = $ts . '.' . $rnd . '.' . $mac;
    try {
        $pdo->prepare('INSERT INTO crm_login_nonces (h, created_at) VALUES (?, ?)')->execute([hash('sha256', $token), now_ms()]);
        $pdo->prepare('DELETE FROM crm_login_nonces WHERE created_at < ?')->execute([now_ms() - 20 * 60 * 1000]);
        // Строки '#csrf' в crm_login_attempts раньше чистились только при неудачном входе и копились
        if (random_int(1, 20) === 1) crm_login_attempts_gc($pdo);
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
/**
 * Длина считается в символах, а не байтах (иначе «мама» — 8 байт — проходила как 8 символов).
 * Верхний предел 64: bcrypt учитывает только первые 72 байта, кириллица — 2 байта на символ.
 */
function crm_pass_ok(string $pass): ?string {
    $n = mb_strlen($pass, 'UTF-8');
    if ($n < 8) return 'Пароль мин. 8 символов';
    if ($n > 64 || strlen($pass) > 72) return 'Пароль не длиннее 64 символов';
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
    // Битое тело — ошибка запроса, а не «пустой объект» (иначе save_lead создавал лид «Без названия»)
    if (!is_array($j)) err('Некорректный запрос');
    return $j;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
/** Строка из произвольного JSON-значения: массивы/объекты → пусто (раньше — warning и сломанный JSON). */
function strv($v, int $max = 300, string $fallback = ''): string {
    if (!is_scalar($v)) return $fallback;
    $s = trim(str_replace("\0", '', (string) $v));
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s !== '' ? $s : $fallback;
}
/** Целое из JSON-значения; массив/объект → 0 (а не 1, как даёт (int) от непустого массива). */
function intv($v): int {
    if (is_int($v)) return $v;
    if (is_float($v)) return (int) $v;
    if (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))) return (int) trim($v);
    if (is_bool($v)) return $v ? 1 : 0;
    return 0;
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
    if (($_SESSION['pw'] ?? '') !== crm_pw_fingerprint($u)) crm_session_kill('Пароль был изменён, войдите заново');
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
/**
 * Редактировать запись лога может её автор (по user_id) или админ.
 * Записи с user_id = 0 — от удалённых сотрудников: их правит только админ (раньше право давалось
 * по совпадению имени, и новый сотрудник с тем же именем получал чужие комментарии).
 */
function can_edit_comment(array $user, array $c): bool {
    if (crm_is_sys_comment($c)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    $uid = (int) ($c['user_id'] ?? 0);
    return $uid > 0 && $uid === (int) $user['id'];
}
/**
 * Системные записи («Лид создан», «Лид передан», «Статус изменён») — след того, что происходило
 * с карточкой; удалить их может только админ.
 */
function can_delete_comment(array $user, array $c): bool {
    if (crm_is_sys_comment($c)) return ($user['role'] ?? '') === 'admin';
    return can_edit_comment($user, $c);
}
/**
 * Справочник направлений и перевозчиков общий, но удалять/переименовывать запись (а с ней —
 * чужие логи и файлы каскадом) может только её создатель или админ. Добавлять и вести лог — все.
 */
function can_manage_ref(array $user, array $row): bool {
    if (($user['role'] ?? '') === 'admin') return true;
    $by = (int) ($row['created_by'] ?? 0);
    return $by > 0 && $by === (int) $user['id'];
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
        // Колонка name — VARCHAR(255): длинное имя раньше валило INSERT («Не удалось сохранить»)
        $orig = crm_short_filename(basename((string) $name), 200);
        $atts[] = ['name' => $orig, 'size' => $size, 'type' => strv($mime, 120), 'dataUrl' => 'uploads/' . $fname];
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

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$hasSess = isset($_COOKIE[CRM_SESSION_NAME]) && (string) $_COOKIE[CRM_SESSION_NAME] !== '';

// Любое предупреждение (warning/notice) — исключение: попадёт в общий catch как 500 + error_log,
// а не в тело ответа. Раньше «Array to string conversion» отдавал HTML вместо JSON.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false; // подавлено через @
    // Deprecated (например, после обновления PHP на хостинге) — не повод ронять запрос: в лог штатно
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

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
    if (!$uFile || ($_SESSION['pw'] ?? '') !== crm_pw_fingerprint($uFile)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Need login';
        exit;
    }
    // Сессия больше не нужна — отпускаем блокировку, пока читаем файл с диска
    session_write_close();
    crm_serve_file($pdo, (string) (is_string($_GET['f'] ?? null) ? $_GET['f'] : ''), $uFile);
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
    crm_login_ok($pdo, $email, $ip);
    crm_session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['pw'] = crm_pw_fingerprint($u);
    $_SESSION['last'] = time();
    $_SESSION['born'] = time();
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
    // Regenerate ID перед уничтожением: старый session ID больше не действителен,
    // и даже если файл сессии ещё не удалён сборщиком мусора — предъявить его нельзя.
    session_regenerate_id(true);
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
$readActions = ['ui', 'whoami', 'get_data', 'get_lead', 'get_comments', 'search_leads', 'get_directions', 'get_carriers', 'get_carrier', 'get_users', 'integrity_check', 'get_activity'];
if (!in_array($action, $readActions, true)) {
    if ($method !== 'POST') err('Метод не поддерживается: нужен POST');
    require_csrf();
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    require_csrf();
}
$viewUid = crm_view_uid($pdo, $user);
// Дальше сессия только читается. Отпускаем файл сессии, чтобы параллельные запросы вкладки
// (доска + лог + картинки) не ждали друг друга. Действия, которые пишут в сессию
// (change_password, update_user), сессию держат.
if (in_array($action, $readActions, true) || ($action !== 'change_password' && $action !== 'update_user')) {
    session_write_close();
}
// Редкая фоновая уборка uploads/: файлы, на которые не осталось ссылок в БД (упавшие транзакции и т.п.)
if (random_int(1, 1000) === 1) {
    try { crm_sweep_uploads($pdo); } catch (Throwable $e) { crm_log_fail('sweep_uploads', $e); }
}

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

    case 'sweep_uploads': {
        // Ручная уборка uploads/ (файлы без записей в БД); автоматически то же выполняется раз в ~1000 запросов (см. выше).
        require_admin($user);
        [$checked, $removed] = crm_sweep_uploads($pdo);
        ok(['checked' => $checked, 'removed' => $removed]);
    }

    case 'integrity_check': {
        // Диагностика ссылочной целостности (замена FOREIGN KEY, которых нет в схеме).
        // Находит orphan-записи: комментарии без лида, вложения без комментария и т.д.
        require_admin($user);
        $issues = crm_integrity_check($pdo);
        ok(['issues' => $issues, 'count' => count($issues)]);
    }

    case 'get_activity': {
        $year = intv($_GET['year'] ?? date('Y'));
        if ($year < 2020 || $year > 2099) $year = (int) date('Y');
        ok(['clients' => crm_client_activity($pdo, $viewUid, $year), 'year' => $year]);
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
        // Ставка и маржа парсятся одинаково строго: раньше из ставки молча вырезались не-цифры
        // и «12 500,50» превращалось в 1250050.
        $rate = crm_parse_money(strv($in['rate'] ?? '', 40));
        if ($rate === null) err('Ставка: число, копейки через запятую');
        $rate = crm_money_in($rate);
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
        if ($id === '') {
            $id = 'a_' . bin2hex(random_bytes(6));
            if (crm_sync_lead_apps_count($pdo, $leadId) >= CRM_MAX_APPS_PER_LEAD) err('Слишком много заявок в одном лиде');
        }
        // Оптимистическая блокировка: если заявку изменили в другой вкладке — не перезаписывать молча.
        // Раньше updatedAt в заявках не проверялся, и параллельные сохранения затирали друг друга.
        if ($existing && array_key_exists('updatedAt', $in) && (int) $existing['updated_at'] !== intv($in['updatedAt'])) {
            err('Заявка изменена в другом месте');
        }
        try {
            if (!$existing) {
                $pdo->prepare('INSERT INTO crm_lead_apps (id, lead_id, city_from, city_to, rate, margin, vat, carrier_company, carrier_inn, carrier_name, carrier_phone, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $leadId, $from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $now]);
            } else {
                $rev = (int) $existing['updated_at'];
                $updApp = $pdo->prepare('UPDATE crm_lead_apps SET city_from=?, city_to=?, rate=?, margin=?, vat=?, carrier_company=?, carrier_inn=?, carrier_name=?, carrier_phone=?, updated_at=? WHERE id=? AND lead_id=? AND updated_at=?');
                $updApp->execute([$from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $id, $leadId, $rev]);
                if ($updApp->rowCount() === 0) err('Заявка изменена в другом месте');
            }
        } catch (PDOException $e) {
            crm_log_fail('save_lead_app', $e);
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
        // Оптимистическая блокировка: если заявку изменили в другой вкладке — предупредить.
        if (array_key_exists('updatedAt', $in) && (int) $app['updated_at'] !== intv($in['updatedAt'])) {
            err('Заявка изменена в другом месте');
        }
        try {
            $pdo->prepare('DELETE FROM crm_lead_apps WHERE id = ? AND lead_id = ?')->execute([$id, $leadId]);
        } catch (PDOException $e) {
            crm_log_fail('delete_lead_app', $e);
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
            $dir = crm_direction_by_id($pdo, $id);
            if (!$dir) err('Направление не найдено');
            if (!can_manage_ref($user, $dir)) err('Переименовать направление может тот, кто его добавил, или администратор');
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
        $dir = crm_direction_by_id($pdo, $id);
        if (!$dir) err('Направление не найдено');
        if (!can_manage_ref($user, $dir)) err('Удалить направление может тот, кто его добавил, или администратор');
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
            crm_log_fail('delete_direction', $e);
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
                'canManage' => can_manage_ref($user, $dir),
            ],
            'carriers' => crm_carriers_list($pdo, $id, $user),
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
            $rev = (int) ($cur['updated_at'] ?? 0);
            if (array_key_exists('updatedAt', $in) && $rev !== intv($in['updatedAt'])) {
                err('Карточка изменена в другом месте');
            }
            $note = array_key_exists('note', $in) ? strv($in['note'] ?? '', 2000) : (string) ($cur['note'] ?? '');
            // Условие по updated_at — единственная защита от одновременной правки в двух вкладках.
            // (Старой ветки «updated_at = 0 → обновить без условия» больше нет: после миграции v6 нулей
            // не бывает, а безусловный UPDATE перетирал чужие изменения.)
            $upd = $pdo->prepare('UPDATE crm_carriers SET name = ?, phone = ?, company = ?, note = ?, updated_at = ? WHERE id = ? AND updated_at = ?');
            $upd->execute([$name, $phone, $company, $note, $now, $id, $rev]);
            if ($upd->rowCount() === 0) err('Карточка изменена в другом месте');
        }
        crm_meta_bump($pdo, 'routes');
        ok(['id' => $id, 'updatedAt' => $now]);
    }

    case 'delete_carrier': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $car = crm_carrier_by_id($pdo, $id);
        if (!$car) err('Контакт не найден');
        if (!can_manage_ref($user, $car)) err('Удалить перевозчика может тот, кто его добавил, или администратор');
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
                'canManage' => can_manage_ref($user, $row),
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
        try {
            $pdo->beginTransaction();
            crm_insert_comment($pdo, 'crm_carrier_comments', 'crm_carrier_attachments', 'carrier_id', $carrierId, $text, $user, $atts, 'cc_');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            crm_log_fail('add_carrier_comment', $e);
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
            crm_log_fail('edit_carrier_comment', $e);
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
            crm_log_fail('delete_carrier_comment', $e);
            err('Не удалось удалить');
        }
        crm_unlink_urls($urls);
        crm_meta_bump($pdo, 'routes');
        ok(['updatedAt' => $rev]);
    }

    case 'get_data': {
        $uid = $viewUid;
        $hash = substr(hash('sha256', crm_board_rev($pdo, $uid)), 0, 32);
        $client = strv($_GET['hash'] ?? '', 64);
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
        $uid = $viewUid;
        $stages = crm_stages($pdo, $uid);
        $row = $id !== '' ? crm_lead_for_user($pdo, $id, $uid) : null;
        if (!$row) {
            if ($id !== '') {
                // Чужой id — «не найден»; несуществующий — игнорируем: id новых лидов выдаёт только сервер
                // (раньше клиент мог создать лид с произвольным id, вплоть до «../../etc»).
                $any = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
                $any->execute([$id]);
                if ($any->fetch()) err('Лид не найден');
            }
            $id = 'l_' . bin2hex(random_bytes(6));
        }

        $title = strv($in['title'] ?? ($row['title'] ?? ''), 200, 'Без названия');
        $inn = preg_replace('/\D/', '', strv($in['inn'] ?? ($row['inn'] ?? ''), 12)) ?? '';
        if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) err('ИНН 10 или 12 цифр');
        $phone = strv($in['phone'] ?? ($row['phone'] ?? ''), 40);
        $email = strv($in['email'] ?? ($row['email'] ?? ''), 120);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
        // Продавец по умолчанию — владелец доски (админ через ?as= создаёт лид сотруднику, а не себе)
        $ownerName = $uid === (int) $user['id'] ? (string) $user['name'] : (string) ((crm_user_by_id($pdo, $uid)['name'] ?? '') ?: $user['name']);
        $manager = strv($in['manager'] ?? ($row['manager'] ?? $ownerName), 80, $ownerName);
        $logistName = strv($in['logistName'] ?? ($row['logist_name'] ?? ''), 80);
        $logistPhone = strv($in['logistPhone'] ?? ($row['logist_phone'] ?? ''), 40);
        $apps = 0;
        $stage = strv($in['stage'] ?? ($row['stage'] ?? ($stages[0] ?? 'Новый')), 80);
        if (!in_array($stage, $stages, true)) $stage = $row['stage'] ?? ($stages[0] ?? 'Новый');

        if ($row && array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
            err('Карточка изменена в другом месте');
        }

        $now = now_ms();
        $transferredTo = null;
        $pdo->beginTransaction();
        try {
            if (!$row) {
                $ins = $pdo->prepare('INSERT INTO crm_leads (id,user_id,title,inn,phone,email,manager,logist_name,logist_phone,applications_count,stage,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$id, $uid, $title, $inn, $phone, $email, $manager, $logistName, $logistPhone, $apps, $stage, $now, $now]);
                crm_sys_comment($pdo, $id, 'Лид создан');
            } else {
                $upd = $pdo->prepare('UPDATE crm_leads SET title=?,inn=?,phone=?,email=?,manager=?,logist_name=?,logist_phone=?,stage=?,updated_at=? WHERE id=? AND user_id=? AND updated_at=?');
                $upd->execute([$title, $inn, $phone, $email, $manager, $logistName, $logistPhone, $stage, $now, $id, $uid, (int) $row['updated_at']]);
                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    err('Карточка изменена в другом месте');
                }
            }
            // Передача лида — только по явному id сотрудника (клиент подставляет его после
            // подтверждения «Передать лид?»). Раньше цель угадывалась по любому слову из поля
            // «Продавец», и совпадение по имени могло отдать лид не тому человеку.
            $toId = intv($in['transferTo'] ?? 0);
            if ($toId > 0 && $toId !== $uid) {
                $via = $uid === (int) $user['id'] ? '' : (string) $user['name'];
                $toName = crm_transfer_lead($pdo, $id, $uid, $toId, $ownerName, $stage, $via, (int) $row['updated_at'] ?? $now);
                if ($toName === null) {
                    $pdo->rollBack();
                    err($row ? 'Карточка изменена в другом месте' : 'Сотрудник не найден');
                }
                $transferredTo = $toName;
                $now = now_ms();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$e instanceof PDOException) throw $e;
            crm_log_fail('save_lead', $e);
            err('Не удалось сохранить');
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
        if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
            err('Карточка изменена в другом месте');
        }
        $now = now_ms();
        if ($row['stage'] !== $stage) {
            $from = (string) $row['stage'];
            // Транзакция: UPDATE и системный комментарий атомарны. Раньше комментарий шёл
            // отдельно — при сбое БД лид перемещался, но запись в логе не появлялась.
            $pdo->beginTransaction();
            try {
                $stU = $pdo->prepare('UPDATE crm_leads SET stage = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ?');
                $stU->execute([$stage, $now, $id, $uid, (int) $row['updated_at']]);
                if ($stU->rowCount() === 0) {
                    $pdo->rollBack();
                    err('Карточка изменена в другом месте');
                }
                crm_sys_comment($pdo, $id, "Статус изменен: {$from} ➔ {$stage}");
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (!$e instanceof PDOException) throw $e;
                crm_log_fail('move_lead', $e);
                err('Не удалось переместить');
            }
        }
        ok(['updatedAt' => $row['stage'] === $stage ? (int) $row['updated_at'] : $now]);
    }

    case 'delete_lead': {
        $in = body_json();
        $id = strv($in['id'] ?? '', 80);
        $uid = $viewUid;
        $row = crm_lead_for_user($pdo, $id, $uid);
        if (!$row) err('Лид не найден');
        // Оптимистическая блокировка: если лид изменили в другой вкладке — предупредить, а не удалять молча.
        if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
            err('Карточка изменена в другом месте');
        }
        crm_purge_lead($pdo, $id);
        ok();
    }

    case 'add_comment': {
        // TODO(архитектура): add_comment принимает FormData ($_POST), а edit_comment — и JSON и FormData
        // (через crm_edit_comment_input). add_carrier_comment — только FormData. Непоследовательность
        // усложняет поддержку. При рефакторинге — унифицировать: либо все через multipart (т.к. файлы),
        // либо загружать файлы отдельным action'ом, а комментарии — всегда JSON.
        $leadId = strv($_POST['lead_id'] ?? '', 80);
        $text = strv($_POST['text'] ?? '', 20000);
        if (!crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
        $atts = crm_take_uploads(8);
        if ($text === '' && !$atts) err('Пусто');
        try {
            $pdo->beginTransaction();
            crm_insert_comment($pdo, 'crm_comments', 'crm_attachments', 'lead_id', $leadId, $text, $user, $atts, 'c_');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            crm_discard_uploads($atts);
            crm_log_fail('add_comment', $e);
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
            crm_log_fail('edit_comment', $e);
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
            crm_log_fail('delete_comment', $e);
            err('Не удалось удалить');
        }
        crm_unlink_urls($urls);
        ok(['updatedAt' => $rev]);
    }

    case 'delete_attachment': {
        $in = body_json();
        $id = intv($in['id'] ?? 0);
        // kind обязателен: без него id неоднозначен (см. crm_find_attachment)
        $kind = strv($in['kind'] ?? '', 16);
        if ($kind !== 'lead' && $kind !== 'carrier') err('Не указан тип вложения');
        $row = crm_find_attachment($pdo, $id, $kind);
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
            crm_log_fail('delete_attachment', $e);
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
        if (count($ns) > CRM_MAX_STAGES) err('Не больше ' . CRM_MAX_STAGES . ' этапов');
        // Дубликаты с разным регистром («Новый»/«новый») упали бы на UNIQUE-индексе с невнятной ошибкой
        $keys = array_map(fn($s) => mb_strtolower($s, 'UTF-8'), $ns);
        if (count($keys) !== count(array_unique($keys))) err('Имя занято');
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
            crm_log_fail('save_stages', $e);
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
        $id = intv($in['id'] ?? 0);
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
        // При смене роли инкрементируем token_version: все существующие сессии этого пользователя
        // отвалятся на следующем запросе (crm_pw_fingerprint включает token_version).
        // Раньше снятый админ сохранял привилегии до истечения сессии (до 8 часов).
        $roleChanged = ($target['role'] ?? '') !== $role;
        if ($roleChanged) {
            $pdo->prepare('UPDATE crm_users SET name = ?, email = ?, role = ?, token_version = token_version + 1 WHERE id = ?')
                ->execute([$name, $email, $role, $id]);
        } else {
            $pdo->prepare('UPDATE crm_users SET name = ?, email = ?, role = ? WHERE id = ?')
                ->execute([$name, $email, $role, $id]);
        }
        if ($name !== (string) $target['name']) {
            $pdo->prepare('UPDATE crm_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
            $pdo->prepare('UPDATE crm_carrier_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
            // «Продавец» на карточках — то же имя; иначе на лидах остаётся старое, а передача по имени ломается
            $pdo->prepare('UPDATE crm_leads SET manager = ? WHERE user_id = ? AND manager = ?')->execute([$name, $id, (string) $target['name']]);
        }
        if ($pass !== '') {
            // Инкрементируем token_version при смене пароля: все существующие сессии этого
            // пользователя инвалидируются. Раньше это работало неявно (хэш пароля входит в
            // crm_pw_fingerprint), но явная инвалидация надёжнее — не зависит от состава fingerprint.
            $pdo->prepare('UPDATE crm_users SET password = ?, token_version = token_version + 1 WHERE id = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            if ($id === (int) $user['id']) {
                $fresh = crm_user_by_id($pdo, $id);
                if ($fresh) $_SESSION['pw'] = crm_pw_fingerprint($fresh);
            }
        }
        crm_meta_bump($pdo, 'users');
        ok();
    }

    case 'delete_user': {
        require_admin($user);
        $in = body_json();
        $id = intv($in['id'] ?? 0);
        if ($id === (int) $user['id']) err('Нельзя удалить себя');
        $target = crm_user_by_id($pdo, $id);
        if (!$target) err('Сотрудник не найден');
        if (($target['role'] ?? '') === 'admin' && crm_admin_count($pdo) <= 1) err('Нельзя удалить последнего администратора');
        // transferTo: id сотрудника, которому уходят лиды удаляемого; 0/пусто — удалить лиды вместе с логами и файлами
        $transferTo = intv($in['transferTo'] ?? 0);
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
        // token_version инкрементируется для консистентности с update_user: явная инвалидация
        // сессий не зависит от состава crm_pw_fingerprint (хэш пароля может из него уйти).
        $pdo->prepare('UPDATE crm_users SET password = ?, token_version = token_version + 1 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        unset($_SESSION['must_change']);
        // Своя сессия остаётся; все остальные сессии этого пользователя отвалятся на следующем запросе
        // (id сессии не меняем: параллельный запрос вкладки — опрос доски — со старым id вылетел бы на вход)
        $fresh = crm_user_by_id($pdo, (int) $user['id']);
        if ($fresh) $_SESSION['pw'] = crm_pw_fingerprint($fresh);
        ok();
    }

    default:
        err('Неизвестное действие');
}
} catch (Throwable $e) {
    // В ответ — общая фраза, в лог сервера — что именно и где (иначе сбои на проде невидимы).
    error_log(sprintf('CRM api action=%s uid=%s: %s: %s in %s:%d',
        $action, (string) ($_SESSION['user_id'] ?? '-'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) { /* соединение уже могло закрыться */ }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
    exit;
}
