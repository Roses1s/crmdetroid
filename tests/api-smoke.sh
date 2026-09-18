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
R=$(get "$JI" "get_clients"); check "§34: общий реестр видит чужих без контактов" "any(c.get('title')=='Smoke B1' and c.get('owner')=='$B_NAME' and c.get('mine') is False for c in r.get('clients',[])) and any(c.get('title')=='Smoke A1' and c.get('mine') is True for c in r.get('clients',[])) and all('phone' not in c and 'logistPhone' not in c and 'email' not in c for c in r.get('clients',[])) and r.get('total',0)>=2" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LB1\",\"title\":\"hijack\"}"); check "чужой лид через save_lead → Лид не найден" "r.get('error')=='Лид не найден'" "$R"

# --- 2а. Код АТИ и Имя логиста при создании лида ---------------------------
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke ATI","ati":"ATI-12345","logistName":"Логист Смоук","phone":"+7 (912) 000-11-22","logistPhone":"+7 (912) 000-11-22"}'); LATI=$(jget "$R" "r['id']")
R=$(get "$JI" "get_lead&id=$LATI")
check "ati сохраняется при создании" "r['lead'].get('ati')=='ATI-12345'" "$R"
check "logistName сохраняется при создании" "r['lead'].get('logistName')=='Логист Смоук'" "$R"
# Окно создания шлёт телефон и в phone, и в logistPhone (контакт логиста)
check "телефон из окна создания попал в контакт логиста" "r['lead'].get('logistPhone')=='+7 (912) 000-11-22'" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LATI\",\"ati\":\"ATI-99\"}")
R=$(get "$JI" "get_lead&id=$LATI"); check "ati обновляется через save_lead" "r['lead'].get('ati')=='ATI-99'" "$R"
R=$(get "$JI" "get_data"); check "лёгкая выборка досок содержит logistPhone" "all('logistPhone' in l for l in r.get('leads',[]))" "$R"
post "$JI" "$TI" delete_lead "{\"id\":\"$LATI\"}" >/dev/null

# --- 3. пересечения в поиске только по точному запросу --------------------
R=$(get "$JI" "search_leads&q=78"); check "поиск «78» — пересечений нет" "r.get('intersections')==[]" "$R"
R=$(get "$JI" "search_leads&q=7809"); check "поиск «7809» — пересечение с лидом B найдено (индекс ИНН)" "any(i.get('inn')=='7809876543' for i in r.get('intersections',[]))" "$R"
# v16: пересечения по названию идут через FULLTEXT (поиск по началу слов)
R=$(get "$JI" "search_leads&q=Smok"); check "поиск «Smok» — пересечение по началу слова найдено (FULLTEXT)" "any(i.get('inn')=='7809876543' for i in r.get('intersections',[]))" "$R"
R=$(get "$JI" "search_leads&q=SMOKE"); check "поиск «SMOKE» — регистр не важен" "any(i.get('inn')=='7809876543' for i in r.get('intersections',[]))" "$R"
R=$(get "$JI" "search_leads&q=%2BSmok%2A%20%22x"); check "операторы BOOLEAN MODE в запросе не ломают поиск" "r.get('success') is True" "$R"

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
R=$(post "$JP" "$TP" save_carrier "{\"id\":\"$CID\",\"directionId\":\"$DID\",\"name\":\"Хайджек\"}"); check "B не может править карточку перевозчика A" "'администратор' in r.get('error','')" "$R"
R=$(post "$JI" "$TI" save_carrier "{\"id\":\"$CID\",\"directionId\":\"$DID\",\"name\":\"ИП Смоук\"}"); check "создатель может править свою карточку перевозчика" "r.get('success') is True" "$R"
R=$(upload "$JP" "$TP" -F carrier_id="$CID" -F text="запись B" "$B?action=add_carrier_comment"); check "B может писать в лог перевозчика A" "r.get('success') is True" "$R"
R=$(get "$JP" "get_carriers&id=$DID"); check "canManage=false для B, у перевозчика A тоже" "r['direction']['canManage'] is False and all(c['canManage'] is False for c in r['carriers'])" "$R"
R=$(post "$JA" "$TA" delete_carrier "{\"id\":\"$CID\"}"); check "админ удаляет перевозчика" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" delete_direction "{\"id\":\"$DID\"}"); check "создатель удаляет направление" "r.get('success') is True" "$R"

# --- 7. заявки: строгий парсинг денег, лимиты этапов ---------------------
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"12 500,50\",\"margin\":\"1 000\"}"); check "ставка «12 500,50» → 12500.50" "r.get('application',{}).get('rate')=='12500.50'" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"1 000\",\"number\":\"SMK-1\"}")
check "v18: номер заявки сохраняется при создании" "r.get('application',{}).get('number')=='SMK-1'" "$R"
APPN=$(jget "$R" "r['application']['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APPN\",\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"1 000\",\"number\":\"SMK-2\"}")
check "v18: номер заявки обновляется" "r.get('application',{}).get('number')=='SMK-2'" "$R"
R=$(get "$JI" "get_apps&q=SMK-2"); check "v18: поиск заявки по номеру" "any(a.get('number')=='SMK-2' for a in r.get('apps',[]))" "$R"
check "v21: продавец в реестре заявок" "any(a.get('sellerName')=='$A_NAME' for a in r.get('apps',[]))" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"50 000\",\"vat\":\"22\",\"carrierRate\":\"30 000\",\"carrierVat\":\"5\"}")
check "v19: ставка+налоги обеих сторон сохраняются" "r.get('application',{}).get('vat')==22 and r.get('application',{}).get('carrierRate')=='30000' and r.get('application',{}).get('carrierVat')==5" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"vat\":\"13\"}"); check "v19: чужой налог заказчика отклонён" "'Налоги заказчика' in r.get('error','')" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APPN\",\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"1 000\",\"carrierRate\":\"25 000\",\"carrierVat\":\"0\"}")
check "v19: обновление пишет ставку перевозчика и НДС 0%" "r.get('application',{}).get('carrierRate')=='25000' and r.get('application',{}).get('carrierVat')==0" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APPN\",\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"1 000\",\"vat\":\"\",\"carrierVat\":\"\"}")
check "v19: пустые налоги → без НДС с обеих сторон" "r.get('application',{}).get('vat') is None and r.get('application',{}).get('carrierVat') is None" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LA1\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"abc\"}"); check "ставка «abc» отклонена" "'Ставка' in r.get('error','')" "$R"
ST=$(python3 -c 'import json; print(json.dumps({"stages":["Э%d"%i for i in range(21)]}))')
R=$(post "$JI" "$TI" save_stages "$ST"); check "21 этап → отказ" "'Не больше' in r.get('error','')" "$R"
R=$(post "$JI" "$TI" save_stages '{"stages":["Новый","новый"]}'); check "дубликат этапа с разным регистром → Имя занято" "r.get('error')=='Имя занято'" "$R"

