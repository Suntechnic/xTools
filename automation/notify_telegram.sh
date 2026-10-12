#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
INI_PATH="$SCRIPT_DIR/tg.ini"

read_ini_setting() {
    local section="$1"
    local key="$2"

    awk -v target_section="$section" -v target_key="$key" '
        function trim(value) {
            sub(/^[[:space:]]+/, "", value)
            sub(/[[:space:]]+$/, "", value)
            return value
        }
        {
            line = $0
            sub(/\r$/, "", line)
            line = trim(line)

            if (line ~ /^\[[^]]+\]$/) {
                current_section = line
                sub(/^\[/, "", current_section)
                sub(/\]$/, "", current_section)
                next
            }

            separator = index(line, "=")
            if (!separator || trim(substr(line, 1, separator - 1)) != target_key) next

            value = trim(substr(line, separator + 1))
            quote = substr(value, 1, 1)
            if (quote == "\"" || quote == "\047") {
                parsed = ""
                escaped = 0
                for (position = 2; position <= length(value); position++) {
                    character = substr(value, position, 1)
                    if (escaped) {
                        parsed = parsed character
                        escaped = 0
                    } else if (character == "\\") {
                        escaped = 1
                    } else if (character == quote) {
                        break
                    } else {
                        parsed = parsed character
                    }
                }
                value = parsed
            } else {
                sub(/[;#].*$/, "", value)
                value = trim(value)
            }

            if (current_section == target_section) section_value = value
            else if (current_section == "") root_value = value
        }
        END {
            if (section_value != "") print section_value
            else print root_value
        }
    ' "$INI_PATH"
}

trim_text() {
    local value="$1"
    value="${value#"${value%%[!$' \t\n\r\v\f']*}"}"
    value="${value%"${value##*[!$' \t\n\r\v\f']}"}"
    printf '%s' "$value"
}

if [[ ! -f "$INI_PATH" ]]; then
    printf 'Файл настроек не найден: %s\n' "$INI_PATH" >&2
    exit 1
fi

for dependency in curl jq; do
    if ! command -v "$dependency" >/dev/null 2>&1; then
        printf 'Не найдена обязательная утилита: %s\n' "$dependency" >&2
        exit 1
    fi
done

TOKEN="$(read_ini_setting telegram bot_token)"
[[ -n "$TOKEN" ]] || TOKEN="$(read_ini_setting telegram token)"
CHAT_IDS_RAW="$(read_ini_setting telegram chat_ids)"
[[ -n "$CHAT_IDS_RAW" ]] || CHAT_IDS_RAW="$(read_ini_setting telegram chat_id)"
PROXY="$(read_ini_setting telegram proxy)"
PROXY_USER="$(read_ini_setting telegram proxy_user)"
PROXY_PASS="$(read_ini_setting telegram proxy_pass)"

if [[ -z "$TOKEN" ]]; then
    printf 'В tg.ini не найден token/bot_token.\n' >&2
    exit 1
fi

CHAT_IDS=()
IFS=', ' read -r -a CHAT_IDS <<< "$CHAT_IDS_RAW"
if [[ ${#CHAT_IDS[@]} -eq 0 || -z "${CHAT_IDS[0]:-}" ]]; then
    printf 'В tg.ini не найден chat_id/chat_ids.\n' >&2
    exit 1
fi

MESSAGE_TEXT="$(trim_text "${1:-}")"
if [[ -z "$MESSAGE_TEXT" ]]; then
    printf 'Список коммитов пустой, уведомление не отправлено.\n'
    exit 0
fi

MESSAGE_TEXT="$(printf '%s' "$MESSAGE_TEXT" | jq -Rs 'if length > 4000 then .[:3950] + "\n... (сообщение обрезано)" else . end')"

SEND_ERRORS=()
for CHAT_ID in "${CHAT_IDS[@]}"; do
    [[ -n "$CHAT_ID" ]] || continue

    CURL_ARGS=(--silent --show-error --max-time 15 --write-out $'\n%{http_code}')
    if [[ -n "$PROXY" ]]; then
        [[ "$PROXY" == tcp://* ]] && PROXY="http://${PROXY#tcp://}"
        CURL_ARGS+=(--proxy "$PROXY")
        if [[ -n "$PROXY_USER" && -n "$PROXY_PASS" ]]; then
            CURL_ARGS+=(--proxy-user "$PROXY_USER:$PROXY_PASS")
        fi
    fi

    if RESPONSE="$(curl "${CURL_ARGS[@]}" \
        --data-urlencode "chat_id=$CHAT_ID" \
        --data-urlencode "text=$MESSAGE_TEXT" \
        --data-urlencode 'disable_web_page_preview=true' \
        "https://api.telegram.org/bot${TOKEN}/sendMessage" 2>&1)"; then
        HTTP_STATUS="${RESPONSE##*$'\n'}"
        RESPONSE_BODY="${RESPONSE%$'\n'*}"
        if [[ "$HTTP_STATUS" =~ ^2[0-9][0-9]$ ]] && jq -e '.ok == true' >/dev/null 2>&1 <<< "$RESPONSE_BODY"; then
            continue
        fi
        DETAILS="$(jq -r '.description // empty' <<< "$RESPONSE_BODY" 2>/dev/null || true)"
        [[ -n "$DETAILS" ]] || DETAILS="$RESPONSE_BODY"
        SEND_ERRORS+=("chat_id $CHAT_ID: Telegram API вернул ошибку.")
        [[ "$HTTP_STATUS" =~ ^[1-9][0-9][0-9]$ ]] && SEND_ERRORS+=("HTTP status: $HTTP_STATUS")
        [[ -n "$DETAILS" ]] && SEND_ERRORS+=("Детали: $DETAILS")
    else
        SEND_ERRORS+=("chat_id $CHAT_ID: Не удалось выполнить HTTP-запрос к Telegram API.")
        [[ -n "$RESPONSE" ]] && SEND_ERRORS+=("Детали: $RESPONSE")
    fi
done

if [[ ${#SEND_ERRORS[@]} -gt 0 ]]; then
    printf '%s\n' "${SEND_ERRORS[@]}" >&2
    exit 1
fi

printf 'Уведомление в Telegram успешно отправлено в %s чат(а/ов).\n' "${#CHAT_IDS[@]}"