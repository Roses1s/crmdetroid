<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Теги лидов (v17): личный справочник сотрудника (crm_tags) + привязки к лидам
 * (crm_lead_tags). Все действия работают с доской $viewUid (админ через ?as= управляет
 * тегами сотрудника, как и лидами). Цвет — только из палитры CRM_TAG_COLORS (db.php):
 * клиент подставляет цвет в style-атрибут чипа, произвольные строки недопустимы.
 * Каждое изменение дёргает crm_meta_bump('tags_<uid>') — hash доски в get_data меняется,
 * и остальные вкладки перечитывают данные.
 */

/** Создать или переименовать/перекрасить тег. Вход: {id?, name, color}. */
function crm_action_save_tag(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = intv($in['id'] ?? 0);
    $name = strv($in['name'] ?? '', 40);
    if ($name === '') err('Укажите название тега');
    $color = crm_tag_color(strv($in['color'] ?? '', 7));
    try {
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE crm_tags SET name = ?, color = ? WHERE id = ? AND user_id = ?');
            $st->execute([$name, $color, $id, $viewUid]);
            if ($st->rowCount() === 0) {
                // Либо тег чужой/не существует, либо значения не изменились — различаем SELECT'ом
                $chk = $pdo->prepare('SELECT id FROM crm_tags WHERE id = ? AND user_id = ?');
                $chk->execute([$id, $viewUid]);
                if (!$chk->fetch()) err('Тег не найден');
            }
        } else {
            $st = $pdo->prepare('INSERT INTO crm_tags (user_id, name, color, created_at) VALUES (?,?,?,?)');
            $st->execute([$viewUid, $name, $color, now_ms()]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) err('Тег с таким названием уже есть');
        crm_log_fail('save_tag', $e);
        err('Не удалось сохранить тег');
    }
    crm_meta_bump($pdo, 'tags_' . $viewUid);
    ok(['tag' => ['id' => $id, 'name' => $name, 'color' => $color], 'tags' => crm_tags_for_user($pdo, $viewUid)]);
}

/** Удалить тег из справочника (снимается со всех лидов). Вход: {id}. */
function crm_action_delete_tag(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = intv($in['id'] ?? 0);
    if ($id <= 0) err('Тег не найден');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('DELETE FROM crm_tags WHERE id = ? AND user_id = ?');
        $st->execute([$id, $viewUid]);
        if ($st->rowCount() === 0) {
            $pdo->rollBack();
            err('Тег не найден');
        }
        $pdo->prepare('DELETE FROM crm_lead_tags WHERE tag_id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$e instanceof PDOException) throw $e;
        crm_log_fail('delete_tag', $e);
        err('Не удалось удалить тег');
    }
    crm_meta_bump($pdo, 'tags_' . $viewUid);
    ok(['tags' => crm_tags_for_user($pdo, $viewUid)]);
}

/**
 * Назначить лиду набор тегов целиком. Вход: {leadId, tagIds: [..]}.
 * Идемпотентно: полная замена привязок (DELETE + INSERT в транзакции), поэтому
 * никаких конфликтов «добавили/сняли одновременно» — выигрывает последний запрос.
 * Чужие/несуществующие id молча отбрасываются (фильтр по user_id).
 */
function crm_action_set_lead_tags(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $leadId = strv($in['leadId'] ?? '', 80);
    if ($leadId === '' || !crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
    $raw = $in['tagIds'] ?? [];
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $v) {
            $n = intv($v);
            if ($n > 0) $ids[$n] = true;
        }
    }
    $ids = array_slice(array_keys($ids), 0, 20); // здравый предел на лид
    // Только собственные теги владельца доски
    $valid = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM crm_tags WHERE user_id = ? AND id IN ($ph)");
        $st->execute(array_merge([$viewUid], $ids));
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM crm_lead_tags WHERE lead_id = ?')->execute([$leadId]);
        if ($valid) {
            $ins = $pdo->prepare('INSERT INTO crm_lead_tags (lead_id, tag_id) VALUES (?,?)');
            foreach ($valid as $tid) $ins->execute([$leadId, $tid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$e instanceof PDOException) throw $e;
        crm_log_fail('set_lead_tags', $e);
        err('Не удалось сохранить теги лида');
    }
    crm_meta_bump($pdo, 'tags_' . $viewUid);
    $map = crm_lead_tags_map($pdo, $viewUid);
    ok(['leadTags' => $map[$leadId] ?? []]);
}
