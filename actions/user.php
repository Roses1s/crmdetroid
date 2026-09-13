<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Пользователи: список, регистрация, изменение, удаление с передачей лидов, смена пароля, профиль (me).
 * Вынесено из api.php (TODO #15). Каждое действие завершает запрос (ok/err), поэтому never.
 */

function crm_action_me(PDO $pdo, array $user, int $viewUid): never {
    ok(['user' => crm_user_public($user)]);
}

function crm_action_get_users(PDO $pdo, array $user, int $viewUid): never {
    require_admin($user);
    $rows = $pdo->query('SELECT u.id, u.name, u.email, u.role, (SELECT COUNT(*) FROM crm_leads l WHERE l.user_id = u.id) AS leads
        FROM crm_users u ORDER BY u.id ASC')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['leads'] = (int) $r['leads']; }
    unset($r);
    ok(['users' => $rows]);
}

function crm_action_register_user(PDO $pdo, array $user, int $viewUid): never {
    require_admin($user);
    $in = body_json();
    $name = strv($in['name'] ?? '', 80);
    $email = mb_strtolower(strv($in['email'] ?? '', 120));
    $pass = (string) ($in['password'] ?? '');
    if ($name === '' || $email === '' || $pass === '') err('Все поля');
    if (crm_reserved_user_name($name)) err('Это имя зарезервировано');
    $bad = crm_pass_ok($pass);
    if ($bad) err($bad);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
    if (crm_user_by_email($pdo, $email)) err('E-mail уже занят');
    $role = strv($in['role'] ?? 'user', 16);
    if ($role !== 'admin') $role = 'user';
    try {
        $pdo->prepare('INSERT INTO crm_users (name, email, password, role, created_at) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, crm_password_hash($pass), $role, now_ms()]);
    } catch (PDOException $e) {
        // Гонка с параллельной регистрацией: проверка «занят» выше не атомарна с INSERT —
        // нарушение uq_email отдаём тем же сообщением, а не 500 (тот же паттерн, что в save_tag).
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) err('E-mail уже занят');
        throw $e;
    }
    $newId = (int) $pdo->lastInsertId();
    crm_ensure_user_stages($pdo, $newId);
    crm_meta_bump($pdo, 'users');
    crm_audit($pdo, $user, 'user_create', $email, 'id=' . $newId . ' role=' . $role);
    ok(['id' => $newId]);
}

function crm_action_update_user(PDO $pdo, array $user, int $viewUid): never {
    require_admin($user);
    $in = body_json();
    $id = intv($in['id'] ?? 0);
    $name = strv($in['name'] ?? '', 80);
    $email = mb_strtolower(strv($in['email'] ?? '', 120));
    $pass = (string) ($in['password'] ?? '');
    if ($name === '' || $email === '') err('Обязательны Имя и Email');
    if (crm_reserved_user_name($name)) err('Это имя зарезервировано');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
    if ($pass !== '') {
        $bad = crm_pass_ok($pass);
        if ($bad) err($bad);
    }
    $target = crm_user_by_id($pdo, $id);
    if (!$target) err('Сотрудник не найден');
    $other = crm_user_by_email($pdo, $email);
    if ($other && (int) $other['id'] !== $id) err('E-mail уже занят');
    $role = strv($in['role'] ?? ($target['role'] ?? 'user'), 16);
    if ($role !== 'admin') $role = 'user';
    if (($target['role'] ?? '') === 'admin' && $role !== 'admin' && crm_admin_count($pdo) <= 1) {
        err('Нельзя снять роль с последнего администратора');
    }
    if ($id === (int) $user['id'] && ($target['role'] ?? '') === 'admin' && $role !== 'admin') {
        err('Нельзя снять роль с себя');
    }
    // При смене роли инкрементируем token_version: все существующие сессии этого пользователя
    // отвалятся на следующем запросе (crm_pw_fingerprint включает token_version).
    // Раньше снятый админ сохранял привилегии до истечения сессии (до 8 часов).
    $roleChanged = ($target['role'] ?? '') !== $role;
    try {
        if ($roleChanged) {
            $pdo->prepare('UPDATE crm_users SET name = ?, email = ?, role = ?, token_version = token_version + 1 WHERE id = ?')
                ->execute([$name, $email, $role, $id]);
        } else {
            $pdo->prepare('UPDATE crm_users SET name = ?, email = ?, role = ? WHERE id = ?')
                ->execute([$name, $email, $role, $id]);
        }
    } catch (PDOException $e) {
        // Гонка: e-mail заняли между проверкой выше и UPDATE — uq_email, а не 500
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) err('E-mail уже занят');
        throw $e;
    }
    if ($name !== (string) $target['name']) {
        $pdo->prepare('UPDATE crm_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
        $pdo->prepare('UPDATE crm_carrier_comments SET author = ? WHERE user_id = ?')->execute([$name, $id]);
        // «Продавец» на карточках — то же имя; иначе на лидах остаётся старое, а передача по имени ломается
        $pdo->prepare('UPDATE crm_leads SET manager = ? WHERE user_id = ? AND manager = ?')->execute([$name, $id, (string) $target['name']]);
    }
    if ($pass !== '') {
        // Инкрементируем token_version при смене пароля: все существующие сессии этого
        // пользователя инвалидируются. Раньше это работало неявно (хэш пароля входит в
        // crm_pw_fingerprint), но явная инвалидация надёжнее — не зависит от состава fingerprint.
        $pdo->prepare('UPDATE crm_users SET password = ?, token_version = token_version + 1 WHERE id = ?')
            ->execute([crm_password_hash($pass), $id]);
        if ($id === (int) $user['id']) {
            $fresh = crm_user_by_id($pdo, $id);
            if ($fresh) $_SESSION['pw'] = crm_pw_fingerprint($fresh);
        }
    }
    crm_meta_bump($pdo, 'users');
    $audit = [];
    if ($roleChanged) $audit[] = 'role: ' . ($target['role'] ?? '') . '→' . $role;
    if ($pass !== '') $audit[] = 'password changed';
    if ($name !== (string) $target['name']) $audit[] = 'renamed';
    if ($audit) crm_audit($pdo, $user, 'user_update', (string) $target['email'], implode('; ', $audit));
    ok();
}

function crm_action_delete_user(PDO $pdo, array $user, int $viewUid): never {
    require_admin($user);
    $in = body_json();
    $id = intv($in['id'] ?? 0);
    if ($id === (int) $user['id']) err('Нельзя удалить себя');
    $target = crm_user_by_id($pdo, $id);
    if (!$target) err('Сотрудник не найден');
    if (($target['role'] ?? '') === 'admin' && crm_admin_count($pdo) <= 1) err('Нельзя удалить последнего администратора');
    // transferTo: id сотрудника, которому уходят лиды удаляемого; 0/пусто — удалить лиды вместе с логами и файлами
    $transferTo = intv($in['transferTo'] ?? 0);
    if ($transferTo > 0) {
        if ($transferTo === $id) err('Нельзя передать лиды удаляемому сотруднику');
        if (!crm_user_by_id($pdo, $transferTo)) err('Получатель лидов не найден');
    }
    $moved = crm_purge_user($pdo, $id, $transferTo);
    crm_meta_bump($pdo, 'users');
    crm_audit($pdo, $user, 'user_delete', (string) $target['email'], $transferTo > 0 ? "leads→$transferTo ($moved)" : 'leads purged');
    ok(['transferred' => $moved]);
}

function crm_action_change_password(PDO $pdo, array $user, int $viewUid): never {
    $in = body_json();
    $new = (string) ($in['password'] ?? '');
    $old = (string) ($in['old'] ?? '');
    $bad = crm_pass_ok($new);
    if ($bad) err($bad);
    if (empty($_SESSION['must_change'])) {
        if ($old === '' || !password_verify($old, (string) ($user['password'] ?? ''))) err('Неверный пароль');
    }
    // token_version инкрементируется для консистентности с update_user: явная инвалидация
    // сессий не зависит от состава crm_pw_fingerprint (хэш пароля может из него уйти).
    $pdo->prepare('UPDATE crm_users SET password = ?, token_version = token_version + 1 WHERE id = ?')
        ->execute([crm_password_hash($new), (int) $user['id']]);
    unset($_SESSION['must_change']);
    // Своя сессия остаётся; все остальные сессии этого пользователя отвалятся на следующем запросе
    // (id сессии не меняем: параллельный запрос вкладки — опрос доски — со старым id вылетел бы на вход)
    $fresh = crm_user_by_id($pdo, (int) $user['id']);
    if ($fresh) $_SESSION['pw'] = crm_pw_fingerprint($fresh);
    crm_audit($pdo, $user, 'password_change', (string) $user['email']);
    ok();
}
