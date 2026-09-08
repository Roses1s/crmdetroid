<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Настройка этапов воронки пользователя (save_stages): переименование, добавление, удаление с переносом лидов.
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_save_stages(PDO $pdo, array $user, int $viewUid): never {
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
