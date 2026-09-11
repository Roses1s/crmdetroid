<?php
/**
 * CRM «Детроид» — API на MySQL.
 * Контракт: api.php?action=...
 *
 * Архитектура (TODO #15/#18/#20 — выполнены, ревизия 2026-09-08):
 * Здесь остались middleware (сессии, auth, CSRF, лимиты запросов), общие хелперы
 * запроса (body_json/strv/intv, права, приём вложений, crm_apply_comment_*) и роутинг.
 * Сами действия — в actions/ (44 действия; каждое завершает запрос, поэтому never):
 *   actions/auth.php    — csrf, login, logout, check_auth, file. Выполняются ДО общего
 *                         middleware ($user/$viewUid ещё нет), поэтому сигнатура другая:
 *                         crm_action_xxx(PDO $pdo, bool $hasSess): never
 *   actions/lead.php    — save_lead, move_lead, delete_lead, get_lead, get_data, get_activity,
 *                         save_lead_app, delete_lead_app
 *   actions/tags.php    — save_tag, delete_tag, set_lead_tags (личные теги лидов, v17)
 *   actions/comment.php — add_comment, edit_comment, delete_comment, delete_attachment, get_comments
 *   actions/user.php    — register_user, update_user, delete_user, get_users, change_password, me
 *   actions/routes.php  — save_direction, delete_direction, get_directions, save_carrier, delete_carrier,
 *                         get_carriers, get_carrier, add_carrier_comment, edit_carrier_comment, delete_carrier_comment
 *   actions/search.php  — search_leads
 *   actions/admin.php   — whoami, sweep_uploads, integrity_check, get_audit
 *   actions/stages.php  — save_stages
 *   'ui' остаётся в api.php: это выдача интерфейса (readfile ui.html), а не доменное действие.
 * Действия после middleware экспортируют crm_action_xxx(PDO $pdo, array $user, int $viewUid): never.
 * Безопасность actions/: каталог закрыт в .htaccess (RewriteRule рядом с data|uploads|tests),
 * а в каждом файле первой строкой guard `defined('CRM_API') || exit;` (константу объявляет
 * api.php до require) — прямой запрос actions/lead.php не исполняет код даже без mod_rewrite.
 * При добавлении нового действия: функция в подходящий actions/*.php, строка в switch ниже,
 * read-only действия — также в $readActions (иначе потребуется POST + CSRF).
 * php -l в CI подхватывает actions/*.php по glob, PHPStan анализирует каталог целиком.
 *
 * TODO(архитектура #20) — ВЫПОЛНЕНО: out/ok/err/now_ms/crm_log_fail вынесены в http.php
 * (его подключает db.php через require_once); crm_pdo() и crm_view_uid() бросают CrmError,
 * который ловится в конце этого файла и превращается в прежний JSON-ответ через err().
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

// Guard для файлов actions/*: они исполняются только в контексте api.php
// (см. `defined('CRM_API') || exit;` первой строкой каждого action-файла).
const CRM_API = true;
require __DIR__ . '/actions/auth.php';
require __DIR__ . '/actions/admin.php';
require __DIR__ . '/actions/stages.php';
require __DIR__ . '/actions/search.php';
require __DIR__ . '/actions/user.php';
require __DIR__ . '/actions/comment.php';
require __DIR__ . '/actions/routes.php';
require __DIR__ . '/actions/lead.php';
require __DIR__ . '/actions/tags.php';

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
        // filemtime может вернуть false (гонка с параллельным удалением) — такой файл не трогаем
        $mt = @filemtime($f);
        if ($mt !== false && $mt < $limit) @unlink($f);
    }
}
function crm_session_kill(string $msg = 'Сессия истекла'): never {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
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

// out() / ok() / err() / now_ms() объявлены в http.php (его подключает db.php первым делом).
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
function strv(mixed $v, int $max = 300, string $fallback = ''): string {
    if (!is_scalar($v)) return $fallback;
    $s = str_replace("\0", '', (string) $v);
    // Невалидный UTF-8 обходит json_decode через $_GET (?q=%FF%FE) и $_POST из FormData
    // (add_comment и др.). Раньше он доходил до MySQL (ошибка 1366 → 500 в strict-режиме),
    // а попав в БД — ломал json_encode ответа: get_comments лида навсегда отдавал пустое тело.
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s !== '' ? $s : $fallback;
}
/** Целое из JSON-значения; массив/объект → 0 (а не 1, как даёт (int) от непустого массива). */
function intv(mixed $v): int {
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
/*
 * Общие тела add/edit/delete для комментариев лидов и перевозчиков.
 * Раньше case-блоки add_comment/add_carrier_comment, edit_comment/edit_carrier_comment,
 * delete_comment/delete_carrier_comment дублировали друг друга почти построчно (~70 строк),
 * и фиксы приходилось вносить дважды (см. ревью, п. 7.1). Различия — только имена таблиц,
 * колонка владельца и функция touch, они передаются параметрами. Имена таблиц приходят
 * литералами из call-site'ов (не из ввода пользователя), поэтому интерполяция безопасна.
 */
/** Добавить комментарий с вложениями. При ошибке завершает запрос через err(). */
function crm_apply_comment_add(PDO $pdo, string $table, string $attTable, string $ownerCol, string $ownerId, string $text, array $user, string $prefix, string $logTag): void {
    $atts = crm_take_uploads(8);
    if ($text === '' && !$atts) err('Пусто');
    try {
        $pdo->beginTransaction();
        crm_insert_comment($pdo, $table, $attTable, $ownerCol, $ownerId, $text, $user, $atts, $prefix);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_discard_uploads($atts);
        crm_log_fail($logTag, $e);
        err('Не удалось сохранить');
    }
}
/** Обновить текст комментария и дописать вложения (суммарно не более 8). */
function crm_apply_comment_edit(PDO $pdo, string $cid, string $text, string $table, string $attTable, string $logTag): void {
    $have = 0;
    try {
        $stN = $pdo->prepare("SELECT COUNT(*) FROM {$attTable} WHERE comment_id = ?");
        $stN->execute([$cid]);
        $have = (int) $stN->fetchColumn();
    } catch (PDOException $e) { $have = 0; }
    $atts = crm_take_uploads(max(0, 8 - $have));
    if ($text === '' && $have === 0 && !$atts) { crm_discard_uploads($atts); err('Пусто'); }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE {$table} SET text = ?, edited_at = ? WHERE id = ?")->execute([$text, now_ms(), $cid]);
        if ($atts) {
            $insA = $pdo->prepare("INSERT INTO {$attTable} (comment_id, name, size, type, data_url) VALUES (?,?,?,?,?)");
            foreach ($atts as $a) $insA->execute([$cid, $a['name'], $a['size'], $a['type'], $a['dataUrl']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_discard_uploads($atts);
        crm_log_fail($logTag, $e);
        err('Не удалось сохранить');
    }
}
/**
 * Удалить комментарий с вложениями. $touch — обновление ревизии карточки внутри транзакции;
 * возвращает её результат (updatedAt). Файлы с диска стираются после успешного коммита.
 */
function crm_apply_comment_delete(PDO $pdo, string $cid, string $table, string $attTable, string $logTag, callable $touch): int {
    $urls = crm_att_urls($pdo, $attTable, [$cid]);
    $rev = 0;
    $pdo->beginTransaction();
    try {
        crm_delete_att_rows($pdo, $attTable, [$cid]);
        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$cid]);
        $rev = (int) $touch();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_log_fail($logTag, $e);
        err('Не удалось удалить');
    }
    crm_unlink_urls($urls);
    return $rev;
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

// Действия до общего middleware — в actions/auth.php (у них нет $user/$viewUid)
if ($action === 'csrf') crm_action_csrf($pdo, $hasSess);
if ($action === 'file') crm_action_file($pdo, $hasSess);
if ($action === 'check_auth') crm_action_check_auth($pdo, $hasSess);
if ($action === 'login') crm_action_login($pdo, $hasSess);
if ($action === 'logout') crm_action_logout($pdo, $hasSess);

if (!$hasSess && session_status() !== PHP_SESSION_ACTIVE) err('Сессия истекла', true);
$user = require_user($pdo);
if (!empty($_SESSION['must_change']) && $action !== 'change_password' && $action !== 'ui') {
    out(['success' => false, 'error' => 'Смените временный пароль', 'must_change_password' => true]);
}
if (crm_session_throttled()) err('Слишком много запросов. Подождите минуту');

// Только чтение — разрешён GET. Всё остальное меняет данные: строго POST + CSRF-токен.
// (Раньше мутация проходила и по GET без CSRF — например, GET save_lead создавал пустой лид.)
$readActions = ['ui', 'me', 'whoami', 'get_data', 'get_lead', 'get_comments', 'search_leads', 'get_directions', 'get_carriers', 'get_carrier', 'get_users', 'integrity_check', 'get_activity', 'get_apps', 'get_audit'];
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
    case 'whoami': crm_action_whoami($pdo, $user, $viewUid);

    case 'sweep_uploads': crm_action_sweep_uploads($pdo, $user, $viewUid);

    case 'get_audit': crm_action_get_audit($pdo, $user, $viewUid);

    case 'integrity_check': crm_action_integrity_check($pdo, $user, $viewUid);

    case 'get_activity': crm_action_get_activity($pdo, $user, $viewUid);

    case 'get_apps': crm_action_get_apps($pdo, $user, $viewUid);

    case 'ui': {
        $path = __DIR__ . '/ui.html';
        if (!is_readable($path)) err('Нет интерфейса');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    case 'me': crm_action_me($pdo, $user, $viewUid);

    case 'get_comments': crm_action_get_comments($pdo, $user, $viewUid);

    case 'get_lead': crm_action_get_lead($pdo, $user, $viewUid);

    case 'save_lead_app': crm_action_save_lead_app($pdo, $user, $viewUid);

    case 'delete_lead_app': crm_action_delete_lead_app($pdo, $user, $viewUid);

    case 'save_tag': crm_action_save_tag($pdo, $user, $viewUid);

    case 'delete_tag': crm_action_delete_tag($pdo, $user, $viewUid);

    case 'set_lead_tags': crm_action_set_lead_tags($pdo, $user, $viewUid);

    case 'search_leads': crm_action_search_leads($pdo, $user, $viewUid);

    case 'get_directions': crm_action_get_directions($pdo, $user, $viewUid);

    case 'save_direction': crm_action_save_direction($pdo, $user, $viewUid);

    case 'delete_direction': crm_action_delete_direction($pdo, $user, $viewUid);

    case 'get_carriers': crm_action_get_carriers($pdo, $user, $viewUid);

    case 'save_carrier': crm_action_save_carrier($pdo, $user, $viewUid);

    case 'delete_carrier': crm_action_delete_carrier($pdo, $user, $viewUid);

    case 'get_carrier': crm_action_get_carrier($pdo, $user, $viewUid);

    case 'add_carrier_comment': crm_action_add_carrier_comment($pdo, $user, $viewUid);

    case 'edit_carrier_comment': crm_action_edit_carrier_comment($pdo, $user, $viewUid);

    case 'delete_carrier_comment': crm_action_delete_carrier_comment($pdo, $user, $viewUid);

    case 'get_data': crm_action_get_data($pdo, $user, $viewUid);

    case 'save_lead': crm_action_save_lead($pdo, $user, $viewUid);

    case 'move_lead': crm_action_move_lead($pdo, $user, $viewUid);

    case 'delete_lead': crm_action_delete_lead($pdo, $user, $viewUid);

    case 'add_comment': crm_action_add_comment($pdo, $user, $viewUid);

    case 'edit_comment': crm_action_edit_comment($pdo, $user, $viewUid);

    case 'delete_comment': crm_action_delete_comment($pdo, $user, $viewUid);

    case 'delete_attachment': crm_action_delete_attachment($pdo, $user, $viewUid);

    case 'save_stages': crm_action_save_stages($pdo, $user, $viewUid);

    case 'get_users': crm_action_get_users($pdo, $user, $viewUid);

    case 'register_user': crm_action_register_user($pdo, $user, $viewUid);

    case 'update_user': crm_action_update_user($pdo, $user, $viewUid);

    case 'delete_user': crm_action_delete_user($pdo, $user, $viewUid);

    case 'change_password': crm_action_change_password($pdo, $user, $viewUid);

    default:
        err('Неизвестное действие');
}
} catch (CrmError $e) {
    // Доменная ошибка из слоя данных (crm_pdo, crm_view_uid — TODO #20): сообщение
    // безопасно для пользователя, HTTP-статус подберёт crm_err_status — ответ байт в байт
    // такой же, как раньше давал прямой err() из db.php.
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) { /* соединение уже могло закрыться */ }
    }
    err($e->getMessage(), $e->needLogin);
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
