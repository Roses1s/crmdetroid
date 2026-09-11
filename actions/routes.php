<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Справочник маршрутов: направления и перевозчики с их комментариями.
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_get_directions(PDO $pdo, array $user, int $viewUid): never {
    $q = strv($_GET['q'] ?? '', 80);
    ok(['directions' => crm_directions_list($pdo, $q)]);
}

function crm_action_save_direction(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $from = crm_norm_city(strv($in['cityFrom'] ?? '', 80));
    $to = crm_norm_city(strv($in['cityTo'] ?? '', 80));
    if ($from === '' || $to === '') err('Укажите города откуда и куда');
    $id = strv($in['id'] ?? '', 80);
    $uid = (int) $user['id'];
    if ($id === '') {
        $dup = $pdo->prepare('SELECT id FROM crm_directions WHERE city_from = ? AND city_to = ?');
        $dup->execute([$from, $to]);
        if ($dup->fetch()) err('Такое направление уже есть');
        $id = crm_new_id($pdo, 'd_', 'crm_directions');
        try {
            $pdo->prepare('INSERT INTO crm_directions (id, city_from, city_to, created_by, created_at) VALUES (?,?,?,?,?)')
                ->execute([$id, $from, $to, $uid, now_ms()]);
        } catch (PDOException $e) {
            // Гонка: направление добавили между проверкой и INSERT — uq_dir, а не 500
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) err('Такое направление уже есть');
            throw $e;
        }
    } else {
        $dir = crm_direction_by_id($pdo, $id);
        if (!$dir) err('Направление не найдено');
        if (!can_manage_ref($user, $dir)) err('Переименовать направление может тот, кто его добавил, или администратор');
        $dup = $pdo->prepare('SELECT id FROM crm_directions WHERE city_from = ? AND city_to = ? AND id <> ?');
        $dup->execute([$from, $to, $id]);
        if ($dup->fetch()) err('Такое направление уже есть');
        try {
            $pdo->prepare('UPDATE crm_directions SET city_from = ?, city_to = ? WHERE id = ?')->execute([$from, $to, $id]);
        } catch (PDOException $e) {
            // Гонка: такую пару городов создали между проверкой и UPDATE — uq_dir, а не 500
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) err('Такое направление уже есть');
            throw $e;
        }
    }
    crm_meta_bump($pdo, 'routes');
    ok(['id' => $id]);
}

function crm_action_delete_direction(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $dir = crm_direction_by_id($pdo, $id);
    if (!$dir) err('Направление не найдено');
    if (!can_manage_ref($user, $dir)) err('Удалить направление может тот, кто его добавил, или администратор');
    $ids = $pdo->prepare('SELECT id FROM crm_carriers WHERE direction_id = ?');
    $ids->execute([$id]);
    $urls = [];
    $pdo->beginTransaction();
    try {
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $urls = array_merge($urls, crm_purge_carrier($pdo, (string) $cid, false));
        }
        $pdo->prepare('DELETE FROM crm_directions WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_log_fail('delete_direction', $e);
        err('Не удалось удалить');
    }
    crm_unlink_urls($urls);
    crm_meta_bump($pdo, 'routes');
    // Каскадное удаление (перевозчики, логи, файлы) — след в аудите обязателен.
    crm_audit($pdo, $user, 'direction_delete', $id, (string) $dir['city_from'] . ' → ' . (string) $dir['city_to']);
    ok();
}

function crm_action_get_carriers(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    $dir = crm_direction_by_id($pdo, $id);
    if (!$dir) err('Направление не найдено');
    ok([
        'direction' => [
            'id' => $dir['id'],
            'cityFrom' => $dir['city_from'],
            'cityTo' => $dir['city_to'],
            'createdByName' => $dir['creator'] ?: '',
            'canManage' => can_manage_ref($user, $dir),
        ],
        'carriers' => crm_carriers_list($pdo, $id, $user),
    ]);
}

