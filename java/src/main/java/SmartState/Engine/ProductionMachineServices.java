package SmartState.Engine;

import SmartState.Launcher;
import org.json.JSONArray;
import org.json.JSONObject;

public class ProductionMachineServices implements MachineServices {

    @Override
    public void insertStateLog(MachineInstance machine, String logJson) {
        Launcher.dbEngine.insertStateLog(machine.getParticipantUuid(), machine.getEnrollmentUuid(), machine.getStudyUuid(), logJson);
    }

    @Override
    public void uploadSaveState(MachineInstance machine, String stateJson) {
        Launcher.dbEngine.uploadSaveState(machine.getEnrollmentUuid(), machine.getStudyUuid(), stateJson);
    }

    @Override
    public void sendSms(MachineInstance machine, String body) {
        Launcher.msgUtils.sendMessage(machine.getParticipantMap().get("number"), body,
                machine.getParticipantUuid(), machine.getStudyUuid());
    }

    @Override
    public void sendScheduledSms(MachineInstance machine, String body, long deliverAtEpoch) {
        Launcher.msgUtils.sendScheduledMessage(machine.getParticipantMap().get("number"), body,
                java.time.Instant.ofEpochSecond(deliverAtEpoch).atZone(java.time.ZoneId.of("UTC")),
                machine.getParticipantUuid(), machine.getStudyUuid());
    }

    @Override
    public void notifyAdmins(MachineInstance machine, String body) {
        for (String[] staff : Launcher.dbEngine.getStaffParticipants(machine.getStudyUuid())) {
            Launcher.msgUtils.sendMessage(staff[1], body, staff[0], machine.getStudyUuid());
        }
    }

    @Override
    public void notifyAdminsScheduled(MachineInstance machine, String body, long deliverAtEpoch) {
        for (String[] staff : Launcher.dbEngine.getStaffParticipants(machine.getStudyUuid())) {
            Launcher.msgUtils.sendScheduledMessage(staff[1], body,
                    java.time.Instant.ofEpochSecond(deliverAtEpoch).atZone(java.time.ZoneId.of("UTC")),
                    staff[0], machine.getStudyUuid());
        }
    }

    @Override
    public String generateSurveyToken(MachineInstance machine, String surveyKey, String questionsJson) {
        return Launcher.dbEngine.generateAndSaveToken(machine.getParticipantUuid(), machine.getStudyUuid(),
                machine.getEnrollmentUuid(), surveyKey, questionsJson);
    }

    @Override
    public void saveSurveyResponses(JSONObject answers, String token) {
        Launcher.dbEngine.saveSurveyResponses(answers, token);
    }

    @Override
    public JSONObject llmQuery(JSONArray messages) {
        return Launcher.llmConnector.query(messages);
    }

    @Override
    public void saveDeviceResult(MachineInstance machine, String stateName, String result) {
        Launcher.dbEngine.saveDeviceResult(machine.getParticipantUuid(), stateName, result);
    }

    @Override
    public String getDeviceResult(MachineInstance machine) {
        return Launcher.dbEngine.getDeviceResult(machine.getParticipantUuid());
    }

    @Override
    public String getParticipantTimezone(String participantUuid) {
        return Launcher.dbEngine.getParticipantTimezone(participantUuid);
    }

    @Override
    public String surveyUrl() {
        return Launcher.surveyURL;
    }

    @Override
    public void saveEventTime(MachineInstance machine, String stateName, String field, long epochSeconds) {
        Launcher.dbEngine.saveEventTime(machine.getParticipantUuid(), stateName, field, epochSeconds);
    }

    @Override
    public void flagLastIncomingMessage(MachineInstance machine, String flag) {
        Launcher.dbEngine.flagLastIncomingMessage(machine.getParticipantUuid(), flag);
    }

    @Override
    public long[] getTreCounters(MachineInstance machine, String anchorState) {
        return Launcher.dbEngine.getTreCounters(machine.getParticipantUuid(), anchorState);
    }

    @Override
    public void setTreCounters(MachineInstance machine, String anchorState, long successful, long total, long after8pm) {
        Launcher.dbEngine.setTreCounters(machine.getParticipantUuid(), anchorState, successful, total, after8pm);
    }

    @Override
    public void setEatingWindowDuration(MachineInstance machine, String anchorState, long seconds) {
        Launcher.dbEngine.setEatingWindowDuration(machine.getParticipantUuid(), anchorState, seconds);
    }

    @Override
    public String adminPhone(MachineInstance machine) {
        String phone = Launcher.dbEngine.getStudyContactPhone(machine.getStudyUuid());
        if (phone == null || phone.isEmpty()) {
            phone = Launcher.config.getStringParam("admin_phone_number", "");
        }
        return phone;
    }

    @Override
    public String adminTimezone() {
        return Launcher.config.getStringParam("admin_time_zone", "UTC");
    }
}
