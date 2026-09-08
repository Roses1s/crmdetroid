<?php
declare(strict_types=1);

/*
 * Файлы CRM: имена и валидация вложений uploads/, выдача файла с проверкой прав
 * (crm_serve_file), уборка потерянных файлов (TODO #18 — вынесено из db.php).
 * Подключается из db.php (require_once).
 */

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

/** Обрезать имя файла до $max символов, сохранив расширение. */
function crm_short_filename(string $name, int $max = 200): string {
    $name = trim(str_replace(["\0", "\r", "\n"], '', $name));
    if ($name === '') return 'file';
    if (mb_strlen($name, 'UTF-8') <= $max) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? mb_substr($name, 0, mb_strlen($name, 'UTF-8') - mb_strlen($ext, 'UTF-8') - 1, 'UTF-8') : $name;
    $keep = max(1, $max - ($ext !== '' ? mb_strlen($ext, 'UTF-8') + 1 : 0));
    $base = mb_substr($base, 0, $keep, 'UTF-8');
    return $ext !== '' ? $base . '.' . $ext : $base;
}

function crm_unlink_upload(string $url): void {
    $name = crm_upload_name($url);
    if ($name === null) return;
    $path = CRM_UPLOAD_DIR . '/' . $name;
    if (is_file($path)) @unlink($path);
}

/**
 * Удалить из uploads/ файлы, на которые нет ссылок ни в одной таблице вложений
 * (остатки от упавших транзакций, ручных правок БД, старых версий). Файлы моложе часа
 * не трогаем — они могут принадлежать запросу, который ещё выполняется.
 * Возвращает [проверено, удалено].
 */
function crm_sweep_uploads(PDO $pdo): array {
    $dir = realpath(CRM_UPLOAD_DIR);
    if ($dir === false) return [0, 0];
    $known = [];
    foreach (['crm_attachments', 'crm_carrier_attachments'] as $t) {
        foreach ($pdo->query("SELECT data_url FROM $t") as $r) {
            $n = crm_upload_name((string) $r['data_url']);
            if ($n !== null) $known[$n] = true;
        }
    }
    $checked = 0;
    $removed = 0;
    $limit = time() - 3600;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === '.gitkeep') continue;
        $p = $dir . '/' . $f;
        if (!is_file($p)) continue;
        $checked++;
        if (isset($known[$f])) continue;
        // Трогаем только файлы, которые создала сама CRM (свой формат имени); чужое не удаляем
        if (crm_upload_name('uploads/' . $f) === null) continue;
        if ((int) @filemtime($p) > $limit) continue;
        if (@unlink($p)) $removed++;
    }
    return [$checked, $removed];
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
    // Файлы лидов: доступ только владельцу доски (или админу).
    $st = $pdo->prepare('SELECT a.name, l.user_id FROM crm_attachments a
        INNER JOIN crm_comments c ON c.id = a.comment_id
        INNER JOIN crm_leads l ON l.id = c.lead_id
        WHERE a.data_url = ? LIMIT 1');
    $st->execute([$url]);
    $row = $st->fetch();
    if ($row && !$admin && (int) $row['user_id'] !== $uid) {
        $row = null;
    }
    // Файлы перевозчиков: доступны всем авторизованным (справочник направлений/перевозчиков — общий,
    // см. README «Права и правила»: добавлять и вести лог могут все сотрудники).
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
        // filename* (RFC 5987) — чтобы «Договор.pdf» скачивался с русским именем, а не «_______.pdf»
        header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($orig));
    }
    readfile($path);
    exit;
}

/**
 * Вложение по id и виду ('lead' | 'carrier'). Таблицы crm_attachments и crm_carrier_attachments
 * нумеруются независимо, поэтому id без вида неоднозначен: раньше поиск шёл «сначала у лидов»,
 * и клик «×» на файле перевозчика мог удалить файл из лида с тем же номером.
 */
function crm_find_attachment(PDO $pdo, int $id, string $kind): ?array {
    if ($id <= 0) return null;
    if ($kind === 'lead') {
        $st = $pdo->prepare('SELECT a.id, a.comment_id, a.name, a.data_url, c.lead_id AS owner_id, c.author, c.user_id, \'lead\' AS kind
            FROM crm_attachments a INNER JOIN crm_comments c ON c.id = a.comment_id WHERE a.id = ?');
    } elseif ($kind === 'carrier') {
        $st = $pdo->prepare('SELECT a.id, a.comment_id, a.name, a.data_url, c.carrier_id AS owner_id, c.author, c.user_id, \'carrier\' AS kind
            FROM crm_carrier_attachments a INNER JOIN crm_carrier_comments c ON c.id = a.comment_id WHERE a.id = ?');
    } else {
        return null;
    }
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
