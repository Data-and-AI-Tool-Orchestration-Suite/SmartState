package SmartState.Database;

import SmartState.Launcher;
import org.apache.commons.dbcp2.BasicDataSource;
import org.json.JSONObject;

import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class DBEngine {
    private BasicDataSource ds;

    public DBEngine() {
        try {
            String dbConnectionString = "jdbc:postgresql://" + Launcher.config.getStringParam("db_host") + ":" + Launcher.config.getStringParam("db_port") + "/" + Launcher.config.getStringParam("db_name");
            ds = new BasicDataSource();
            ds.setDriverClassName("org.postgresql.Driver");
            ds.setUrl(dbConnectionString);
            ds.setUsername(Launcher.config.getStringParam("db_user"));
            ds.setPassword(Launcher.config.getStringParam("db_password"));
            ds.setMaxTotal(16);
            ds.setMaxIdle(4);
            ds.setDefaultQueryTimeout(30);
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public boolean isHealthy() {
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement("SELECT 1");
             ResultSet rs = stmt.executeQuery()) {
            return rs.next();
        } catch (Exception ex) {
            return false;
        }
    }

    public List<Map<String, String>> getActiveEnrollments() {
        List<Map<String, String>> rows = new ArrayList<>();
        String query = "SELECT e.enrollment_uuid, e.participant_uuid, e.definition_uuid, e.protocol_name, e.study_uuid, " +
                "s.name AS study_name, pd.version, p.participant_json " +
                "FROM enrollments e " +
                "JOIN protocol_definitions pd ON pd.definition_uuid = e.definition_uuid " +
                "JOIN participants p ON p.participant_uuid = e.participant_uuid " +
                "JOIN studies s ON s.study_uuid = e.study_uuid " +
                "WHERE e.ended_at IS NULL AND pd.status IN ('published', 'archived')";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query);
             ResultSet rs = stmt.executeQuery()) {
            while (rs.next()) {
                rows.add(enrollmentRow(rs));
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return rows;
    }

    public Map<String, String> getActiveEnrollment(String participantUuid, String protocolName) {
        String query = "SELECT e.enrollment_uuid, e.participant_uuid, e.definition_uuid, e.protocol_name, e.study_uuid, " +
                "s.name AS study_name, pd.version, p.participant_json " +
                "FROM enrollments e " +
                "JOIN protocol_definitions pd ON pd.definition_uuid = e.definition_uuid " +
                "JOIN participants p ON p.participant_uuid = e.participant_uuid " +
                "JOIN studies s ON s.study_uuid = e.study_uuid " +
                "WHERE e.participant_uuid = ?::uuid AND e.protocol_name = ? AND e.ended_at IS NULL";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, protocolName);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return enrollmentRow(rs);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return null;
    }

    private Map<String, String> enrollmentRow(ResultSet rs) throws Exception {
        Map<String, String> row = new HashMap<>();
        row.put("enrollment_uuid", rs.getString("enrollment_uuid"));
        row.put("participant_uuid", rs.getString("participant_uuid"));
        row.put("definition_uuid", rs.getString("definition_uuid"));
        row.put("protocol_name", rs.getString("protocol_name"));
        row.put("study_uuid", rs.getString("study_uuid"));
        row.put("study_name", rs.getString("study_name"));
        row.put("version", rs.getString("version"));
        row.put("participant_json", rs.getString("participant_json"));
        return row;
    }

    public String getProtocolDefinitionJson(String definitionUuid) {
        String query = "SELECT definition FROM protocol_definitions WHERE definition_uuid = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, definitionUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return null;
    }

    public void pruneSaveStateEntries(String enrollmentUuid) {
        String query = "DELETE FROM save_state WHERE ts NOT IN (SELECT ts FROM save_state WHERE enrollment_uuid = ?::uuid ORDER BY ts DESC LIMIT 50) AND enrollment_uuid = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, enrollmentUuid);
            stmt.setString(2, enrollmentUuid);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public boolean uploadSaveState(String enrollmentUuid, String studyUuid, String stateJson) {
        if (enrollmentUuid == null || stateJson == null) {
            return false;
        }
        pruneSaveStateEntries(enrollmentUuid);
        String query = "INSERT INTO save_state (enrollment_uuid, study_uuid, ts, state_json) VALUES (?::uuid, ?::uuid, timezone('utc', now()), ?::jsonb)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, enrollmentUuid);
            stmt.setString(2, studyUuid);
            stmt.setString(3, stateJson);
            stmt.executeUpdate();
            return true;
        } catch (Exception ex) {
            ex.printStackTrace();
            return false;
        }
    }

    public String getSaveStateByEnrollment(String enrollmentUuid) {
        String query = "SELECT state_json FROM save_state WHERE enrollment_uuid = ?::uuid ORDER BY ts DESC LIMIT 1";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, enrollmentUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public void insertStateLog(String participantUuid, String enrollmentUuid, String studyUuid, String logJson) {
        String query = "INSERT INTO state_log (participant_uuid, enrollment_uuid, study_uuid, ts, log_json) VALUES (?::uuid, ?::uuid, ?::uuid, NOW(), ?::jsonb)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, enrollmentUuid);
            stmt.setString(3, studyUuid);
            stmt.setString(4, logJson);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public String getParticipantCurrentState(String participantUuid, String protocol) {
        String query = "SELECT log_json->>'state' FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'protocol' = ? ORDER BY ts DESC LIMIT 1";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, protocol);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public void insertOutgoingMessage(String messageUuid, String participantUuid, String studyUuid, String timestamp, String messageJson) {
        String query = "INSERT INTO messages (message_uuid, participant_uuid, study_uuid, ts, message_direction, message_json) VALUES (?::uuid, ?::uuid, ?::uuid, ?::timestamp, 'outgoing', ?::jsonb)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, messageUuid);
            stmt.setString(2, participantUuid);
            stmt.setString(3, studyUuid);
            stmt.setString(4, timestamp);
            stmt.setString(5, messageJson);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void insertIncomingMessage(String participantUuid, String studyUuid, String messageJson) {
        String query = "INSERT INTO messages (message_uuid, participant_uuid, study_uuid, ts, message_direction, message_json) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, NOW(), 'incoming', ?::jsonb)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, studyUuid);
            stmt.setString(3, messageJson);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void insertQueuedMessage(String messageUuid, String participantUuid, String studyUuid, String toNumber, String fromNumber, String scheduledFor, String messageJson) {
        String query = "INSERT INTO queued_messages (message_uuid, participant_uuid, study_uuid, toNumber, fromNumber, scheduledFor, message_json) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?::timestamp, ?::jsonb)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, messageUuid);
            stmt.setString(2, participantUuid);
            stmt.setString(3, studyUuid);
            stmt.setString(4, toNumber);
            stmt.setString(5, fromNumber);
            stmt.setString(6, scheduledFor);
            stmt.setString(7, messageJson);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void processDueQueuedMessages() {
        String selectQuery = "SELECT message_uuid, participant_uuid, study_uuid, toNumber, message_json->>'Body' AS body FROM queued_messages WHERE scheduledFor <= NOW() FOR UPDATE SKIP LOCKED";
        String deleteQuery = "DELETE FROM queued_messages WHERE message_uuid = ?::uuid";
        try (Connection conn = ds.getConnection()) {
            conn.setAutoCommit(false);
            try (PreparedStatement stmt = conn.prepareStatement(selectQuery);
                 ResultSet rs = stmt.executeQuery()) {
                while (rs.next()) {
                    Launcher.msgUtils.sendMessage(rs.getString("toNumber"), rs.getString("body"),
                            rs.getString("participant_uuid"), rs.getString("study_uuid"));
                    try (PreparedStatement delete = conn.prepareStatement(deleteQuery)) {
                        delete.setString(1, rs.getString("message_uuid"));
                        delete.executeUpdate();
                    }
                }
                conn.commit();
            } catch (Exception ex) {
                conn.rollback();
                throw ex;
            } finally {
                conn.setAutoCommit(true);
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public List<String[]> getStaffParticipants(String studyUuid) {
        List<String[]> staff = new ArrayList<>();
        String query = "SELECT participant_uuid, participant_json->>'number' AS number FROM participants WHERE study_uuid = ?::uuid AND participant_json->>'staff' = 'true'";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, studyUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                while (rs.next()) {
                    staff.add(new String[]{rs.getString("participant_uuid"), rs.getString("number")});
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return staff;
    }

    public List<Map<String, String>> getParticipantsByPhone(String phoneNumber) {
        List<Map<String, String>> rows = new ArrayList<>();
        String query = "SELECT p.participant_uuid, p.study_uuid, s.name AS study_name FROM participants p JOIN studies s ON s.study_uuid = p.study_uuid WHERE p.participant_json->>'number' = ?";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, phoneNumber);
            try (ResultSet rs = stmt.executeQuery()) {
                while (rs.next()) {
                    Map<String, String> row = new HashMap<>();
                    row.put("participant_uuid", rs.getString("participant_uuid"));
                    row.put("study_uuid", rs.getString("study_uuid"));
                    row.put("study_name", rs.getString("study_name"));
                    rows.add(row);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return rows;
    }

    public String getParticipantIdFromDevice(String devEUI) {
        String query = "SELECT participant_uuid FROM participants WHERE participant_json->>'devEUI' = ?";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, devEUI.toLowerCase());
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public String getParticipantTimezone(String participantUuid) {
        String query = "SELECT participant_json->>'time_zone' AS tz FROM participants WHERE participant_uuid = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString("tz");
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public String getStudyFromNumber(String studyUuid) {
        String query = "SELECT settings->>'twilio_from_number' FROM studies WHERE study_uuid = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, studyUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return null;
    }

    public void saveDeviceResult(String participantUuid, String stateName, String result) {
        String query = "UPDATE state_log SET log_json = jsonb_set(log_json, '{deviceReading}', to_jsonb(?)) WHERE ts IN (SELECT ts FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = ? ORDER BY ts DESC LIMIT 1)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, result);
            stmt.setString(2, participantUuid);
            stmt.setString(3, stateName);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public String getDeviceResult(String participantUuid) {
        String query = "SELECT log_json->>'deviceReading' AS reading FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'deviceReading' IS NOT NULL ORDER BY ts DESC LIMIT 1";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString("reading");
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public String generateAndSaveToken(String participantUuid, String studyUuid, String enrollmentUuid, String surveyKey, String questionsJson) {
        String query = "INSERT INTO surveys (token, participant_uuid, study_uuid, enrollment_uuid, survey_key, questions_json, created_at) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, ?, ?::jsonb, (now() at time zone 'utc')) RETURNING token";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, studyUuid);
            stmt.setString(3, enrollmentUuid);
            stmt.setString(4, surveyKey);
            stmt.setString(5, questionsJson);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return "";
    }

    public void saveSurveyResponses(JSONObject answers, String token) {
        if (token == null) {
            return;
        }
        String query = "UPDATE surveys SET finished_at = timezone('utc', now()), survey_json = ?::jsonb WHERE token = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, answers.toString());
            stmt.setString(2, token);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void saveEventTime(String participantUuid, String stateName, String field, long epochSeconds) {
        String query = "UPDATE state_log SET log_json = jsonb_set(log_json, ARRAY[?], to_jsonb(?::bigint)) WHERE ts IN (SELECT ts FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = ? ORDER BY ts DESC LIMIT 1)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, field);
            stmt.setLong(2, epochSeconds);
            stmt.setString(3, participantUuid);
            stmt.setString(4, stateName);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void flagLastIncomingMessage(String participantUuid, String flag) {
        String query = "UPDATE messages SET message_json = jsonb_set(message_json, ARRAY[?], to_jsonb('true'::text)) WHERE ts IN (SELECT ts FROM messages WHERE participant_uuid = ?::uuid AND message_direction = 'incoming' ORDER BY ts DESC LIMIT 1)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, flag);
            stmt.setString(2, participantUuid);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public long[] getTreCounters(String participantUuid, String anchorState) {
        String query = "SELECT COALESCE((log_json->>'successful_TRE')::bigint, 0), COALESCE((log_json->>'total_TRE')::bigint, 0), COALESCE((log_json->>'after8pm')::bigint, 0) FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = ? AND log_json ?? 'total_TRE' ORDER BY ts DESC LIMIT 1";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, participantUuid);
            stmt.setString(2, anchorState);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return new long[]{rs.getLong(1), rs.getLong(2), rs.getLong(3)};
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return new long[]{0, 0, 0};
    }

    public void setTreCounters(String participantUuid, String anchorState, long successful, long total, long after8pm) {
        String query = "UPDATE state_log SET log_json = log_json || jsonb_build_object('successful_TRE', ?::bigint, 'total_TRE', ?::bigint, 'after8pm', ?::bigint) WHERE ts IN (SELECT ts FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = ? ORDER BY ts DESC LIMIT 1)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setLong(1, successful);
            stmt.setLong(2, total);
            stmt.setLong(3, after8pm);
            stmt.setString(4, participantUuid);
            stmt.setString(5, anchorState);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public void setEatingWindowDuration(String participantUuid, String anchorState, long seconds) {
        String query = "UPDATE state_log SET log_json = jsonb_set(log_json, '{eating_window_duration}', to_jsonb(?::bigint)) WHERE ts IN (SELECT ts FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = ? ORDER BY ts DESC LIMIT 1)";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setLong(1, seconds);
            stmt.setString(2, participantUuid);
            stmt.setString(3, anchorState);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public String getStudyContactPhone(String studyUuid) {
        String query = "SELECT contact_phone FROM studies WHERE study_uuid = ?::uuid";
        try (Connection conn = ds.getConnection();
             PreparedStatement stmt = conn.prepareStatement(query)) {
            stmt.setString(1, studyUuid);
            try (ResultSet rs = stmt.executeQuery()) {
                if (rs.next()) {
                    return rs.getString(1);
                }
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        }
        return null;
    }

    public void close() {
        try {
            ds.close();
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }
}
