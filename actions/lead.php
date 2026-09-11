<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Лиды: доска (get_data), карточка, сохранение, перенос между этапами, удаление, заявки лида, активность клиентов.
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_get_data(PDO $pdo, array $user, int $viewUid): never {
    $uid = $viewUid;
    $stages = crm_stages($pdo, $uid);
    // Хэш ревизии — из лёгкой выборки (только id и времена), полные строки не грузим,
    // пока не станет ясно, что они нужны (п. 12 ревью: раньше каждый полл тянул всё).
    $st = $pdo->prepare('SELECT id, created_at, updated_at FROM crm_leads WHERE user_id = ? ORDER BY created_at ASC');
    $st->execute([$uid]);
    $allIds = []; $u = 0; $cr = 0; $idsConcat = '';
    foreach ($st as $r) {
        $allIds[] = (string) $r['id'];
        $u = max($u, (int) $r['updated_at']);
        $cr = max($cr, (int) $r['created_at']);
        $idsConcat .= (string) $r['id'];
    }
    $c = count($allIds);
    $h = $c > 0 ? substr(hash('sha256', $idsConcat), 0, 16) : '0';
    // tags_<uid> в ревизии: изменение справочника тегов или привязок (v17) не трогает
    // updated_at лидов, но должно перерисовать доску на других вкладках.
    $revStr = $uid . '|' . $c . '|' . $u . '|' . $cr . '|' . $h . '|' . crm_meta_get($pdo, 'users') . '|' . crm_meta_get($pdo, 'tags_' . $uid) . '|' . implode("\n", $stages);
    $hash = substr(hash('sha256', $revStr), 0, 32);
    $client = strv($_GET['hash'] ?? '', 64);
    if ($client !== '' && strlen($client) === strlen($hash) && hash_equals($hash, $client)) {
        ok(['unchanged' => true, 'hash' => $hash]);
    }
    // Дельта (п. 12 ревью): клиент, у которого уже есть доска (hash + since = максимальный
    // виденный им таймштамп), получает только изменённые/новые лиды + полный список id
    // (по нему клиент удаляет исчезнувшие и восстанавливает порядок). Сравнение >= since,
    // а не >: два изменения в одну миллисекунду (второе — после ответа) иначе потерялись бы.
    // Случай «переименован этап» (crm_leads.stage меняется без updated_at) дельта покрывает
    // на клиенте: изменение $stages меняет hash, и клиент при несовпадении своего списка
    // этапов с присланным делает полную перезагрузку (см. Store.load).
    $since = intv($_GET['since'] ?? 0);
    if ($client !== '' && $since > 0) {
        $chSt = $pdo->prepare('SELECT id, title, inn, phone, logist_phone, manager, applications_count, stage, created_at, updated_at FROM crm_leads WHERE user_id = ? AND (updated_at >= ? OR created_at >= ?) ORDER BY created_at ASC');
        $chSt->execute([$uid, $since, $since]);
        $changed = [];
        foreach ($chSt as $r) $changed[] = crm_lead_row_to_api($r, false);
        ok(['hash' => $hash, 'delta' => true, 'ids' => $allIds, 'changed' => $changed, 'stages' => $stages, 'user' => crm_user_public($user), 'colleagues' => crm_colleagues($pdo),
            'tags' => crm_tags_for_user($pdo, $uid), 'leadTags' => (object) crm_lead_tags_map($pdo, $uid)]);
    }
    ok(['hash' => $hash, 'stages' => $stages, 'leads' => crm_leads_full($pdo, $uid), 'user' => crm_user_public($user), 'colleagues' => crm_colleagues($pdo),
        'tags' => crm_tags_for_user($pdo, $uid), 'leadTags' => (object) crm_lead_tags_map($pdo, $uid)]);
}

function crm_action_get_lead(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    $row = $id === '' ? null : crm_lead_for_user($pdo, $id, $viewUid);
    if (!$row) err('Лид не найден');
    $lead = crm_lead_row_to_api($row, true);
    $lead['applications'] = crm_lead_apps($pdo, $id);
    $lead['applicationsCount'] = count($lead['applications']);
    $lead['appsStats'] = crm_apps_stats($pdo, $viewUid, $id, (string) ($row['inn'] ?? ''));
    ok(['lead' => $lead]);
}