# --- 8. передача лида только по id -----------------------------------------
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"manager\":\"Второй\",\"transfer\":true}"); check "старый способ (manager+transfer) не передаёт" "r.get('success') is True and not r.get('transferred')" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"transferTo\":999999}"); check "transferTo на несуществующего → Сотрудник не найден" "r.get('error')=='Сотрудник не найден'" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LA1\",\"title\":\"Smoke A1\",\"transferTo\":$UB}"); check "transferTo=B → передан" "r.get('transferred') is True" "$R"
R=$(get "$JP" "get_comments&id=$LA1"); check "у B в логе запись «Лид передан»" "any('Лид передан' in c['text'] for c in r.get('comments',[]))" "$R"
# Регрессия: новый лид с transferTo в одном запросе раньше падал в 500 ($row['updated_at'] при $row=null)
R=$(post "$JI" "$TI" save_lead "{\"title\":\"Smoke новый с передачей\",\"transferTo\":$UB}"); LNEW=$(jget "$R" "r.get('id','')"); check "новый лид с transferTo сразу передан (не 500)" "r.get('transferred') is True" "$R"
[ -n "$LNEW" ] && post "$JP" "$TP" delete_lead "{\"id\":\"$LNEW\"}" >/dev/null

# --- 8а. при передаче лида сохраняются комментарии, файлы и заявки ----------
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke передача с содержимым"}'); LTR=$(jget "$R" "r['id']")
upload "$JI" "$TI" -F lead_id="$LTR" -F text="комментарий до передачи" -F "files[]=@$TMP/t.png;filename=до-передачи.png" "$B?action=add_comment" >/dev/null
post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LTR\",\"cityFrom\":\"Пермь\",\"cityTo\":\"Казань\",\"rate\":\"7 000\",\"margin\":\"1 500\"}" >/dev/null
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LTR\",\"title\":\"Smoke передача с содержимым\",\"transferTo\":$UB}"); check "лид с логом, файлом и заявкой передан B" "r.get('transferred') is True" "$R"
R=$(get "$JP" "get_comments&id=$LTR")
check "у B после передачи виден комментарий A" "any(c['text']=='комментарий до передачи' for c in r.get('comments',[]))" "$R"
check "у B после передачи видно вложение" "any(a['name']=='до-передачи.png' for c in r.get('comments',[]) for a in c.get('attachments',[]))" "$R"
FURL=$(jget "$R" "[a['dataUrl'] for c in r['comments'] for a in c.get('attachments',[]) if a['name']=='до-передачи.png'][0]")
HTTPB=$(curl -s -o /dev/null -w '%{http_code}' -b "$JP" "${B%api.php}$FURL")
check "B скачивает файл переданного лида (200)" "'$HTTPB'=='200'" '{}'
HTTPA=$(curl -s -o /dev/null -w '%{http_code}' -b "$JI" "${B%api.php}$FURL")
check "A после передачи файл больше не доступен (404)" "'$HTTPA'=='404'" '{}'
R=$(get "$JP" "get_lead&id=$LTR")
check "заявка пережила передачу (count=1, маржа 1500)" "r['lead']['applicationsCount']==1 and r['lead']['appsStats']['margin']==1500" "$R"
R=$(get "$JI" "get_lead&id=$LTR"); check "A после передачи лид не видит" "r.get('error')=='Лид не найден'" "$R"
post "$JP" "$TP" delete_lead "{\"id\":\"$LTR\"}" >/dev/null

# --- 8б. теги лидов (v17): справочник, привязка, изоляция, чистка ------------
R=$(post "$JI" "$TI" save_tag '{"name":"Смоук срочно","color":"#ef4444"}'); TG1=$(jget "$R" "r['tag']['id']")
check "тег создан с цветом из палитры" "r.get('success') is True and r['tag']['color']=='#ef4444'" "$R"
R=$(post "$JI" "$TI" save_tag '{"name":"Смоук цвет","color":"#bad бяка"}')
TG2=$(jget "$R" "r['tag']['id']")
check "цвет не из палитры заменён на дефолтный" "r['tag']['color']=='#6366f1'" "$R"
R=$(post "$JI" "$TI" save_tag '{"name":"Смоук срочно","color":"#3b82f6"}')
check "дубль названия тега отклонён" "r.get('error')=='Тег с таким названием уже есть'" "$R"
R=$(post "$JI" "$TI" save_tag '{"name":"Смоук свободный","color":"#123abc"}'); TG39=$(jget "$R" "r['tag']['id']")
check "§39: свободный hex принят" "r.get('success') is True and r['tag']['color']=='#123abc'" "$R"
R=$(post "$JI" "$TI" save_tag "{\"id\":$TG39,\"name\":\"Смоук свободный\",\"color\":\"#abcdef\"}"); check "§39: перекраска тега" "r.get('success') is True and r['tag']['color']=='#abcdef'" "$R"
R=$(post "$JI" "$TI" save_tag '{"name":"Смоук регистр","color":"#ABCDEF"}'); check "§39: верхний регистр нормализуется" "r['tag']['color']=='#abcdef'" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke лид с тегами"}'); LTG=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" set_lead_tags "{\"leadId\":\"$LTG\",\"tagIds\":[$TG1,$TG2,999999]}")
check "теги назначены лиду (несуществующий id отброшен)" "r.get('success') is True and sorted(t['id'] for t in r['leadTags'])==sorted([$TG1,$TG2])" "$R"
R=$(get "$JI" "get_data&hash=x")
check "get_data отдаёт справочник и карту тегов" "any(t['id']==$TG1 for t in r.get('tags',[])) and any(t['id']==$TG1 for t in r.get('leadTags',{}).get('$LTG',[]))" "$R"
# чужой тег нельзя назначить своему лиду
R=$(post "$JP" "$TP" save_tag '{"name":"Смоук чужой","color":"#22c55e"}'); TGB=$(jget "$R" "r['tag']['id']")
R=$(post "$JI" "$TI" set_lead_tags "{\"leadId\":\"$LTG\",\"tagIds\":[$TGB]}")
check "чужой тег отброшен при назначении" "r.get('success') is True and r['leadTags']==[]" "$R"
post "$JI" "$TI" set_lead_tags "{\"leadId\":\"$LTG\",\"tagIds\":[$TG1,$TG2]}" >/dev/null
R=$(post "$JP" "$TP" delete_tag "{\"id\":$TG1}")
check "чужой тег нельзя удалить" "r.get('error')=='Тег не найден'" "$R"
# передача лида снимает теги прежнего владельца
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LTG\",\"title\":\"Smoke лид с тегами\",\"transferTo\":$UB}")
check "лид с тегами передан B" "r.get('transferred') is True" "$R"
R=$(get "$JP" "get_data&hash=x")
check "у B на переданном лиде нет чужих тегов" "r.get('leadTags',{}).get('$LTG',[])==[]" "$R"
post "$JP" "$TP" delete_lead "{\"id\":\"$LTG\"}" >/dev/null
# удаление тега чистит справочник
R=$(post "$JI" "$TI" delete_tag "{\"id\":$TG1}"); check "тег удалён из справочника" "r.get('success') is True and all(t['id']!=$TG1 for t in r.get('tags',[]))" "$R"
post "$JI" "$TI" delete_tag "{\"id\":$TG2}" >/dev/null
post "$JP" "$TP" delete_tag "{\"id\":$TGB}" >/dev/null

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

