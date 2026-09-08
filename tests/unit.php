<?php
/**
 * Юнит-тесты чистых функций db.php (без БД и HTTP).
 * Запуск: php tests/unit.php  (код выхода 1 при провале).
 * Покрывает самую рискованную логику: парсер денег (миграция v10 уже ловила
 * «12 500,50 → 1250050»), IP/CIDR, переименование этапов, имена файлов, HTTP-статусы.
 */
declare(strict_types=1);

// Минимальные константы, которые db.php использует внутри функций
if (!defined('CRM_UPLOAD_DIR')) define('CRM_UPLOAD_DIR', sys_get_temp_dir());
if (!defined('CRM_TRUSTED_PROXIES')) define('CRM_TRUSTED_PROXIES', '');

require __DIR__ . '/../db.php';

$fails = 0;
function t(string $name, $got, $want): void {
    global $fails;
    if ($got === $want) {
        echo "PASS | $name\n";
    } else {
        $fails++;
        echo 'FAIL | ' . $name . ' | got=' . var_export($got, true) . ' want=' . var_export($want, true) . "\n";
    }
}

// --- crm_parse_money: строгий парсер сумм -----------------------------------
t('money: 45000', crm_parse_money('45000'), '45000');
t('money: 45 000 (пробел)', crm_parse_money('45 000'), '45000');
t('money: 45 000 (nbsp)', crm_parse_money("45\xC2\xA0000"), '45000');
t('money: 12 500,50 → 12500.50', crm_parse_money('12 500,50'), '12500.50');
t('money: 17608.65', crm_parse_money('17608.65'), '17608.65');
t('money: 1234,5 → 1234.50 (добивка копеек)', crm_parse_money('1234,5'), '1234.50');
t('money: хвостовая точка обрезается', crm_parse_money('500.'), '500');
t('money: пусто → пустая строка', crm_parse_money(''), '');
t('money: abc → null', crm_parse_money('abc'), null);
t('money: диапазон 12000-15000 → null', crm_parse_money('12000-15000'), null);
t('money: 1e5 → null', crm_parse_money('1e5'), null);
t('money: три знака после запятой → null', crm_parse_money('1,234'), null);
t('money: 13 цифр → null (лимит 12)', crm_parse_money('1234567890123'), null);
t('money: отрицательное → null', crm_parse_money('-100'), null);

// --- crm_legacy_money: миграционный парсер (валютные подписи) ---------------
t('legacy: 45000 руб.', crm_legacy_money('45000 руб.'), '45000');
t('legacy: 12 500 ₽', crm_legacy_money('12 500 ₽'), '12500');
t('legacy: 45000 rub', crm_legacy_money('45000 rub'), '45000');
t('legacy: от 40 000 → null', crm_legacy_money('от 40 000'), null);
t('legacy: 40-45 тыс → null', crm_legacy_money('40-45 тыс'), null);
t('legacy: пусто → null', crm_legacy_money(''), null);

// --- crm_money_out: DECIMAL из БД → строка API ------------------------------
t('out: 45000.00 → 45000', crm_money_out('45000.00'), '45000');
t('out: 1234.50 → 1234.50', crm_money_out('1234.50'), '1234.50');
t('out: 1234.5 → 1234.50', crm_money_out('1234.5'), '1234.50');
t('out: null → пусто', crm_money_out(null), '');
t('out: 0.00 → 0', crm_money_out('0.00'), '0');

// --- crm_ip_in_list: IP/CIDR ------------------------------------------------
t('ip: точный IPv4', crm_ip_in_list('10.0.0.5', ['10.0.0.5']), true);
t('ip: не в списке', crm_ip_in_list('10.0.0.6', ['10.0.0.5']), false);
t('ip: CIDR /8', crm_ip_in_list('10.255.1.2', ['10.0.0.0/8']), true);
t('ip: CIDR /8 мимо', crm_ip_in_list('11.0.0.1', ['10.0.0.0/8']), false);
t('ip: CIDR /31 граница', crm_ip_in_list('192.168.1.1', ['192.168.1.0/31']), true);
t('ip: CIDR /31 за границей', crm_ip_in_list('192.168.1.2', ['192.168.1.0/31']), false);
t('ip: loopback-алиас', crm_ip_in_list('127.0.0.1', ['loopback']), true);
t('ip: loopback 127.x', crm_ip_in_list('127.1.2.3', ['loopback']), true);
t('ip: IPv6 точный', crm_ip_in_list('::1', ['::1']), true);
t('ip: IPv6 CIDR', crm_ip_in_list('fd00::42', ['fd00::/8']), true);
t('ip: v4 не матчится на v6-запись', crm_ip_in_list('10.0.0.1', ['fd00::/8']), false);
t('ip: битый IP', crm_ip_in_list('не-ip', ['10.0.0.0/8']), false);
t('ip: маска вне диапазона игнорируется', crm_ip_in_list('10.0.0.1', ['10.0.0.0/99']), false);

