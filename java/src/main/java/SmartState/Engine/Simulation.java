package SmartState.Engine;

import SmartState.TimeUtils.TimezoneHelper;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import org.json.JSONArray;
import org.json.JSONObject;

import java.time.Clock;
import java.time.Instant;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

public class Simulation {

    public static class MutableClock extends Clock {
        private Instant instant;

        public MutableClock(Instant instant) {
            this.instant = instant;
        }

        public void advanceSeconds(long seconds) {
            instant = instant.plusSeconds(seconds);
        }

        @Override
        public ZoneId getZone() {
            return ZoneOffset.UTC;
        }

        @Override
        public Clock withZone(ZoneId zone) {
            return this;
        }

        @Override
        public Instant instant() {
            return instant;
        }
    }

    static class SimTimerService extends TimerService {
        final Map<String, Long> deadlines = new LinkedHashMap<>();
        final Map<String, Long> generations = new HashMap<>();
        long generationCounter;
        final MutableClock clock;

        SimTimerService(MutableClock clock) {
            this.clock = clock;
            super.shutdown();
        }

        @Override
        public long schedule(MachineInstance machine, String timerName, long delaySeconds) {
            long generation = ++generationCounter;
            deadlines.put(timerName, clock.instant().getEpochSecond() + delaySeconds);
            generations.put(timerName, generation);
            return generation;
        }

        @Override
        public boolean isCurrent(MachineInstance machine, String timerName, long generation) {
            Long current = generations.get(timerName);
            return current != null && current == generation;
        }

        @Override
        public void cancel(String enrollmentUuid, String timerName) {
            deadlines.remove(timerName);
            generations.remove(timerName);
        }

        @Override
        public void cancelAll(String enrollmentUuid) {
            deadlines.clear();
            generations.clear();
        }

        void fireDue(MachineInstance machine) {
            boolean fired = true;
            while (fired) {
                fired = false;
                long now = clock.instant().getEpochSecond();
                for (Map.Entry<String, Long> entry : new LinkedHashMap<>(deadlines).entrySet()) {
                    if (entry.getValue() <= now) {
                        String timerName = entry.getKey();
                        long generation = generations.getOrDefault(timerName, 0L);
                        machine.onTimerFire(timerName, generation);
                        fired = true;
                        break;
                    }
                }
            }
        }
    }

    public static class CollectingServices implements MachineServices {
        public final List<Map<String, String>> stateLog = new ArrayList<>();
        public final List<String> smsMessages = new ArrayList<>();
        public final List<String> scheduledMessages = new ArrayList<>();
        public final List<String> adminMessages = new ArrayList<>();
        public final List<JSONObject> surveyResponses = new ArrayList<>();
        public final Map<String, Long> eventTimes = new LinkedHashMap<>();
        public long[] treCounters = new long[]{0, 0, 0};
        public long eatingWindow;
        public String deviceResult = "";
        public String llmReply = "1-2 times";
        public final com.google.gson.Gson gson = new com.google.gson.Gson();

        @Override
        public void insertStateLog(MachineInstance machine, String logJson) {
            Map<String, String> map = gson.fromJson(logJson, new com.google.gson.reflect.TypeToken<Map<String, String>>() { }.getType());
            stateLog.add(map);
        }

        @Override
        public void uploadSaveState(MachineInstance machine, String stateJson) {
        }

        @Override
        public void sendSms(MachineInstance machine, String body) {
            smsMessages.add(body);
        }

        @Override
        public void sendScheduledSms(MachineInstance machine, String body, long deliverAtEpoch) {
            scheduledMessages.add(body);
        }

        @Override
        public void notifyAdmins(MachineInstance machine, String body) {
            adminMessages.add(body);
        }

        @Override
        public void notifyAdminsScheduled(MachineInstance machine, String body, long deliverAtEpoch) {
            adminMessages.add(body);
        }

        @Override
        public String generateSurveyToken(MachineInstance machine, String surveyKey, String questionsJson) {
            return UUID.randomUUID().toString();
        }

        @Override
        public void saveSurveyResponses(JSONObject answers, String token) {
            surveyResponses.add(answers);
        }

        @Override
        public JSONObject llmQuery(JSONArray messages) {
            return new JSONObject().put("content", llmReply);
        }

