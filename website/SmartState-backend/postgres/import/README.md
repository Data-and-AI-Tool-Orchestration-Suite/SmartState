# Fasting → SmartState v2 Data Import

Migrates the legacy fasting apps' MSSQL database (`myDB`) into a **fresh** SmartState v2
Postgres instance (Postgres 18, schema from `../init.sql`).

## Files

| File | Purpose |
|---|---|
| `export_fasting.ps1` | Run on/against the OLD MSSQL server. Exports each table to UTF-8 CSV under `.\csv\`. |
| `01_staging.sql` | Creates `fasting_staging` schema (all-text columns) and `\copy`-loads `./csv/*.csv`. |
| `02a_definitions.sql` | **Generated** file: inlines each fasting protocol JSON as a dollar-quoted literal into `fasting_staging.protocol_defs`. Regenerate with `build_definitions.ps1` / `build_definitions.sh`. |
| `02_transform.sql` | The transform: studies, protocol definitions, users/roles, participants (with Sleep folding), enrollments, messages, state_log, surveys, queued_messages. Single `BEGIN`/`COMMIT` with a sanity-count report at the end. |
| `import_fasting.sh` | Orchestrator: runs 01 → 02a → 02 inside the postgres container, prints the count report, then drops `fasting_staging` unless `KEEP_STAGING=1`. |
| `build_definitions.ps1` / `build_definitions.sh` | Regenerate `02a_definitions.sql` from `java/src/main/resources/protocols/fasting/*.json`. |

## Runbook

### 1. Export from the old MSSQL server

On a machine with `bcp` (SQL Server command-line utilities) and network access to the old server:

```powershell
cd website\SmartState-backend\postgres\import
.\export_fasting.ps1 -Server "OLD-SQL-HOST,1433" -User sa -Password '<SA_PASSWORD>' -Database myDB
```

This writes `.\csv\<table>.csv` for: `user_roles`, `users`, `protocol_types`, `participants`,
`enrollments`, `messages`, `state_log`, `queued_messages`, `surveys`.

How JSON survives the round trip: each table is exported with
`bcp "<SELECT>" queryout <file> -S <server> -U sa -P <pwd> -w -t <US> -r <RS>`
using the ASCII **unit separator (0x1F)** as field terminator and **record separator (0x1E)**
as row terminator — characters that cannot legally appear inside JSON/NVARCHAR data.
`-w` exports UTF-16, which the script re-encodes to UTF-8 (no BOM) and converts to
RFC-4180 CSV: every non-empty field is double-quoted with internal quotes doubled, so
embedded commas, quotes and newlines inside `*_json` columns are preserved. Empty fields
are left unquoted so `\copy ... NULL ''` reads them back as SQL NULL.

Note: `messages` can be large; the converter reads each table into memory. If the export
machine is memory-constrained, run it there anyway (it is a one-off) or split the messages
export by date range.

### 2. Regenerate `02a_definitions.sql` (only if protocol JSONs changed)

```powershell
.\build_definitions.ps1
```

or on a POSIX box with `jq`:

```sh
./build_definitions.sh
```

The generator reads every `*.json` in `java/src/main/resources/protocols/fasting/`, takes the
`name` field from each file, and inlines the file body between `$ssdef$ ... $ssdef$` markers
(tag auto-extended if the content ever contains it). The committed `02a_definitions.sql` was
generated from: `Baseline.hpm.v1.json`, `Baseline.v1.json`, `Control.v1.json`,
`DailyMessage.v1.json`, `Sleep.v1.json`, `SleepSurvey.v1.json`, `TRE.ccw-hpm.v1.json`,
`TRE.timed.v1.json`, `WeeklyMessage.v1.json`.

### 3. Copy this directory (with `csv/`) into the postgres container

```sh
docker cp website/SmartState-backend/postgres/import <postgres-container>:/import
```

or bind-mount it in the compose file:

```yaml
    volumes:
      - ./SmartState-backend/postgres/import:/import
```

### 4. Run the import inside the container

```sh
docker exec -it <postgres-container> sh /import/import_fasting.sh
```

The script uses `$POSTGRES_USER` / `$POSTGRES_DB` (falling back to `$PGUSER` / `$PGDATABASE`,
then `postgres`). It runs:

1. `01_staging.sql` — staging schema + CSV load (prints staging row counts),
2. `02a_definitions.sql` — protocol definition JSONs into staging,
3. `02_transform.sql` — the whole transform in one transaction, ending with two sanity
   reports: staging-vs-target row counts per entity, and a fold/skip detail table.

To keep the staging schema for post-import spot checks:

```sh
docker exec -e KEEP_STAGING=1 -it <postgres-container> sh /import/import_fasting.sh
```

then later: `DROP SCHEMA fasting_staging CASCADE;`

### 5. Verify

- Compare the sanity report's `staging_rows` vs `target_rows`. Differences must be explained
  by the fold/skip detail table (sentinel messages, folded sleep participants, past queued
  messages, enrollments without a matching definition, unmapped ADMIN-study rows).
- `state_log_rows_without_enrollment_link` should be small; those rows are still fully
  preserved (verbatim `log_json`), just not linked to an active enrollment.
- Log into SmartState, confirm the studies **HPM, CCW, SEC, TIMED** (and
  **Fasting Sleep (unmatched)** if any sleep participants failed to fold) appear, spot-check a
  few participants' message history and TRE adherence data.

## Design decisions

### Studies

Fixed, deterministic study UUIDs so re-runs and cross-references are stable:

| Study | UUID |
|---|---|
| HPM | `10000000-0000-0000-0000-000000000001` |
| CCW | `10000000-0000-0000-0000-000000000002` |
| SEC | `10000000-0000-0000-0000-000000000003` |
| TIMED | `10000000-0000-0000-0000-000000000004` |
| Fasting Sleep (unmatched) | `10000000-0000-0000-0000-000000000005` |

The legacy study string `T2D` (stale predecessor of TIMED) is mapped to the TIMED study.
The legacy `ADMIN` sentinel study is **not** created (see sentinel handling below).

### Sleep / SleepSurvey folding

The legacy system modeled the Sleep sub-study as *separate participant rows* under study
strings `Sleep` / `SleepSurvey`, duplicating people who were really CCW or SEC participants.
SmartState v2 keeps one participant per person per study, so:

- A `Sleep`/`SleepSurvey` participant whose `participant_json->>'number'` (phone) exactly
  matches a `CCW` or `SEC` participant's phone is **folded into that parent participant**:
  no new participant row is created; all of their messages, enrollments, state_log, surveys
  and queued messages are re-pointed at the parent participant and the parent's study.
- If a phone matches more than one parent (e.g. present in both CCW and SEC), the match is
  chosen deterministically (`ORDER BY study, participant_uuid` — CCW wins over SEC) and the
  case is counted in the report as `sleep_participants_ambiguous_phone_match`. Review these
  manually if the count is non-zero.
- A `Sleep`/`SleepSurvey` participant with no phone or no matching parent keeps their own
  UUID and becomes a participant of the dedicated **Fasting Sleep (unmatched)** study, which
  is seeded with the `Sleep` and `SleepSurvey` protocol definitions so their enrollments
  still resolve.

Consequence: messages/enrollments recorded under study string `Sleep`/`SleepSurvey` for a
folded participant now appear under the parent study (CCW/SEC) — that is the intended
"folds INTO the participant's parent study" behavior.

### Protocol definitions

One v1 `published` definition per (study, protocol name), seeded from the JSON files:

| File | Seeded into |
|---|---|
| `Baseline.hpm.v1.json` (name `Baseline`) | HPM |
| `Baseline.v1.json` (name `Baseline`) | CCW, SEC, TIMED |
| `Control.v1.json` | HPM, CCW, SEC, TIMED |
| `DailyMessage.v1.json` | HPM, CCW, SEC, TIMED |
| `WeeklyMessage.v1.json` | HPM, CCW, SEC, TIMED |
| `TRE.ccw-hpm.v1.json` (name `TRE`) | HPM, CCW |
| `TRE.timed.v1.json` (name `TRE`) | TIMED |
| `Sleep.v1.json` | CCW, SEC, Fasting Sleep (unmatched) |
| `SleepSurvey.v1.json` | CCW, SEC, Fasting Sleep (unmatched) |

Legacy enrollments are joined `enrollments → protocol_types(study, name) → participant's
target study → protocol_definitions(name, v1, published)`. Enrollments whose (study, name)
has no seeded definition are skipped and counted
(`enrollments_skipped_no_matching_definition`).

### Enrollments

- Original `enrollment_uuid` preserved.
- `enrolled_at` = earliest legacy `state_log` timestamp for that participant *and protocol*
  (via `log_json->>'protocol'`), falling back to the participant's earliest state_log row of
  any protocol, falling back to `now()`.
- `status = 1` (active) → `ended_at NULL`; `status = 0` → `ended_at = now()`,
  `end_reason = 'imported-inactive'`.
- Because folding can merge two legacy participants, a merged participant could end up with
  two *active* enrollments of the same protocol name, which the target's partial unique index
  forbids. Only the first (lowest `enrollment_uuid`) stays active; the rest are closed with
  `end_reason = 'imported-duplicate'` (counted in the report).

### Sentinel admin participant (`00000000-0000-0000-0000-000000000000`)

The legacy system logged staff/admin-directed SMS traffic against a single sentinel
participant in the `ADMIN` study. SmartState v2 requires every message to belong to a real
participant in a real study, so the transform creates **one hidden "Study Staff" participant
per study** (`a0000000-0000-0000-0000-00000000000{1..4}` for HPM/CCW/SEC/TIMED,
`participant_json = {"first_name":"Study","last_name":"Staff","hidden":true}`). Each
sentinel message is mapped to the staff participant of the study named in the message's own
`study` column. Sentinel messages whose study string cannot be mapped (e.g. `ADMIN`,
`Sleep`) are skipped and counted (`messages_skipped_sentinel_unmappable_study`).

### Messages, state_log, surveys, queued_messages

- **messages**: UUID, `ts`, `message_direction` and the full `message_json` (including
  `isAfter8pm`, `wakeBeforeSleep`, `hidden` flags) preserved verbatim; `study_uuid` comes
  from the participant's post-fold study.
- **state_log**: `log_json` preserved **verbatim** — it carries the TRE adherence dataset
  (`successful_TRE`, `total_TRE`, `after8pm`, `eating_window_duration`, `start_cal_time`,
  `end_cal_time`, `sleep_time`, `wake_time`). `enrollment_uuid` is linked to the
  participant's *active* enrollment whose `protocol_name` equals `log_json->>'protocol'`
  when resolvable, otherwise left NULL (data still fully queryable by participant/study/ts).
- **surveys**: `survey_name` → `survey_key`; study resolved via the (post-fold) participant;
  `enrollment_uuid` and `questions_json` left NULL (the legacy schema had no reliable link).
- **queued_messages**: only rows with `scheduledFor > now()` are migrated; stale queue rows
  are dead letters and are dropped (counted in the report).

### Users and roles

- `linkblue` → `eppn` and `id` = `lower(linkblue) || '@uky.edu'`.
- Legacy role `0` (Admin) → `is_super_admin = TRUE`.
- Legacy role `2` (PI) → SmartState role **4 (PI)**; role `1` (non-PHI) → **5 (Non-PHI)**;
  role `3` (User) → **3 (User)** — each granted via `user_study_roles` across all four
  studies (HPM, CCW, SEC, TIMED). The transform defensively inserts role rows
  `(4,'PI')` and `(5,'Non-PHI')` with `ON CONFLICT DO NOTHING` in case the app migration
  that introduces them has not run yet.
- Users with any other legacy role value get a `users` row but no study roles.

## Caveats — data intentionally NOT migrated

- **`save_state` is skipped entirely.** Serialized machine state from the old engine is not
  portable; the new engine re-anchors every restored enrollment via each protocol
  definition's `restore` policy (`same-day-else-reset`, `recompute-from-clock`, etc.) on
  first tick. No participant-visible data lives in `save_state`.
- **`user_sessions` are dropped.** Everyone re-authenticates.
- **`p_hash` (passwords) is dropped.** Researchers log in via CILogon; `user_study_roles`
  rows ARE pre-created keyed on `eppn` (`<linkblue>@uky.edu`). If the backend keys new
  CILogon logins on a different `users.id` than the eppn, a super admin may need to merge
  the migrated user row / re-grant roles after each researcher's first login.
- **`time_zones`** is not migrated — the target schema seeds its own copy.
- **ADMIN-study participants** other than the sentinel (if any exist) and all their rows are
  skipped and surface in the `*_skipped_unmapped_participant` report counters.
- **Past queued messages** (`scheduledFor <= now()`) are dropped.
- **Sentinel messages with unmappable study strings** are dropped (counted).
- The `group` array inside `participant_json` is dropped; only `first_name`, `last_name`,
  `number`, `time_zone`, `hidden` are kept.
- Legacy control characters 0x1E/0x1F inside NVARCHAR data would corrupt the export framing;
  they are illegal in JSON strings and are not expected in this dataset.