# --- 11а. дельта-синхронизация get_data (ревью, п. 12) ----------------------
R=$(get "$JI" get_data); H1=$(jget "$R" "r['hash']")
check "get_data отдаёт hash" "r.get('hash','')!=''" "$R"
R=$(get "$JI" "get_data&hash=$H1"); check "повторный запрос с тем же hash → unchanged" "r.get('unchanged') is True" "$R"
# создаём лид → hash меняется, дельта с since=1 должна принести его в changed + ids
R=$(post "$JI" "$TI" save_lead '{"title":"Дельта-лид"}'); LD=$(jget "$R" "r.get('id','')")
R=$(get "$JI" "get_data&hash=$H1&since=1")
check "дельта: delta=true и есть ids" "r.get('delta') is True and isinstance(r.get('ids'),list)" "$R"
check "дельта: новый лид в changed" "any(c['id']=='$LD' for c in r.get('changed',[]))" "$R"
check "дельта: новый лид в ids" "'$LD' in r.get('ids',[])" "$R"
H2=$(jget "$R" "r['hash']")
DSINCE=$(jget "$R" "max([c['updatedAt'] for c in r.get('changed',[])]+[1])")
# без изменений: дельта с актуальным since — changed пуст (или только сам лид при зазоре)
R=$(get "$JI" "get_data&hash=badhash&since=$((DSINCE+1))")
check "дельта: без изменений changed пуст" "r.get('delta') is True and r.get('changed')==[]" "$R"
# удаляем лид: он должен исчезнуть из ids
post "$JI" "$TI" delete_lead "{\"id\":\"$LD\"}" >/dev/null
R=$(get "$JI" "get_data&hash=$H2&since=1")
check "дельта: удалённый лид исчез из ids" "r.get('delta') is True and '$LD' not in r.get('ids',[])" "$R"
# запрос без since (первый заход клиента) — по-прежнему полный ответ
R=$(get "$JI" "get_data&hash=badhash")
check "без since → полный ответ с leads" "r.get('delta') is None and isinstance(r.get('leads'),list)" "$R"
# баннер «Вышло обновление»: build и v едут во всех трёх формах get_data
check "полный ответ несёт build и v сборки" "len(r.get('build',''))==12 and r.get('v','')!=''" "$R"
B1=$(jget "$R" "r.get('build','')"); V1=$(jget "$R" "r.get('v','')"); H1B=$(jget "$R" "r['hash']")
R=$(get "$JI" "get_data&hash=$H1B"); check "unchanged тоже несёт build и v" "r.get('unchanged') is True and r.get('build')=='$B1' and r.get('v')=='$V1'" "$R"

# --- 12. админские сервисные действия --------------------------------------
R=$(post "$JI" "$TI" sweep_uploads '{}'); check "sweep_uploads недоступен сотруднику" "r.get('error')=='Нет прав'" "$R"
R=$(post "$JA" "$TA" sweep_uploads '{}'); check "sweep_uploads доступен админу" "r.get('success') is True and 'checked' in r" "$R"
R=$(get "$JI" "get_audit"); check "get_audit недоступен сотруднику" "r.get('error')=='Нет прав'" "$R"
# LIMIT через bindValue(PARAM_INT) — проверяем против настоящего MySQL, включая зажим limit
R=$(get "$JA" "get_audit&limit=3"); check "get_audit: limit работает (события входов есть)" "r.get('success') is True and 0 < len(r.get('events',[])) <= 3" "$R"
R=$(get "$JA" "get_audit&limit=99999"); check "get_audit: limit зажат до 500" "r.get('success') is True and len(r.get('events',[])) <= 500" "$R"

# --- 13. HTTP-статусы ошибок (ревью, пп. 2.5 / 12.8) ------------------------
# err() отдаёт честные коды: 401 need_login, 403 права, 404 не найдено, 405 метод, 200 успех.
scode() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
C=$(scode "$B?action=get_data"); check "без сессии → 401" "'$C'=='401'" "{\"_raw\":\"$C\"}"
C=$(scode -b "$JI" "$B?action=get_data&as=$UB"); check "чужая доска не-админом → 403" "'$C'=='403'" "{\"_raw\":\"$C\"}"
C=$(scode -b "$JI" "$B?action=get_lead&id=l_000000000000"); check "несуществующий лид → 404" "'$C'=='404'" "{\"_raw\":\"$C\"}"
C=$(scode -b "$JI" -H "X-CSRF-Token: $TI" "$B?action=save_lead"); check "GET-мутация → 405" "'$C'=='405'" "{\"_raw\":\"$C\"}"
C=$(scode -b "$JI" "$B?action=get_data"); check "успешный запрос → 200" "'$C'=='200'" "{\"_raw\":\"$C\"}"

