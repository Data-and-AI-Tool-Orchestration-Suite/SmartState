#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROTOCOL_DIR="${1:-$SCRIPT_DIR/../../../../java/src/main/resources/protocols/fasting}"
OUT_FILE="${2:-$SCRIPT_DIR/02a_definitions.sql}"

command -v jq >/dev/null 2>&1 || { echo "jq is required" >&2; exit 1; }

{
    echo '\echo Loading fasting protocol definitions into staging'
    echo ''
    echo 'CREATE TABLE IF NOT EXISTS fasting_staging.protocol_defs('
    echo '    file_name TEXT PRIMARY KEY,'
    echo '    def_name TEXT NOT NULL,'
    echo '    definition JSONB NOT NULL'
    echo ');'
    echo ''
    echo 'DELETE FROM fasting_staging.protocol_defs;'
    echo ''
    for f in "$PROTOCOL_DIR"/*.json; do
        base=$(basename "$f")
        name=$(jq -r '.name' "$f")
        if [ -z "$name" ] || [ "$name" = "null" ]; then
            echo "Missing name field in $base" >&2
            exit 1
        fi
        tag='ssdef'
        while grep -q "\$$tag\$" "$f"; do tag="${tag}x"; done
        echo "INSERT INTO fasting_staging.protocol_defs (file_name, def_name, definition) VALUES"
        echo "('$base', '$name', \$$tag\$"
        cat "$f"
        echo "\$$tag\$::jsonb);"
        echo ''
        echo "Added $base as '$name'" >&2
    done
    echo 'SELECT file_name, def_name FROM fasting_staging.protocol_defs ORDER BY file_name;'
} > "$OUT_FILE"

echo "Wrote $OUT_FILE" >&2
