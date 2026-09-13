<?php
declare(strict_types=1);
defined('CRM_API') || exit; // только через api.php

/*
 * Действия до общего middleware ($user/$viewUid ещё нет): выдача login-CSRF, вход,
 * выход, проверка сессии, отдача файла вложения. Вынесено из api.php (TODO #15).
 * Сигнатура отличается от остальных actions: crm_action_xxx(PDO $pdo, bool $hasSess): never —
 * вызываются из api.php до require_user(), как раньше ранние if-блоки.
 * Каждое действие завершает запрос (ok/err/exit), поэтому never.
 */

function crm_action_csrf(PDO $pdo, bool $hasSess): never {
    // Один токен = одна попытка входа. Лимит с запасом на офисный NAT (несколько человек за одним IP);
    // сам вход защищён отдельно: 8 попыток на (email, IP) и 80 на IP за 15 минут.
    if (crm_anon_throttled($pdo, crm_client_ip(), '#csrf', 120, 15 * 60 * 1000)) {
        err('Слишком много запросов. Подождите минуту');
    }
    ok(['csrf' => crm_login_csrf_issue($pdo)]);
}

function crm_action_file(PDO $pdo, bool $hasSess): never {
    $id = (int) ($_SESSION['user_id'] ?? 0);
    // Пользователь выбирается один раз (раньше crm_user_by_id вызывался дважды — до и после throttle)
    $uFile = ($hasSess && $id) ? crm_user_by_id($pdo, $id) : null;
    if (!$uFile) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Need login';
        exit;
    }
    crm_session_touch();
    if (crm_session_throttled(600, 60, 'file')) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many requests';
        exit;
    }
    if (($_SESSION['pw'] ?? '') !== crm_pw_fingerprint($uFile)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Need login';
        exit;
    }
    // Сессия больше не нужна — отпускаем блокировку, пока читаем файл с диска
    session_write_close();
    crm_serve_file($pdo, (string) (is_string($_GET['f'] ?? null) ? $_GET['f'] : ''), $uFile);
}

function crm_action_check_auth(PDO $pdo, bool $hasSess): never {
    if (!$hasSess) err('Сессия истекла', true);
    $u = require_user($pdo);
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u), 'mustChangePassword' => !empty($_SESSION['must_change'])]);
}

function crm_action_login(PDO $pdo, bool $hasSess): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Раньше и неверный метод, и неверный Content-Type отвечали «CSRF» — дезориентировало при отладке
    if ($method !== 'POST') err('Метод не поддерживается: нужен POST');
    if (!crm_want_json()) err('Ожидается Content-Type: application/json');
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !crm_login_csrf_ok($pdo, $sent)) err('CSRF');
    $in = body_json();
    $email = mb_strtolower(strv($in['email'] ?? '', 120));
    $password = (string) ($in['password'] ?? '');
    $ip = crm_client_ip();
    if ($email === '' || $password === '') err('Заполните поля');
    if (crm_login_throttled($pdo, $email, $ip)) err('Слишком много попыток. Подождите 15 минут');
    $u = crm_user_by_email($pdo, $email);
    // Dummy тем же алгоритмом, что боевые хэши (crm_dummy_hash): bcrypt-константа после
    // миграции на Argon2id снова выдавала бы существование e-mail по времени ответа.
    $dummy = crm_dummy_hash();
    $hash = is_array($u) ? (string) ($u['password'] ?? $dummy) : $dummy;
    if ($hash === '' || !crm_hash_looks_valid($hash)) $hash = $dummy;
    $okPass = password_verify($password, $hash);
    if (!$u || !$okPass) {
        crm_login_fail($pdo, $email, $ip);
        err('Неверный e-mail или пароль');
    }
    // Плавная миграция хэшей на актуальный алгоритм (Argon2id при наличии): пароль
    // сейчас в открытом виде — единственный момент, когда можно перехэшировать.
    // Побочный эффект (одноразовый, при первом входе после обновления): отпечаток в
    // crm_pw_fingerprint включает хэш, поэтому другие открытые сессии этого пользователя
    // попросят войти заново — как при смене пароля. $u обновляем, чтобы новая сессия
    // получила отпечаток от нового хэша.
    if (password_needs_rehash($hash, crm_password_algo())) {
        try {
            $newHash = crm_password_hash($password);
            $pdo->prepare('UPDATE crm_users SET password = ? WHERE id = ?')->execute([$newHash, (int) $u['id']]);
            $u['password'] = $newHash;
        } catch (Throwable $e) { crm_log_fail('rehash', $e); }
    }
    crm_login_ok($pdo, $email, $ip);
    crm_audit($pdo, $u, 'login', $email);
    crm_session_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['pw'] = crm_pw_fingerprint($u);
    $_SESSION['last'] = time();
    $_SESSION['born'] = time();
    unset($_SESSION['csrf']);
    if (defined('CRM_DEFAULT_ADMIN_PASS') && $password === CRM_DEFAULT_ADMIN_PASS) {
        $_SESSION['must_change'] = 1;
    } else {
        unset($_SESSION['must_change']);
    }
    ok(['csrf' => csrf_token(), 'user' => crm_user_public($u), 'mustChangePassword' => !empty($_SESSION['must_change'])]);
}

function crm_action_logout(PDO $pdo, bool $hasSess): never {
    if (!$hasSess) ok();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Как в login: неверный метод — не «CSRF» (ревью, п. 12.3)
    if ($method !== 'POST') err('Метод не поддерживается: нужен POST');
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = (string) ($_SESSION['csrf'] ?? '');
    // Строгая проверка: раньше пустой $_SESSION['csrf'] пропускал запрос без токена —
    // сторонний сайт мог насильно разлогинить пользователя (logout-CSRF). Токена нет
    // только у неавторизованной сессии, а такой logout и так отвечает ok() выше по $hasSess;
    // для авторизованной сессии токен обязан быть.
    if ((int) ($_SESSION['user_id'] ?? 0) > 0
        && ($have === '' || $sent === '' || strlen($sent) !== strlen($have) || !hash_equals($have, $sent))) err('CSRF');
    // Выход — в аудит (входы уже пишутся; ревью, п. 12.6). До очистки сессии, пока известен user_id.
    $outUid = (int) ($_SESSION['user_id'] ?? 0);
    if ($outUid > 0) {
        $outUser = crm_user_by_id($pdo, $outUid);
        if ($outUser) crm_audit($pdo, $outUser, 'logout', (string) $outUser['email']);
    }
    // Regenerate ID перед уничтожением: старый session ID больше не действителен,
    // и даже если файл сессии ещё не удалён сборщиком мусора — предъявить его нельзя.
    session_regenerate_id(true);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    ok();
}
