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
    $st = $pdo->prepare('SELECT id, created_at, updated_at FROM crm_leads WHERE user_id = ? AND deleted_at = 0 ORDER BY created_at ASC');
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
        // build и здесь обязателен: поллинг получает unchanged в 99% тиков, и именно
        // по нему клиент замечает деплой (баннер «Вышло обновление»).
        ok(['unchanged' => true, 'hash' => $hash, 'build' => crm_build(), 'v' => crm_client_v()]);
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
        $chSt = $pdo->prepare('SELECT id, title, inn, phone, logist_phone, manager, applications_count, stage, created_at, updated_at FROM crm_leads WHERE user_id = ? AND deleted_at = 0 AND (updated_at >= ? OR created_at >= ?) ORDER BY created_at ASC');
        $chSt->execute([$uid, $since, $since]);
        $changed = [];
        foreach ($chSt as $r) $changed[] = crm_lead_row_to_api($r, false);
        ok(['hash' => $hash, 'delta' => true, 'ids' => $allIds, 'changed' => $changed, 'stages' => $stages, 'user' => crm_user_public($user), 'colleagues' => crm_colleagues($pdo),
            'tags' => crm_tags_for_user($pdo, $uid), 'leadTags' => (object) crm_lead_tags_map($pdo, $uid), 'build' => crm_build(), 'v' => crm_client_v()]);
    }
    ok(['hash' => $hash, 'stages' => $stages, 'leads' => crm_leads_full($pdo, $uid), 'user' => crm_user_public($user), 'colleagues' => crm_colleagues($pdo),
        'tags' => crm_tags_for_user($pdo, $uid), 'leadTags' => (object) crm_lead_tags_map($pdo, $uid), 'build' => crm_build(), 'v' => crm_client_v()]);
}