# --- 14. невалидный UTF-8 во входных данных ---------------------------------
# Новая сессия для A: лимит 90 запросов/60 с на сессию (§0–§13 с проверками v19 его
# выбирают полностью) — без перелогина get_comments упирается в 429 (поймано CI).
# Тот же приём, что перед §15.
TI=$(login "$JI" "$A_EMAIL" "$A_PASS")
# %FF%FE в query string и битые байты в FormData обходят json_decode; раньше они доходили
# до MySQL (ошибка 1366 → 500) или, попав в БД, ломали json_encode ответа (пустое тело).
C=$(scode -b "$JI" "$B?action=search_leads&q=%FF%FEsmoke"); check "битый UTF-8 в поиске → не 500" "'$C'!='500'" "{\"_raw\":\"$C\"}"
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke UTF8"}'); LU8=$(jget "$R" "r.get('id','')")
R=$(upload "$JI" "$TI" -F "lead_id=$LU8" -F "text=битые байты: $(printf '\xff\xfe')" "$B?action=add_comment")
check "битый UTF-8 в комментарии не роняет запрос (не 500)" "r.get('success') is True or r.get('error','')!=''" "$R"
R=$(get "$JI" "get_comments&id=$LU8"); check "лог лида после битого комментария читается" "r.get('success') is True" "$R"
[ -n "$LU8" ] && post "$JI" "$TI" delete_lead "{\"id\":\"$LU8\"}" >/dev/null

# --- 15. оптимистическая блокировка, переименование этапов, чужие теги -----
# Новая сессия для A: лимит 90 запросов/60 с на сессию (§0–§14 его почти выбрали),
# без перелогина проверки §15 упираются в 429 (поймано CI)
TI=$(login "$JI" "$A_EMAIL" "$A_PASS")
# move_lead со stale-ревизией: читаем ревизию, сохраняем лид (ревизия растёт), двигаем по старой
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke 409"}'); L409=$(jget "$R" "r.get('id','')")
R=$(get "$JI" "get_lead&id=$L409"); REV409=$(jget "$R" "r['lead']['updatedAt']"); STG409=$(jget "$R" "r['lead']['stage']")
post "$JI" "$TI" save_lead "{\"id\":\"$L409\",\"title\":\"Smoke 409b\"}" >/dev/null
R=$(post "$JI" "$TI" move_lead "{\"id\":\"$L409\",\"stage\":\"$STG409\",\"updatedAt\":$REV409}")
check "move_lead со старой ревизией → конфликт" "r.get('error')=='Карточка изменена в другом месте'" "$R"
C=$(scode -b "$JI" -H "X-CSRF-Token: $TI" -H 'Content-Type: application/json' -d "{\"id\":\"$L409\",\"stage\":\"$STG409\",\"updatedAt\":$REV409}" "$B?action=move_lead")
check "конфликт move_lead → HTTP 409" "'$C'=='409'" "{\"_raw\":\"$C\"}"
# со свежей ревизией — двигается (позитивный контроль)
R=$(get "$JI" "get_lead&id=$L409"); FRESH409=$(jget "$R" "r['lead']['updatedAt']")
OTHERSTG=$(get "$JI" get_data | jget "$(cat)" "[s for s in r['stages'] if s!='$STG409'][0]")
R=$(post "$JI" "$TI" move_lead "{\"id\":\"$L409\",\"stage\":\"$OTHERSTG\",\"updatedAt\":$FRESH409}")
check "move_lead со свежей ревизией двигает" "r.get('success') is True" "$R"
# save_lead_app со stale-ревизией заявки
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$L409\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"5 000\"}")
APP409=$(jget "$R" "r['application']['id']"); AUREV1=$(jget "$R" "r['application']['updatedAt']")
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APP409\",\"leadId\":\"$L409\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"5 000\",\"margin\":\"500\"}")
check "обновление заявки без updatedAt проходит" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APP409\",\"leadId\":\"$L409\",\"cityFrom\":\"Москва\",\"cityTo\":\"Казань\",\"updatedAt\":$AUREV1}")
check "save_lead_app со старой ревизией → конфликт" "r.get('error')=='Заявка изменена в другом месте'" "$R"
C=$(scode -b "$JI" -H "X-CSRF-Token: $TI" -H 'Content-Type: application/json' -d "{\"id\":\"$APP409\",\"leadId\":\"$L409\",\"cityFrom\":\"Москва\",\"cityTo\":\"Казань\",\"updatedAt\":$AUREV1}" "$B?action=save_lead_app")
check "конфликт save_lead_app → HTTP 409" "'$C'=='409'" "{\"_raw\":\"$C\"}"
# переименование этапа тянет за собой лиды (и обратно при восстановлении)
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke rename"}'); LRN=$(jget "$R" "r.get('id','')")
ST0=$(get "$JI" get_data | jget "$(cat)" "r['stages'][0]")
R=$(get "$JI" "get_lead&id=$LRN"); check "новый лид на первом этапе" "r['lead']['stage']=='$ST0'" "$R"
STAGES_JSON=$(get "$JI" get_data | jget "$(cat)" "json.dumps(r['stages'],ensure_ascii=False)")
RENAMED=$(python3 -c "import sys,json; s=json.loads(sys.argv[1]); s[0]='Смоук Этап'; print(json.dumps({'stages':s},ensure_ascii=False))" "$STAGES_JSON")
R=$(post "$JI" "$TI" save_stages "$RENAMED"); check "переименование этапа принято" "r.get('success') is True" "$R"
R=$(get "$JI" "get_lead&id=$LRN"); check "лид переехал на переименованный этап" "r['lead']['stage']=='Смоук Этап'" "$R"
RESTORE=$(python3 -c "import sys,json; print(json.dumps({'stages':json.loads(sys.argv[1])},ensure_ascii=False))" "$STAGES_JSON")
R=$(post "$JI" "$TI" save_stages "$RESTORE"); check "этапы восстановлены" "r.get('success') is True" "$R"
R=$(get "$JI" "get_lead&id=$LRN"); check "лид вернулся на исходный этап" "r['lead']['stage']=='$ST0'" "$R"
post "$JI" "$TI" delete_lead "{\"id\":\"$LRN\"}" >/dev/null
# чужой set_lead_tags: сессия B убита сменой пароля в §10 — входим заново
TP=$(login "$JP" "$B_EMAIL" "$B_PASS")
R=$(post "$JP" "$TP" set_lead_tags "{\"leadId\":\"$L409\",\"tagIds\":[1]}")
check "чужой лид через set_lead_tags → Лид не найден" "r.get('error')=='Лид не найден'" "$R"
post "$JI" "$TI" delete_lead "{\"id\":\"$L409\"}" >/dev/null

