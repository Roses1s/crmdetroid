<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Поиск лидов по всем полям и логу (search_leads).
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_search_leads(PDO $pdo, array $user, int $viewUid): never {
    $q = strv($_GET['q'] ?? '', 120);
    $out = crm_search_leads($pdo, $viewUid, $q);
    if (($user['role'] ?? '') === 'admin') $out['employees'] = crm_search_employees($pdo, $q);
    ok($out);
}
