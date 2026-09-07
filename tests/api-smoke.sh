#!/usr/bin/env bash
# Регрессионные проверки API на тестовом стенде (НЕ на проде: скрипт создаёт пользователей и лиды).
#
# Что нужно:
#   - запущенный PHP-сервер с CRM и отдельной тестовой БД:
#       php -S 127.0.0.1:8089 -t /путь/к/crm      (config.php должен смотреть в тестовую БД)
#   - curl, python3
#
# Запуск:
#   CRM_URL=http://127.0.0.1:8089 ADMIN_EMAIL=admin@detroid.local ADMIN_PASS='...' bash tests/api-smoke.sh
#
# Скрипт сам создаёт двух сотрудников (smoke-a@test.local / smoke-b@test.local) при первом запуске
# и повторно использует их дальше. Каждая проверка печатает PASS/FAIL; код выхода 1, если есть FAIL.

set -u
B="${CRM_URL:-http://127.0.0.1:8089}/api.php"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@detroid.local}"
ADMIN_PASS="${ADMIN_PASS:?Укажите ADMIN_PASS}"
A_EMAIL="smoke-a@test.local"; A_PASS="SmokePassA1"; A_NAME="Смоук Первый"
B_EMAIL="smoke-b@test.local"; B_PASS="SmokePassB1"; B_NAME="Смоук Второй"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
FAILS=0

pass() { echo "PASS | $1"; }
fail() { echo "FAIL | $1 | $2"; FAILS=$((FAILS+1)); }
check() { # check "имя" "условие-выражение-python над переменной r (JSON-строка)" "ответ"
  if python3 -c "import sys,json
raw=sys.argv[2]
try: r=json.loads(raw)
except Exception: r={'_raw': raw}
sys.exit(0 if ($2) else 1)" "$1" "$3" 2>/dev/null; then pass "$1"; else fail "$1" "$3"; fi
}
jget() { python3 -c "import sys,json; r=json.loads(sys.argv[1]); print(eval(sys.argv[2]))" "$1" "$2" 2>/dev/null; }

# --- helpers -------------------------------------------------------------
csrf_login_token() { curl -s "$B?action=csrf" | jget "$(cat)" "r['csrf']" 2>/dev/null || curl -s "$B?action=csrf" | sed 's/.*"csrf":"\([^"]*\)".*/\1/'; }
login() { # login <jar> <email> <pass>  → печатает session csrf
  local T; T=$(curl -s "$B?action=csrf" | sed 's/.*"csrf":"\([^"]*\)".*/\1/')
  curl -s -c "$1" -H "X-CSRF-Token: $T" -H 'Content-Type: application/json' -d "{\"email\":\"$2\",\"password\":\"$3\"}" "$B?action=login" | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p'
}
post() { curl -s -b "$1" -H "X-CSRF-Token: $2" -H 'Content-Type: application/json' -d "$4" "$B?action=$3${5:-}"; }
get() { curl -s -b "$1" "$B?action=$2"; }
upload() { curl -s -b "$1" -H "X-CSRF-Token: $2" "${@:3}"; }

printf 'P\x89PNG\r\n\x1a\n' | tail -c 8 > "$TMP/t.png"  # PNG magic
printf '%%PDF-1.4\n%%%%EOF\n' > "$TMP/t.pdf"

# --- 0. вход админа, создание тестовых сотрудников ------------------------
JA="$TMP/ja"; TA=$(login "$JA" "$ADMIN_EMAIL" "$ADMIN_PASS")
[ -n "$TA" ] || { echo "Не удалось войти админом ($ADMIN_EMAIL)"; exit 2; }
post "$JA" "$TA" register_user "{\"name\":\"$A_NAME\",\"email\":\"$A_EMAIL\",\"password\":\"$A_PASS\",\"role\":\"user\"}" >/dev/null
post "$JA" "$TA" register_user "{\"name\":\"$B_NAME\",\"email\":\"$B_EMAIL\",\"password\":\"$B_PASS\",\"role\":\"user\"}" >/dev/null
USERS=$(get "$JA" get_users)
UA=$(jget "$USERS" "[u['id'] for u in r['users'] if u['email']=='$A_EMAIL'][0]")
UB=$(jget "$USERS" "[u['id'] for u in r['users'] if u['email']=='$B_EMAIL'][0]")
JI="$TMP/ji"; TI=$(login "$JI" "$A_EMAIL" "$A_PASS")
JP="$TMP/jp"; TP=$(login "$JP" "$B_EMAIL" "$B_PASS")
check "вход сотрудников A(id=$UA) и B(id=$UB)" "'$TI'!='' and '$TP'!=''" '{}'