# --- 16. страница заявки: карточка, статус, лог (v20) -----------------------
# Новая сессия для A: лимит 90 запросов/60 с на сессию (§0–§15 его выбирают
# полностью) — без перелогина один из запросов §16 упирается в 429 (поймано CI).
# Тот же приём, что перед §14/§15.
TI=$(login "$JI" "$A_EMAIL" "$A_PASS")
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke заявка"}'); LAPP=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"10 000\",\"number\":\"SMK-A\"}"); APP=$(jget "$R" "r['application']['id']"); REV0=$(jget "$R" "r['application']['updatedAt']")
check "v20: новая заявка со статусом 0" "r.get('application',{}).get('status')==0" "$R"
R=$(get "$JI" "get_app&id=$APP"); check "v20: get_app отдаёт заявку и название лида" "r.get('application',{}).get('id')=='$APP' and r.get('leadTitle')=='Smoke заявка'" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"Заказчик Смоук","inn":"1717171717","logistName":"Логист Смоук","logistPhone":"+7 (900) 000-00-01"}'); LCUST=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LCUST\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\"}"); APPCUST=$(jget "$R" "r['application']['id']")
R=$(get "$JI" "get_app&id=$APPCUST"); check "§32: get_app отдаёт заказчика из лида" "r.get('leadInn')=='1717171717' and r.get('leadLogistName')=='Логист Смоук' and r.get('leadLogistPhone')=='+7 (900) 000-00-01'" "$R"
R=$(get "$JP" "get_app&id=$APP"); check "v20: чужой get_app → Заявка не найдена" "r.get('error')=='Заявка не найдена'" "$R"
R=$(get "$JI" "get_lead&id=$LAPP"); check "v20: заявки лида содержат status" "all('status' in a for a in r['lead'].get('applications',[]))" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); check "v20: создание пишет «Заявка создана»" "any(c.get('author')=='Система' and 'Заявка создана' in c.get('text','') for c in r.get('comments',[]))" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Новоград\",\"cityTo\":\"Староград\",\"rate\":\"3 000\"}"); check "v20: заявка с новым маршрутом создана" "r.get('success') is True" "$R"
R=$(get "$JI" "get_directions"); check "v20: маршрут заявки продублирован в направления" "any(d.get('cityFrom')=='Новоград' and d.get('cityTo')=='Староград' and d.get('createdByName')=='$A_NAME' for d in r.get('directions',[]))" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Новоград\",\"cityTo\":\"Староград\",\"rate\":\"4 000\"}"); check "v20: повторный маршрут — заявка создана" "r.get('success') is True" "$R"
R=$(get "$JI" "get_directions"); check "v20: дубль направления не создан" "sum(1 for d in r.get('directions',[]) if d.get('cityFrom')=='Новоград' and d.get('cityTo')=='Староград')==1" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Новоград\",\"cityTo\":\"Староград\",\"rate\":\"5 000\",\"carrierCompany\":\"Смоук Транс\",\"carrierInn\":\"1234567890\",\"carrierName\":\"Пётр\",\"carrierPhone\":\"+7 (900) 111-22-33\"}"); check "v21: заявка с перевозчиком создана" "r.get('success') is True" "$R"
R=$(get "$JI" "get_directions"); DID2=$(jget "$R" "[d['id'] for d in r['directions'] if d.get('cityFrom')=='Новоград' and d.get('cityTo')=='Староград'][0]")
R=$(get "$JI" "get_carriers&id=$DID2"); check "v21: перевозчик заявки в справочнике с реквизитами" "any(c.get('company')=='Смоук Транс' and c.get('inn')=='1234567890' and c.get('name')=='Пётр' and c.get('phone')=='+7 (900) 111-22-33' and c.get('createdByName')=='$A_NAME' for c in r.get('carriers',[]))" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Новоград\",\"cityTo\":\"Староград\",\"rate\":\"6 000\",\"carrierCompany\":\"Смоук Транс\",\"carrierInn\":\"1234567890\",\"carrierName\":\"Пётр\",\"carrierPhone\":\"+7 (900) 111-22-33\"}"); check "v21: повторный перевозчик — заявка создана" "r.get('success') is True" "$R"
R=$(get "$JI" "get_carriers&id=$DID2"); check "v21: дубль перевозчика не создан" "sum(1 for c in r.get('carriers',[]) if c.get('inn')=='1234567890')==1" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Новоград\",\"cityTo\":\"Староград\",\"rate\":\"7 000\"}"); check "v21: заявка без перевозчика создана" "r.get('success') is True" "$R"
R=$(get "$JI" "get_carriers&id=$DID2"); check "v21: пустой перевозчик не создан" "len(r.get('carriers',[]))==1" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"loadAddress\":\"ул. Северная, 54\",\"loadContact\":\"+7 (900) 111-22-33 Пётр\",\"loadDateFrom\":\"2026-09-20\",\"loadTime\":\"к 9 утра\",\"unloadDateTo\":\"2026-09-22\"}"); check "v24: точка погрузки со свободным временем" "r.get('application',{}).get('loadAddress')=='ул. Северная, 54' and r.get('application',{}).get('loadDateFrom')=='2026-09-20' and r.get('application',{}).get('loadTime')=='к 9 утра'" "$R"
APPLOAD=$(jget "$R" "r['application']['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"id\":\"$APPLOAD\",\"leadId\":\"$LAPP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"loadAddress\":\"ул. Северная, 54\",\"loadContact\":\"+7 (900) 111-22-33 Пётр\",\"loadDateFrom\":\"2026-09-21\",\"loadTime\":\"к 9 утра\",\"unloadDateTo\":\"2026-09-22\"}"); check "v23: дата погрузки правится" "r.get('application',{}).get('loadDateFrom')=='2026-09-21'" "$R"
R=$(get "$JI" "get_app_comments&id=$APPLOAD"); check "v23: смена даты пишет системную запись" "any('Дата погрузки с' in c.get('text','') and '2026-09-21' in c.get('text','') for c in r.get('comments',[]))" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"loadDateFrom\":\"21.09.2026\"}"); check "v23: кривая дата отклонена" "'Дата погрузки с' in r.get('error','')" "$R"
R=$(post "$JI" "$TI" save_app "{\"id\":\"$APP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"12 000\"}"); check "v20: save_app обновляет ставку" "r.get('application',{}).get('rate')=='12000'" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); check "v20: смена ставки пишет системную запись" "any('Ставка заказчика' in c.get('text','') and '10000' in c.get('text','') for c in r.get('comments',[]))" "$R"
R=$(post "$JI" "$TI" save_app "{\"id\":\"$APP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"13 000\",\"updatedAt\":$REV0}"); check "v20: save_app со старой ревизией → конфликт" "r.get('error')=='Заявка изменена в другом месте'" "$R"
R=$(post "$JP" "$TP" save_app "{\"id\":\"$APP\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"rate\":\"1 000\"}"); check "v20: чужой save_app → Заявка не найдена" "r.get('error')=='Заявка не найдена'" "$R"
R=$(post "$JI" "$TI" set_app_status "{\"id\":\"$APP\",\"status\":5}"); check "v20: статус вне 0/1/2 отклонён" "r.get('error')=='Неизвестный статус заявки'" "$R"
R=$(post "$JI" "$TI" set_app_status "{\"id\":\"$APP\",\"status\":1}"); check "v20: статус 1 принят" "r.get('application',{}).get('status')==1" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); check "v20: смена статуса пишет системную запись" "any('В работе' in c.get('text','') and 'Машина загрузилась' in c.get('text','') for c in r.get('comments',[]))" "$R"
R=$(post "$JI" "$TI" set_app_status "{\"id\":\"$APP\",\"status\":1}"); check "v20: повторный тот же статус — не ошибка" "r.get('success') is True" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); check "v20: повторный статус не плодит запись" "sum(1 for c in r.get('comments',[]) if 'Машина загрузилась' in c.get('text',''))==1" "$R"
R=$(post "$JP" "$TP" set_app_status "{\"id\":\"$APP\",\"status\":2}"); check "v20: чужой set_app_status → Заявка не найдена" "r.get('error')=='Заявка не найдена'" "$R"
R=$(upload "$JI" "$TI" -F app_id="$APP" -F text="запись в логе заявки" -F "files[]=@$TMP/t.png;filename=app.png" "$B?action=add_app_comment"); check "v20: add_app_comment с файлом" "r.get('success') is True" "$R"
R=$(upload "$JP" "$TP" -F app_id="$APP" -F text="чужая запись" "$B?action=add_app_comment"); check "v20: чужой add_app_comment → Заявка не найдена" "r.get('error')=='Заявка не найдена'" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); CMT=$(jget "$R" "[c['id'] for c in r['comments'] if c.get('text')=='запись в логе заявки'][0]"); AID=$(jget "$R" "[a['id'] for c in r['comments'] for a in c.get('attachments',[])][0]")
R=$(post "$JP" "$TP" edit_app_comment "{\"id\":\"$CMT\",\"text\":\"хайджек\"}"); check "v20: чужой edit_app_comment → Комментарий не найден" "r.get('error')=='Комментарий не найден'" "$R"
R=$(post "$JI" "$TI" edit_app_comment "{\"id\":\"$CMT\",\"text\":\"запись исправлена\"}"); check "v20: edit_app_comment правит свою" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" delete_attachment "{\"id\":$AID,\"kind\":\"app\"}"); check "v20: delete_attachment kind=app удаляет файл заявки" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" delete_app_comment "{\"id\":\"$CMT\"}"); check "v20: delete_app_comment удаляет свою" "r.get('success') is True" "$R"
R=$(get "$JI" "get_app_comments&id=$APP"); check "v20: удалённая запись исчезла из лога" "all(c.get('id')!='$CMT' for c in r.get('comments',[]))" "$R"
R=$(post "$JI" "$TI" delete_lead_app "{\"id\":\"$APP\"}"); check "v20: delete_lead_app удаляет" "r.get('success') is True" "$R"
R=$(get "$JI" "get_app&id=$APP"); check "v20: удалённая заявка не читается" "r.get('error')=='Заявка не найдена'" "$R"
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LAPP\",\"cityFrom\":\"А\",\"cityTo\":\"Б\",\"rate\":\"1 000\"}"); ACAS=$(jget "$R" "r['application']['id']")
upload "$JI" "$TI" -F app_id="$ACAS" -F text="лог каскада" -F "files[]=@$TMP/t.png;filename=cas.png" "$B?action=add_app_comment" >/dev/null
post "$JI" "$TI" delete_lead "{\"id\":\"$LAPP\"}" >/dev/null
R=$(get "$JA" integrity_check); check "v20: integrity_check без сирот лога заявок" "all(i[0] not in ('crm_app_comments','crm_app_attachments') for i in r.get('issues',[]))" "$R"

