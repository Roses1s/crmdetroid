<?php
// Пробник живости PHP: намеренно БЕЗ require (config/db/actions), чтобы отвечать
// «PHP исполняется» даже когда api.php мёртв. Повод — авария 2026-09-17:
// оборванная ручная заливка одного PHP-файла роняла весь API (api.php требует
// все actions на старте), и снаружи было видно только пустой 500.
// Использование после (ручного) деплоя: открыть в браузере
// https://crmdetroid.ru/health.php — должен отдать {"ok":true,"v":"fixNN",...}.
// Поле v читается из index.html (?v=), руками его бампать не нужно.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$v = null;
$idx = @file_get_contents(__DIR__ . '/index.html');
if (is_string($idx) && preg_match('/js\\/app\\.js\\?v=([A-Za-z0-9_.-]+)/', $idx, $m)) $v = $m[1];
echo json_encode(['ok' => true, 'v' => $v, 'php' => PHP_VERSION, 'time' => time()], JSON_UNESCAPED_UNICODE);
