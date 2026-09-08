<?php
declare(strict_types=1);

/*
 * Безопасность CRM: IP клиента и доверенные прокси, лимиты попыток входа,
 * валидация загружаемых файлов (TODO #18 — вынесено из db.php).
 * Подключается из db.php (require_once). Использует now_ms() из http.php.
 */

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

function crm_login_throttled(PDO $pdo, string $email, string $ip = ''): bool {
    // Пустой IP (crm_client_ip вернул '' при невалидном REMOTE_ADDR) — используем '0.0.0.0',
    // чтобы IP-лимит всё равно считался. Раньше при пустом IP лимит 80/IP пропускался,
    // и злоумышленник с невалидным адресом обходил защиту.
    if ($ip === '') $ip = '0.0.0.0';
    $since = now_ms() - 15 * 60 * 1000;
    $st = $pdo->prepare("SELECT COUNT(*) FROM crm_login_attempts WHERE ip = ? AND email NOT LIKE '#%' AND attempted_at > ?");
    $st->execute([$ip, $since]);
    if ((int) $st->fetchColumn() >= 80) return true;
    $st = $pdo->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?');
    $st->execute([$email, $ip, $since]);
    return (int) $st->fetchColumn() >= 8;
}

/** Удалить записи о попытках старше суток (лимиты считают только последние 15 минут). */
function crm_login_attempts_gc(PDO $pdo): void {
    try {
        $pdo->prepare('DELETE FROM crm_login_attempts WHERE attempted_at < ?')->execute([now_ms() - 24 * 3600 * 1000]);
    } catch (PDOException $e) { /* ok */ }
}

function crm_login_fail(PDO $pdo, string $email, string $ip = ''): void {
    // Нормализация IP: crm_login_throttled считает по '0.0.0.0' при пустом IP,
    // поэтому записываем с тем же значением — иначе подсчёт не найдёт эти строки.
    if ($ip === '') $ip = '0.0.0.0';
    $pdo->prepare('INSERT INTO crm_login_attempts (email, ip, attempted_at) VALUES (?,?,?)')->execute([$email, $ip, now_ms()]);
    crm_login_attempts_gc($pdo);
}

/**
 * Успешный вход сбрасывает счётчик только для этой пары (email, IP). Раньше сбрасывались попытки
 * со всех адресов — каждый вход жертвы обнулял лимит подбирающему пароль с другого IP.
 */
function crm_login_ok(PDO $pdo, string $email, string $ip = ''): void {
    // Нормализация IP: crm_login_fail сохраняет с '0.0.0.0' при пустом IP,
    // поэтому удаляем с тем же значением.
    if ($ip === '') $ip = '0.0.0.0';
    $pdo->prepare('DELETE FROM crm_login_attempts WHERE email = ? AND ip = ?')->execute([$email, $ip]);
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
