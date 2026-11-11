BEGIN;

\echo Seeding roles

INSERT INTO user_roles (role_id, role_name) VALUES
    (4, 'PI'),
    (5, 'Non-PHI')
ON CONFLICT (role_id) DO NOTHING;

\echo Creating studies

INSERT INTO studies (study_uuid, name, settings) VALUES
    ('10000000-0000-0000-0000-000000000001', 'HPM', '{}'),
    ('10000000-0000-0000-0000-000000000002', 'CCW', '{}'),
    ('10000000-0000-0000-0000-000000000003', 'SEC', '{}'),
    ('10000000-0000-0000-0000-000000000004', 'TIMED', '{}'),
    ('10000000-0000-0000-0000-000000000005', 'Fasting Sleep (unmatched)', '{}')
ON CONFLICT (name) DO NOTHING;

CREATE TEMP TABLE study_map(study_name TEXT PRIMARY KEY, study_uuid UUID NOT NULL) ON COMMIT DROP;
INSERT INTO study_map VALUES
    ('HPM',   '10000000-0000-0000-0000-000000000001'),
    ('CCW',   '10000000-0000-0000-0000-000000000002'),
    ('SEC',   '10000000-0000-0000-0000-000000000003'),
    ('TIMED', '10000000-0000-0000-0000-000000000004'),
    ('T2D',   '10000000-0000-0000-0000-000000000004'),
    ('SLEEP_UNMATCHED', '10000000-0000-0000-0000-000000000005');

\echo Seeding protocol definitions

CREATE TEMP TABLE def_targets(file_name TEXT, study_uuid UUID) ON COMMIT DROP;
INSERT INTO def_targets VALUES
    ('Baseline.hpm.v1.json', '10000000-0000-0000-0000-000000000001'),
    ('Baseline.v1.json',     '10000000-0000-0000-0000-000000000002'),
    ('Baseline.v1.json',     '10000000-0000-0000-0000-000000000003'),
    ('Baseline.v1.json',     '10000000-0000-0000-0000-000000000004'),
    ('Control.v1.json',      '10000000-0000-0000-0000-000000000001'),
    ('Control.v1.json',      '10000000-0000-0000-0000-000000000002'),
    ('Control.v1.json',      '10000000-0000-0000-0000-000000000003'),
    ('Control.v1.json',      '10000000-0000-0000-0000-000000000004'),
    ('DailyMessage.v1.json', '10000000-0000-0000-0000-000000000001'),
    ('DailyMessage.v1.json', '10000000-0000-0000-0000-000000000002'),
    ('DailyMessage.v1.json', '10000000-0000-0000-0000-000000000003'),
    ('DailyMessage.v1.json', '10000000-0000-0000-0000-000000000004'),
    ('WeeklyMessage.v1.json','10000000-0000-0000-0000-000000000001'),
    ('WeeklyMessage.v1.json','10000000-0000-0000-0000-000000000002'),
    ('WeeklyMessage.v1.json','10000000-0000-0000-0000-000000000003'),
    ('WeeklyMessage.v1.json','10000000-0000-0000-0000-000000000004'),
    ('TRE.ccw-hpm.v1.json',  '10000000-0000-0000-0000-000000000001'),
    ('TRE.ccw-hpm.v1.json',  '10000000-0000-0000-0000-000000000002'),
    ('TRE.timed.v1.json',    '10000000-0000-0000-0000-000000000004'),
    ('Sleep.v1.json',        '10000000-0000-0000-0000-000000000002'),
    ('Sleep.v1.json',        '10000000-0000-0000-0000-000000000003'),
    ('Sleep.v1.json',        '10000000-0000-0000-0000-000000000005'),
    ('SleepSurvey.v1.json',  '10000000-0000-0000-0000-000000000002'),
    ('SleepSurvey.v1.json',  '10000000-0000-0000-0000-000000000003'),
    ('SleepSurvey.v1.json',  '10000000-0000-0000-0000-000000000005');