function crm_action_save_carrier(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $dirId = strv($in['directionId'] ?? '', 80);
    if (!crm_direction_by_id($pdo, $dirId)) err('Направление не найдено');
    $name = strv($in['name'] ?? '', 120);
    if ($name === '') err('Укажите имя или название');
    $phone = strv($in['phone'] ?? '', 40);
    $company = strv($in['company'] ?? '', 200);
    $id = strv($in['id'] ?? '', 80);
    $uid = (int) $user['id'];
    $now = now_ms();
    if ($id === '') {
        $note = strv($in['note'] ?? '', 2000);
        $id = crm_new_id($pdo, 'k_', 'crm_carriers');
        $pdo->prepare('INSERT INTO crm_carriers (id, direction_id, name, phone, company, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $dirId, $name, $phone, $company, $note, $uid, $now, $now]);
    } else {
        $st = $pdo->prepare('SELECT * FROM crm_carriers WHERE id = ? AND direction_id = ?');
        $st->execute([$id, $dirId]);
        $cur = $st->fetch();
        if (!$cur) err('Контакт не найден');
        // Править карточку перевозчика может её создатель или админ — как и удалять/переименовывать
        // направление (см. README «Права и правила»). Раньше проверка была только на delete_carrier,
        // и любой сотрудник мог переписать чужую карточку через save_carrier.
        if (!can_manage_ref($user, $cur)) err('Изменить перевозчика может тот, кто его добавил, или администратор');
        $rev = (int) ($cur['updated_at'] ?? 0);
        if (array_key_exists('updatedAt', $in) && $rev !== intv($in['updatedAt'])) {
            err('Карточка изменена в другом месте');
        }
        $note = array_key_exists('note', $in) ? strv($in['note'] ?? '', 2000) : (string) ($cur['note'] ?? '');
        // Условие по updated_at — единственная защита от одновременной правки в двух вкладках.
        // (Старой ветки «updated_at = 0 → обновить без условия» больше нет: после миграции v6 нулей
        // не бывает, а безусловный UPDATE перетирал чужие изменения.)
        $upd = $pdo->prepare('UPDATE crm_carriers SET name = ?, phone = ?, company = ?, note = ?, updated_at = ? WHERE id = ? AND updated_at = ?');
        $upd->execute([$name, $phone, $company, $note, $now, $id, $rev]);
        if ($upd->rowCount() === 0) err('Карточка изменена в другом месте');
    }
    crm_meta_bump($pdo, 'routes');
    ok(['id' => $id, 'updatedAt' => $now]);
}

function crm_action_delete_carrier(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $car = crm_carrier_by_id($pdo, $id);
    if (!$car) err('Контакт не найден');
    if (!can_manage_ref($user, $car)) err('Удалить перевозчика может тот, кто его добавил, или администратор');
    crm_purge_carrier($pdo, $id);
    crm_meta_bump($pdo, 'routes');
    // Удаление с логом и файлами — след в аудите обязателен.
    crm_audit($pdo, $user, 'carrier_delete', $id, (string) $car['name']);
    ok();
}

function crm_action_get_carrier(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    $row = crm_carrier_by_id($pdo, $id);
    if (!$row) err('Контакт не найден');
    $dir = crm_direction_by_id($pdo, (string) $row['direction_id']);
    ok([
        'carrier' => [
            'id' => $row['id'],
            'directionId' => $row['direction_id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'company' => $row['company'],
            'note' => $row['note'] ?? '',
            'createdByName' => $row['creator'] ?: '',
            'canManage' => can_manage_ref($user, $row),
            'updatedAt' => (int) ($row['updated_at'] ?? $row['created_at'] ?? 0),
        ],
        'direction' => $dir ? [
            'id' => $dir['id'],
            'cityFrom' => $dir['city_from'],
            'cityTo' => $dir['city_to'],
        ] : null,
        'comments' => crm_carrier_comments($pdo, $id),
    ]);
}

function crm_action_add_carrier_comment(PDO $pdo, array $user, int $viewUid): never {
    [$carrierId, $text] = crm_comment_input('carrier_id');
    if (!crm_carrier_by_id($pdo, $carrierId)) err('Контакт не найден');
    crm_apply_comment_add($pdo, 'crm_carrier_comments', 'crm_carrier_attachments', 'carrier_id', $carrierId, $text, $user, 'cc_', 'add_carrier_comment');
    $rev = crm_touch_carrier($pdo, $carrierId);
    crm_meta_bump($pdo, 'routes');
    ok(['updatedAt' => $rev]);
}

function crm_action_edit_carrier_comment(PDO $pdo, array $user, int $viewUid): never {
    [$cid, $text] = crm_comment_input('id');
    $c = crm_carrier_comment_by_id($pdo, $cid);
    if (!$c) err('Комментарий не найден');
    if (!can_edit_comment($user, $c)) err('Нет прав');
    crm_apply_comment_edit($pdo, $cid, $text, 'crm_carrier_comments', 'crm_carrier_attachments', 'edit_carrier_comment');
    $rev = crm_touch_carrier($pdo, (string) $c['carrier_id']);
    crm_meta_bump($pdo, 'routes');
    ok(['updatedAt' => $rev]);
}

function crm_action_delete_carrier_comment(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $cid = strv($in['id'] ?? '', 80);
    $c = crm_carrier_comment_by_id($pdo, $cid);
    if (!$c) err('Комментарий не найден');
    if (!can_delete_comment($user, $c)) err('Нет прав');
    $rev = crm_apply_comment_delete($pdo, $cid, 'crm_carrier_comments', 'crm_carrier_attachments', 'delete_carrier_comment',
        static fn () => crm_touch_carrier($pdo, (string) $c['carrier_id']));
    crm_meta_bump($pdo, 'routes');
    ok(['updatedAt' => $rev]);
}