# --- §35 корзина: мягкое удаление и «Взять в работу» ---
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke Del","inn":"7701000001"}'); LDEL=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LDEL\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\"}"); APPDEL=$(jget "$R" "r['application']['id']")
R=$(post "$JI" "$TI" delete_lead "{\"id\":\"$LDEL\"}"); check "§35: удаление мягкое" "r.get('success') is True" "$R"
R=$(get "$JI" "get_data&hash=x"); check "§35: удалённый скрыт с доски" "all(l.get('id')!='$LDEL' for l in r.get('leads',[]))" "$R"
R=$(get "$JI" "get_lead&id=$LDEL"); check "§35: свой удалённый читается" "r.get('lead',{}).get('deleted') is True" "$R"
R=$(get "$JP" "get_lead&id=$LDEL"); check "§35: чужой удалённый читается любым" "r.get('lead',{}).get('deleted') is True and r.get('lead',{}).get('applications')==[]" "$R"
R=$(post "$JI" "$TI" save_lead "{\"id\":\"$LDEL\",\"title\":\"X\"}"); check "§35: правка удалённого закрыта" "r.get('error')=='Лид не найден'" "$R"
R=$(post "$JP" "$TP" restore_lead "{\"id\":\"$LDEL\"}"); check "§35: взятие в работу на этап Новый" "r.get('success') is True and r.get('stage')=='Новый'" "$R"
R=$(get "$JP" "get_lead&id=$LDEL"); check "§35: забранный лид рабочий, заявка цела" "r.get('lead',{}).get('deleted') is False and len(r.get('lead',{}).get('applications',[]))==1" "$R"
R=$(get "$JI" "get_lead&id=$LDEL"); check "§35: бывший владелец потерял доступ" "r.get('error')=='Лид не найден'" "$R"
post "$JA" "$TA" register_user "{\"name\":\"Смоук Третий\",\"email\":\"smoke-c@test.local\",\"password\":\"SmokePassC1\",\"role\":\"user\"}" >/dev/null
JC="$TMP/jc"; TC=$(login "$JC" "smoke-c@test.local" "SmokePassC1")
R=$(post "$JC" "$TC" save_stages '{"stages":["Первичный"]}'); check "§35: этапы без Нового сохраняются" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke Del3"}'); LDEL3=$(jget "$R" "r['id']")
post "$JI" "$TI" delete_lead "{\"id\":\"$LDEL3\"}" >/dev/null
R=$(post "$JC" "$TC" restore_lead "{\"id\":\"$LDEL3\"}"); check "§35: без Нового — на первый этап" "r.get('stage')=='Первичный'" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke Del4"}'); LDEL4=$(jget "$R" "r['id']")
post "$JI" "$TI" delete_lead "{\"id\":\"$LDEL4\"}" >/dev/null
R=$(post "$JA" "$TA" purge_lead "{\"id\":\"$LDEL4\"}"); check "§35: админ стирает из корзины" "r.get('success') is True" "$R"
R=$(get "$JI" "get_lead&id=$LDEL4"); check "§35: стёртый не читается" "r.get('error')=='Лид не найден'" "$R"
R=$(post "$JA" "$TA" purge_lead "{\"id\":\"$LA1\"}"); check "§35: purge активного запрещён" "r.get('error')=='Лид не найден в удалённых'" "$R"
R=$(post "$JI" "$TI" purge_lead "{\"id\":\"$LDEL3\"}"); check "§35: purge не-админу запрещён" "r.get('error')=='Нет прав'" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"Smoke Trash","inn":"7701000002"}'); LTRASH=$(jget "$R" "r['id']")
post "$JI" "$TI" delete_lead "{\"id\":\"$LTRASH\"}" >/dev/null
R=$(get "$JI" "search_leads&q=Trash&filter=deleted"); check "§36: корзина ищется фильтром" "any(c.get('title')=='Smoke Trash' and c.get('owner')=='$A_NAME' for c in r.get('leads',[]))" "$R"
R=$(get "$JI" "search_leads&filter=deleted"); check "§36: пустой запрос отдаёт корзину" "any(c.get('id')=='$LTRASH' for c in r.get('leads',[]))" "$R"
R=$(get "$JI" "search_leads&q=Trash"); check "§36: без фильтра удалённые скрыты" "all(c.get('id')!='$LTRASH' for c in r.get('leads',[]))" "$R"
R=$(get "$JI" "search_leads&q=7809&filter=mine"); check "§36: фильтр Мои режет пересечения" "r.get('intersections')==[] and r.get('leads')==[]" "$R"

