#!/usr/bin/env bash
# Готовит стенд для tests/e2e-jsdom.mjs: ждёт php -S, снимает must_change у админа
# (пароль из конфига → ADMIN_NEW_PASS), создаёт сотрудников ivan@x.ru / petr@x.ru
# и стартовый лид ivan'а (тест 16 ожидает у него непустую доску).
#
# Переменные окружения:
#   CRM_URL         базовый URL стенда (по умолчанию http://127.0.0.1:8089)
#   ADMIN_EMAIL     e-mail админа из конфига
#   ADMIN_PASS      текущий пароль админа (из конфига)
#   ADMIN_NEW_PASS  новый пароль админа (им же потом входит tests/e2e-jsdom.mjs)
#
# Использование (стенд уже запущен: php -S 127.0.0.1:8089 -t .):
#   CRM_URL=... ADMIN_EMAIL=... ADMIN_PASS=... ADMIN_NEW_PASS=... bash tests/e2e-setup.sh
set -euo pipefail

B="${CRM_URL:-http://127.0.0.1:8089}/api.php"
ADMIN_EMAIL="${ADMIN_EMAIL:?Укажите ADMIN_EMAIL}"
ADMIN_PASS="${ADMIN_PASS:?Укажите ADMIN_PASS}"
ADMIN_NEW_PASS="${ADMIN_NEW_PASS:?Укажите ADMIN_NEW_PASS}"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

csrf_of() { curl -s "$B?action=csrf" | sed 's/.*"csrf":"\([^"]*\)".*/\1/'; }
login() { # login <jar> <email> <pass> → печатает session csrf
  local T; T=$(csrf_of)
  curl -s -c "$1" -H "X-CSRF-Token: $T" -H 'Content-Type: application/json' \
    -d "{\"email\":\"$2\",\"password\":\"$3\"}" "$B?action=login" | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p'
}

# 1. ждём сервер (до 30 с), иначе дальше всё равно всё упадёт
for i in $(seq 1 30); do
  if curl -sf "$B?action=csrf" >/dev/null; then break; fi
  [ "$i" = 30 ] && { echo "стенд не отвечает: $B" >&2; exit 1; }
  sleep 1
done

# 2. вход конфиг-паролем и смена: снимает must_change, иначе e2e упрётся в «Смените временный пароль»
JA="$TMP/ja"
TA=$(login "$JA" "$ADMIN_EMAIL" "$ADMIN_PASS")
[ -n "$TA" ] || { echo "не удалось войти админом ($ADMIN_EMAIL)" >&2; exit 1; }
curl -s -b "$JA" -H "X-CSRF-Token: $TA" -H 'Content-Type: application/json' \
  -d "{\"old\":\"$ADMIN_PASS\",\"password\":\"$ADMIN_NEW_PASS\"}" "$B?action=change_password" >/dev/null
TA=$(login "$JA" "$ADMIN_EMAIL" "$ADMIN_NEW_PASS")
[ -n "$TA" ] || { echo "не удалось войти новым паролем" >&2; exit 1; }

# 3. сотрудники, которых ожидает e2e (повторный запуск: «E-mail уже занят» — не ошибка)
for u in '{"name":"Иван Тестов","email":"ivan@x.ru","password":"IvanPass123","role":"user"}' \
         '{"name":"Пётр Сидоров","email":"petr@x.ru","password":"PetrPass123","role":"user"}'; do
  curl -s -b "$JA" -H "X-CSRF-Token: $TA" -H 'Content-Type: application/json' \
    -d "$u" "$B?action=register_user" >/dev/null
done

# 4. стартовый лид ivan'а (тест 16 ожидает непустую доску)
JI="$TMP/ji"
TI=$(login "$JI" "ivan@x.ru" "IvanPass123")
[ -n "$TI" ] || { echo "не удалось войти ivan@x.ru" >&2; exit 1; }
curl -s -b "$JI" -H "X-CSRF-Token: $TI" -H 'Content-Type: application/json' \
  -d '{"title":"CI стартовый лид"}' "$B?action=save_lead" >/dev/null

echo "стенд готов: $B"
