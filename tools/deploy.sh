#!/usr/bin/env bash
# Деплой CRM «Детроид» на хостинг по FTP/SFTP (lftp) или в локальный каталог (rsync).
#
# Заливает ТОЛЬКО файлы приложения (список ниже — единый источник правды для
# «Обновления работающего сайта» из README). Никогда не трогает: config.php,
# uploads/, data/ — живые данные сайта.
#
# Использование:
#   tools/deploy.sh --dry-run                # показать, что будет залито (нужен DEPLOY_URL)
#   tools/deploy.sh                          # залить на хостинг
#   tools/deploy.sh --to /path/to/dir        # «залить» в локальный каталог (rsync)
#
# Настройка (переменные окружения или файл .deploy.env рядом со скриптом — в git не хранится):
#   DEPLOY_URL   например ftp://user@ftp.host.ru/public_html или sftp://user@host/path
#   DEPLOY_PASS  пароль FTP/SFTP (или настройте ~/.netrc и оставьте пустым)
set -euo pipefail

cd "$(dirname "$0")/.."

# Единый список заливаемого (папки — целиком)
FILES=(
  api.php db.php http.php security.php files.php migrations.php
  index.html ui.html app.css noscript.css icon.svg robots.txt .htaccess
)
DIRS=(actions js)

DRY_RUN=0
LOCAL_TO=""
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --to) LOCAL_TO="${2:?--to требует путь}"; shift ;;
    *) echo "Неизвестный аргумент: $1" >&2; exit 2 ;;
  esac
  shift
done

# Перед заливкой — те же проверки, что в CI (ловим сломанный синтаксис до сайта)
if command -v php >/dev/null 2>&1; then
  for f in api.php db.php http.php security.php files.php migrations.php actions/*.php; do
    php -l "$f" >/dev/null
  done
  echo "php -l: OK"
else
  echo "ВНИМАНИЕ: php не найден локально, синтаксис не проверен" >&2
fi
if command -v node >/dev/null 2>&1; then
  for f in js/*.js; do node --check "$f"; done
  echo "node --check: OK"
fi

# --- Локальный режим (rsync в каталог) ----------------------------------------
if [ -n "$LOCAL_TO" ]; then
  RSYNC_OPTS=(-rv --checksum)
  [ "$DRY_RUN" = "1" ] && RSYNC_OPTS+=(--dry-run)
  # Файлы корня — без --delete: там же живут config.php/uploads/data, их сносить нельзя.
  # Каталоги приложения — с --delete: снятые с деплоя файлы иначе вечно лежат на сайте (п. 6.2).
  rsync "${RSYNC_OPTS[@]}" "${FILES[@]}" "$LOCAL_TO/"
  for d in "${DIRS[@]}"; do
    rsync "${RSYNC_OPTS[@]}" --delete "$d/" "$LOCAL_TO/$d/"
  done
  echo "Готово: залито в $LOCAL_TO"
  exit 0
fi

# --- FTP/SFTP через lftp --------------------------------------------------------
[ -f tools/.deploy.env ] && . tools/.deploy.env
: "${DEPLOY_URL:?Задайте DEPLOY_URL (ftp://user@host/path) в окружении или tools/.deploy.env}"

if ! command -v lftp >/dev/null 2>&1; then
  echo "Нужен lftp: apt install lftp / brew install lftp" >&2
  exit 1
fi

LFTP_CMDS="set cmd:fail-exit yes; set ssl:verify-certificate yes;"
for f in "${FILES[@]}"; do
  LFTP_CMDS+=" put -O . '$f';"
done
for d in "${DIRS[@]}"; do
  # --delete: зеркало точное, снятые файлы удаляются (п. 6.2). Безопасно: внутри каталогов
  # приложения чужих файлов нет — config.php/uploads/data лежат в корне, их mirror не трогает.
  LFTP_CMDS+=" mirror -R --only-newer --delete --no-perms '$d' '$d';"
done

if [ "$DRY_RUN" = "1" ]; then
  echo "Будут залиты файлы: ${FILES[*]}"
  echo "Будут залиты каталоги: ${DIRS[*]}"
  echo "Назначение: $DEPLOY_URL"
  exit 0
fi

echo "Деплой на $DEPLOY_URL ..."
lftp -u "$(echo "$DEPLOY_URL" | sed -E 's#^[a-z]+://([^@/]+)@.*#\1#'),${DEPLOY_PASS:-}" \
  "$(echo "$DEPLOY_URL" | sed -E 's#^([a-z]+://)[^@/]+@#\1#')" \
  -e "$LFTP_CMDS bye"

echo "Готово. Откройте CRM и проверьте вход: миграции схемы применятся первым запросом."
echo "Напоминание: перед крупными обновлениями снимите бэкап (php tools/backup.php)."
