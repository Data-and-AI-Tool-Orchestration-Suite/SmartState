#!/bin/sh
set -eu

cd "$(dirname "$0")"

DB_USER="${POSTGRES_USER:-${PGUSER:-postgres}}"
DB_NAME="${POSTGRES_DB:-${PGDATABASE:-postgres}}"
PSQL="psql -v ON_ERROR_STOP=1 -U $DB_USER -d $DB_NAME"

echo "Importing fasting data into database '$DB_NAME' as user '$DB_USER'"

echo "Step 1/3: loading staging schema and CSVs"
$PSQL -f 01_staging.sql

echo "Step 2/3: loading protocol definitions"
$PSQL -f 02a_definitions.sql

echo "Step 3/3: running transform (single transaction)"
$PSQL -f 02_transform.sql

if [ "${KEEP_STAGING:-0}" = "1" ]; then
    echo "KEEP_STAGING=1 set: leaving fasting_staging schema in place"
else
    echo "Dropping fasting_staging schema"
    $PSQL -c "DROP SCHEMA IF EXISTS fasting_staging CASCADE;"
fi

echo "Import complete"
