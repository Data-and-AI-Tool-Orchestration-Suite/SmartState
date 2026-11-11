#!/bin/sh
set -eu
: "${ADMIN_EPPN:?ADMIN_EPPN must be set in .env}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD must be set in .env}"
: "${ENGINE_DB_PASSWORD:?ENGINE_DB_PASSWORD must be set in .env}"
sed -e "s/<ADMIN_EPPN>/${ADMIN_EPPN}/g" \
    -e "s/<APP_DB_PASSWORD>/${APP_DB_PASSWORD}/g" \
    -e "s/<ENGINE_DB_PASSWORD>/${ENGINE_DB_PASSWORD}/g" \
    /opt/smartstate/init.sql.template \
  | psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"
