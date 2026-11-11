package SmartState.Engine;

import org.json.JSONArray;
import org.json.JSONObject;

public interface MachineServices {

    void insertStateLog(MachineInstance machine, String logJson);

    void uploadSaveState(MachineInstance machine, String stateJson);

    void sendSms(MachineInstance machine, String body);

    void sendScheduledSms(MachineInstance machine, String body, long deliverAtEpoch);

    void notifyAdmins(MachineInstance machine, String body);

    void notifyAdminsScheduled(MachineInstance machine, String body, long deliverAtEpoch);

    String generateSurveyToken(MachineInstance machine, String surveyKey, String questionsJson);

    void saveSurveyResponses(JSONObject answers, String token);

    JSONObject llmQuery(JSONArray messages);

    void saveDeviceResult(MachineInstance machine, String stateName, String result);

    String getDeviceResult(MachineInstance machine);

    String getParticipantTimezone(String participantUuid);

    String surveyUrl();

    void saveEventTime(MachineInstance machine, String stateName, String field, long epochSeconds);

    void flagLastIncomingMessage(MachineInstance machine, String flag);

    long[] getTreCounters(MachineInstance machine, String anchorState);

    void setTreCounters(MachineInstance machine, String anchorState, long successful, long total, long after8pm);

    void setEatingWindowDuration(MachineInstance machine, String anchorState, long seconds);

    String adminPhone(MachineInstance machine);

    String adminTimezone();
}
