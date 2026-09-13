<?php
declare(strict_types=1);

/*
 * HTTP-примитивы CRM: JSON-ответы, коды ошибок, время, лог сбоев (TODO #20 — выполнено).
 * Раньше жили в db.php, из-за чего слой данных был непригоден без HTTP-контекста.
 * Подключается из db.php (require_once), поэтому порядок подключений в api.php и tests/
 * не меняется. В CLI header()/http_response_code() безвредны (PHP их игнорирует).
 */

/**
 * Доменная ошибка: сообщение безопасно показывать пользователю (в отличие от системных
 * исключений, которые глобальный catch в api.php прячет за «Ошибка сервера»).
 * Бросается из слоя данных (crm_pdo, crm_view_uid) вместо прямого err() — db.php больше
 * не завершает HTTP-запрос сам; api.php ловит CrmError и отвечает через err(), поэтому
 * маппинг сообщений на HTTP-статусы (crm_err_status) продолжает работать как раньше.
 */
class CrmError extends RuntimeException {
    public bool $needLogin;

    public function __construct(string $message, bool $needLogin = false) {
        parent::__construct($message);
        $this->needLogin = $needLogin;
    }
}

/** Отправить JSON и завершить запрос. */
function out(array $data): never {
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE — страховка от битого UTF-8, уже лежащего в БД (старые данные,
    // прямые правки в MySQL): без флага json_encode возвращал false, и клиент получал пустое тело.
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ok(array $extra = []): never { out(['success' => true] + $extra); }
/**
 * HTTP-статус для ошибки. Раньше все ошибки уходили с кодом 200 — в логах хостинга/прокси
 * и мониторинге всё выглядело успехом. Клиент на статус не завязан (парсит JSON при любом коде),
 * поэтому смена кодов обратно-совместима. Явный $status в err() имеет приоритет; иначе —
 * маппинг по типовым сообщениям (это дешевле, чем править все вызовы).
 */
function crm_err_status(string $message, bool $needLogin): int {
    if ($needLogin) return 401;
    if ($message === 'Нет прав' || $message === 'CSRF') return 403;
    if (str_contains($message, 'не найден')) return 404; // «не найден/не найдена/не найдено»
    if (str_starts_with($message, 'Метод не поддерживается')) return 405;
    if (str_contains($message, 'в другом месте')) return 409; // оптимистическая блокировка
    if (str_starts_with($message, 'Слишком много')) return 429;
    // Ошибки конфигурации/подключения к БД — проблема сервера, не клиента
    if (str_contains($message, 'config.php') || str_contains($message, 'pdo_mysql')
        || str_contains($message, 'подключиться к базе')) return 500;
    return 400;
}
function err(string $message, bool $needLogin = false, int $status = 0): never {
    if ($status <= 0) $status = crm_err_status($message, $needLogin);
    if (!headers_sent()) http_response_code($status);
    $r = ['success' => false, 'error' => $message];
    if ($needLogin) $r['need_login'] = true;
    out($r);
}
function now_ms(): int { return (int) round(microtime(true) * 1000); }

/** Записать причину сбоя в лог сервера перед тем, как отдать пользователю общую фразу. */
function crm_log_fail(string $where, Throwable $e): void {
    error_log(sprintf('CRM %s: %s: %s in %s:%d', $where, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
}