function crm_action_save_lead(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $uid = $viewUid;
    $stages = crm_stages($pdo, $uid);
    $row = $id !== '' ? crm_lead_for_user($pdo, $id, $uid) : null;
    if (!$row) {
        if ($id !== '') {
            // Чужой id — «не найден»; несуществующий — игнорируем: id новых лидов выдаёт только сервер
            // (раньше клиент мог создать лид с произвольным id, вплоть до «../../etc»).
            $any = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
            $any->execute([$id]);
            if ($any->fetch()) err('Лид не найден');
        }
        $id = crm_new_id($pdo, 'l_', 'crm_leads');
    }

    $title = strv($in['title'] ?? ($row['title'] ?? ''), 200, 'Без названия');
    $inn = preg_replace('/\D/', '', strv($in['inn'] ?? ($row['inn'] ?? ''), 12)) ?? '';
    if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) err('ИНН 10 или 12 цифр');
    $phone = strv($in['phone'] ?? ($row['phone'] ?? ''), 40);
    // Код АТИ (ati.su): колонка ati существовала в схеме с ранних версий, но не была
    // выведена в интерфейс. Телефон лида из карточки убран (остался в окне создания и в БД).
    $ati = strv($in['ati'] ?? ($row['ati'] ?? ''), 300);
    $email = strv($in['email'] ?? ($row['email'] ?? ''), 120);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
    // Продавец по умолчанию — владелец доски (админ через ?as= создаёт лид сотруднику, а не себе)
    $ownerName = $uid === (int) $user['id'] ? (string) $user['name'] : (string) ((crm_user_by_id($pdo, $uid)['name'] ?? '') ?: $user['name']);
    $manager = strv($in['manager'] ?? ($row['manager'] ?? $ownerName), 80, $ownerName);
    $logistName = strv($in['logistName'] ?? ($row['logist_name'] ?? ''), 80);
    $logistPhone = strv($in['logistPhone'] ?? ($row['logist_phone'] ?? ''), 40);
    $apps = 0;
    $stage = strv($in['stage'] ?? ($row['stage'] ?? ($stages[0] ?? 'Новый')), 80);
    if (!in_array($stage, $stages, true)) $stage = $row['stage'] ?? ($stages[0] ?? 'Новый');

    if ($row && array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
        err('Карточка изменена в другом месте');
    }

    $now = now_ms();
    $transferredTo = null;
    $pdo->beginTransaction();
    try {
        if (!$row) {
            $ins = $pdo->prepare('INSERT INTO crm_leads (id,user_id,title,inn,phone,ati,email,manager,logist_name,logist_phone,applications_count,stage,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$id, $uid, $title, $inn, $phone, $ati, $email, $manager, $logistName, $logistPhone, $apps, $stage, $now, $now]);
            crm_sys_comment($pdo, $id, 'Лид создан');
        } else {
            $upd = $pdo->prepare('UPDATE crm_leads SET title=?,inn=?,phone=?,ati=?,email=?,manager=?,logist_name=?,logist_phone=?,stage=?,updated_at=? WHERE id=? AND user_id=? AND updated_at=?');
            $upd->execute([$title, $inn, $phone, $ati, $email, $manager, $logistName, $logistPhone, $stage, $now, $id, $uid, (int) $row['updated_at']]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                err('Карточка изменена в другом месте');
            }
        }
        // Передача лида — только по явному id сотрудника (клиент подставляет его после
        // подтверждения «Передать лид?»). Раньше цель угадывалась по любому слову из поля
        // «Продавец», и совпадение по имени могло отдать лид не тому человеку.
        $toId = intv($in['transferTo'] ?? 0);
        if ($toId > 0 && $toId !== $uid) {
            // Несуществующий получатель проверяется отдельно: раньше null из crm_transfer_lead
            // означал и «сотрудник не найден», и «конфликт версии» — сообщение выбиралось наугад.
            if (!crm_user_by_id($pdo, $toId)) {
                $pdo->rollBack();
                err('Сотрудник не найден');
            }
            $via = $uid === (int) $user['id'] ? '' : (string) $user['name'];
            // К этому моменту updated_at лида равен $now и для нового (INSERT выше), и для
            // существующего (UPDATE выше ставит updated_at = $now). Раньше сюда передавался
            // старый $row['updated_at'] — WHERE не находил строку, и передача существующего
            // лида всегда падала «Карточка изменена в другом месте» (поймано smoke-тестами в CI).
            $toName = crm_transfer_lead($pdo, $id, $uid, $toId, $ownerName, $stage, $via, $now);
            if ($toName === null) {
                $pdo->rollBack();
                err('Карточка изменена в другом месте');
            }
            $transferredTo = $toName;
            $now = now_ms();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$e instanceof PDOException) throw $e;
        crm_log_fail('save_lead', $e);
        err('Не удалось сохранить');
    }
    if ($transferredTo !== null) {
        crm_audit($pdo, $user, 'lead_transfer', $id, 'to: ' . $transferredTo);
        ok(['id' => $id, 'transferred' => true, 'to' => $transferredTo, 'updatedAt' => $now]);
    }
    ok(['id' => $id, 'updatedAt' => $now]);
}