# --- 1. базовые запреты --------------------------------------------------
R=$(curl -s "$B?action=save_lead&title=x"); check "GET-мутация без сессии отклонена" "r.get('need_login') or r.get('success') is False" "$R"
R=$(get "$JI" "get_data&as=$UB"); check "?as= для не-админа → Нет прав" "r.get('error')=='Нет прав'" "$R"
R=$(post "$JI" "$TI" save_lead '{bad json'); check "битый JSON → «Некорректный запрос», лид не создан" "r.get('error')=='Некорректный запрос'" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":["массив"],"inn":{"a":1}}'); check "массив вместо строки не ломает JSON-ответ" "r.get('success') is True" "$R"
LJUNK=$(jget "$R" "r['id']"); post "$JI" "$TI" delete_lead "{\"id\":\"$LJUNK\"}" >/dev/null

# --- 2. id лидов выдаёт сервер -------------------------------------------
R=$(post "$JI" "$TI" save_lead '{"id":"../../evil","title":"Smoke A1","inn":"7701234567"}')
LA1=$(jget "$R" "r['id']"); check "клиентский id игнорируется, сервер выдал свой (l_hex)" "r.get('id','').startswith('l_') and len(r['id'])==14" "$R"
R=$(post "$JP" "$TP" save_lead '{"title":"Smoke B1","inn":"7809876543"}'); LB1=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LB1\",\"title\":\"hijack\"}"); check "чужой лид через save_lead → Лид не найден" "r.get('error')=='Лид не найден'" "$R"

# --- 3. пересечения в поиске только по точному запросу --------------------
R=$(get "$JI" "search_leads&q=78"); check "поиск «78» — пересечений нет" "r.get('intersections')==[]" "$R"
R=$(get "$JI" "search_leads&q=7809"); check "поиск «7809» — пересечение с лидом B найдено" "any(i.get('inn')=='7809876543' for i in r.get('intersections',[]))" "$R"

# --- 4. вложения: kind обязателен, номера независимы ----------------------
upload "$JI" "$TI" -F lead_id="$LA1" -F text=f -F "files[]=@$TMP/t.png;filename=a.png" "$B?action=add_comment" >/dev/null
R=$(get "$JI" "get_comments&id=$LA1"); ATT=$(jget "$R" "[a['id'] for c in r['comments'] for a in c.get('attachments',[])][0]")
check "вложение к лиду загружено" "'$ATT'!=''" "$R"
R=$(post "$JI" "$TI" delete_attachment "{\"id\":$ATT}"); check "delete_attachment без kind отклонён" "r.get('error')=='Не указан тип вложения'" "$R"
R=$(post "$JI" "$TI" delete_attachment "{\"id\":$ATT,\"kind\":\"carrier\"}"); check "delete_attachment с чужим kind не удаляет файл лида" "r.get('success') is not True" "$R"
R=$(post "$JI" "$TI" delete_attachment "{\"id\":$ATT,\"kind\":\"lead\"}"); check "delete_attachment kind=lead удаляет" "r.get('success') is True" "$R"
LONG=$(python3 -c 'print("ф"*290+".png")')
R=$(upload "$JI" "$TI" -F lead_id="$LA1" -F text=long -F "files[]=@$TMP/t.png;filename=$LONG" "$B?action=add_comment"); check "файл с именем 294 символа принят (обрезан до 200)" "r.get('success') is True" "$R"
upload "$JI" "$TI" -F lead_id="$LA1" -F text=pdf -F "files[]=@$TMP/t.pdf;filename=Договор.pdf" "$B?action=add_comment" >/dev/null
URL=$(get "$JI" "get_comments&id=$LA1" | python3 -c 'import sys,json; d=json.load(sys.stdin); print([a["dataUrl"] for c in d["comments"] for a in c.get("attachments",[]) if a["name"].endswith(".pdf")][0])')
HDR=$(curl -s -D - -o /dev/null -b "$JI" "${B%api.php}$URL")
if echo "$HDR" | grep -q "filename\*=UTF-8''%D0%94"; then pass "скачивание: Content-Disposition с filename*=UTF-8"; else fail "скачивание: Content-Disposition с filename*=UTF-8" "$(echo "$HDR" | grep -i disposition)"; fi