function crm_action_get_lead(PDO $pdo, array $user, int $viewUid): never {
    $id = strv($_GET['id'] ?? '', 80);
    $row = $id === '' ? null : crm_lead_for_user($pdo, $id, $viewUid);
    $deleted = null;
    if (!$row) {
        // Удалённый лид открывается любому менеджеру (§35) — только чтение + «Взять в работу».
        $deleted = $id === '' ? null : crm_deleted_lead($pdo, $id);
        if (!$deleted) err('Лид не найден');
        $row = $deleted;
    }
    $lead = crm_lead_row_to_api($row, true);
    if ($deleted && (int) $row['user_id'] !== $viewUid) {
        // Чужая корзина: заявки и статистику не отдаём (карточка показывает лид и лог).
        $lead['applications'] = [];
        $lead['applicationsCount'] = 0;
        $lead['appsStats'] = null;
    } else {
        $lead['applications'] = crm_lead_apps($pdo, $id);
        $lead['applicationsCount'] = count($lead['applications']);
        $lead['appsStats'] = crm_apps_stats($pdo, $viewUid, $id, (string) ($row['inn'] ?? ''));
    }
    if ($deleted) {
        $by = crm_user_by_id($pdo, (int) ($row['deleted_by'] ?? 0));
        $lead['deletedBy'] = (string) ($by['name'] ?? '');
    }
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
    // 20 символов до вырезания цифр: ИНН с пробелами/дефисами иначе резался и браковался (ревью §37, G18)
    $inn = preg_replace('/\D/', '', strv($in['inn'] ?? ($row['inn'] ?? ''), 20)) ?? '';
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
            $upd = $pdo->prepare('UPDATE crm_leads SET title=?,inn=?,phone=?,ati=?,email=?,manager=?,logist_name=?,logist_phone=?,stage=?,updated_at=? WHERE id=? AND user_id=? AND updated_at=? AND deleted_at = 0');
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
            // Возвращает и ревизию, записанную в БД: раньше ответ уходил со вторым now_ms(),
            // отличавшимся от записанного на доли миллисекунды (ревью, п. 2.4).
            $moved = crm_transfer_lead($pdo, $id, $uid, $toId, $ownerName, $stage, $via, $now);
            if ($moved === null) {
                $pdo->rollBack();
                err('Карточка изменена в другом месте');
            }
            [$transferredTo, $now] = $moved;
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
            $stU = $pdo->prepare('UPDATE crm_leads SET stage = ?, updated_at = ? WHERE id = ? AND user_id = ? AND updated_at = ? AND deleted_at = 0');
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

/**
 * Общий реестр «Клиенты» (§34): все лиды всех менеджеров, но БЕЗ контактов —
 * только название, ИНН и владелец. Свои помечаем mine (их можно открыть).
 */
function crm_action_get_clients(PDO $pdo, array $user, int $viewUid): never {
    // Пилюли Все/Мои/Удаленные живут в «Клиентах» (§38, переехали с доски).
    $filter = strv($_GET['filter'] ?? 'all', 10);
    if (!in_array($filter, ['all', 'mine', 'deleted'], true)) $filter = 'all';
    if ($filter === 'deleted') {
        // Корзина в реестре: свежак первым, владелец + кто удалил. mine=false —
        // взять в работу может любой (клик открывает карточку с кнопкой).
        $total = (int) $pdo->query('SELECT COUNT(*) FROM crm_leads l LEFT JOIN crm_users u ON u.id = l.user_id WHERE l.deleted_at <> 0')->fetchColumn();
        $st = $pdo->query('SELECT l.id, l.title, l.inn, u.name AS owner, d.name AS deleted_by FROM crm_leads l LEFT JOIN crm_users u ON u.id = l.user_id LEFT JOIN crm_users d ON d.id = l.deleted_by WHERE l.deleted_at <> 0 ORDER BY l.deleted_at DESC LIMIT 500');
        $out = [];
        foreach ($st as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'title' => (string) $r['title'],
                'inn' => (string) $r['inn'],
                'owner' => (string) ($r['owner'] ?? ''),
                'mine' => false,
                'deleted' => true,
                'deletedBy' => (string) ($r['deleted_by'] ?? ''),
            ];
        }
        ok(['clients' => $out, 'total' => $total]);
    }
    $mineOnly = $filter === 'mine';
    $where = $mineOnly ? 'l.deleted_at = 0 AND l.user_id = ?' : 'l.deleted_at = 0';
    $tot = $pdo->prepare("SELECT COUNT(*) FROM crm_leads l INNER JOIN crm_users u ON u.id = l.user_id WHERE $where");
    $tot->execute($mineOnly ? [$viewUid] : []);
    $total = (int) $tot->fetchColumn();
    $st = $pdo->prepare("SELECT l.id, l.title, l.inn, u.name AS owner, (l.user_id = ?) AS mine FROM crm_leads l INNER JOIN crm_users u ON u.id = l.user_id WHERE $where ORDER BY l.title ASC LIMIT 500");
    $st->execute($mineOnly ? [$viewUid, $viewUid] : [$viewUid]);
    $out = [];
    foreach ($st as $r) {
        $out[] = [
            'id' => (string) $r['id'],
            'title' => (string) $r['title'],
            'inn' => (string) $r['inn'],
            'owner' => (string) $r['owner'],
            'mine' => (int) $r['mine'] === 1,
        ];
    }
    ok(['clients' => $out, 'total' => $total]);
}

function crm_action_delete_lead(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $uid = $viewUid;
    $row = crm_lead_for_user($pdo, $id, $uid);
    if (!$row) {
        // Идемпотентность: свой уже удалённый — ok (двойной клик, повтор).
        $del = $id === '' ? null : crm_deleted_lead($pdo, $id);
        if ($del && (int) $del['user_id'] === $uid) ok();
        err('Лид не найден');
    }
    // Оптимистическая блокировка: если лид изменили в другой вкладке — предупредить, а не удалять молча.
    if (array_key_exists('updatedAt', $in) && (int) $row['updated_at'] !== intv($in['updatedAt'])) {
        err('Карточка изменена в другом месте');
    }
    // Мягкое удаление (§35): лид уходит в корзину, заявки/лог/файлы целы.
    $now = now_ms();
    $pdo->prepare('UPDATE crm_leads SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ? AND deleted_at = 0')->execute([$now, $uid, $now, $id]);
    crm_audit($pdo, $user, 'lead_delete', $id, (string) ($row['title'] ?? ''));
    ok();
}

