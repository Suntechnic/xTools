#!/bin/bash
set -eu

# Уведомляем только о завершении бэкапов.
[[ "${DUPLICATI__EVENTNAME:-}" == "AFTER" ]] || exit 0
[[ "${DUPLICATI__OPERATIONNAME:-}" == "Backup" ]] || exit 0

name="${DUPLICATI__backup_name:-Без имени}"
result="${DUPLICATI__PARSED_RESULT:-Unknown}"

case "$result" in
  Success)     status="✅ Бэкап успешно завершён" ;;
  Warning)     status="⚠️ Бэкап завершён с предупреждениями" ;;
  Error|Fatal) status="❌ Ошибка бэкапа" ;;
  *)           status="❓ Результат бэкапа неизвестен" ;;
esac

message=$(printf '%s\nСервер: %s\nЗадание: %s\nРезультат: %s' \
  "$status" "$(hostname)" "$name" "$result")

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
notify_script="$script_dir/notify_telegram.php"

if [[ ! -r "$notify_script" ]]; then
  echo "LOG:WARN Не найден notify_telegram.php рядом с обёрткой"
  exit 1
fi

if /usr/bin/timeout 30s /usr/bin/php "$notify_script" "$message"; then
  exit 0
else
  rc=$?
  echo "LOG:WARN Отправка уведомления завершилась с кодом $rc"
  exit "$rc"
fi