# --- 5. системные записи и записи уволенных ------------------------------
R=$(get "$JI" "get_comments&id=$LA1"); SYS=$(jget "$R" "[c['id'] for c in r['comments'] if c['author']=='Система'][0]")
R=$(post "$JI" "$TI" delete_comment "{\"id\":\"$SYS\"}"); check "владелец не может удалить системную запись" "r.get('error')=='Нет прав'" "$R"
R=$(post "$JA" "$TA" delete_comment "{\"id\":\"$SYS\"}" "&as=$UA"); check "админ может удалить системную запись" "r.get('success') is True" "$R"

# --- 6. справочник: удаление/переименование — создатель или админ ---------
R=$(post "$JI" "$TI" save_direction '{"cityFrom":"Смоукград","cityTo":"Тестбург"}'); DID=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_carrier "{\"directionId\":\"$DID\",\"name\":\"ИП Смоук\"}"); CID=$(jget "$R" "r['id']")
R=$(post "$JP" "$TP" delete_direction "{\"id\":\"$DID\"}"); check "B не может удалить направление, созданное A" "'администратор' in r.get('error','')" "$R"
R=$(post "$JP" "$TP" save_direction "{\"id\":\"$DID\",\"cityFrom\":\"Смоукград\",\"cityTo\":\"Другой\"}"); check "B не может переименовать направление A" "'администратор' in r.get('error','')" "$R"
R=$(post "$JP" "$TP" delete_carrier "{\"id\":\"$CID\"}"); check "B не может удалить перевозчика A" "'администратор' in r.get('error','')" "$R"
R=$(upload "$JP" "$TP" -F carrier_id="$CID" -F text="запись B" "$B?action=add_carrier_comment"); check "B может писать в лог перевозчика A" "r.get('success') is True" "$R"
R=$(get "$JP" "get_carriers&id=$DID"); check "canManage=false для B, у перевозчика A тоже" "r['direction']['canManage'] is False and all(c['canManage'] is False for c in r['carriers'])" "$R"
R=$(post "$JA" "$TA" delete_carrier "{\"id\":\"$CID\"}"); check "админ удаляет перевозчика" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" delete_direction "{\"id\":\"$DID\"}"); check "создатель удаляет направление" "r.get('success') is True" "$R"

# --- 7. заявки: строгий парсинг денег, лимиты этапов ---------------------
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"12 500,50\",\"margin\":\"1 000\"}"); check "ставка «12 500,50» → 12500.50" "r.get('application',{}).get('rate')=='12500.50'" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"abc\"}"); check "ставка «abc» отклонена" "'Ставка' in r.get('error','')" "$R"
ST=$(python3 -c 'import json; print(json.dumps({"stages":["Э%d"%i for i in range(21)]}))')
R=$(post "$JI" "$TI" save_stages "$ST"); check "21 этап → отказ" "'Не больше' in r.get('error','')" "$R"
R=$(post "$JI" "$TI" save_stages '{"stages":["Новый","новый"]}'); check "дубликат этапа с разным регистром → Имя занято" "r.get('error')=='Имя занято'" "$R"

# --- 8. передача лида только по id -----------------------------------------
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"manager\":\"Второй\",\"transfer\":true}"); check "старый способ (manager+transfer) не передаёт" "r.get('success') is True and not r.get('transferred')" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"transferTo\":999999}"); check "transferTo на несуществующего → Сотрудник не найден" "r.get('error')=='Сотрудник не найден'" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"transferTo\":$UB}"); check "transferTo=B → передан" "r.get('transferred') is True" "$R"
R=$(get "$JP" "get_comments&id=$LA1"); check "у B в логе запись «Лид передан»" "any('Лид передан' in c['text'] for c in r.get('comments',[]))" "$R"

