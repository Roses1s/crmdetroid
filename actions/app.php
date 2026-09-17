<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Детальная страница заявки (v20): карточка (get_app/save_app), статус
 * (set_app_status), лог заявки (get_app_comments/add/edit/delete_app_comment).
 * Лог — паритет с логом лида: те же общие тела crm_apply_comment_* из api.php,
 * та же проверка авторства (can_edit_comment/can_delete_comment).
 * Права = доступ к лиду-владельцу (crm_app_for_user); отдельных прав на заявку нет.
 */

function crm_action_get_app(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    $app = crm_app_for_user($pdo, $id, $viewUid);
    if (!$app) err('Заявка не найдена');
    $lead = crm_lead_for_user($pdo, (string) $app['lead_id'], $viewUid);
    ok([
        'application' => crm_lead_app_to_api($app),
        'leadTitle' => (string) ($lead['title'] ?? ''),
        'leadInn' => (string) ($lead['inn'] ?? ''),
        'leadLogistName' => (string) ($lead['logist_name'] ?? ''),
        'leadLogistPhone' => (string) ($lead['logist_phone'] ?? ''),
    ]);
}

function crm_action_get_app_comments(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    if (!crm_app_for_user($pdo, $id, $viewUid)) err('Заявка не найдена');
    ok(['comments' => crm_app_comments($pdo, $id)]);
}

/**
 * Ответ save_app/set_app_status: свежая заявка (ревизия страницы — внутри
 * application.updatedAt) + статистика лида + ревизия лида для доски.
 * Форма повторяет save_lead_app (минус applicationsCount: число заявок не меняется).
 */
function crm_app_saved_payload(PDO $pdo, string $id, string $leadId, int $viewUid, int $leadRev): array {
    $saved = crm_lead_app_by_id($pdo, $id);
    $leadRow = crm_lead_for_user($pdo, $leadId, $viewUid);
    return [
        'id' => $id,
        'application' => $saved ? crm_lead_app_to_api($saved) : null,
        'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($leadRow['inn'] ?? '')),
        'updatedAt' => $leadRev,
    ];
}

/**
 * Сохранение полей заявки со страницы. Только обновление (создание — через
 * модалку save_lead_app); валидация общая (crm_validate_app_fields),
 * блокировка оптимистическая — как в save_lead_app.
 */
function crm_action_save_app(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $app = crm_app_for_user($pdo, $id, $viewUid);
    if (!$app) err('Заявка не найдена');
    $leadId = (string) $app['lead_id'];
    $f = crm_validate_app_fields($in);
    if (array_key_exists('updatedAt', $in) && (int) $app['updated_at'] !== intv($in['updatedAt'])) {
        err('Заявка изменена в другом месте');
    }
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE crm_lead_apps SET `number`=?, city_from=?, city_to=?, rate=?, margin=?, vat=?, carrier_rate=?, carrier_vat=?, carrier_company=?, carrier_inn=?, carrier_name=?, carrier_phone=?, load_address=?, load_contact=?, load_date_from=?, load_date_to=?, load_time_from=?, load_time_to=?, unload_address=?, unload_contact=?, unload_date_from=?, unload_date_to=?, unload_time_from=?, unload_time_to=?, updated_at=? WHERE id=? AND lead_id=? AND updated_at=?');
        $upd->execute([$f['number'], $f['cityFrom'], $f['cityTo'], $f['rate'], $f['margin'], $f['vat'], $f['carrierRate'], $f['carrierVat'], $f['carrierCompany'], $f['carrierInn'], $f['carrierName'], $f['carrierPhone'], $f['loadAddress'], $f['loadContact'], $f['loadDateFrom'], $f['loadDateTo'], $f['loadTimeFrom'], $f['loadTimeTo'], $f['unloadAddress'], $f['unloadContact'], $f['unloadDateFrom'], $f['unloadDateTo'], $f['unloadTimeFrom'], $f['unloadTimeTo'], $now, $id, $leadId, (int) $app['updated_at']]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            err('Заявка изменена в другом месте');
        }
        crm_app_sys_field_changes($pdo, $id, $app, $f);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_log_fail('save_app', $e);
        err('Не удалось сохранить заявку');
    }
    $leadRev = crm_touch_lead($pdo, $leadId);
    ok(crm_app_saved_payload($pdo, $id, $leadId, $viewUid, $leadRev));
}

