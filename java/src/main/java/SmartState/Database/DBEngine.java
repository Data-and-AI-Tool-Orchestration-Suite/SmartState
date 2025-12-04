package SmartState.Database;

import SmartState.Launcher;
import com.google.gson.Gson;
import org.apache.commons.dbcp2.*;
import org.apache.commons.pool2.ObjectPool;
import org.apache.commons.pool2.impl.GenericObjectPool;
import org.json.JSONObject;

import javax.sql.DataSource;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class DBEngine {
    private Gson gson;
    private DataSource ds;

    public DBEngine() {

        try {
            gson = new Gson();
            // Driver needs to be identified in order to load the namespace in the JVM
            String dbDriver = "org.postgresql.Driver";

            Class.forName(dbDriver).newInstance();

            String dbConnectionString = "jdbc:postgresql://" + Launcher.config.getStringParam("db_host") + ":"
                    + Launcher.config.getStringParam("db_port") + "/" + Launcher.config.getStringParam("db_name")
                    + "?ssl=false";

            ds = setupDataSource(dbConnectionString, Launcher.config.getStringParam("db_user"),
                    Launcher.config.getStringParam("db_password"));
        } catch (Exception ex) {
            ex.printStackTrace();
        }
    }

    public static DataSource setupDataSource(String connectURI, String login, String password) {
        //
        // First, we'll create a ConnectionFactory that the
        // pool will use to create Connections.
        // We'll use the DriverManagerConnectionFactory,
        // using to connect string passed in the command line
        // arguments.
        //
        ConnectionFactory connectionFactory;
        if ((login == null) && (password == null)) {
            connectionFactory = new DriverManagerConnectionFactory(connectURI, null);
        } else {
            connectionFactory = new DriverManagerConnectionFactory(connectURI,
                    login, password);
        }

        //
        // Next we'll create the PoolableConnectionFactory, which wraps
        // the "real" Connections created by the ConnectionFactory with
        // the classes that implement the pooling functionality.
        //
        PoolableConnectionFactory poolableConnectionFactory = new PoolableConnectionFactory(connectionFactory, null);

        //
        // Now we'll need a ObjectPool that serves as the
        // actual pool of connections.
        //
        // We'll use a GenericObjectPool instance, although
        // any ObjectPool implementation will suffice.
        //
        ObjectPool<PoolableConnection> connectionPool = new GenericObjectPool<>(poolableConnectionFactory);

        // Set the factory's pool property to the owning pool
        poolableConnectionFactory.setPool(connectionPool);

        //
        // Finally, we create the PoolingDriver itself,
        // passing in the object pool we created.
        //
        return new PoolingDataSource<>(connectionPool);
    }

    public int executeUpdate(String stmtString) {
        Connection conn = null;
        Statement stmt = null;
        int result = -1;
        try {
            conn = ds.getConnection();
            stmt = conn.createStatement();
            result = stmt.executeUpdate(stmtString);

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    public Map<String, String> getParticipantIdFromPhoneNumber(String phoneNumber) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        Map<String, String> participantId = new HashMap<>();
        try {
            String queryString = "SELECT participant_uuid, study FROM participants WHERE participant_json->>'number' = ?";

            conn = ds.getConnection();
            stmt = conn.prepareStatement(queryString);
            stmt.setString(1, phoneNumber);
            rs = stmt.executeQuery();

            while (rs.next()) {
                participantId.put(rs.getString("study"), rs.getString("participant_uuid"));
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return participantId;
    }

    public List<Map<String, String>> getParticipantMapByGroup(String study, String groupName) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        List<Map<String, String>> participantMaps = null;
        try {
            participantMaps = new ArrayList<>();

            String queryString = "SELECT participant_uuid, participant_json FROM participants WHERE EXISTS (SELECT 1 FROM jsonb_array_elements_text(participant_json->'group') AS elem WHERE elem = ?)";

            conn = ds.getConnection();
            stmt = conn.prepareStatement(queryString);
            stmt.setString(1, groupName);
            rs = stmt.executeQuery();

            while (rs.next()) {
                Map<String, String> participantMap = gson.fromJson(rs.getString("participant_json"), Map.class);
                participantMap.put("participant_uuid", rs.getString("participant_uuid"));
                participantMap.put("study", study);
                participantMaps.add(participantMap);
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }

        return participantMaps;
    }

    public String getEnrollmentUUIDFromName(String name) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String result = "";
        try {
            String query = "SELECT enrollment_uuid FROM enrollments WHERE protocol_type_uuid IN (SELECT protocol_type_uuid FROM protocol_types WHERE name = ?)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, name);
            rs = stmt.executeQuery();

            if (rs.next()) {
                result = rs.getString(1);
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    public String getEnrollmentName(String enrollUUID) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String result = "";

        try {
            String query = "SELECT name FROM protocol_types WHERE protocol_type_uuid IN (SELECT protocol_type_uuid FROM enrollments WHERE enrollment_uuid = ?::uuid)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, enrollUUID);
            rs = stmt.executeQuery();
            if (rs.next()) {
                result = rs.getString(1);
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    // remove all but latest 50 rows from save state
    public void pruneSaveStateEntries(String enrollment_uuid) {
        Connection conn = null;
        PreparedStatement stmt = null;
        try {
            String query = "DELETE FROM save_state WHERE ts NOT IN (SELECT ts FROM save_state WHERE enrollment_uuid = ?::uuid ORDER BY ts DESC LIMIT 50) AND enrollment_uuid = ?::uuid";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, enrollment_uuid);
            stmt.setString(2, enrollment_uuid);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
    }

    public boolean uploadSaveState(String stateJSON, String participant_uuid, String protocol) {
        Connection conn = null;
        PreparedStatement stmt = null;
        try {
            String enrollment_uuid = getEnrollmentUUID(participant_uuid, protocol);
            if (enrollment_uuid == null)
                return false;
            pruneSaveStateEntries(enrollment_uuid);
            String query = "INSERT INTO save_state (enrollment_uuid, ts, state_json) VALUES (?::uuid, timezone('utc', now()), ?::jsonb)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, enrollment_uuid);
            stmt.setString(2, stateJSON);
            stmt.executeUpdate();
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return true;
    }

    public String getParticipantCurrentState(String partUUID, String protocol) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String result = "";
        try {
            String query = "SELECT log_json->>'state' FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'protocol' = ? ORDER BY ts DESC LIMIT 1";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, partUUID);
            stmt.setString(2, protocol);
            rs = stmt.executeQuery();

            if (rs.next()) {
                result = rs.getString(1);
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    public String getSaveState(String participantUUID, String protocol) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String json = "";
        try {
            String query = "SELECT state_json FROM save_state WHERE enrollment_uuid = ?::uuid ORDER BY ts DESC LIMIT 1";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, getEnrollmentUUID(participantUUID, protocol));
            rs = stmt.executeQuery();

            if (rs.next()) {
                json = rs.getString(1);
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return json;
    }

    public void checkQueuedMessageDatabase() {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;

        try {
            String query = "SELECT message_uuid, study, toNumber, message_json->>'Body' AS body FROM queued_messages WHERE scheduledFor <= NOW()";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            rs = stmt.executeQuery();

            while (rs.next()) {
                String toNumber = rs.getString("toNumber");
                String messageJson = rs.getString("body");
                String messageId = rs.getString("message_uuid");
                String study = rs.getString("study");
                removeFromQueuedMessage(messageId);
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
    }

    public void removeFromQueuedMessage(String messageId) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;

        try {
            String query = "DELETE FROM queued_messages where message_uuid = ?::uuid";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, messageId);
            stmt.executeUpdate();

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
    }

    public String getEnrollmentUUID(String uuid, String protocol) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String result = null;
        try {
            String query = "SELECT enrollment_uuid FROM enrollments WHERE participant_uuid = ?::uuid AND status = true AND protocol_type_uuid IN (SELECT protocol_type_uuid FROM protocol_types WHERE name = ?)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, uuid);
            stmt.setString(2, protocol);
            rs = stmt.executeQuery();

            if (rs.next()) {
                result = rs.getString(1);
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    public ArrayList<String> getAdminNumbers() {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        ArrayList<String> adminNumbers = new ArrayList<>();

        try {
            String query = "SELECT phone_number FROM users WHERE EXISTS (SELECT 1 FROM user_roles WHERE role_name = 'Study Admin' AND role_id = ANY(users.roles))";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            rs = stmt.executeQuery();

            while (rs.next()) {
                adminNumbers.add(rs.getString(1));
            }
        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return adminNumbers;
    }

    public String generateAndSaveToken(String participantUUID) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String token = "";

        try {
            String query = "INSERT INTO surveys (token, participant_uuid, created_at) VALUES (gen_random_uuid(), ?::uuid, (now() at time zone 'utc')) RETURNING token";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, participantUUID);
            rs = stmt.executeQuery();

            if (rs.next()) {
                token = rs.getString(1);
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return token;
    }

    public String getParticipantIdFromDevice(String devEUI) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String participantId = "";

        try {
            String query = "SELECT participant_uuid FROM participants WHERE participant_json->>'devEUI' = ?";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, devEUI.toLowerCase());
            rs = stmt.executeQuery();

            if (rs.next()) {
                participantId = rs.getString("participant_uuid");
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return participantId;
    }

    public void saveGlucoseResults(String participantUUID, String result) {
        Connection conn = null;
        PreparedStatement stmt = null;

        try {
            String query = "UPDATE state_log SET log_json = jsonb_set(log_json, '{glucoseReading}', to_jsonb(?)) WHERE ts IN (SELECT ts FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = 'startReading' ORDER BY ts DESC LIMIT 1)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, result);
            stmt.setString(2, participantUUID);
            stmt.executeUpdate();

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
    }

    public String getGlucoseResults(String participantUUID) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String result = "";

        try {
            String query = "SELECT log_json->>'glucoseReading' AS reading FROM state_log WHERE participant_uuid = ?::uuid AND log_json->>'state' = 'startReading' AND log_json->>'glucoseReading' IS NOT NULL ORDER BY ts DESC LIMIT 1";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, participantUUID);
            rs = stmt.executeQuery();

            if (rs.next()) {
                result = rs.getString("reading");
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return result;
    }

    public String getStudyFromParticipantId(String uuid) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String studies = "";

        try {
            String query = "SELECT study FROM participants WHERE participant_uuid=?";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, uuid);
            rs = stmt.executeQuery();

            if (rs.next()) {
                studies = rs.getString("study");
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return studies;
    }

    public Map<String, List<String>> getProtocolFromParticipantId(String uuid) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        Map<String, List<String>> protocol = new HashMap<>();

        try {
            String query = "SELECT study, name FROM protocol_types WHERE protocol_type_uuid IN (SELECT protocol_type_uuid FROM enrollments WHERE participant_uuid = ? AND status = true)";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, uuid);
            rs = stmt.executeQuery();

            while (rs.next()) {
                String study = rs.getString("study");
                if (!protocol.containsKey(study)) {
                    protocol.put(study, new ArrayList<>());
                }
                protocol.get(study).add(rs.getString("name"));
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return protocol;
    }

    public String getParticipantTimezone(String participantUUID) {
        Connection conn = null;
        PreparedStatement stmt = null;
        ResultSet rs = null;
        String tz = "";

        try {
            String query = "SELECT participant_json->>'time_zone' AS tz FROM participants WHERE participant_uuid = ?::uuid";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, participantUUID);
            rs = stmt.executeQuery();

            if (rs.next()) {
                tz = rs.getString("tz");
            }

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                rs.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
        return tz;
    }

    public void saveSurveyResponses(JSONObject answers, String token) {
        Connection conn = null;
        PreparedStatement stmt = null;

        try {
            String query = "UPDATE surveys SET finished_at=timezone('utc', now()), survey_json=?::jsonb WHERE token=?::uuid";
            conn = ds.getConnection();
            stmt = conn.prepareStatement(query);
            stmt.setString(1, answers.toString());
            stmt.setString(2, token);
            stmt.executeUpdate();

        } catch (Exception ex) {
            ex.printStackTrace();
        } finally {
            try {
                stmt.close();
            } catch (Exception e) {
                /* Null Ignored */ }
            try {
                conn.close();
            } catch (Exception e) {
                /* Null Ignored */ }
        }
    }
}