function crm_action_move_lead(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $stage = strv($in['stage'] ?? '', 80);
    $uid = $viewUid;
    $stages = crm_stages($pdo, $uid);
    if ($stage === '' || !in_array($stage, $stages, true)) err('Нет такого этапа');
    $row = crm_lead_for_user($pdo, $id, $uid);
    if (!$row) err('Лид не найден');
    if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
        err('Карточка изменена в другом месте');
    }
    $now = now_ms();
    if ($row['stage'] !== $stage) {
        $from = (string) $row['stage'];
        // Транзакция: UPDATE и системный комментарий атомарны. Раньше комментарий шёл
        // отдельно — при сбое БД лид перемещался, но запись в логе не появлялась.
        $pdo->beginTransaction();
        try {
            $stU = $pdo->prepare('UPDATE crm_leads SET stage = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ?');
            $stU->execute([$stage, $now, $id, $uid, (int) $row['updated_at']]);
            if ($stU->rowCount() === 0) {
                $pdo->rollBack();
                err('Карточка изменена в другом месте');
            }
            crm_sys_comment($pdo, $id, "Статус изменен: {$from} ➔ {$stage}");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$e instanceof PDOException) throw $e;
            crm_log_fail('move_lead', $e);
            err('Не удалось переместить');
        }
    }
    ok(['updatedAt' => $row['stage'] === $stage ? (int) $row['updated_at'] : $now]);
}

function crm_action_delete_lead(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $uid = $viewUid;
    $row = crm_lead_for_user($pdo, $id, $uid);
    if (!$row) err('Лид не найден');
    // Оптимистическая блокировка: если лид изменили в другой вкладке — предупредить, а не удалять молча.
    if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
        err('Карточка изменена в другом месте');
    }
    crm_purge_lead($pdo, $id);
    ok();
}