        @Override
        public void saveDeviceResult(MachineInstance machine, String stateName, String result) {
            deviceResult = result;
        }

        @Override
        public String getDeviceResult(MachineInstance machine) {
            return deviceResult;
        }

        @Override
        public String getParticipantTimezone(String participantUuid) {
            return null;
        }

        @Override
        public String surveyUrl() {
            return "https://example.org/survey/take-survey/";
        }

        @Override
        public void saveEventTime(MachineInstance machine, String stateName, String field, long epochSeconds) {
            eventTimes.put(field, epochSeconds);
        }

        @Override
        public void flagLastIncomingMessage(MachineInstance machine, String flag) {
        }

        @Override
        public long[] getTreCounters(MachineInstance machine, String anchorState) {
            return treCounters.clone();
        }

        @Override
        public void setTreCounters(MachineInstance machine, String anchorState, long successful, long total, long after8pm) {
            treCounters = new long[]{successful, total, after8pm};
        }

        @Override
        public void setEatingWindowDuration(MachineInstance machine, String anchorState, long seconds) {
            eatingWindow = seconds;
        }

        @Override
        public String adminPhone(MachineInstance machine) {
            return "+15559999999";
        }

        @Override
        public String adminTimezone() {
            return "America/New_York";
        }
    }

    public static JsonObject run(ProtocolDefinition def, String timezone, String startLocalTime, JsonArray script) {
        ZoneId zone = ZoneId.of(timezone);
        Instant start = LocalDateTime.parse(startLocalTime).atZone(zone).toInstant();
        MutableClock clock = new MutableClock(start);
        SimTimerService timers = new SimTimerService(clock);
        CollectingServices services = new CollectingServices();

        Map<String, String> participantMap = new HashMap<>();
        participantMap.put("participant_uuid", UUID.randomUUID().toString());
        participantMap.put("study_uuid", UUID.randomUUID().toString());
        participantMap.put("study_name", "Simulation");
        participantMap.put("first_name", "Alex");
        participantMap.put("last_name", "Test");
        participantMap.put("number", "+15550000000");
        participantMap.put("time_zone", timezone);

        MachineInstance machine = new MachineInstance(def, participantMap, UUID.randomUUID().toString(),
                UUID.randomUUID().toString(), 0, services, timers, Runnable::run, clock);
        machine.start();
        machine.restore("", false);

        if (script != null) {
            for (JsonElement e : script) {
                JsonObject step = e.getAsJsonObject();
                if (step.has("advanceSeconds")) {
                    long total = step.get("advanceSeconds").getAsLong();
                    long stepSize = 60;
                    while (total > 0) {
                        long delta = Math.min(stepSize, total);
                        clock.advanceSeconds(delta);
                        total -= delta;
                        timers.fireDue(machine);
                    }
                } else if (step.has("event")) {
                    machine.submitEvent(step.get("event").getAsString());
                } else if (step.has("sms")) {
                    Map<String, String> sms = new HashMap<>();
                    sms.put("Body", step.get("sms").getAsString());
                    if (machine.matchesHelp(sms.get("Body"))) {
                        machine.submitHelp();
                    } else {
                        machine.submitSms(sms);
                    }
                } else if (step.has("command")) {
                    Map<String, String> command = new HashMap<>();
                    command.put("Command", step.get("command").getAsString());
                    machine.submitCommandMessage(command);
                } else if (step.has("devicePacket")) {
                    machine.submitDevicePacket(new HashMap<>());
                }
            }
        }

        JsonObject result = new JsonObject();
        JsonArray states = new JsonArray();
        for (Map<String, String> entry : services.stateLog) {
            JsonObject o = new JsonObject();
            for (Map.Entry<String, String> field : entry.entrySet()) {
                o.addProperty(field.getKey(), field.getValue());
            }
            states.add(o);
        }
        result.add("states", states);
        JsonArray messages = new JsonArray();
        for (String body : services.smsMessages) {
            messages.add(body);
        }
        result.add("messages", messages);
        JsonArray adminMessages = new JsonArray();
        for (String body : services.adminMessages) {
            adminMessages.add(body);
        }
        result.add("adminMessages", adminMessages);
        result.addProperty("finalState", machine.getCurrentState());
        return result;
    }
}
