<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Комментарии лида и вложения: чтение, добавление, правка, удаление (в т.ч. отдельного вложения).
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_get_comments(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    if ($id === '' || !crm_lead_for_user($pdo, $id, $viewUid)) err('Лид не найден');
    ok(['comments' => crm_lead_comments($pdo, $id)]);
}

function crm_action_add_comment(PDO $pdo, array $user, int $viewUid): never {
    [$leadId, $text] = crm_comment_input('lead_id');
    if (!crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
    crm_apply_comment_add($pdo, 'crm_comments', 'crm_attachments', 'lead_id', $leadId, $text, $user, 'c_', 'add_comment');
    $rev = crm_touch_lead($pdo, $leadId);
    ok(['updatedAt' => $rev]);
}

function crm_action_edit_comment(PDO $pdo, array $user, int $viewUid): never {
    [$cid, $text] = crm_comment_input('id');
    $c = crm_comment_for_user($pdo, $cid, $viewUid);
    if (!$c) err('Комментарий не найден');
    if (!can_edit_comment($user, $c)) err('Нет прав');
    crm_apply_comment_edit($pdo, $cid, $text, 'crm_comments', 'crm_attachments', 'edit_comment');
    $rev = crm_touch_lead($pdo, (string) $c['lead_id']);
    ok(['updatedAt' => $rev]);
}

function crm_action_delete_comment(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $cid = strv($in['id'] ?? '', 80);
    $c = crm_comment_for_user($pdo, $cid, $viewUid);
    if (!$c) err('Комментарий не найден');
    if (!can_delete_comment($user, $c)) err('Нет прав');
    $rev = crm_apply_comment_delete($pdo, $cid, 'crm_comments', 'crm_attachments', 'delete_comment',
        static fn () => crm_touch_lead($pdo, (string) $c['lead_id']));
    ok(['updatedAt' => $rev]);
}

function crm_action_delete_attachment(PDO $pdo, array $user, int $viewUid): never {
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