// --- crm_stage_renames: определение переименования этапа --------------------
t('stages: одно переименование', crm_stage_renames(['А', 'Б'], ['А', 'В']), [['Б', 'В']]);
t('stages: перестановка — не переименование', crm_stage_renames(['А', 'Б'], ['Б', 'А']), []);
t('stages: добавление — не переименование', crm_stage_renames(['А'], ['А', 'Б']), []);
t('stages: удаление — не переименование', crm_stage_renames(['А', 'Б'], ['А']), []);
t('stages: два изменения — не угадываем', crm_stage_renames(['А', 'Б'], ['В', 'Г']), []);

// --- crm_upload_name: разбор ссылки на файл ---------------------------------
t('upload: hex-имя', crm_upload_name('uploads/0123456789abcdef.png'), '0123456789abcdef.png');
t('upload: верхний регистр нормализуется', crm_upload_name('uploads/ABCDEF0123456789.PNG'), 'abcdef0123456789.png');
t('upload: api-ссылка', crm_upload_name('api.php?action=file&f=0123456789abcdef.pdf'), '0123456789abcdef.pdf');
t('upload: traversal отбрасывается', crm_upload_name('uploads/../config.php'), null);
t('upload: чужой формат имени', crm_upload_name('uploads/evil.php'), null);
t('upload: пусто', crm_upload_name(''), null);

// --- crm_short_filename ------------------------------------------------------
t('short: короткое не трогаем', crm_short_filename('файл.pdf', 200), 'файл.pdf');
t('short: расширение сохраняется', crm_short_filename(str_repeat('ф', 300) . '.pdf', 20), str_repeat('ф', 16) . '.pdf');
t('short: пусто → file', crm_short_filename('', 200), 'file');
t('short: NUL и переводы строк чистятся', crm_short_filename("a\r\nb\0c.txt", 200), 'abc.txt');

// --- crm_like_pat: экранирование LIKE ----------------------------------------
t('like: проценты экранируются', crm_like_pat('50%'), '%50\%%');
t('like: подчёркивание экранируется', crm_like_pat('a_b'), '%a\_b%');
t('like: бэкслэш экранируется', crm_like_pat('a\\b'), '%a\\\\b%');

// --- crm_err_status: маппинг ошибок на HTTP-коды ------------------------------
t('status: need_login → 401', crm_err_status('Сессия истекла', true), 401);
t('status: Нет прав → 403', crm_err_status('Нет прав', false), 403);
t('status: CSRF → 403', crm_err_status('CSRF', false), 403);
t('status: не найден → 404', crm_err_status('Лид не найден', false), 404);
t('status: метод → 405', crm_err_status('Метод не поддерживается: нужен POST', false), 405);
t('status: конфликт версий → 409', crm_err_status('Карточка изменена в другом месте', false), 409);
t('status: лимит → 429', crm_err_status('Слишком много попыток. Подождите 15 минут', false), 429);
t('status: валидация → 400', crm_err_status('ИНН 10 или 12 цифр', false), 400);

// --- crm_name_key / crm_reserved_user_name ------------------------------------
t('reserved: Система', crm_reserved_user_name('  СИСТЕМА '), true);
t('reserved: system', crm_reserved_user_name('System'), true);
t('reserved: обычное имя', crm_reserved_user_name('Иван'), false);

// --- crm_dummy_hash: защита от перечисления по таймингу --------------------------
t('dummy: тот же алгоритм, что боевые хэши', password_needs_rehash(crm_dummy_hash(), crm_password_algo()), false);
t('dummy: стабилен в рамках процесса', crm_dummy_hash() === crm_dummy_hash(), true);
t('dummy: выглядит валидным', crm_hash_looks_valid(crm_dummy_hash()), true);

// --- crm_norm_city: нормализация пробелов + устойчивость к битому UTF-8 ----------
t('city: схлопывание пробелов', crm_norm_city("  Санкт -  Петербург  "), 'Санкт - Петербург');
t('city: обычный город', crm_norm_city('Москва'), 'Москва');
// preg_replace с /u на невалидном UTF-8 возвращает null — раньше trim(null) кидал TypeError (500)
t('city: битый UTF-8 не роняет запрос', crm_norm_city("\xFF\xFEbad"), "\xFF\xFEbad");

// --- CrmError (TODO #20): доменная ошибка слоя данных ----------------------------
t('CrmError: сообщение сохраняется', (new CrmError('Нет прав'))->getMessage(), 'Нет прав');
t('CrmError: needLogin по умолчанию false', (new CrmError('x'))->needLogin, false);
t('CrmError: needLogin=true переносится', (new CrmError('Сессия истекла', true))->needLogin, true);
t('CrmError: это RuntimeException (ловится общим catch)', new CrmError('x') instanceof RuntimeException, true);

// --- crm_is_sys_comment --------------------------------------------------------
t('sys: user_id=0 + Система', crm_is_sys_comment(['user_id' => 0, 'author' => 'Система']), true);
t('sys: user_id=0 но не Система', crm_is_sys_comment(['user_id' => 0, 'author' => 'Иван']), false);
t('sys: Система но user_id>0', crm_is_sys_comment(['user_id' => 5, 'author' => 'Система']), false);

echo "\n" . ($fails === 0 ? 'ALL PASSED' : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
