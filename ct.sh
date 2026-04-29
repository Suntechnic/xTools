#!/bin/bash
# Использование: .dev/tools/ct.sh bitrix:catalog.element my-template
# Например:
# .dev/tools/ct.sh bitrix:system.pagenavigation


COMPONENT=$1
TEMPLATE=${2:-.default}

SRC="bitrix/components/${COMPONENT/:/\/}/templates/.default"
DST="local/templates/.default/components/${COMPONENT/:/\/}/${TEMPLATE}"

if [ ! -d "$SRC" ]; then
    echo "❌ Шаблон не найден: $SRC"
    exit 1
fi

if [ -d "$DST" ]; then
    read -p "⚠️  Папка $DST уже существует. Перезаписать? (y/n): " CONFIRM
    [ "$CONFIRM" != "y" ] && exit 0
fi

mkdir -p "$DST"
cp -r "$SRC/." "$DST/"
echo "✅ Скопировано в $DST"
