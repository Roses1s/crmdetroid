#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Бэкап CRM «Детроид»: дамп всех таблиц crm_* в data/backups/crm-db-*.sql.gz,
 * опционально архив вложений uploads/ (--uploads). Только CLI — запускается
 * вручную или по cron (см. README, раздел «Бэкапы»).
 *
 * Запуск:  php tools/backup.php [--keep=14] [--uploads] [--dir=/путь/к/каталогу]
 *   --keep=N    сколько последних бэкапов хранить (ротация; по умолчанию 14)
 *   --uploads   дополнительно упаковать uploads/ в crm-uploads-*.tar.gz
 *   --dir=PATH  каталог для бэкапов (по умолчанию data/backups — закрыт от веба)
 *
 * Восстановление БД: панель хостинга → phpMyAdmin → Импорт (файл .sql.gz),
 * либо в консоли: zcat crm-db-*.sql.gz | mysql -h ХОСТ -P ПОРТ -u ЛОГИН -p БАЗА
 *
 * Дамп снимается в одной транзакции (REPEATABLE READ + CONSISTENT SNAPSHOT):
 * данные согласованы между таблицами, даже если CRM в этот момент используется.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
/** @var list<string> $argv PHPStan не знает, что в CLI $argv определён всегда (register_argc_argv) */
$argv = $_SERVER['argv'] ?? [];

$keep = 14;
$withUploads = false;
$dir = __DIR__ . '/../data/backups';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    } elseif ($arg === '--uploads') {
        $withUploads = true;
    } elseif (preg_match('/^--dir=(.+)$/', $arg, $m)) {
        $dir = $m[1];
    } else {
        fwrite(STDERR, "Неизвестный аргумент: {$arg}\nИспользование: php tools/backup.php [--keep=14] [--uploads] [--dir=PATH]\n");
        exit(2);
    }
}

if (!is_file(__DIR__ . '/../config.php')) {
    fwrite(STDERR, "Нет config.php рядом с api.php — бэкапить нечего (скрипт запускается на сервере с работающей CRM)\n");
    exit(1);
}
require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

$t0 = microtime(true);
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Не удалось создать каталог {$dir}\n");
    exit(1);
}

// Не даём двум бэкапам идти параллельно (cron мог запустить второй, пока первый не закончился)
$lock = fopen($dir . '/.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Бэкап уже выполняется (lock занят) — выходим\n");
    exit(0);
}

try {
    $pdo = crm_pdo();
} catch (CrmError $e) {
    fwrite(STDERR, 'Ошибка подключения: ' . $e->getMessage() . "\n");
    exit(1);
}

$stamp = date('Ymd-His');

// --- Дамп БД -----------------------------------------------------------------
$tables = $pdo->query("SHOW TABLES LIKE 'crm\\_%'")->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) {
    fwrite(STDERR, "Таблицы crm_* не найдены — нечего бэкапить\n");
    exit(1);
}
$dbFile = $dir . '/crm-db-' . $stamp . '.sql.gz';
$gz = gzopen($dbFile, 'wb6');
if ($gz === false) {
    fwrite(STDERR, "Не удалось открыть {$dbFile} на запись\n");
    exit(1);
}

$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

gzwrite($gz, "-- CRM «Детроид»: бэкап " . date('c') . "\n"
    . "-- Восстановление: zcat файл.sql.gz | mysql -h ХОСТ -P ПОРТ -u ЛОГИН -p БАЗА\n"
    . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

$totalRows = 0;
foreach ($tables as $table) {
    /** @var array{0:string,1:string} $create */
    $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
    gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");

    $st = $pdo->query("SELECT * FROM `{$table}`");
    $batch = [];
    $cols = null;
    while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        if ($cols === null) {
            $cols = '`' . implode('`,`', array_keys($row)) . '`';
        }
        $vals = [];
        foreach ($row as $v) {
            $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
        }
        $batch[] = '(' . implode(',', $vals) . ')';
        $totalRows++;
        if (count($batch) >= 200) {
            gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }
    if ($batch && $cols !== null) {
        gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    gzwrite($gz, "\n");
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
gzclose($gz);
$pdo->exec('COMMIT');

// --- Архив uploads/ (опционально) ---------------------------------------------
$upFile = null;
if ($withUploads) {
    $upDir = defined('CRM_UPLOAD_DIR') ? CRM_UPLOAD_DIR : __DIR__ . '/../uploads';
    if (is_dir($upDir) && count(glob($upDir . '/*') ?: []) > 0) {
        $tarPath = $dir . '/crm-uploads-' . $stamp . '.tar';
        try {
            $tar = new PharData($tarPath);
            $tar->buildFromDirectory($upDir);
            $tar->compress(Phar::GZ);
            unset($tar);
            @unlink($tarPath); // остаётся только .tar.gz
            $upFile = $tarPath . '.gz';
        } catch (Throwable $e) {
            @unlink($tarPath);
            fwrite(STDERR, 'Архив uploads не создан: ' . $e->getMessage() . "\n");
        }
    } else {
        fwrite(STDERR, "uploads/ пуст или отсутствует — архив не нужен\n");
    }
}

// --- Ротация: храним только последние --keep файлов каждого вида ---------------
$removed = 0;
foreach (['crm-db-*.sql.gz', 'crm-uploads-*.tar.gz'] as $pattern) {
    $files = glob($dir . '/' . $pattern) ?: [];
    sort($files); // имена содержат дату — лексикографический порядок хронологичен
    foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) {
        if (@unlink($old)) $removed++;
    }
}

$fmt = static fn (string $f): string => basename($f) . ' (' . round(filesize($f) / 1024) . ' КБ)';
echo 'Готово за ' . round(microtime(true) - $t0, 1) . " с\n";
echo '  БД: ' . $fmt($dbFile) . ', таблиц: ' . count($tables) . ', строк: ' . $totalRows . "\n";
if ($upFile !== null) echo '  Uploads: ' . $fmt($upFile) . "\n";
if ($removed > 0) echo "  Ротация: удалено старых бэкапов: {$removed}\n";
exit(0);