function crm_action_save_lead_app(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $leadId = strv($in['leadId'] ?? '', 80);
    $row = $leadId === '' ? null : crm_lead_for_user($pdo, $leadId, $viewUid);
    if (!$row) err('Лид не найден');
    $from = crm_norm_city(strv($in['cityFrom'] ?? '', 80));
    $to = crm_norm_city(strv($in['cityTo'] ?? '', 80));
    if ($from === '' || $to === '') err('Укажите откуда и куда');
    // Ставка и маржа парсятся одинаково строго: раньше из ставки молча вырезались не-цифры
    // и «12 500,50» превращалось в 1250050.
    $rate = crm_parse_money(strv($in['rate'] ?? '', 40));
    if ($rate === null) err('Ставка: число, копейки через запятую');
    $rate = crm_money_in($rate);
    $margin = crm_parse_money(strv($in['margin'] ?? '', 40));
    if ($margin === null) err('Маржа: число, копейки через запятую');
    $margin = crm_money_in($margin);
    $vat = !empty($in['vat']) ? 1 : 0;
    $company = strv($in['carrierCompany'] ?? '', 200);
    $inn = preg_replace('/\D/', '', strv($in['carrierInn'] ?? '', 12)) ?? '';
    if ($inn !== '' && strlen($inn) !== 10 && strlen($inn) !== 12) err('ИНН 10 или 12 цифр');
    $name = strv($in['carrierName'] ?? '', 80);
    $phone = strv($in['carrierPhone'] ?? '', 40);
    $id = strv($in['id'] ?? '', 80);
    $now = now_ms();
    $existing = $id !== '' ? crm_lead_app_by_id($pdo, $id) : null;
    if ($id !== '' && (!$existing || (string) $existing['lead_id'] !== $leadId)) err('Заявка не найдена');
    if ($id === '') {
        $id = crm_new_id($pdo, 'a_', 'crm_lead_apps');
        if (crm_sync_lead_apps_count($pdo, $leadId) >= CRM_MAX_APPS_PER_LEAD) err('Слишком много заявок в одном лиде');
    }
    // Оптимистическая блокировка: если заявку изменили в другой вкладке — не перезаписывать молча.
    // Раньше updatedAt в заявках не проверялся, и параллельные сохранения затирали друг друга.
    if ($existing && array_key_exists('updatedAt', $in) && (int) $existing['updated_at'] !== intv($in['updatedAt'])) {
        err('Заявка изменена в другом месте');
    }
    try {
        if (!$existing) {
            $pdo->prepare('INSERT INTO crm_lead_apps (id, lead_id, city_from, city_to, rate, margin, vat, carrier_company, carrier_inn, carrier_name, carrier_phone, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $leadId, $from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $now]);
        } else {
            $rev = (int) $existing['updated_at'];
            $updApp = $pdo->prepare('UPDATE crm_lead_apps SET city_from=?, city_to=?, rate=?, margin=?, vat=?, carrier_company=?, carrier_inn=?, carrier_name=?, carrier_phone=?, updated_at=? WHERE id=? AND lead_id=? AND updated_at=?');
            $updApp->execute([$from, $to, $rate, $margin, $vat, $company, $inn, $name, $phone, $now, $id, $leadId, $rev]);
            if ($updApp->rowCount() === 0) err('Заявка изменена в другом месте');
        }
    } catch (PDOException $e) {
        crm_log_fail('save_lead_app', $e);
        err('Не удалось сохранить заявку');
    }
    $n = crm_sync_lead_apps_count($pdo, $leadId);
    $rev = crm_touch_lead($pdo, $leadId);
    $saved = crm_lead_app_by_id($pdo, $id);
    ok([
        'id' => $id,
        'application' => $saved ? crm_lead_app_to_api($saved) : null,
        'applicationsCount' => $n,
        'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($row['inn'] ?? '')),
        'updatedAt' => $rev,
    ]);
}

function crm_action_delete_lead_app(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $app = $id === '' ? null : crm_lead_app_by_id($pdo, $id);
    if (!$app) err('Заявка не найдена');
    $leadId = (string) $app['lead_id'];
    if (!crm_lead_for_user($pdo, $leadId, $viewUid)) err('Лид не найден');
    // Оптимистическая блокировка: если заявку изменили в другой вкладке — предупредить.
    if (array_key_exists('updatedAt', $in) && (int) $app['updated_at'] !== intv($in['updatedAt'])) {
        err('Заявка изменена в другом месте');
    }
    try {
        $pdo->prepare('DELETE FROM crm_lead_apps WHERE id = ? AND lead_id = ?')->execute([$id, $leadId]);
    } catch (PDOException $e) {
        crm_log_fail('delete_lead_app', $e);
        err('Не удалось удалить');
    }
    $n = crm_sync_lead_apps_count($pdo, $leadId);
    $rev = crm_touch_lead($pdo, $leadId);
    $leadRow = crm_lead_for_user($pdo, $leadId, $viewUid);
    ok([
        'applicationsCount' => $n,
        'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($leadRow['inn'] ?? '')),
        'updatedAt' => $rev,
    ]);
}

function crm_action_get_activity(PDO $pdo, array $user, int $viewUid): never {
    $year = intv($_GET['year'] ?? date('Y'));
    if ($year < 2020 || $year > 2099) $year = (int) date('Y');
    ok(['clients' => crm_client_activity($pdo, $viewUid, $year), 'year' => $year]);
}