INSERT INTO protocol_definitions (study_uuid, name, version, status, definition, published_at)
SELECT t.study_uuid, d.def_name, 1, 'published', d.definition, (NOW() AT TIME ZONE 'utc')
FROM def_targets t
JOIN fasting_staging.protocol_defs d ON d.file_name = t.file_name
ON CONFLICT (study_uuid, name, version) DO NOTHING;

\echo Building participant fold map

CREATE TEMP TABLE pmap(
    old_uuid UUID PRIMARY KEY,
    new_uuid UUID NOT NULL,
    study_uuid UUID NOT NULL,
    folded BOOLEAN NOT NULL DEFAULT FALSE
) ON COMMIT DROP;

INSERT INTO pmap (old_uuid, new_uuid, study_uuid, folded)
SELECT p.participant_uuid::uuid, p.participant_uuid::uuid, m.study_uuid, FALSE
FROM fasting_staging.participants p
JOIN study_map m ON m.study_name = p.study
WHERE p.study IN ('HPM', 'CCW', 'SEC', 'TIMED', 'T2D')
  AND p.participant_uuid::uuid <> '00000000-0000-0000-0000-000000000000';

CREATE TEMP TABLE sleep_candidates ON COMMIT DROP AS
SELECT s.participant_uuid::uuid AS old_uuid,
       b.participant_uuid::uuid AS parent_uuid,
       b.study AS parent_study,
       row_number() OVER (
           PARTITION BY s.participant_uuid
           ORDER BY b.study, b.participant_uuid
       ) AS rn
FROM fasting_staging.participants s
JOIN fasting_staging.participants b
  ON b.study IN ('CCW', 'SEC')
 AND NULLIF(b.participant_json::jsonb ->> 'number', '') IS NOT NULL
 AND b.participant_json::jsonb ->> 'number' = s.participant_json::jsonb ->> 'number'
WHERE s.study IN ('Sleep', 'SleepSurvey')
  AND NULLIF(s.participant_json::jsonb ->> 'number', '') IS NOT NULL;

INSERT INTO pmap (old_uuid, new_uuid, study_uuid, folded)
SELECT c.old_uuid, c.parent_uuid, m.study_uuid, TRUE
FROM sleep_candidates c
JOIN study_map m ON m.study_name = c.parent_study
WHERE c.rn = 1;

INSERT INTO pmap (old_uuid, new_uuid, study_uuid, folded)
SELECT s.participant_uuid::uuid,
       s.participant_uuid::uuid,
       (SELECT study_uuid FROM study_map WHERE study_name = 'SLEEP_UNMATCHED'),
       FALSE
FROM fasting_staging.participants s
WHERE s.study IN ('Sleep', 'SleepSurvey')
  AND NOT EXISTS (SELECT 1 FROM sleep_candidates c WHERE c.old_uuid = s.participant_uuid::uuid);

\echo Migrating participants

INSERT INTO participants (participant_uuid, study_uuid, participant_json)
SELECT pm.new_uuid,
       pm.study_uuid,
       jsonb_strip_nulls(jsonb_build_object(
           'first_name', p.participant_json::jsonb -> 'first_name',
           'last_name',  p.participant_json::jsonb -> 'last_name',
           'number',     p.participant_json::jsonb -> 'number',
           'time_zone',  p.participant_json::jsonb -> 'time_zone',
           'hidden',     p.participant_json::jsonb -> 'hidden'
       ))
FROM pmap pm
JOIN fasting_staging.participants p ON p.participant_uuid::uuid = pm.old_uuid
WHERE pm.folded = FALSE
ON CONFLICT (participant_uuid) DO NOTHING;

\echo Creating staff participants for sentinel admin traffic