/**
 * «Взять в работу» (§35): любой менеджер забирает удалённый лид себе.
 * Этап — «Новый», если есть у берущего, иначе его первый этап.
 * WHERE deleted_at <> 0 + rowCount — защита от двойного взятия.
 */
function crm_action_restore_lead(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $uid = (int) ($user['id'] ?? 0);
    $selfName = strv((string) ($user['name'] ?? ''), 80);
    $row = $id === '' ? null : crm_deleted_lead($pdo, $id);
    if (!$row) {
        if ($id !== '' && crm_lead_for_user($pdo, $id, $viewUid)) err('Лид не удалён');
        err('Лид не найден');
    }
    $stages = crm_stages($pdo, $uid);
    $stage = in_array('Новый', $stages, true) ? 'Новый' : ($stages[0] ?? 'Новый');
    $now = now_ms();
    $upd = $pdo->prepare('UPDATE crm_leads SET user_id = ?, stage = ?, manager = ?, deleted_at = 0, deleted_by = 0, updated_at = ? WHERE id = ? AND deleted_at <> 0');
    $upd->execute([$uid, $stage, $selfName, $now, $id]);
    if ($upd->rowCount() === 0) err('Лид уже забрали');
    crm_sys_comment($pdo, $id, 'Лид взят в работу: ' . $selfName);
    crm_audit($pdo, $user, 'lead_restore', $id, (string) ($row['title'] ?? ''));
    ok(['id' => $id, 'stage' => $stage]);
}

/**
 * Стирание из корзины (§35): только админ, только уже удалённого.
 * Активный лид сначала должен уйти в корзину обычным удалением.
 */
function crm_action_purge_lead(PDO $pdo, array $user, int $viewUid): never {
    require_admin($user);
    $in = body_json();
    $id = strv($in['id'] ?? '', 80);
    $row = $id === '' ? null : crm_deleted_lead($pdo, $id);
    if (!$row) err('Лид не найден в удалённых');
    crm_purge_lead($pdo, $id);
    crm_audit($pdo, $user, 'lead_purge', $id, (string) ($row['title'] ?? ''));
    ok();
}