# --- 9. продавец по умолчанию и переименование ----------------------------
R=$(post "$JA" "$TA" save_lead '{"title":"Smoke от админа"}' "&as=$UA"); LADM=$(jget "$R" "r['id']")
R=$(get "$JI" "get_lead&id=$LADM"); check "лид, созданный админом через ?as=, имеет продавца = владелец доски" "r['lead']['manager']=='$A_NAME'" "$R"
post "$JA" "$TA" update_user "{\"id\":$UA,\"name\":\"$A_NAME Переим\",\"email\":\"$A_EMAIL\",\"role\":\"user\"}" >/dev/null
R=$(get "$JI" "get_lead&id=$LADM"); check "переименование сотрудника обновило продавца на лиде" "r['lead']['manager']=='$A_NAME Переим'" "$R"
post "$JA" "$TA" update_user "{\"id\":$UA,\"name\":\"$A_NAME\",\"email\":\"$A_EMAIL\",\"role\":\"user\"}" >/dev/null

# --- 10. пароли и сессии --------------------------------------------------
R=$(post "$JA" "$TA" update_user "{\"id\":$UB,\"name\":\"$B_NAME\",\"email\":\"$B_EMAIL\",\"role\":\"user\",\"password\":\"парольйц\"}"); check "пароль из 8 кириллических символов принят" "r.get('success') is True" "$R"
R=$(post "$JA" "$TA" update_user "{\"id\":$UB,\"name\":\"$B_NAME\",\"email\":\"$B_EMAIL\",\"role\":\"user\",\"password\":\"$(python3 -c 'print("a"*65)')\"}"); check "пароль 65 символов отклонён" "'64' in r.get('error','')" "$R"
R=$(get "$JP" get_data); check "смена пароля админом выбросила сессию B" "r.get('need_login') is True" "$R"
post "$JA" "$TA" update_user "{\"id\":$UB,\"name\":\"$B_NAME\",\"email\":\"$B_EMAIL\",\"role\":\"user\",\"password\":\"$B_PASS\"}" >/dev/null
JI2="$TMP/ji2"; TI2=$(login "$JI2" "$A_EMAIL" "$A_PASS")
R=$(post "$JI" "$TI" change_password "{\"old\":\"$A_PASS\",\"password\":\"${A_PASS}x\"}"); check "смена своего пароля" "r.get('success') is True" "$R"
R=$(get "$JI" get_data); check "своя сессия после смены пароля жива" "r.get('success') is True" "$R"
R=$(get "$JI2" get_data); check "вторая сессия после смены пароля выброшена" "r.get('need_login') is True" "$R"
TI=$(get "$JI" check_auth | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p'); post "$JI" "$TI" change_password "{\"old\":\"${A_PASS}x\",\"password\":\"$A_PASS\"}" >/dev/null

# --- 11. хэш доски не зависит от справочника -------------------------------
R=$(post "$JI" "$TI" save_direction '{"cityFrom":"Хэшград","cityTo":"Тестбург"}'); DID=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_carrier "{\"directionId\":\"$DID\",\"name\":\"ИП Хэш\"}"); CID=$(jget "$R" "r['id']")
H1=$(get "$JI" get_data | jget "$(cat)" "r['hash']")
upload "$JI" "$TI" -F carrier_id="$CID" -F text="запись" "$B?action=add_carrier_comment" >/dev/null
H2=$(get "$JI" get_data | jget "$(cat)" "r['hash']")
check "комментарий к перевозчику не меняет хэш доски" "'$H1'=='$H2'" '{}'
post "$JI" "$TI" delete_direction "{\"id\":\"$DID\"}" >/dev/null

# --- 12. админские сервисные действия --------------------------------------
R=$(post "$JI" "$TI" sweep_uploads '{}'); check "sweep_uploads недоступен сотруднику" "r.get('error')=='Нет прав'" "$R"
R=$(post "$JA" "$TA" sweep_uploads '{}'); check "sweep_uploads доступен админу" "r.get('success') is True and 'checked' in r" "$R"

# --- уборка ---------------------------------------------------------------
post "$JI" "$TI" delete_lead "{\"id\":\"$LADM\"}" >/dev/null
TP=$(login "$JP" "$B_EMAIL" "$B_PASS"); post "$JP" "$TP" delete_lead "{\"id\":\"$LA1\"}" >/dev/null; post "$JP" "$TP" delete_lead "{\"id\":\"$LB1\"}" >/dev/null

echo
if [ "$FAILS" -eq 0 ]; then echo "ALL PASSED"; else echo "$FAILS FAILED"; exit 1; fi