CREATE TEMP TABLE staff_map(study_uuid UUID PRIMARY KEY, participant_uuid UUID NOT NULL) ON COMMIT DROP;
INSERT INTO staff_map VALUES
    ('10000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001'),
    ('10000000-0000-0000-0000-000000000002', 'a0000000-0000-0000-0000-000000000002'),
    ('10000000-0000-0000-0000-000000000003', 'a0000000-0000-0000-0000-000000000003'),
    ('10000000-0000-0000-0000-000000000004', 'a0000000-0000-0000-0000-000000000004');

INSERT INTO participants (participant_uuid, study_uuid, participant_json)
SELECT sm.participant_uuid,
       sm.study_uuid,
       jsonb_build_object('first_name', 'Study', 'last_name', 'Staff', 'hidden', TRUE)
FROM staff_map sm
ON CONFLICT (participant_uuid) DO NOTHING;

\echo Migrating enrollments

CREATE TEMP TABLE enroll_map ON COMMIT DROP AS
SELECT e.enrollment_uuid::uuid AS enrollment_uuid,
       pm.new_uuid AS participant_uuid,
       pm.study_uuid,
       pt.name AS protocol_name,
       pd.definition_uuid,
       (e.status = '1') AS active
FROM fasting_staging.enrollments e
JOIN fasting_staging.protocol_types pt ON pt.protocol_type_uuid = e.protocol_type_uuid
JOIN pmap pm ON pm.old_uuid = e.participant_uuid::uuid
LEFT JOIN protocol_definitions pd
       ON pd.study_uuid = pm.study_uuid
      AND pd.name = pt.name
      AND pd.version = 1
      AND pd.status = 'published';

CREATE TEMP TABLE first_log ON COMMIT DROP AS
SELECT p2.new_uuid AS participant_uuid,
       l.log_json::jsonb ->> 'protocol' AS protocol_name,
       min(l.ts::timestamp) AS min_ts
FROM fasting_staging.state_log l
JOIN pmap p2 ON p2.old_uuid = l.participant_uuid::uuid
GROUP BY 1, 2;

CREATE TEMP TABLE first_log_any ON COMMIT DROP AS
SELECT participant_uuid, min(min_ts) AS min_ts
FROM first_log
GROUP BY 1;

INSERT INTO enrollments (enrollment_uuid, participant_uuid, definition_uuid, study_uuid, protocol_name, enrolled_at, ended_at, end_reason)
SELECT em.enrollment_uuid,
       em.participant_uuid,
       em.definition_uuid,
       em.study_uuid,
       em.protocol_name,
       COALESCE(fl.min_ts, fla.min_ts, (NOW() AT TIME ZONE 'utc')),
       CASE WHEN em.active AND em.rn = 1 THEN NULL ELSE (NOW() AT TIME ZONE 'utc') END,
       CASE WHEN em.active AND em.rn = 1 THEN NULL
            WHEN em.active THEN 'imported-duplicate'
            ELSE 'imported-inactive' END
FROM (
    SELECT e2.*,
           row_number() OVER (
               PARTITION BY e2.participant_uuid, e2.protocol_name, e2.active
               ORDER BY e2.enrollment_uuid
           ) AS rn
    FROM enroll_map e2
    WHERE e2.definition_uuid IS NOT NULL
) em
LEFT JOIN first_log fl ON fl.participant_uuid = em.participant_uuid AND fl.protocol_name = em.protocol_name
LEFT JOIN first_log_any fla ON fla.participant_uuid = em.participant_uuid;

\echo Migrating messages

INSERT INTO messages (message_uuid, participant_uuid, study_uuid, ts, message_direction, message_json)
SELECT m.message_uuid::uuid,
       pm.new_uuid,
       pm.study_uuid,
       m.ts::timestamp,
       m.message_direction,
       m.message_json::jsonb
FROM fasting_staging.messages m
JOIN pmap pm ON pm.old_uuid = m.participant_uuid::uuid;

