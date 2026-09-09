<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Админские действия: диагностика IP/прокси, ручная уборка uploads/, аудит-лог, проверка целостности.
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_whoami(PDO $pdo, array $user, int $viewUid): never {
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

function crm_action_sweep_uploads(PDO $pdo, array $user, int $viewUid): never {
    // Ручная уборка uploads/ (файлы без записей в БД); автоматически то же выполняется раз в ~1000 запросов (см. api.php перед switch).
    require_admin($user);
    [$checked, $removed] = crm_sweep_uploads($pdo);
    ok(['checked' => $checked, 'removed' => $removed]);
}

function crm_action_integrity_check(PDO $pdo, array $user, int $viewUid): never {
    // Диагностика ссылочной целостности (замена FOREIGN KEY, которых нет в схеме).
    // Находит orphan-записи: комментарии без лида, вложения без комментария и т.д.
    require_admin($user);
    $issues = crm_integrity_check($pdo);
    ok(['issues' => $issues, 'count' => count($issues)]);
}

function crm_action_get_audit(PDO $pdo, array $user, int $viewUid): never {
    // Последние события аудит-лога (только админ). limit ≤ 500.
    require_admin($user);
    $limit = max(1, min(500, intv($_GET['limit'] ?? 100)));
    $rows = [];
    try {
        // LIMIT через bindValue(PARAM_INT): при EMULATE_PREPARES=false MySQL принимает
        // параметр в LIMIT, если он привязан как целое. Раньше $limit интерполировался —
        // безопасно (int, зажатый max/min), но выбивалось из общего стиля «ввод только параметрами».
        $st = $pdo->prepare('SELECT actor_id, actor_name, action, target, details, ip, created_at FROM crm_audit ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st as $r) {
            $rows[] = [
                'actorId' => (int) $r['actor_id'],
                'actorName' => $r['actor_name'],
                'action' => $r['action'],
                'target' => $r['target'],
                'details' => $r['details'],
                'ip' => $r['ip'],
                'time' => (int) $r['created_at'],
            ];
        }
    } catch (PDOException $e) { /* таблица появится после миграции v14 */ }
    ok(['events' => $rows]);
}