/**
 * Смена статуса заявки. Переходы свободные (ограничений нет, решение штурма),
 * whitelist — crm_app_statuses(). Повторный клик по текущему статусу —
 * не ошибка и не событие в логе: просто отдаём свежее состояние.
 */
function crm_action_set_app_status(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $app = crm_app_for_user($pdo, $id, $viewUid);
    if (!$app) err('Заявка не найдена');
    $leadId = (string) $app['lead_id'];
    $statuses = crm_app_statuses();
    // Строгий список, как налоги в save_lead_app: strv приводит и JSON-число,
    // мусор ('abc', '1.5') в список не попадает.
    $toRaw = strv($in['status'] ?? '', 3);
    if (!in_array($toRaw, ['0', '1', '2'], true)) err('Неизвестный статус заявки');
    $to = (int) $toRaw;
    $from = (int) ($app['status'] ?? 0);
    if ($to === $from) {
        $leadRow = crm_lead_for_user($pdo, $leadId, $viewUid);
        ok(crm_app_saved_payload($pdo, $id, $leadId, $viewUid, (int) ($leadRow['updated_at'] ?? 0)));
    }
    if (array_key_exists('updatedAt', $in) && (int) $app['updated_at'] !== intv($in['updatedAt'])) {
        err('Заявка изменена в другом месте');
    }
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE crm_lead_apps SET status = ?, updated_at = ? WHERE id = ? AND lead_id = ? AND updated_at = ?');
        $upd->execute([$to, $now, $id, $leadId, (int) $app['updated_at']]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            err('Заявка изменена в другом месте');
        }
        // Старого статуса вне справочника быть не должно (валидация + integrity_check),
        // но ручная правка БД не должна ронять смену статуса в 500.
        $fromLabel = $statuses[$from] ?? ('#' . $from);
        crm_sys_comment($pdo, $id, "Статус изменен: {$fromLabel} ➔ {$statuses[$to]}", 'crm_app_comments', 'app_id', 'ac_');
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_log_fail('set_app_status', $e);
        err('Не удалось сменить статус');
    }
    $leadRev = crm_touch_lead($pdo, $leadId);
    ok(crm_app_saved_payload($pdo, $id, $leadId, $viewUid, $leadRev));
}

function crm_action_add_app_comment(PDO $pdo, array $user, int $viewUid): never {
    [$appId, $text] = crm_comment_input('app_id');
    if (!crm_app_for_user($pdo, $appId, $viewUid)) err('Заявка не найдена');
    crm_apply_comment_add($pdo, 'crm_app_comments', 'crm_app_attachments', 'app_id', $appId, $text, $user, 'ac_', 'add_app_comment');
    $rev = crm_touch_app($pdo, $appId);
    ok(['updatedAt' => $rev]);
}

function crm_action_edit_app_comment(PDO $pdo, array $user, int $viewUid): never {
    [$cid, $text] = crm_comment_input('id');
    $c = crm_app_comment_for_user($pdo, $cid, $viewUid);
    if (!$c) err('Комментарий не найден');
    if (!can_edit_comment($user, $c)) err('Нет прав');
    crm_apply_comment_edit($pdo, $cid, $text, 'crm_app_comments', 'crm_app_attachments', 'edit_app_comment');
    $rev = crm_touch_app($pdo, (string) $c['app_id']);
    ok(['updatedAt' => $rev]);
}

function crm_action_delete_app_comment(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $cid = strv($in['id'] ?? '', 80);
    $c = crm_app_comment_for_user($pdo, $cid, $viewUid);
    if (!$c) err('Комментарий не найден');
    if (!can_delete_comment($user, $c)) err('Нет прав');
    $rev = crm_apply_comment_delete($pdo, $cid, 'crm_app_comments', 'crm_app_attachments', 'delete_app_comment',
        static fn () => crm_touch_app($pdo, (string) $c['app_id']));
    ok(['updatedAt' => $rev]);
}