INSERT INTO messages (message_uuid, participant_uuid, study_uuid, ts, message_direction, message_json)
SELECT m.message_uuid::uuid,
       st.participant_uuid,
       st.study_uuid,
       m.ts::timestamp,
       m.message_direction,
       m.message_json::jsonb
FROM fasting_staging.messages m
JOIN study_map sm ON sm.study_name = m.study
JOIN staff_map st ON st.study_uuid = sm.study_uuid
WHERE m.participant_uuid::uuid = '00000000-0000-0000-0000-000000000000';

\echo Migrating state_log

INSERT INTO state_log (participant_uuid, enrollment_uuid, study_uuid, ts, log_json)
SELECT pm.new_uuid,
       e.enrollment_uuid,
       pm.study_uuid,
       l.ts::timestamp,
       l.log_json::jsonb
FROM fasting_staging.state_log l
JOIN pmap pm ON pm.old_uuid = l.participant_uuid::uuid
LEFT JOIN enrollments e
       ON e.participant_uuid = pm.new_uuid
      AND e.protocol_name = l.log_json::jsonb ->> 'protocol'
      AND e.ended_at IS NULL;

\echo Migrating surveys

INSERT INTO surveys (token, participant_uuid, study_uuid, enrollment_uuid, survey_key, questions_json, created_at, finished_at, survey_json)
SELECT s.token::uuid,
       pm.new_uuid,
       pm.study_uuid,
       NULL,
       s.survey_name,
       NULL,
       s.created_at::timestamp,
       s.finished_at::timestamp,
       s.survey_json::jsonb
FROM fasting_staging.surveys s
JOIN pmap pm ON pm.old_uuid = s.participant_uuid::uuid;

\echo Migrating future queued_messages

INSERT INTO queued_messages (message_uuid, participant_uuid, study_uuid, toNumber, fromNumber, scheduledFor, message_json)
SELECT q.message_uuid::uuid,
       pm.new_uuid,
       pm.study_uuid,
       q.tonumber,
       q.fromnumber,
       q.scheduledfor::timestamp,
       q.message_json::jsonb
FROM fasting_staging.queued_messages q
JOIN pmap pm ON pm.old_uuid = q.participant_uuid::uuid
WHERE q.scheduledfor::timestamp > (NOW() AT TIME ZONE 'utc');

\echo Migrating users and study roles

INSERT INTO users (id, eppn, is_super_admin)
SELECT lower(u.linkblue) || '@uky.edu',
       lower(u.linkblue) || '@uky.edu',
       (u.role = '0')
FROM fasting_staging.users u
ON CONFLICT (id) DO NOTHING;

INSERT INTO user_study_roles (user_id, study_uuid, role_id)
SELECT lower(u.linkblue) || '@uky.edu',
       s.study_uuid,
       rm.role_id
FROM fasting_staging.users u
JOIN (VALUES ('1', 5), ('2', 4), ('3', 3)) AS rm(old_role, role_id) ON rm.old_role = u.role
CROSS JOIN (
    SELECT DISTINCT study_uuid FROM study_map
    WHERE study_name IN ('HPM', 'CCW', 'SEC', 'TIMED')
) s
ON CONFLICT (user_id, study_uuid) DO NOTHING;

\echo Sanity report: staging vs target row counts

SELECT 'participants' AS entity,
       (SELECT count(*) FROM fasting_staging.participants) AS staging_rows,
       (SELECT count(*) FROM participants) AS target_rows
UNION ALL
SELECT 'enrollments',
       (SELECT count(*) FROM fasting_staging.enrollments),
       (SELECT count(*) FROM enrollments)
UNION ALL
SELECT 'messages',
       (SELECT count(*) FROM fasting_staging.messages),
       (SELECT count(*) FROM messages)
UNION ALL
SELECT 'state_log',
       (SELECT count(*) FROM fasting_staging.state_log),
       (SELECT count(*) FROM state_log)
