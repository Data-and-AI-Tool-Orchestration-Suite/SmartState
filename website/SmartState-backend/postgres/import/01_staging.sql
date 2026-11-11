\echo Creating fasting_staging schema

DROP SCHEMA IF EXISTS fasting_staging CASCADE;
CREATE SCHEMA fasting_staging;

CREATE TABLE fasting_staging.user_roles(
    id TEXT,
    role_name TEXT
);

CREATE TABLE fasting_staging.users(
    id TEXT,
    linkblue TEXT,
    role TEXT,
    p_hash TEXT
);

CREATE TABLE fasting_staging.protocol_types(
    protocol_type_uuid TEXT,
    study TEXT,
    name TEXT
);

CREATE TABLE fasting_staging.participants(
    participant_uuid TEXT,
    study TEXT,
    participant_json TEXT
);

CREATE TABLE fasting_staging.enrollments(
    enrollment_uuid TEXT,
    participant_uuid TEXT,
    protocol_type_uuid TEXT,
    status TEXT
);

CREATE TABLE fasting_staging.messages(
    message_uuid TEXT,
    participant_uuid TEXT,
    ts TEXT,
    message_direction TEXT,
    study TEXT,
    message_json TEXT
);

CREATE TABLE fasting_staging.state_log(
    participant_uuid TEXT,
    ts TEXT,
    log_json TEXT
);

CREATE TABLE fasting_staging.queued_messages(
    message_uuid TEXT,
    participant_uuid TEXT,
    tonumber TEXT,
    fromnumber TEXT,
    scheduledfor TEXT,
    message_json TEXT,
    study TEXT
);

CREATE TABLE fasting_staging.surveys(
    token TEXT,
    participant_uuid TEXT,
    created_at TEXT,
    finished_at TEXT,
    survey_json TEXT,
    survey_name TEXT
);

\echo Loading CSVs

\copy fasting_staging.user_roles FROM './csv/user_roles.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.users FROM './csv/users.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.protocol_types FROM './csv/protocol_types.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.participants FROM './csv/participants.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.enrollments FROM './csv/enrollments.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.messages FROM './csv/messages.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.state_log FROM './csv/state_log.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.queued_messages FROM './csv/queued_messages.csv' WITH (FORMAT csv, HEADER true, NULL '')
\copy fasting_staging.surveys FROM './csv/surveys.csv' WITH (FORMAT csv, HEADER true, NULL '')

\echo Indexing staging tables

CREATE INDEX fasting_staging_state_log_participant_idx ON fasting_staging.state_log(participant_uuid);
CREATE INDEX fasting_staging_messages_participant_idx ON fasting_staging.messages(participant_uuid);
CREATE INDEX fasting_staging_participants_study_idx ON fasting_staging.participants(study);

\echo Staging load complete
SELECT 'user_roles' AS staging_table, count(*) AS rows FROM fasting_staging.user_roles
UNION ALL SELECT 'users', count(*) FROM fasting_staging.users
UNION ALL SELECT 'protocol_types', count(*) FROM fasting_staging.protocol_types
UNION ALL SELECT 'participants', count(*) FROM fasting_staging.participants
UNION ALL SELECT 'enrollments', count(*) FROM fasting_staging.enrollments
UNION ALL SELECT 'messages', count(*) FROM fasting_staging.messages
UNION ALL SELECT 'state_log', count(*) FROM fasting_staging.state_log
UNION ALL SELECT 'queued_messages', count(*) FROM fasting_staging.queued_messages
UNION ALL SELECT 'surveys', count(*) FROM fasting_staging.surveys;