# --- §37: ревью-фиксы (бэкенд-сторона F3/F4/F5/G1/G2/G6/G18) ----------------
# RFIX1: лог заявки замороженного лида — правка своим владельцем закрыта (F3)
R=$(post "$JI" "$TI" save_lead '{"title":"РФИКС корзина лог"}'); LRFX=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LRFX\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\"}"); ARFX=$(jget "$R" "r['application']['id']")
upload "$JI" "$TI" -F app_id="$ARFX" -F text="запись до корзины" "$B?action=add_app_comment" >/dev/null
R=$(get "$JI" "get_app_comments&id=$ARFX"); CRFX=$(jget "$R" "[c['id'] for c in r['comments'] if c.get('text')=='запись до корзины'][0]")
post "$JI" "$TI" delete_lead "{\"id\":\"$LRFX\"}" >/dev/null
R=$(post "$JI" "$TI" edit_app_comment "{\"id\":\"$CRFX\",\"text\":\"правка в корзине\"}"); check "§37: правка лога заявки в корзине закрыта" "r.get('error')=='Комментарий не найден'" "$R"
# RFIX2: ИНН перевозчика — сохранение, чтение, валидация (F5)
R=$(post "$JI" "$TI" save_direction '{"cityFrom":"ИННград","cityTo":"Тестбург"}'); DRFX=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_carrier "{\"directionId\":\"$DRFX\",\"name\":\"ИП РФИКС\",\"inn\":\"7701000001\"}"); KRFX=$(jget "$R" "r['id']")
R=$(get "$JI" "get_carrier&id=$KRFX"); check "§37: ИНН перевозчика читается карточкой" "r.get('carrier',{}).get('inn')=='7701000001'" "$R"
R=$(post "$JI" "$TI" save_carrier "{\"id\":\"$KRFX\",\"directionId\":\"$DRFX\",\"name\":\"ИП РФИКС\",\"inn\":\"123\"}"); check "§37: короткий ИНН перевозчика отклонён" "r.get('error')=='ИНН 10 или 12 цифр'" "$R"
# RFIX3: get_clients уважает ?as= (F4)
R=$(get "$JA" "get_clients&as=$UA"); check "§37: get_clients с ?as= видит чужое как своё" "any(c.get('mine') is True for c in r.get('clients',[]))" "$R"
# RFIX4: move удалённого закрыт (G1; save покрыт тестом §35 выше)
R=$(post "$JI" "$TI" save_lead '{"title":"РФИКС корзина мув"}'); LRFX2=$(jget "$R" "r['id']")
post "$JI" "$TI" delete_lead "{\"id\":\"$LRFX2\"}" >/dev/null
R=$(post "$JI" "$TI" move_lead "{\"id\":\"$LRFX2\",\"stage\":\"Новый\"}"); check "§37: move удалённого закрыт" "r.get('error')=='Лид не найден'" "$R"
# RFIX5: save_stages не трогает корзину (G2) — отдельный сотрудник, без побочек
post "$JA" "$TA" register_user "{\"name\":\"РФИКС\",\"email\":\"rfix@test.local\",\"password\":\"RfixPass11\",\"role\":\"user\"}" >/dev/null
JR="$TMP/jr"; TR=$(login "$JR" "rfix@test.local" "RfixPass11")
R=$(post "$JR" "$TR" save_lead '{"title":"РФИКС этап корзина"}'); LR5=$(jget "$R" "r['id']")
post "$JR" "$TR" delete_lead "{\"id\":\"$LR5\"}" >/dev/null
R=$(post "$JR" "$TR" save_lead '{"title":"РФИКС этап актив"}'); LR5A=$(jget "$R" "r['id']")
R=$(post "$JR" "$TR" save_stages '{"stages":["ТолькоОдин"]}'); check "§37: save_stages принял один этап" "r.get('success') is True" "$R"
R=$(get "$JR" "get_lead&id=$LR5A"); check "§37: активный переехал на уцелевший этап" "r.get('lead',{}).get('stage')=='ТолькоОдин'" "$R"
R=$(get "$JR" "get_lead&id=$LR5"); check "§37: удалённый держит старый этап" "r.get('lead',{}).get('stage')!='ТолькоОдин'" "$R"
# RFIX6: увольнение с передачей лидов обнуляет авторство обоих логов (G6)
post "$JA" "$TA" register_user "{\"name\":\"РФИКС Увол\",\"email\":\"rfixd@test.local\",\"password\":\"RfixPass22\",\"role\":\"user\"}" >/dev/null
JE="$TMP/je"; TE=$(login "$JE" "rfixd@test.local" "RfixPass22")
R=$(post "$JE" "$TE" save_lead '{"title":"РФИКС передача"}'); LRE=$(jget "$R" "r['id']")
upload "$JE" "$TE" -F lead_id="$LRE" -F text="лог лида увольняемого" "$B?action=add_comment" >/dev/null
R=$(post "$JE" "$TE" save_lead_app "{\"leadId\":\"$LRE\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\"}"); ARE=$(jget "$R" "r['application']['id']")
upload "$JE" "$TE" -F app_id="$ARE" -F text="лог заявки увольняемого" "$B?action=add_app_comment" >/dev/null
R=$(post "$JE" "$TE" save_lead '{"title":"РФИКС корзина передача"}'); LRED=$(jget "$R" "r['id']")
post "$JE" "$TE" delete_lead "{\"id\":\"$LRED\"}" >/dev/null
UE=$(jget "$(get "$JA" get_users)" "[u['id'] for u in r['users'] if u['email']=='rfixd@test.local'][0]")
R=$(post "$JA" "$TA" delete_user "{\"id\":$UE,\"transferTo\":$UA}"); check "§37: увольнение с передачей" "r.get('success') is True and r.get('transferred')==1" "$R"
R=$(get "$JI" "get_comments&id=$LRE"); check "§37: лог лида после передачи без dangling user_id" "any(c.get('text')=='лог лида увольняемого' and c.get('userId')==0 for c in r.get('comments',[]))" "$R"
R=$(get "$JI" "get_app_comments&id=$ARE"); check "§37: лог заявки после передачи без dangling user_id" "any(c.get('text')=='лог заявки увольняемого' and c.get('userId')==0 for c in r.get('comments',[]))" "$R"
R=$(get "$JA" "get_lead&id=$LRED"); check "§37: корзина увольняемого допуржена, а не передана" "r.get('error')=='Лид не найден'" "$R"
# RFIX7: ИНН с пробелами (13 символов) принимается везде (G18)
R=$(post "$JI" "$TI" save_lead '{"title":"РФИКС ИНН пробелы","inn":"7 701 000 001"}'); check "§37: ИНН лида с пробелами принят" "r.get('success') is True" "$R"
R=$(post "$JI" "$TI" save_lead '{"title":"РФИКС ИНН заявка"}'); LRFX7=$(jget "$R" "r['id']")
R=$(post "$JI" "$TI" save_lead_app "{\"leadId\":\"$LRFX7\",\"cityFrom\":\"Москва\",\"cityTo\":\"Уфа\",\"carrierInn\":\"7 701 000 001\"}"); check "§37: ИНН перевозчика заявки с пробелами принят" "r.get('success') is True" "$R"