UNION ALL
SELECT 'surveys',
       (SELECT count(*) FROM fasting_staging.surveys),
       (SELECT count(*) FROM surveys)
UNION ALL
SELECT 'queued_messages',
       (SELECT count(*) FROM fasting_staging.queued_messages),
       (SELECT count(*) FROM queued_messages)
UNION ALL
SELECT 'users',
       (SELECT count(*) FROM fasting_staging.users),
       (SELECT count(*) FROM users)
UNION ALL
SELECT 'protocol_definitions',
       (SELECT count(*) FROM fasting_staging.protocol_defs),
       (SELECT count(*) FROM protocol_definitions);

\echo Sanity report: fold and skip detail

SELECT * FROM (VALUES
    ('sleep_participants_folded_into_parent',
        (SELECT count(*) FROM pmap WHERE folded)),
    ('sleep_participants_unmatched',
        (SELECT count(*) FROM pmap p WHERE NOT p.folded AND p.study_uuid = '10000000-0000-0000-0000-000000000005')),
    ('sleep_participants_ambiguous_phone_match',
        (SELECT count(DISTINCT old_uuid) FROM sleep_candidates WHERE rn > 1)),
    ('enrollments_skipped_no_matching_definition',
        (SELECT count(*) FROM enroll_map WHERE definition_uuid IS NULL)),
    ('enrollments_skipped_unmapped_participant',
        (SELECT count(*) FROM fasting_staging.enrollments e
         WHERE NOT EXISTS (SELECT 1 FROM pmap pm WHERE pm.old_uuid = e.participant_uuid::uuid))),
    ('enrollments_closed_as_duplicate_active',
        (SELECT count(*) FROM enrollments WHERE end_reason = 'imported-duplicate')),
    ('messages_sentinel_mapped_to_staff',
        (SELECT count(*) FROM fasting_staging.messages m
         JOIN study_map sm ON sm.study_name = m.study
         JOIN staff_map st ON st.study_uuid = sm.study_uuid
         WHERE m.participant_uuid::uuid = '00000000-0000-0000-0000-000000000000')),
    ('messages_skipped_sentinel_unmappable_study',
        (SELECT count(*) FROM fasting_staging.messages m
         WHERE m.participant_uuid::uuid = '00000000-0000-0000-0000-000000000000'
           AND NOT EXISTS (
               SELECT 1 FROM study_map sm
               JOIN staff_map st ON st.study_uuid = sm.study_uuid
               WHERE sm.study_name = m.study))),
    ('messages_skipped_unmapped_participant',
        (SELECT count(*) FROM fasting_staging.messages m
         WHERE m.participant_uuid::uuid <> '00000000-0000-0000-0000-000000000000'
           AND NOT EXISTS (SELECT 1 FROM pmap pm WHERE pm.old_uuid = m.participant_uuid::uuid))),
    ('state_log_skipped_unmapped_participant',
        (SELECT count(*) FROM fasting_staging.state_log l
         WHERE NOT EXISTS (SELECT 1 FROM pmap pm WHERE pm.old_uuid = l.participant_uuid::uuid))),
    ('state_log_rows_without_enrollment_link',
        (SELECT count(*) FROM state_log WHERE enrollment_uuid IS NULL)),
    ('surveys_skipped_unmapped_participant',
        (SELECT count(*) FROM fasting_staging.surveys s
         WHERE NOT EXISTS (SELECT 1 FROM pmap pm WHERE pm.old_uuid = s.participant_uuid::uuid))),
    ('queued_messages_skipped_past_or_unmapped',
        (SELECT count(*) FROM fasting_staging.queued_messages q
         WHERE q.scheduledfor::timestamp <= (NOW() AT TIME ZONE 'utc')
            OR NOT EXISTS (SELECT 1 FROM pmap pm WHERE pm.old_uuid = q.participant_uuid::uuid)))
) AS detail(metric, value);

COMMIT;
