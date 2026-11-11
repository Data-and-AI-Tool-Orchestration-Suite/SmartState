# SmartState

The canonical public repository is [Data-and-AI-Tool-Orchestration-Suite/SmartState](https://github.com/Data-and-AI-Tool-Orchestration-Suite/SmartState).
Develop and submit changes through branches and pull requests in this repository.

## Overview
Developing and enforcing study protocols is crucial in medical research, especially as interactions with participants
become more intricate. Traditional rules-based systems struggle to provide the automation and flexibility required for
real-time, personalized data collection. We introduce SmartState, a state-based system designed to act as a personal
agent for each participant, continuously managing and tracking their unique interactions. Unlike traditional reporting
systems, SmartState enables real-time, automated data collection with minimal oversight. By integrating large language
models to distill conversations into structured data, SmartState reduces errors and safeguards data integrity through
built-in protocol and participant auditing.

Read the paper: [SmartState: An Automated Research Protocol Adherence System](https://arxiv.org/abs/2305.04411) ([PubMed](https://pubmed.ncbi.nlm.nih.gov/40502224/)).

SmartState is a **single multi-tenant platform**: one deployment hosts many studies, each walled off from the others.
Researchers design, publish, and version their own protocols from the web UI — no code, no redeploys.

---
## Architecture

```
                      ┌────────────┐
 participant SMS ───► │   Twilio   │ ──► POST /api/incoming ─┐
                      └────────────┘                         │
                                                             ▼
 browser ──► apache ──► php-fpm (website) ──► postgres ◄── engine (Java 25)
                │                                ▲            │
                └── /api/incoming|message|check ─┘────────────┘
                    proxied to the engine                └──► LLM API (OpenAI-compatible)
```

- **Website** (PHP 8.5, AltoRouter MVC): researcher dashboard, participant/protocol/message management,
  the protocol builder, and tokenized participant surveys. Connects to Postgres as `smartstate_app`,
  which is subject to row-level security.
- **Engine** (Java 25): a generic finite-state-machine interpreter. It polls `enrollments`, instantiates one
  machine per active enrollment from the pinned `protocol_definitions` row, runs timers/messages/LLM
  data collection, and writes the audit trail (`state_log`, `messages`, `save_state`). Connects as
  `smartstate_engine` (BYPASSRLS service role with narrowed table grants — it cannot read researcher
  accounts or sessions).
- **Postgres 18**: schema + row-level security policies enforce the tenant walls at the database layer,
  in addition to app-level scoping.
- **Apache** proxies only the engine's public webhook surface (`/api/incoming`, `/api/message`,
  `/api/check`); all researcher-triggered engine calls go server-side from PHP to `http://engine:9000`.

## Multi-tenancy model

- A **study** is the tenant. Every tenant table carries a `study_uuid` foreign key.
- Users hold **per-study roles** (`user_study_roles`: User, Study Admin) plus a global
  `is_super_admin` flag. Route guards check the role in the currently selected study
  (switcher in the top navigation).
- Enforcement is two-walled: every query is study-scoped in application code, and Postgres RLS
  (GUCs `app.user_id` / `app.is_super_admin` / `app.survey_token` set per request) is the backstop.
- Study Admins can receive SMS alerts: they get a staff participant row in their study
  (`participant_json.staff = "true"`), which the engine's `notifyAdmins` action targets.

## Protocols

A protocol is a versioned JSONB document (`protocol_definitions`) describing a finite state machine:
states, entry actions (templated SMS, admin notification, timers with deadline expressions, survey
token generation, LLM structured questions, device-window results), event/timed/auto transitions,
manual-move allowances for the audit screen, restore policy, and optional web survey question sets.

Lifecycle: **draft → published → archived**. Published versions are immutable; edits happen on a clone
draft at `version + 1`. Enrollments pin the exact published version at enroll time; the
**Migrate Participants** screen moves selected enrollments to a newer version (the old enrollment is
ended with `end_reason = 'version_migration'` — nothing is deleted, the audit trail stays intact).

The builder validates definitions against the engine (`POST /api/definitions/validate`) before
publishing and renders a live GraphViz diagram from the JSON. `POST /api/simulate` dry-runs a
definition on a virtual clock and returns the state/message trace.

Two reference protocols ship seeded in the `Default` study: **ReadGlucose** (device-driven glucose
reading episodes over LoRaWAN/webhook) and **Survey** (twice-daily LLM-distilled check-ins) — the same
protocols previously hand-written in Umple/Java, now expressed as data
(`java/src/main/resources/protocols/*.v1.json`).

## Surveys & messaging

- Participants never log in. They interact over **Twilio SMS** and unguessable tokenized survey links.
- Survey pages render the question snapshot (`surveys.questions_json`) captured when the token was
  generated, so responses stay interpretable across protocol versions.
- Outbound SMS uses the study's `twilio_from_number` (Studies screen) with a global fallback from
  `config.php`. Scheduled messages are queued in `queued_messages` and delivered by the engine.
- With `DISABLE_MESSAGING=true` every message is logged to the `messages` audit table but not sent.

---
## Requirements
1. **Docker & Docker Compose** — the whole stack runs in containers.
2. **Twilio Account** — for live SMS (optional in development; leave `DISABLE_MESSAGING=true`).
3. **CILogon client** — for researcher login (register your redirect URI `https://<host>/callback`).
4. **LLM API access** — any OpenAI-compatible chat/completions endpoint.

## Quick start

1. Copy `.env.example` to `.env` and fill in the values (`ADMIN_EPPN` is your login EPPN;
   `APP_DB_PASSWORD` / `ENGINE_DB_PASSWORD` are for the two database roles created at init).
2. Copy `website/SmartState-frontend/config.php.example` to `config.php` and fill in the values
   (Postgres user is `smartstate_app` with `APP_DB_PASSWORD`; CILogon credentials; `java_api_url`
   stays `http://engine:9000`).
3. Start everything:
   ```sh
   docker compose up -d --build
   ```
4. Install PHP dependencies:
   ```sh
   docker compose exec php composer install -d /var/www/html
   ```
5. Open `http://localhost:8080/`, log in via CILogon as the `ADMIN_EPPN` user, and create your first
   study from **Studies** (or use the seeded `Default` study).

The engine builds from `java/` inside compose — no local Maven or JDK needed. For engine-only
development: `cd java && ./mvnw package` (requires JDK 25).

## Configuration reference

| File | Purpose |
|---|---|
| `.env` | Compose-level settings: ports, Postgres credentials, role passwords, `ADMIN_EPPN`, and every engine key (passed to the container as `HLQ_<key>` environment variables — Twilio, LLM, survey URL, watcher timings). |
| `website/SmartState-frontend/config.php` | Website settings: Postgres (`smartstate_app`), CILogon OAuth2, session lifetime, Twilio fallback credentials, `java_api_url`. |
| `java/config.ini` | Optional — only for running the engine outside Docker. Every key can be supplied as `HLQ_<key>` env var instead. |

## Database & migrations

### Upgrading from legacy SmartState

SmartState v2 replaces the legacy protocol engine and database schema. Back up an existing v1
database before upgrading, and migrate its data into a separate v2 database; do not attach an
existing v1 database volume to the v2 services unchanged. The numbered migrations below are
incremental v2 updates, not a general v1-to-v2 migration. Recreate the configuration files from
the current examples, including the separate application and engine database credentials.

### SmartState v2 schema updates

`website/SmartState-backend/postgres/init.sql` is the complete schema and runs automatically on a
fresh volume. Post-freeze schema changes ship as numbered files in
`website/SmartState-backend/postgres/migrations/` (see its README) and are applied with:

```sh
docker compose exec postgres sh /opt/smartstate/migrations/apply.sh
```

To recreate the database from scratch (destroys all data):

```sh
docker compose down -v && docker compose up -d --build
```

## Security

- **No secrets are tracked.** `.env`, `config.php`, and `java/config.ini` are gitignored and are the
  only secret carriers. TLS certificates are not committed — generate a local pair with
  `openssl req -x509 -newkey rsa:4096 -nodes -days 365 -keyout mycert.key -out mycert.crt -subj "/CN=localhost"`.
- **Rotation checklist** (recommended after any exposure, and for the historical dev credentials):
  Postgres passwords (`.env` + `config.php`), the CILogon client secret, the LLM API key, and Twilio
  credentials.
- RLS walls tenant data inside the shared database; it protects against missed scoping in app code,
  not against a compromised superuser or engine credential.
- Twilio webhook requests are signature-validated (`TWILIO_VALIDATE_WEBHOOK`, auto-disabled while
  `DISABLE_MESSAGING=true` for local development).

## Development

- House idioms: PHP models are typed classes with `::create` / `::withID` / `::withRow` /
  `::listForDatatable` statics; controllers return JSON; views are plain PHP over Bootstrap 5 +
  DataTables. The Java engine wires services as `Launcher` statics; per-machine work is serialized
  through a mailbox executor. Code stays comment-free — the structure is the documentation.
- Adding a screen: view under `views/<domain>/`, controller method returning JSON, route in
  `routes.php` with the role guard array.
- Java tests: `cd java && ./mvnw test` (JDK 25) or
  `docker run --rm -v "$PWD/java:/build" -w /build maven:3.9-eclipse-temurin-25 mvn test`.
  The equivalence suites replay the seeded protocols against the legacy state/message sequences.
- CI (`.github/workflows/build.yml`) builds the engine + validates composer + builds all images.

## Future work

- Dark mode (Bootstrap 5.3 `data-bs-theme`), Tom Select to replace bootstrap-select.
- Participant web chat as a protocol action type rendered on tokenized pages.
- Multi-question LLM conversation flows (schemaVersion 2 of the definition format).
