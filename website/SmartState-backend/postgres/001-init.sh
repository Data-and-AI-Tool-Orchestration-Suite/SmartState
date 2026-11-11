#!/bin/sh
set -eu
: "${ADMIN_EPPN:?ADMIN_EPPN must be set in .env}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD must be set in .env}"
: "${ENGINE_DB_PASSWORD:?ENGINE_DB_PASSWORD must be set in .env}"
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -v admin_eppn="$ADMIN_EPPN" \
    -v app_db_password="$APP_DB_PASSWORD" \
    -v engine_db_password="$ENGINE_DB_PASSWORD" \
    -f /opt/smartstate/init.sql.template