function crm_action_save_lead_app(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $leadId = strv($in['leadId'] ?? '', 80);
    $row = $leadId === '' ? null : crm_lead_for_user($pdo, $leadId, $viewUid);
    if (!$row) err('Лид не найден');
    // Валидация общая со страницей заявки (save_app); при ошибке бросает CrmError
    // с тем же текстом, что был здесь через err() — ответы не меняются.
    $f = crm_validate_app_fields($in);
    $number = $f['number'];
    $from = $f['cityFrom'];
    $to = $f['cityTo'];
    $rate = $f['rate'];
    $margin = $f['margin'];
    $vat = $f['vat'];
    $carrierRate = $f['carrierRate'];
    $carrierVat = $f['carrierVat'];
    $company = $f['carrierCompany'];
    $inn = $f['carrierInn'];
    $name = $f['carrierName'];
    $phone = $f['carrierPhone'];
    $loadAddress = $f['loadAddress'];
    $loadContact = $f['loadContact'];
    $loadDateFrom = $f['loadDateFrom'];
    $loadDateTo = $f['loadDateTo'];
    $loadTime = $f['loadTime'];
    $unloadAddress = $f['unloadAddress'];
    $unloadContact = $f['unloadContact'];
    $unloadDateFrom = $f['unloadDateFrom'];
    $unloadDateTo = $f['unloadDateTo'];
    $unloadTime = $f['unloadTime'];
    $id = strv($in['id'] ?? '', 80);
    $now = now_ms();
    $existing = $id !== '' ? crm_lead_app_by_id($pdo, $id) : null;
    if ($id !== '' && (!$existing || (string) $existing['lead_id'] !== $leadId)) err('Заявка не найдена');
    if ($id === '') $id = crm_new_id($pdo, 'a_', 'crm_lead_apps');
    // Оптимистическая блокировка: если заявку изменили в другой вкладке — не перезаписывать молча.
    // Раньше updatedAt в заявках не проверялся, и параллельные сохранения затирали друг друга.
    if ($existing && array_key_exists('updatedAt', $in) && (int) $existing['updated_at'] !== intv($in['updatedAt'])) {
        err('Заявка изменена в другом месте');
    }
    $pdo->beginTransaction();
    try {
        if (!$existing) {
            // Строку лида блокируем до проверки лимита: два параллельных создания при
            // 199 заявках иначе дали бы 201 — проверка и INSERT расходились бы (ревью, п. 3.1).
            $pdo->prepare('SELECT id FROM crm_leads WHERE id = ? FOR UPDATE')->execute([$leadId]);
            if (crm_sync_lead_apps_count($pdo, $leadId) >= CRM_MAX_APPS_PER_LEAD) {
                $pdo->rollBack();
                err('Слишком много заявок в одном лиде');
            }
            $pdo->prepare('INSERT INTO crm_lead_apps (id, lead_id, `number`, city_from, city_to, rate, margin, vat, carrier_rate, carrier_vat, carrier_company, carrier_inn, carrier_name, carrier_phone, load_address, load_contact, load_date_from, load_date_to, load_time, unload_address, unload_contact, unload_date_from, unload_date_to, unload_time, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $leadId, $number, $from, $to, $rate, $margin, $vat, $carrierRate, $carrierVat, $company, $inn, $name, $phone, $loadAddress, $loadContact, $loadDateFrom, $loadDateTo, $loadTime, $unloadAddress, $unloadContact, $unloadDateFrom, $unloadDateTo, $unloadTime, $now, $now]);
            crm_sys_comment($pdo, $id, 'Заявка создана', 'crm_app_comments', 'app_id', 'ac_');
            // Маршрут новой заявки дублируется в справочник направлений (только создание:
            // правка маршрута существующей заявки справочник не трогает).
            [$dirId] = crm_ensure_direction($pdo, $from, $to, (int) $user['id']);
            // Перевозчик новой заявки — туда же, на это направление (компания, ИНН,
            // контакты; пустой — пропускаем, дубль — нет).
            crm_ensure_carrier($pdo, $dirId, $f, (int) $user['id']);
        } else {
            $rev = (int) $existing['updated_at'];
            $updApp = $pdo->prepare('UPDATE crm_lead_apps SET `number`=?, city_from=?, city_to=?, rate=?, margin=?, vat=?, carrier_rate=?, carrier_vat=?, carrier_company=?, carrier_inn=?, carrier_name=?, carrier_phone=?, load_address=?, load_contact=?, load_date_from=?, load_date_to=?, load_time=?, unload_address=?, unload_contact=?, unload_date_from=?, unload_date_to=?, unload_time=?, updated_at=? WHERE id=? AND lead_id=? AND updated_at=?');
            $updApp->execute([$number, $from, $to, $rate, $margin, $vat, $carrierRate, $carrierVat, $company, $inn, $name, $phone, $loadAddress, $loadContact, $loadDateFrom, $loadDateTo, $loadTime, $unloadAddress, $unloadContact, $unloadDateFrom, $unloadDateTo, $unloadTime, $now, $id, $leadId, $rev]);
            if ($updApp->rowCount() === 0) {
                $pdo->rollBack();
                err('Заявка изменена в другом месте');
            }
            crm_app_sys_field_changes($pdo, $id, $existing, $f);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    // v20: вместе с заявкой уходит её лог (комментарии + строки вложений);
    // файлы стираются с диска после коммита — как в crm_apply_comment_delete.
    $cidsSt = $pdo->prepare('SELECT id FROM crm_app_comments WHERE app_id = ?');
    $cidsSt->execute([$id]);
    $cids = $cidsSt->fetchAll(PDO::FETCH_COLUMN);
    $urls = crm_att_urls($pdo, 'crm_app_attachments', $cids);
    $pdo->beginTransaction();
    try {
        crm_delete_att_rows($pdo, 'crm_app_attachments', $cids);
        $pdo->prepare('DELETE FROM crm_app_comments WHERE app_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM crm_lead_apps WHERE id = ? AND lead_id = ?')->execute([$id, $leadId]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        crm_log_fail('delete_lead_app', $e);
        err('Не удалось удалить');
    }
    crm_unlink_urls($urls);
    $n = crm_sync_lead_apps_count($pdo, $leadId);
    $rev = crm_touch_lead($pdo, $leadId);
    $leadRow = crm_lead_for_user($pdo, $leadId, $viewUid);
    ok([
        'applicationsCount' => $n,
        'appsStats' => crm_apps_stats($pdo, $viewUid, $leadId, (string) ($leadRow['inn'] ?? '')),
        'updatedAt' => $rev,
    ]);
}

/**
 * Реестр заявок менеджера (вкладка «Заявки» на дашборде): все заявки всех его лидов,
 * новые сверху. Итоги (кол-во, суммы ставок и маржи) считаются отдельным запросом
 * по ПОЛНОЙ выборке — список ограничен LIMIT 500, и суммирование по нему занижало бы итог.
 * Просмотр чужих заявок — через ?as= (только админ, проверяет crm_view_uid).
 */
function crm_action_get_apps(PDO $pdo, array $user, int $viewUid): never {
    $q = strv($_GET['q'] ?? '', 80);
    $where = 'l.user_id = ? AND l.deleted_at = 0';
    $params = [$viewUid];
    if ($q !== '') {
        $like = crm_like_pat($q);
        $where .= ' AND (l.title LIKE ? OR l.inn LIKE ? OR a.city_from LIKE ? OR a.city_to LIKE ? OR a.carrier_company LIKE ? OR a.carrier_inn LIKE ? OR a.`number` LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    try {
        $tot = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(a.rate), 0) AS r, COALESCE(SUM(a.margin), 0) AS m FROM crm_lead_apps a JOIN crm_leads l ON l.id = a.lead_id JOIN crm_users u ON u.id = l.user_id WHERE $where");
        $tot->execute($params);
        $t = $tot->fetch() ?: ['c' => 0, 'r' => 0, 'm' => 0];
        $st = $pdo->prepare("SELECT a.*, l.title AS lead_title, l.inn AS lead_inn, u.name AS seller_name FROM crm_lead_apps a JOIN crm_leads l ON l.id = a.lead_id JOIN crm_users u ON u.id = l.user_id WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT 500");
        $st->execute($params);
    } catch (PDOException $e) {
        crm_log_fail('get_apps', $e);
        err('Не удалось загрузить заявки');
    }
    $apps = [];
    foreach ($st as $r) {
        $row = crm_lead_app_to_api($r);
        $row['leadTitle'] = $r['lead_title'];
        $row['leadInn'] = $r['lead_inn'];
        $row['sellerName'] = $r['seller_name'];
        $apps[] = $row;
    }
    ok([
        'apps' => $apps,
        'total' => (int) $t['c'],
        'sumRate' => crm_money_out($t['r']),
        'sumMargin' => crm_money_out($t['m']),
    ]);
}

function crm_action_get_activity(PDO $pdo, array $user, int $viewUid): never {
    $year = intv($_GET['year'] ?? date('Y'));
    if ($year < 2020 || $year > 2099) $year = (int) date('Y');
    ok(['clients' => crm_client_activity($pdo, $viewUid, $year), 'year' => $year]);
}
