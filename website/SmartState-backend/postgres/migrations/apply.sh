#!/bin/sh
set -eu
for f in /opt/smartstate/migrations/[0-9]*.sql; do
  [ -e "$f" ] || continue
  name=$(basename "$f")
  if ! psql -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
       -c "SELECT 1 FROM schema_migrations WHERE filename='$name'" | grep -q 1; then
    echo "Applying $name"
    psql -v ON_ERROR_STOP=1 --single-transaction -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f "$f"
    psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
      -c "INSERT INTO schema_migrations(filename) VALUES ('$name')"
  fi
done
