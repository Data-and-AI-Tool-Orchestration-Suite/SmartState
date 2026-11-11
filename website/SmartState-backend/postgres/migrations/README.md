# Migrations

`init.sql` is the complete schema for a fresh install and runs automatically the first
time the postgres container starts with an empty volume.

Once a deployment carries data you cannot recreate, every schema change ships as a
numbered file here (`002_add_x.sql`, `003_...`) and is ALSO folded into `init.sql` so
fresh installs stay equivalent. Applied files are recorded in `schema_migrations`.

Apply pending migrations against a running deployment:

```sh
docker compose exec postgres sh /opt/smartstate/migrations/apply.sh
```
