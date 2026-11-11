BEGIN;

CREATE TABLE IF NOT EXISTS schema_migrations(
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE TABLE IF NOT EXISTS user_roles(
    role_id INT NOT NULL PRIMARY KEY,
    role_name VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS users(
    id VARCHAR(255) NOT NULL PRIMARY KEY,
    email VARCHAR(255),
    phone_number VARCHAR(255),
    timezone VARCHAR(255),
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    full_name VARCHAR(255),
    eppn VARCHAR(255),
    idp VARCHAR(255),
    idp_name VARCHAR(255),
    affiliation VARCHAR(255),
    is_super_admin BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS user_sessions(
    session_id VARCHAR(36) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    expires TIMESTAMP NOT NULL,
    token JSONB NOT NULL,
    current_study UUID,
    CONSTRAINT user_sessions_users_id_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS studies(
    study_uuid UUID NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL UNIQUE,
    contact_phone VARCHAR(32),
    contact_email VARCHAR(255),
    settings JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE TABLE IF NOT EXISTS user_study_roles(
    user_id VARCHAR(255) NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INT NOT NULL REFERENCES user_roles(role_id),
    PRIMARY KEY (user_id, study_uuid)
);

CREATE TABLE IF NOT EXISTS protocol_definitions(
    definition_uuid UUID NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    version INT NOT NULL DEFAULT 1,
    status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    definition JSONB NOT NULL,
    created_by VARCHAR(255) REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    published_at TIMESTAMP,
    UNIQUE (study_uuid, name, version)
);
CREATE UNIQUE INDEX IF NOT EXISTS protocol_definitions_one_draft
    ON protocol_definitions(study_uuid, name) WHERE status = 'draft';

CREATE TABLE IF NOT EXISTS participants(
    participant_uuid UUID NOT NULL PRIMARY KEY,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    participant_json JSONB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);
CREATE INDEX IF NOT EXISTS participants_study_idx ON participants(study_uuid);
CREATE INDEX IF NOT EXISTS participants_number_idx ON participants((participant_json->>'number'));

CREATE TABLE IF NOT EXISTS enrollments(
    enrollment_uuid UUID NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
    participant_uuid UUID NOT NULL REFERENCES participants(participant_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    definition_uuid UUID NOT NULL REFERENCES protocol_definitions(definition_uuid) ON UPDATE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    protocol_name VARCHAR(255) NOT NULL,
    enrolled_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    ended_at TIMESTAMP,
    end_reason VARCHAR(64)
);
CREATE UNIQUE INDEX IF NOT EXISTS enrollments_active_unique
    ON enrollments(participant_uuid, protocol_name) WHERE ended_at IS NULL;
CREATE INDEX IF NOT EXISTS enrollments_study_idx ON enrollments(study_uuid);

CREATE TABLE IF NOT EXISTS messages(
    message_uuid UUID NOT NULL PRIMARY KEY,
    participant_uuid UUID NOT NULL REFERENCES participants(participant_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL,
    message_direction CHAR(8) NOT NULL,
    message_json JSONB NOT NULL
);
CREATE INDEX IF NOT EXISTS messages_study_ts_idx ON messages(study_uuid, ts DESC);
CREATE INDEX IF NOT EXISTS messages_participant_ts_idx ON messages(participant_uuid, ts DESC);

CREATE TABLE IF NOT EXISTS queued_messages(
    message_uuid UUID NOT NULL PRIMARY KEY,
    participant_uuid UUID NOT NULL REFERENCES participants(participant_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    toNumber CHAR(12) NOT NULL,
    fromNumber CHAR(12) NOT NULL,
    scheduledFor TIMESTAMP NOT NULL,
    message_json JSONB NOT NULL
);

CREATE TABLE IF NOT EXISTS save_state(
    enrollment_uuid UUID NOT NULL REFERENCES enrollments(enrollment_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL,
    state_json JSONB NOT NULL
);
CREATE INDEX IF NOT EXISTS save_state_enrollment_ts_idx ON save_state(enrollment_uuid, ts DESC);

CREATE TABLE IF NOT EXISTS state_log(
    participant_uuid UUID NOT NULL REFERENCES participants(participant_uuid) ON DELETE CASCADE,
    enrollment_uuid UUID REFERENCES enrollments(enrollment_uuid) ON DELETE SET NULL,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL,
    log_json JSONB NOT NULL
);
CREATE INDEX IF NOT EXISTS state_log_participant_ts_idx ON state_log(participant_uuid, ts DESC);
CREATE INDEX IF NOT EXISTS state_log_study_ts_idx ON state_log(study_uuid, ts DESC);

CREATE TABLE IF NOT EXISTS surveys(
    token UUID NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
    participant_uuid UUID NOT NULL REFERENCES participants(participant_uuid) ON DELETE CASCADE,
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    enrollment_uuid UUID REFERENCES enrollments(enrollment_uuid) ON DELETE SET NULL,
    survey_key VARCHAR(255),
    questions_json JSONB,
    created_at TIMESTAMP NOT NULL,
    finished_at TIMESTAMP,
    survey_json JSONB
);

CREATE TABLE IF NOT EXISTS time_zones(
    time_zone VARCHAR(64) NOT NULL,
    short_zone VARCHAR(32) NOT NULL,
    UNIQUE (time_zone, short_zone)
);

CREATE TABLE IF NOT EXISTS metrics(
    metric_id UUID NOT NULL PRIMARY KEY DEFAULT gen_random_uuid(),
    participant_id UUID REFERENCES participants(participant_uuid),
    study_uuid UUID NOT NULL REFERENCES studies(study_uuid) ON UPDATE CASCADE ON DELETE CASCADE,
    ts TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    metric_json JSONB NOT NULL
);

COMMIT;

BEGIN;

CREATE ROLE smartstate_app LOGIN PASSWORD '<APP_DB_PASSWORD>';
CREATE ROLE smartstate_engine LOGIN PASSWORD '<ENGINE_DB_PASSWORD>' BYPASSRLS;

GRANT USAGE ON SCHEMA public TO smartstate_app, smartstate_engine;

GRANT SELECT, INSERT, UPDATE, DELETE ON
    user_roles, users, user_sessions, studies, user_study_roles,
    protocol_definitions, participants, enrollments, messages, queued_messages,
    save_state, state_log, surveys, time_zones, metrics
TO smartstate_app;

GRANT SELECT ON studies, participants, enrollments, protocol_definitions TO smartstate_engine;
GRANT SELECT, INSERT, UPDATE, DELETE ON
    messages, queued_messages, save_state, state_log, surveys, metrics
TO smartstate_engine;

CREATE FUNCTION app_user_id() RETURNS TEXT LANGUAGE sql STABLE
    AS $$ SELECT current_setting('app.user_id', true) $$;

CREATE FUNCTION app_is_super_admin() RETURNS BOOLEAN LANGUAGE sql STABLE
    AS $$ SELECT COALESCE(NULLIF(current_setting('app.is_super_admin', true), ''), 'false')::boolean $$;

CREATE FUNCTION app_has_study(sid UUID) RETURNS BOOLEAN LANGUAGE sql STABLE SECURITY DEFINER
    AS $$ SELECT app_is_super_admin() OR EXISTS
        (SELECT 1 FROM user_study_roles r WHERE r.user_id = app_user_id() AND r.study_uuid = sid) $$;

CREATE FUNCTION app_is_study_admin(sid UUID) RETURNS BOOLEAN LANGUAGE sql STABLE SECURITY DEFINER
    AS $$ SELECT app_is_super_admin() OR EXISTS
        (SELECT 1 FROM user_study_roles r
         JOIN user_roles ur ON ur.role_id = r.role_id
         WHERE r.user_id = app_user_id() AND r.study_uuid = sid AND ur.role_name = 'Study Admin') $$;

GRANT EXECUTE ON FUNCTION app_user_id(), app_is_super_admin(), app_has_study(UUID), app_is_study_admin(UUID) TO smartstate_app;

ALTER TABLE studies ENABLE ROW LEVEL SECURITY;
ALTER TABLE studies FORCE ROW LEVEL SECURITY;
CREATE POLICY studies_member_select ON studies FOR SELECT TO smartstate_app
    USING (app_has_study(study_uuid));
CREATE POLICY studies_super_admin_insert ON studies FOR INSERT TO smartstate_app
    WITH CHECK (app_is_super_admin());
CREATE POLICY studies_super_admin_update ON studies FOR UPDATE TO smartstate_app
    USING (app_is_super_admin()) WITH CHECK (app_is_super_admin());
CREATE POLICY studies_super_admin_delete ON studies FOR DELETE TO smartstate_app
    USING (app_is_super_admin());
CREATE POLICY studies_token_select ON studies FOR SELECT TO smartstate_app
    USING (EXISTS (SELECT 1 FROM surveys s WHERE s.study_uuid = studies.study_uuid
        AND s.token::text = current_setting('app.survey_token', true)));

ALTER TABLE user_study_roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_study_roles FORCE ROW LEVEL SECURITY;
CREATE POLICY user_study_roles_member_select ON user_study_roles FOR SELECT TO smartstate_app
    USING (app_has_study(study_uuid) OR user_id = app_user_id());
CREATE POLICY user_study_roles_admin_insert ON user_study_roles FOR INSERT TO smartstate_app
    WITH CHECK (app_is_study_admin(study_uuid));
CREATE POLICY user_study_roles_admin_update ON user_study_roles FOR UPDATE TO smartstate_app
    USING (app_is_study_admin(study_uuid)) WITH CHECK (app_is_study_admin(study_uuid));
CREATE POLICY user_study_roles_admin_delete ON user_study_roles FOR DELETE TO smartstate_app
    USING (app_is_study_admin(study_uuid));

ALTER TABLE protocol_definitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE protocol_definitions FORCE ROW LEVEL SECURITY;
CREATE POLICY protocol_definitions_study_access ON protocol_definitions FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE participants FORCE ROW LEVEL SECURITY;
CREATE POLICY participants_study_access ON participants FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));
CREATE POLICY participants_token_select ON participants FOR SELECT TO smartstate_app
    USING (EXISTS (SELECT 1 FROM surveys s WHERE s.participant_uuid = participants.participant_uuid
        AND s.token::text = current_setting('app.survey_token', true)));

ALTER TABLE enrollments ENABLE ROW LEVEL SECURITY;
ALTER TABLE enrollments FORCE ROW LEVEL SECURITY;
CREATE POLICY enrollments_study_access ON enrollments FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages FORCE ROW LEVEL SECURITY;
CREATE POLICY messages_study_access ON messages FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE queued_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE queued_messages FORCE ROW LEVEL SECURITY;
CREATE POLICY queued_messages_study_access ON queued_messages FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE save_state ENABLE ROW LEVEL SECURITY;
ALTER TABLE save_state FORCE ROW LEVEL SECURITY;
CREATE POLICY save_state_study_access ON save_state FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE state_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE state_log FORCE ROW LEVEL SECURITY;
CREATE POLICY state_log_study_access ON state_log FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

ALTER TABLE surveys ENABLE ROW LEVEL SECURITY;
ALTER TABLE surveys FORCE ROW LEVEL SECURITY;
CREATE POLICY surveys_study_access ON surveys FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));
CREATE POLICY surveys_token_access ON surveys FOR ALL TO smartstate_app
    USING (token::text = current_setting('app.survey_token', true))
    WITH CHECK (token::text = current_setting('app.survey_token', true));

ALTER TABLE metrics ENABLE ROW LEVEL SECURITY;
ALTER TABLE metrics FORCE ROW LEVEL SECURITY;
CREATE POLICY metrics_study_access ON metrics FOR ALL TO smartstate_app
    USING (app_has_study(study_uuid)) WITH CHECK (app_has_study(study_uuid));

COMMIT;

BEGIN;

INSERT INTO user_roles (role_id, role_name) VALUES
    (0, 'Default'),
    (1, 'Super Admin'),
    (2, 'Study Admin'),
    (3, 'User'),
    (4, 'PI'),
    (5, 'Non-PHI')
ON CONFLICT (role_id) DO NOTHING;

INSERT INTO schema_migrations (filename) VALUES
    ('002_fasting_roles.sql')
ON CONFLICT (filename) DO NOTHING;

INSERT INTO users (id, eppn, is_super_admin) VALUES
    ('notloggedin_123456', '<ADMIN_EPPN>', TRUE);

INSERT INTO studies (study_uuid, name, settings) VALUES
    ('00000000-0000-0000-0000-000000000001', 'Default', '{}');

COMMIT;

BEGIN;

INSERT INTO protocol_definitions (definition_uuid, study_uuid, name, version, status, definition, published_at) VALUES
    ('00000000-0000-0000-0000-0000000000a1', '00000000-0000-0000-0000-000000000001', 'ReadGlucose', 1, 'published', $readglucose_v1$
{
  "schemaVersion": 1,
  "name": "ReadGlucose",
  "initialState": "initial",
  "timers": ["startDeadline", "startWarnDeadline", "endOfEpisodeDeadline"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "help": {
      "keywords": ["help"],
      "adminTemplate": "ReadGlucose: A participant is in need of help!",
      "participantReply": "We've notified study administrators that you need help. Someone will be contacting you soon."
    },
    "collection": "none",
    "deviceWindow": {
      "quietSeconds": 30,
      "acceptStates": ["waitStart", "warnStartGlucose", "startReading"],
      "startEvent": "receivedStartGlucose",
      "endEvent": "receivedEndConnection",
      "result": {
        "type": "modulo",
        "of": "count",
        "mod": 3,
        "map": {"0": "low", "2": "high"},
        "default": "normal"
      }
    }
  },
  "restore": {
    "policy": "same-day-else-reset",
    "terminalStates": ["endReadGlucoseProtocol"],
    "fallbackState": "waitStart",
    "noSaveStateEvent": "receivedWaitStart",
    "replay": {
      "waitStart": {"restoring": ["receivedWaitStart"]},
      "warnStartGlucose": {"restoring": ["receivedWarnStart"]},
      "startReading": {"restoring": ["receivedStartGlucose"], "after": ["receivedError"]},
      "endOfEpisode": {"restoring": ["receivedEndofEpisode"]}
    },
    "resetFire": "receivedWaitStart"
  },
  "eventMappings": {
    "sms": {"defaultAction": "ignore"},
    "message": {
      "commandField": "Command",
      "commands": [
        {"equals": "startGlucose", "inStates": ["waitStart", "warnStartGlucose"], "fire": "receivedStartGlucose"},
        {"equals": "endConnection", "inStates": ["startReading"], "fire": "receivedEndConnection"}
      ],
      "otherwise": [{"inStates": ["startReading"], "fire": "receivedError"}]
    }
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "receivedWaitStart", "to": "waitStart"},
        {"event": "receivedWarnStart", "to": "warnStartGlucose"},
        {"event": "receivedStartGlucose", "to": "startReading"},
        {"event": "receivedEndofEpisode", "to": "endOfEpisode"},
        {"event": "receivedEndProtocol", "to": "endReadGlucoseProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "receivedWaitStart"},
        {"to": "warnStartGlucose", "via": "receivedWarnStart"},
        {"to": "endReadGlucoseProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitStart": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startDeadline",
          "deadline": {
            "type": "conditional",
            "if": {"type": "timerWithinMinuteWindow", "timer": "startDeadline", "fromMinute": 0, "toMinute": 15},
            "then": {"type": "secondsToNextMinuteMark", "mark": 15},
            "else": {"type": "fixedSeconds", "seconds": 900}
          }
        },
        {"type": "sendSms", "template": "Hi {first_name}, it's time to check you blood glucose level. Please interact with you blood glucose device to start sending readings."}
      ],
      "transitions": [
        {"event": "receivedStartGlucose", "to": "startReading"},
        {"after": "startDeadline", "to": "warnStartGlucose"},
        {"event": "receivedEndProtocol", "to": "endReadGlucoseProtocol"}
      ],
      "manualMoves": [
        {"to": "warnStartGlucose", "via": "timeout:startDeadline"},
        {"to": "startReading", "via": "receivedStartGlucose"},
        {"to": "endReadGlucoseProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "warnStartGlucose": {
      "entry": [
        {
          "type": "setTimer",
          "timer": "startWarnDeadline",
          "deadline": {
            "type": "conditional",
            "if": {"type": "timerWithinMinuteWindow", "timer": "startWarnDeadline", "fromMinute": 15, "toMinute": 30},
            "then": {"type": "secondsToNextMinuteMark", "mark": 30},
            "else": {"type": "fixedSeconds", "seconds": 900}
          }
        },
        {"type": "sendSms", "template": "We haven't started receiving your glucose measurements yet. Please interact with you blood glucose device to start sending readings. If you need help, please respond with \"help me\"."}
      ],
      "transitions": [
        {"event": "receivedStartGlucose", "to": "startReading"},
        {"after": "startWarnDeadline", "to": "missedStart"},
        {"event": "receivedEndProtocol", "to": "endReadGlucoseProtocol"}
      ],
      "manualMoves": [
        {"to": "startReading", "via": "receivedStartGlucose"},
        {"to": "missedStart", "via": "timeout:startWarnDeadline"},
        {"to": "endReadGlucoseProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "startReading": {
      "entry": [
        {"type": "sendSms", "template": "Your glucose readings are being received. We'll let you know your results momentarily."}
      ],
      "transitions": [
        {"event": "receivedEndConnection", "to": "finishedReading"},
        {"event": "receivedError", "to": "notifyAdmin"},
        {"event": "receivedEndProtocol", "to": "endReadGlucoseProtocol"}
      ],
      "manualMoves": [
        {"to": "notifyAdmin", "via": "receivedError"},
        {"to": "finishedReading", "via": "receivedEndConnection"},
        {"to": "endReadGlucoseProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "missedStart": {
      "entry": [
        {"type": "sendSms", "template": "{first_name}, we haven't started receiving your glucose readings. We will notify an administrator to assist you."}
      ],
      "transitions": [{"auto": true, "to": "notifyAdmin"}],
      "manualMoves": [{"to": "notifyAdmin", "via": "auto"}]
    },
    "notifyAdmin": {
      "entry": [
        {"type": "notifyAdmins", "template": "A participant did not successfully interact with their glucose measurement device within the 30 minute time window or an error occurred. They may require help."}
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "finishedReading": {
      "entry": [
        {"type": "resetDeviceWindow"},
        {
          "type": "sendResultSms",
          "source": "deviceResult",
          "messages": {
            "low": "All finished. Your blood glucose is low. Take steps to raise it.",
            "high": "All finished. Your blood glucose is high. Take steps to lower it.",
            "normal": "All finished. Your blood glucose is normal. Keep up the good work!"
          }
        }
      ],
      "transitions": [{"auto": true, "to": "endOfEpisode"}],
      "manualMoves": [{"to": "endOfEpisode", "via": "auto"}]
    },
    "endOfEpisode": {
      "entry": [
        {"type": "setTimer", "timer": "endOfEpisodeDeadline", "deadline": {"type": "secondsToHourBoundaryPlus", "hours": 2}}
      ],
      "transitions": [
        {"after": "endOfEpisodeDeadline", "to": "waitStart"},
        {"event": "receivedEndProtocol", "to": "endReadGlucoseProtocol"}
      ],
      "manualMoves": [
        {"to": "waitStart", "via": "timeout:endOfEpisodeDeadline"},
        {"to": "endReadGlucoseProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "endReadGlucoseProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  }
}
$readglucose_v1$::jsonb, (NOW() AT TIME ZONE 'utc'));

INSERT INTO protocol_definitions (definition_uuid, study_uuid, name, version, status, definition, published_at) VALUES
    ('00000000-0000-0000-0000-0000000000a2', '00000000-0000-0000-0000-000000000001', 'Survey', 1, 'published', $survey_v1$
{
  "schemaVersion": 1,
  "name": "Survey",
  "initialState": "initial",
  "timers": ["deadlineNoon", "deadline6pm"],
  "settings": {
    "endProtocolEvent": "receivedEndProtocol",
    "help": {
      "keywords": ["help"],
      "adminTemplate": "Survey: A participant is in need of help!",
      "participantReply": "We've notified study administrators that you need help. Someone will be contacting you soon."
    },
    "collection": "llm"
  },
  "restore": {
    "policy": "recompute-from-clock",
    "terminalStates": ["endSurveyProtocol"],
    "recomputeRules": [
      {"if": {"type": "beforeClock", "hour": 12, "minute": 0}, "fire": "isBeforeNoon"},
      {"if": {"type": "beforeClock", "hour": 18, "minute": 0}, "fire": "isBefore6pm"},
      {"if": {"type": "always"}, "fire": "isBeforeNoon"}
    ],
    "resetFire": "recomputeRules"
  },
  "eventMappings": {
    "sms": {"defaultAction": "llmAnswer", "inStates": ["noonSurvey", "survey6pm", "waitFor6pm", "waitForNoon"]}
  },
  "states": {
    "initial": {
      "transitions": [
        {"event": "isBeforeNoon", "to": "waitForNoon"},
        {"event": "isBefore6pm", "to": "waitFor6pm"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "waitFor6pm", "via": "isBefore6pm"},
        {"to": "waitForNoon", "via": "isBeforeNoon"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "waitForNoon": {
      "entry": [
        {"type": "setTimer", "timer": "deadlineNoon", "deadline": {"type": "secondsToClock", "hour": 12, "minute": 0, "ifPastAddDay": true}}
      ],
      "transitions": [
        {"after": "deadlineNoon", "to": "noonSurvey"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "noonSurvey", "via": "timeout:deadlineNoon"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "noonSurvey": {
      "entry": [
        {"type": "generateSurveyToken", "surveyKey": "health_habits"},
        {
          "type": "llmQuestion",
          "question": "How often do you exercise per week?",
          "systemPrompt": "You must determine if the user submits a valid response. The user will be answering the question: \"How often do you exercise per week?\". If you are able to fit the users response into one of the following categories, then the response is valid. The categories are: \"Never\", \"1-2 times\", \"3-4 times\", and \"5+ times\". You then must return the category that the fits the users response. For example, if the user responds with \"I exercise twice a week\", you would respond with \"1-2 times\". If you cannot determine which category fits the users response, list the categories and ask the question again.Respond only with one of the categories and no other text.",
          "validCategories": ["never", "1-2 times", "3-4 times", "5+ times"],
          "answerKey": "exercise",
          "staticAnswers": {"diet": "Average", "smoke": "smoke-no", "sleep": "4-6 hours", "water": "1-2 liters", "comments": "N/a"},
          "linkTemplate": "Hello! Please take a moment to complete the following survey about your health: {survey_link}"
        }
      ],
      "transitions": [{"auto": true, "to": "waitFor6pm"}],
      "manualMoves": []
    },
    "waitFor6pm": {
      "entry": [
        {"type": "setTimer", "timer": "deadline6pm", "deadline": {"type": "secondsToClock", "hour": 18, "minute": 0, "ifPastAddDay": false}}
      ],
      "transitions": [
        {"after": "deadline6pm", "to": "survey6pm"},
        {"event": "receivedEndProtocol", "to": "endSurveyProtocol"}
      ],
      "manualMoves": [
        {"to": "survey6pm", "via": "timeout:deadline6pm"},
        {"to": "endSurveyProtocol", "via": "receivedEndProtocol"}
      ]
    },
    "survey6pm": {
      "entry": [
        {"type": "generateSurveyToken", "surveyKey": "health_habits"},
        {
          "type": "llmQuestion",
          "question": "How often do you exercise per week?",
          "systemPrompt": "You must determine if the user submits a valid response. The user will be answering the question: \"How often do you exercise per week?\". If you are able to fit the users response into one of the following categories, then the response is valid. The categories are: \"Never\", \"1-2 times\", \"3-4 times\", and \"5+ times\". You then must return the category that the fits the users response. For example, if the user responds with \"I exercise twice a week\", you would respond with \"1-2 times\". If you cannot determine which category fits the users response, list the categories and ask the question again.Respond only with one of the categories and no other text.",
          "validCategories": ["never", "1-2 times", "3-4 times", "5+ times"],
          "answerKey": "exercise",
          "staticAnswers": {"diet": "Average", "smoke": "smoke-no", "sleep": "4-6 hours", "water": "1-2 liters", "comments": "N/a"},
          "linkTemplate": "Good Evening! Please take a moment to complete the following survey about your health: {survey_link}"
        }
      ],
      "transitions": [{"auto": true, "to": "waitForNoon"}],
      "manualMoves": []
    },
    "endSurveyProtocol": {
      "terminal": true,
      "transitions": [],
      "manualMoves": []
    }
  },
  "surveys": [
    {
      "key": "health_habits",
      "title": "Health Habits Survey",
      "questions": [
        {"id": "exercise", "type": "select", "label": "How often do you exercise per week?", "options": ["Never", "1-2 times", "3-4 times", "5+ times"], "required": true},
        {"id": "diet", "type": "select", "label": "How would you describe your diet?", "options": ["Poor", "Average", "Healthy", "Very Healthy"], "required": true},
        {"id": "smoke", "type": "radio", "label": "Do you smoke?", "options": ["smoke-yes", "smoke-no"], "required": true},
        {"id": "sleep", "type": "select", "label": "How many hours do you sleep per night?", "options": ["Less than 4 hours", "4-6 hours", "6-8 hours", "More than 8 hours"], "required": true},
        {"id": "water", "type": "select", "label": "How much water do you drink per day?", "options": ["Less than 1 liter", "1-2 liters", "2-3 liters", "More than 3 liters"], "required": true},
        {"id": "comments", "type": "textarea", "label": "Anything else you'd like to share?", "required": false}
      ]
    }
  ]
}
$survey_v1$::jsonb, (NOW() AT TIME ZONE 'utc'));

COMMIT;

BEGIN;
-- Insert timezones into time_zone table
INSERT INTO time_zones (time_zone, short_zone)
VALUES
    ('Africa/Abidjan', 'Africa'),
    ('Africa/Abidjan', 'Africa'),
    ('Africa/Accra', 'Africa'),
    ('Africa/Addis_Ababa', 'Africa'),
    ('Africa/Algiers', 'Africa'),
    ('Africa/Asmara', 'Africa'),
    ('Africa/Asmera', 'Africa'),
    ('Africa/Bamako', 'Africa'),
    ('Africa/Bangui', 'Africa'),
    ('Africa/Banjul', 'Africa'),
    ('Africa/Bissau', 'Africa'),
    ('Africa/Blantyre', 'Africa'),
    ('Africa/Brazzaville', 'Africa'),
    ('Africa/Bujumbura', 'Africa'),
    ('Africa/Cairo', 'Africa'),
    ('Africa/Casablanca', 'Africa'),
    ('Africa/Ceuta', 'Africa'),
    ('Africa/Conakry', 'Africa'),
    ('Africa/Dakar', 'Africa'),
    ('Africa/Dar_es_Salaam', 'Africa'),
    ('Africa/Djibouti', 'Africa'),
    ('Africa/Douala', 'Africa'),
    ('Africa/El_Aaiun', 'Africa'),
    ('Africa/Freetown', 'Africa'),
    ('Africa/Gaborone', 'Africa'),
    ('Africa/Harare', 'Africa'),
    ('Africa/Johannesburg', 'Africa'),
    ('Africa/Juba', 'Africa'),
    ('Africa/Kampala', 'Africa'),
    ('Africa/Khartoum', 'Africa'),
    ('Africa/Kigali', 'Africa'),
    ('Africa/Kinshasa', 'Africa'),
    ('Africa/Lagos', 'Africa'),
    ('Africa/Libreville', 'Africa'),
    ('Africa/Lome', 'Africa'),
    ('Africa/Luanda', 'Africa'),
    ('Africa/Lubumbashi', 'Africa'),
    ('Africa/Lusaka', 'Africa'),
    ('Africa/Malabo', 'Africa'),
    ('Africa/Maputo', 'Africa'),
    ('Africa/Maseru', 'Africa'),
    ('Africa/Mbabane', 'Africa'),
    ('Africa/Mogadishu', 'Africa'),
    ('Africa/Monrovia', 'Africa'),
    ('Africa/Nairobi', 'Africa'),
    ('Africa/Ndjamena', 'Africa'),
    ('Africa/Niamey', 'Africa'),
    ('Africa/Nouakchott', 'Africa'),
    ('Africa/Ouagadougou', 'Africa'),
    ('Africa/Porto-Novo', 'Africa'),
    ('Africa/Sao_Tome', 'Africa'),
    ('Africa/Timbuktu', 'Africa'),
    ('Africa/Tripoli', 'Africa'),
    ('Africa/Tunis', 'Africa'),
    ('Africa/Windhoek', 'Africa'),
    ('America/Adak', 'America'),
    ('America/Anchorage', 'America'),
    ('America/Anguilla', 'America'),
    ('America/Antigua', 'America'),
    ('America/Araguaina', 'America'),
    ('America/Argentina/Buenos_Aires', 'America'),
    ('America/Argentina/Catamarca', 'America'),
    ('America/Argentina/ComodRivadavia', 'America'),
    ('America/Argentina/Cordoba', 'America'),
    ('America/Argentina/Jujuy', 'America'),
    ('America/Argentina/La_Rioja', 'America'),
    ('America/Argentina/Mendoza', 'America'),
    ('America/Argentina/Rio_Gallegos', 'America'),
    ('America/Argentina/Salta', 'America'),
    ('America/Argentina/San_Juan', 'America'),
    ('America/Argentina/San_Luis', 'America'),
    ('America/Argentina/Tucuman', 'America'),
    ('America/Argentina/Ushuaia', 'America'),
    ('America/Aruba', 'America'),
    ('America/Asuncion', 'America'),
    ('America/Atikokan', 'America'),
    ('America/Atka', 'America'),
    ('America/Bahia', 'America'),
    ('America/Bahia_Banderas', 'America'),
    ('America/Barbados', 'America'),
    ('America/Belem', 'America'),
    ('America/Belize', 'America'),
    ('America/Blanc-Sablon', 'America'),
    ('America/Boa_Vista', 'America'),
    ('America/Bogota', 'America'),
    ('America/Boise', 'America'),
    ('America/Buenos_Aires', 'America'),
    ('America/Cambridge_Bay', 'America'),
    ('America/Campo_Grande', 'America'),
    ('America/Cancun', 'America'),
    ('America/Caracas', 'America'),
    ('America/Catamarca', 'America'),
    ('America/Cayenne', 'America'),
    ('America/Cayman', 'America'),
    ('America/Chicago', 'America'),
    ('America/Chihuahua', 'America'),
    ('America/Coral_Harbour', 'America'),
    ('America/Cordoba', 'America'),
    ('America/Costa_Rica', 'America'),
    ('America/Creston', 'America'),
    ('America/Cuiaba', 'America'),
    ('America/Curacao', 'America'),
    ('America/Danmarkshavn', 'America'),
    ('America/Dawson', 'America'),
    ('America/Dawson_Creek', 'America'),
    ('America/Denver', 'America'),
    ('America/Detroit', 'America'),
    ('America/Dominica', 'America'),
    ('America/Edmonton', 'America'),
    ('America/Eirunepe', 'America'),
    ('America/El_Salvador', 'America'),
    ('America/Ensenada', 'America'),
    ('America/Fort_Nelson', 'America'),
    ('America/Fort_Wayne', 'America'),
    ('America/Fortaleza', 'America'),
    ('America/Glace_Bay', 'America'),
    ('America/Godthab', 'America'),
    ('America/Goose_Bay', 'America'),
    ('America/Grand_Turk', 'America'),
    ('America/Grenada', 'America'),
    ('America/Guadeloupe', 'America'),
    ('America/Guatemala', 'America'),
    ('America/Guayaquil', 'America'),
    ('America/Guyana', 'America'),
    ('America/Halifax', 'America'),
    ('America/Havana', 'America'),
    ('America/Hermosillo', 'America'),
    ('America/Indiana/Indianapolis', 'America'),
    ('America/Indiana/Knox', 'America'),
    ('America/Indiana/Marengo', 'America'),
    ('America/Indiana/Petersburg', 'America'),
    ('America/Indiana/Tell_City', 'America'),
    ('America/Indiana/Vevay', 'America'),
    ('America/Indiana/Vincennes', 'America'),
    ('America/Indiana/Winamac', 'America'),
    ('America/Indianapolis', 'America'),
    ('America/Inuvik', 'America'),
    ('America/Iqaluit', 'America'),
    ('America/Jamaica', 'America'),
    ('America/Jujuy', 'America'),
    ('America/Juneau', 'America'),
    ('America/Kentucky/Louisville', 'America'),
    ('America/Kentucky/Monticello', 'America'),
    ('America/Knox_I', 'America'),
    ('America/Kralendijk', 'America'),
    ('America/La_Paz', 'America'),
    ('America/Lima', 'America'),
    ('America/Los_Angeles', 'America'),
    ('America/Louisville', 'America'),
    ('America/Lower_Princes', 'America'),
    ('America/Maceio', 'America'),
    ('America/Managua', 'America'),
    ('America/Manaus', 'America'),
    ('America/Marigot', 'America'),
    ('America/Martinique', 'America'),
    ('America/Matamoros', 'America'),
    ('America/Mazatlan', 'America'),
    ('America/Mendoza', 'America'),
    ('America/Menominee', 'America'),
    ('America/Merida', 'America'),
    ('America/Metlakatla', 'America'),
    ('America/Mexico_City', 'America'),
    ('America/Miquelon', 'America'),
    ('America/Moncton', 'America'),
    ('America/Monterrey', 'America'),
    ('America/Montevideo', 'America'),
    ('America/Montreal', 'America'),
    ('America/Montserrat', 'America'),
    ('America/Nassau', 'America'),
    ('America/New_York', 'America'),
    ('America/Nipigon', 'America'),
    ('America/Nome', 'America'),
    ('America/Noronha', 'America'),
    ('America/North_Dakota/Beulah', 'America'),
    ('America/North_Dakota/Center', 'America'),
    ('America/North_Dakota/New_Salem', 'America'),
    ('America/Ojinaga', 'America'),
    ('America/Panama', 'America'),
    ('America/Pangnirtung', 'America'),
    ('America/Paramaribo', 'America'),
    ('America/Phoenix', 'America'),
    ('America/Port-au-Prince', 'America'),
    ('America/Port_of_Spain', 'America'),
    ('America/Porto_Acre', 'America'),
    ('America/Porto_Velho', 'America'),
    ('America/Puerto_Rico', 'America'),
    ('America/Punta_Arenas', 'America'),
    ('America/Rainy_River', 'America'),
    ('America/Rankin_Inlet', 'America'),
    ('America/Recife', 'America'),
    ('America/Regina', 'America'),
    ('America/Resolute', 'America'),
    ('America/Rio_Branco', 'America'),
    ('America/Rosario', 'America'),
    ('America/Santa_Isabel', 'America'),
    ('America/Santarem', 'America'),
    ('America/Santiago', 'America'),
    ('America/Santo_Domingo', 'America'),
    ('America/Sao_Paulo', 'America'),
    ('America/Scoresbysund', 'America'),
    ('America/Shiprock', 'America'),
    ('America/Sitka', 'America'),
    ('America/St_Barthelemy', 'America'),
    ('America/St_Johns', 'America'),
    ('America/St_Kitts', 'America'),
    ('America/St_Lucia', 'America'),
    ('America/St_Thomas', 'America'),
    ('America/St_Vincent', 'America'),
    ('America/Swift_Current', 'America'),
    ('America/Tegucigalpa', 'America'),
    ('America/Thule', 'America'),
    ('America/Thunder_Bay', 'America'),
    ('America/Tijuana', 'America'),
    ('America/Toronto', 'America'),
    ('America/Tortola', 'America'),
    ('America/Vancouver', 'America'),
    ('America/Virgin', 'America'),
    ('America/Whitehorse', 'America'),
    ('America/Winnipeg', 'America'),
    ('America/Yakutat', 'America'),
    ('America/Yellowknife', 'America'),
    ('Antarctica/Casey', 'Antarctica'),
    ('Antarctica/Davis', 'Antarctica'),
    ('Antarctica/DumontDUrville', 'Antarctica'),
    ('Antarctica/Macquarie', 'Antarctica'),
    ('Antarctica/Mawson', 'Antarctica'),
    ('Antarctica/McMurdo', 'Antarctica'),
    ('Antarctica/Palmer', 'Antarctica'),
    ('Antarctica/Rothera', 'Antarctica'),
    ('Antarctica/South_Pole', 'Antarctica'),
    ('Antarctica/Syowa', 'Antarctica'),
    ('Antarctica/Troll', 'Antarctica'),
    ('Antarctica/Vostok', 'Antarctica'),
    ('Arctic/Longyearbyen', 'Arctic'),
    ('Asia/Aden', 'Asia'),
    ('Asia/Almaty', 'Asia'),
    ('Asia/Amman', 'Asia'),
    ('Asia/Anadyr', 'Asia'),
    ('Asia/Aqtau', 'Asia'),
    ('Asia/Aqtobe', 'Asia'),
    ('Asia/Ashgabat', 'Asia'),
    ('Asia/Ashkhabad', 'Asia'),
    ('Asia/Atyrau', 'Asia'),
    ('Asia/Baghdad', 'Asia'),
    ('Asia/Bahrain', 'Asia'),
    ('Asia/Baku', 'Asia'),
    ('Asia/Bangkok', 'Asia'),
    ('Asia/Barnaul', 'Asia'),
    ('Asia/Beirut', 'Asia'),
    ('Asia/Bishkek', 'Asia'),
    ('Asia/Brunei', 'Asia'),
    ('Asia/Calcutta', 'Asia'),
    ('Asia/Chita', 'Asia'),
    ('Asia/Choibalsan', 'Asia'),
    ('Asia/Chongqing', 'Asia'),
    ('Asia/Chungking', 'Asia'),
    ('Asia/Colombo', 'Asia'),
    ('Asia/Dacca', 'Asia'),
    ('Asia/Damascus', 'Asia'),
    ('Asia/Dhaka', 'Asia'),
    ('Asia/Dili', 'Asia'),
    ('Asia/Dubai', 'Asia'),
    ('Asia/Dushanbe', 'Asia'),
    ('Asia/Famagusta', 'Asia'),
    ('Asia/Gaza', 'Asia'),
    ('Asia/Harbin', 'Asia'),
    ('Asia/Hebron', 'Asia'),
    ('Asia/Ho_Chi_Minh', 'Asia'),
    ('Asia/Hong_Kong', 'Asia'),
    ('Asia/Hovd', 'Asia'),
    ('Asia/Irkutsk', 'Asia'),
    ('Asia/Istanbul', 'Asia'),
    ('Asia/Jakarta', 'Asia'),
    ('Asia/Jayapura', 'Asia'),
    ('Asia/Jerusalem', 'Asia'),
    ('Asia/Kabul', 'Asia'),
    ('Asia/Kamchatka', 'Asia'),
    ('Asia/Karachi', 'Asia'),
    ('Asia/Kashgar', 'Asia'),
    ('Asia/Kathmandu', 'Asia'),
    ('Asia/Katmandu', 'Asia'),
    ('Asia/Khandyga', 'Asia'),
    ('Asia/Kolkata', 'Asia'),
    ('Asia/Krasnoyarsk', 'Asia'),
    ('Asia/Kuala_Lumpur', 'Asia'),
    ('Asia/Kuching', 'Asia'),
    ('Asia/Kuwait', 'Asia'),
    ('Asia/Macao', 'Asia'),
    ('Asia/Macau', 'Asia'),
    ('Asia/Magadan', 'Asia'),
    ('Asia/Makassar', 'Asia'),
    ('Asia/Manila', 'Asia'),
    ('Asia/Muscat', 'Asia'),
    ('Asia/Nicosia', 'Asia'),
    ('Asia/Novokuznetsk', 'Asia'),
    ('Asia/Novosibirsk', 'Asia'),
    ('Asia/Omsk', 'Asia'),
    ('Asia/Oral', 'Asia'),
    ('Asia/Phnom_Penh', 'Asia'),
    ('Asia/Pontianak', 'Asia'),
    ('Asia/Pyongyang', 'Asia'),
    ('Asia/Qatar', 'Asia'),
    ('Asia/Qostanay', 'Asia'),
    ('Asia/Qyzylorda', 'Asia'),
    ('Asia/Rangoon', 'Asia'),
    ('Asia/Riyadh', 'Asia'),
    ('Asia/Saigon', 'Asia'),
    ('Asia/Sakhalin', 'Asia'),
    ('Asia/Samarkand', 'Asia'),
    ('Asia/Seoul', 'Asia'),
    ('Asia/Shanghai', 'Asia'),
    ('Asia/Singapore', 'Asia'),
    ('Asia/Srednekolymsk', 'Asia'),
    ('Asia/Taipei', 'Asia'),
    ('Asia/Tashkent', 'Asia'),
    ('Asia/Tbilisi', 'Asia'),
    ('Asia/Tehran', 'Asia'),
    ('Asia/Tel_Aviv', 'Asia'),
    ('Asia/Thimbu', 'Asia'),
    ('Asia/Thimphu', 'Asia'),
    ('Asia/Tokyo', 'Asia'),
    ('Asia/Tomsk', 'Asia'),
    ('Asia/Ujung_Pandang', 'Asia'),
    ('Asia/Ulaanbaatar', 'Asia'),
    ('Asia/Ulan_Bator', 'Asia'),
    ('Asia/Urumqi', 'Asia'),
    ('Asia/Ust-Nera', 'Asia'),
    ('Asia/Vientiane', 'Asia'),
    ('Asia/Vladivostok', 'Asia'),
    ('Asia/Yakutsk', 'Asia'),
    ('Asia/Yangon', 'Asia'),
    ('Asia/Yekaterinburg', 'Asia'),
    ('Asia/Yerevan', 'Asia'),
    ('Atlantic/Azores', 'Atlantic'),
    ('Atlantic/Bermuda', 'Atlantic'),
    ('Atlantic/Canary', 'Atlantic'),
    ('Atlantic/Cape_Verde', 'Atlantic'),
    ('Atlantic/Faeroe', 'Atlantic'),
    ('Atlantic/Faroe', 'Atlantic'),
    ('Atlantic/Jan_Mayen', 'Atlantic'),
    ('Atlantic/Madeira', 'Atlantic'),
    ('Atlantic/Reykjavik', 'Atlantic'),
    ('Atlantic/South_Georgia', 'Atlantic'),
    ('Atlantic/St_Helena', 'Atlantic'),
    ('Atlantic/Stanley', 'Atlantic'),
    ('Australia/ACT', 'Australia'),
    ('Australia/Adelaide', 'Australia'),
    ('Australia/Brisbane', 'Australia'),
    ('Australia/Broken_Hill', 'Australia'),
    ('Australia/Canberra', 'Australia'),
    ('Australia/Currie', 'Australia'),
    ('Australia/Darwin', 'Australia'),
    ('Australia/Eucla', 'Australia'),
    ('Australia/Hobart', 'Australia'),
    ('Australia/LHI', 'Australia'),
    ('Australia/Lindeman', 'Australia'),
    ('Australia/Lord_Howe', 'Australia'),
    ('Australia/Melbourne', 'Australia'),
    ('Australia/North', 'Australia'),
    ('Australia/NSW', 'Australia'),
    ('Australia/Perth', 'Australia'),
    ('Australia/Queensland', 'Australia'),
    ('Australia/South', 'Australia'),
    ('Australia/Sydney', 'Australia'),
    ('Australia/Tasmania', 'Australia'),
    ('Australia/Victoria', 'Australia'),
    ('Australia/West', 'Australia'),
    ('Australia/Yancowinna', 'Australia'),
    ('Brazil/Acre', 'Brazil'),
    ('Brazil/DeNoronha', 'Brazil'),
    ('Brazil/East', 'Brazil'),
    ('Brazil/West', 'Brazil'),
    ('Canada/Atlantic', 'Canada'),
    ('Canada/Central', 'Canada'),
    ('Canada/Eastern', 'Canada'),
    ('Canada/Mountain', 'Canada'),
    ('Canada/Newfoundland', 'Canada'),
    ('Canada/Pacific', 'Canada'),
    ('Canada/Saskatchewan', 'Canada'),
    ('Canada/Yukon', 'Canada'),
    ('CET', 'CET'),
    ('Chile/Continental', 'Chile'),
    ('Chile/EasterIsland', 'Chile'),
    ('CST6CDT', 'CST6CDT'),
    ('Cuba', 'Cuba'),
    ('EET', 'EET'),
    ('Egypt', 'Egypt'),
    ('Eire', 'Eire'),
    ('EST5EDT', 'EST5EDT'),
    ('Etc/GMT', 'Etc'),
    ('Etc/GMT+0', 'Etc'),
    ('Etc/GMT+1', 'Etc'),
    ('Etc/GMT+10', 'Etc'),
    ('Etc/GMT+11', 'Etc'),
    ('Etc/GMT+12', 'Etc'),
    ('Etc/GMT+2', 'Etc'),
    ('Etc/GMT+3', 'Etc'),
    ('Etc/GMT+4', 'Etc'),
    ('Etc/GMT+5', 'Etc'),
    ('Etc/GMT+6', 'Etc'),
    ('Etc/GMT+7', 'Etc'),
    ('Etc/GMT+8', 'Etc'),
    ('Etc/GMT+9', 'Etc'),
    ('Etc/GMT-0', 'Etc'),
    ('Etc/GMT-1', 'Etc'),
    ('Etc/GMT-10', 'Etc'),
    ('Etc/GMT-11', 'Etc'),
    ('Etc/GMT-12', 'Etc'),
    ('Etc/GMT-13', 'Etc'),
    ('Etc/GMT-14', 'Etc'),
    ('Etc/GMT-2', 'Etc'),
    ('Etc/GMT-3', 'Etc'),
    ('Etc/GMT-4', 'Etc'),
    ('Etc/GMT-5', 'Etc'),
    ('Etc/GMT-6', 'Etc'),
    ('Etc/GMT-7', 'Etc'),
    ('Etc/GMT-8', 'Etc'),
    ('Etc/GMT-9', 'Etc'),
    ('Etc/GMT0', 'Etc'),
    ('Etc/Greenwich', 'Etc'),
    ('Etc/UCT', 'Etc'),
    ('Etc/Universal', 'Etc'),
    ('Etc/UTC', 'Etc'),
    ('Etc/Zulu', 'Etc'),
    ('Europe/Amsterdam', 'Europe'),
    ('Europe/Andorra', 'Europe'),
    ('Europe/Astrakhan', 'Europe'),
    ('Europe/Athens', 'Europe'),
    ('Europe/Belfast', 'Europe'),
    ('Europe/Belgrade', 'Europe'),
    ('Europe/Berlin', 'Europe'),
    ('Europe/Bratislava', 'Europe'),
    ('Europe/Brussels', 'Europe'),
    ('Europe/Bucharest', 'Europe'),
    ('Europe/Budapest', 'Europe'),
    ('Europe/Busingen', 'Europe'),
    ('Europe/Chisinau', 'Europe'),
    ('Europe/Copenhagen', 'Europe'),
    ('Europe/Dublin', 'Europe'),
    ('Europe/Gibraltar', 'Europe'),
    ('Europe/Guernsey', 'Europe'),
    ('Europe/Helsinki', 'Europe'),
    ('Europe/Isle_of_Man', 'Europe'),
    ('Europe/Istanbul', 'Europe'),
    ('Europe/Jersey', 'Europe'),
    ('Europe/Kaliningrad', 'Europe'),
    ('Europe/Kiev', 'Europe'),
    ('Europe/Kirov', 'Europe'),
    ('Europe/Lisbon', 'Europe'),
    ('Europe/Ljubljana', 'Europe'),
    ('Europe/London', 'Europe'),
    ('Europe/Luxembourg', 'Europe'),
    ('Europe/Madrid', 'Europe'),
    ('Europe/Malta', 'Europe'),
    ('Europe/Mariehamn', 'Europe'),
    ('Europe/Minsk', 'Europe'),
    ('Europe/Monaco', 'Europe'),
    ('Europe/Moscow', 'Europe'),
    ('Europe/Nicosia', 'Europe'),
    ('Europe/Oslo', 'Europe'),
    ('Europe/Paris', 'Europe'),
    ('Europe/Podgorica', 'Europe'),
    ('Europe/Prague', 'Europe'),
    ('Europe/Riga', 'Europe'),
    ('Europe/Rome', 'Europe'),
    ('Europe/Samara', 'Europe'),
    ('Europe/San_Marino', 'Europe'),
    ('Europe/Sarajevo', 'Europe'),
    ('Europe/Saratov', 'Europe'),
    ('Europe/Simferopol', 'Europe'),
    ('Europe/Skopje', 'Europe'),
    ('Europe/Sofia', 'Europe'),
    ('Europe/Stockholm', 'Europe'),
    ('Europe/Tallinn', 'Europe'),
    ('Europe/Tirane', 'Europe'),
    ('Europe/Tiraspol', 'Europe'),
    ('Europe/Ulyanovsk', 'Europe'),
    ('Europe/Uzhgorod', 'Europe'),
    ('Europe/Vaduz', 'Europe'),
    ('Europe/Vatican', 'Europe'),
    ('Europe/Vienna', 'Europe'),
    ('Europe/Vilnius', 'Europe'),
    ('Europe/Volgograd', 'Europe'),
    ('Europe/Warsaw', 'Europe'),
    ('Europe/Zagreb', 'Europe'),
    ('Europe/Zaporozhye', 'Europe'),
    ('Europe/Zurich', 'Europe'),
    ('GB', 'GB'),
    ('GB-Eire', 'GB-Eire'),
    ('GMT', 'GMT'),
    ('GMT0', 'GMT0'),
    ('Greenwich', 'Greenwich'),
    ('Hongkong', 'Hongkong'),
    ('Iceland', 'Iceland'),
    ('Indian/Antananarivo', 'Indian'),
    ('Indian/Chagos', 'Indian'),
    ('Indian/Christmas', 'Indian'),
    ('Indian/Cocos', 'Indian'),
    ('Indian/Comoro', 'Indian'),
    ('Indian/Kerguelen', 'Indian'),
    ('Indian/Mahe', 'Indian'),
    ('Indian/Maldives', 'Indian'),
    ('Indian/Mauritius', 'Indian'),
    ('Indian/Mayotte', 'Indian'),
    ('Indian/Reunion', 'Indian'),
    ('Iran', 'Iran'),
    ('Israel', 'Israel'),
    ('Jamaica', 'Jamaica'),
    ('Japan', 'Japan'),
    ('Kwajalein', 'Kwajalein'),
    ('Libya', 'Libya'),
    ('MET', 'MET'),
    ('Mexico/BajaNorte', 'Mexico'),
    ('Mexico/BajaSur', 'Mexico'),
    ('Mexico/General', 'Mexico'),
    ('MST7MDT', 'MST7MDT'),
    ('Navajo', 'Navajo'),
    ('NZ', 'NZ'),
    ('NZ-CHAT', 'NZ-CHAT'),
    ('Pacific/Apia', 'Pacific'),
    ('Pacific/Auckland', 'Pacific'),
    ('Pacific/Bougainville', 'Pacific'),
    ('Pacific/Chatham', 'Pacific'),
    ('Pacific/Chuuk', 'Pacific'),
    ('Pacific/Easter', 'Pacific'),
    ('Pacific/Efate', 'Pacific'),
    ('Pacific/Enderbury', 'Pacific'),
    ('Pacific/Fakaofo', 'Pacific'),
    ('Pacific/Fiji', 'Pacific'),
    ('Pacific/Funafuti', 'Pacific'),
    ('Pacific/Galapagos', 'Pacific'),
    ('Pacific/Gambier', 'Pacific'),
    ('Pacific/Guadalcanal', 'Pacific'),
    ('Pacific/Guam', 'Pacific'),
    ('Pacific/Honolulu', 'Pacific'),
    ('Pacific/Johnston', 'Pacific'),
    ('Pacific/Kiritimati', 'Pacific'),
    ('Pacific/Kosrae', 'Pacific'),
    ('Pacific/Kwajalein', 'Pacific'),
    ('Pacific/Majuro', 'Pacific'),
    ('Pacific/Marquesas', 'Pacific'),
    ('Pacific/Midway', 'Pacific'),
    ('Pacific/Nauru', 'Pacific'),
    ('Pacific/Niue', 'Pacific'),
    ('Pacific/Norfolk', 'Pacific'),
    ('Pacific/Noumea', 'Pacific'),
    ('Pacific/Pago_Pago', 'Pacific'),
    ('Pacific/Palau', 'Pacific'),
    ('Pacific/Pitcairn', 'Pacific'),
    ('Pacific/Pohnpei', 'Pacific'),
    ('Pacific/Ponape', 'Pacific'),
    ('Pacific/Port_Moresby', 'Pacific'),
    ('Pacific/Rarotonga', 'Pacific'),
    ('Pacific/Saipan', 'Pacific'),
    ('Pacific/Samoa', 'Pacific'),
    ('Pacific/Tahiti', 'Pacific'),
    ('Pacific/Tarawa', 'Pacific'),
    ('Pacific/Tongatapu', 'Pacific'),
    ('Pacific/Truk', 'Pacific'),
    ('Pacific/Wake', 'Pacific'),
    ('Pacific/Wallis', 'Pacific'),
    ('Pacific/Yap', 'Pacific'),
    ('Poland', 'Poland'),
    ('Portugal', 'Portugal'),
    ('PRC', 'PRC'),
    ('PST8PDT', 'PST8PDT'),
    ('ROK', 'ROK'),
    ('Singapore', 'Singapore'),
    ('SystemV/AST4', 'SystemV'),
    ('SystemV/AST4ADT', 'SystemV'),
    ('SystemV/CST6', 'SystemV'),
    ('SystemV/CST6CDT', 'SystemV'),
    ('SystemV/EST5', 'SystemV'),
    ('SystemV/EST5EDT', 'SystemV'),
    ('SystemV/HST10', 'SystemV'),
    ('SystemV/MST7', 'SystemV'),
    ('SystemV/MST7MDT', 'SystemV'),
    ('SystemV/PST8', 'SystemV'),
    ('SystemV/PST8PDT', 'SystemV'),
    ('SystemV/YST9', 'SystemV'),
    ('SystemV/YST9YDT', 'SystemV'),
    ('Turkey', 'Turkey'),
    ('UCT', 'UCT'),
    ('Universal', 'Universal'),
    ('US/Alaska', 'US'),
    ('US/Aleutian', 'US'),
    ('US/Arizona', 'US'),
    ('US/Central', 'US'),
    ('US/East-Indiana', 'US'),
    ('US/Eastern', 'US'),
    ('US/Hawaii', 'US'),
    ('US/Indiana-Starke', 'US'),
    ('US/Michigan', 'US'),
    ('US/Mountain', 'US'),
    ('US/Pacific', 'US'),
    ('US/Pacific-New', 'US'),
    ('US/Samoa', 'US'),
    ('UTC', 'UTC'),
    ('W-SU', 'W-SU'),
    ('WET', 'WET'),
    ('Zulu', 'Zulu')
    ON CONFLICT (time_zone, short_zone) DO NOTHING;

COMMIT;