# --- §38: пилюли в «Клиентах» (get_clients ?filter=) -------------------------
R=$(get "$JI" "get_clients&filter=mine"); check "§38: Мои — только свои" "r.get('success') is True and len(r.get('clients',[]))>0 and all(c.get('mine') is True for c in r.get('clients',[]))" "$R"
R=$(get "$JI" "get_clients&filter=deleted"); check "§38: Удалённые — корзина с владельцем" "any(c.get('id')=='$LTRASH' and c.get('deleted') is True and c.get('owner')=='$A_NAME' for c in r.get('clients',[]))" "$R"
R=$(get "$JI" "get_clients&filter=bogus"); check "§38: левый фильтр → Все" "r.get('success') is True and any(c.get('mine') is False for c in r.get('clients',[])) and any(c.get('mine') is True for c in r.get('clients',[]))" "$R"

# --- уборка ---------------------------------------------------------------
post "$JI" "$TI" delete_lead "{\"id\":\"$LADM\"}" >/dev/null
TP=$(login "$JP" "$B_EMAIL" "$B_PASS"); post "$JP" "$TP" delete_lead "{\"id\":\"$LA1\"}" >/dev/null; post "$JP" "$TP" delete_lead "{\"id\":\"$LB1\"}" >/dev/null

echo
if [ "$FAILS" -eq 0 ]; then echo "ALL PASSED"; else echo "$FAILS FAILED"; exit 1; fi
