package SmartState.Engine;

import com.google.gson.JsonObject;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.stream.Collectors;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

public class SurveyEquivalenceTest {

    private static final String TZ = "America/New_York";

    private ProtocolDefinition def;
    private Simulation.MutableClock clock;
    private Simulation.SimTimerService timers;
    private Simulation.CollectingServices services;
    private MachineInstance machine;

    @BeforeEach
    void setUp() throws Exception {
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser()
                .parse(ProtocolDefinitionParserTest.fixture("Survey.v1.json"));
        assertTrue(result.validation.isOk());
        def = result.definition;
    }

    private void createMachine(String localStart) {
        Instant start = LocalDateTime.parse(localStart).atZone(ZoneId.of(TZ)).toInstant();
        clock = new Simulation.MutableClock(start);
        timers = new Simulation.SimTimerService(clock);
        services = new Simulation.CollectingServices();

        Map<String, String> participantMap = new HashMap<>();
        participantMap.put("participant_uuid", UUID.randomUUID().toString());
        participantMap.put("study_uuid", UUID.randomUUID().toString());
        participantMap.put("study_name", "Default");
        participantMap.put("first_name", "Pat");
        participantMap.put("number", "+15550000002");
        participantMap.put("time_zone", TZ);

        machine = new MachineInstance(def, participantMap, UUID.randomUUID().toString(),
                UUID.randomUUID().toString(), 1, services, timers, Runnable::run, clock);
        machine.start();
    }

    private void advanceSeconds(long total) {
        long step = 300;
        while (total > 0) {
            long delta = Math.min(step, total);
            clock.advanceSeconds(delta);
            total -= delta;
            timers.fireDue(machine);
        }
    }

    private List<String> stateSequence() {
        return services.stateLog.stream().map(m -> m.get("state")).collect(Collectors.toList());
    }

    @Test
    void fullDayCycleMatchesLegacySequence() {
        createMachine("2026-01-05T08:00:00");
        machine.restore("", false);

        assertEquals(List.of("initial", "waitForNoon"), stateSequence());

        advanceSeconds(4 * 3600);
        assertEquals(List.of("initial", "waitForNoon", "noonSurvey", "waitFor6pm"), stateSequence());
        assertEquals("How often do you exercise per week?", services.smsMessages.get(0));

        advanceSeconds(6 * 3600);
        assertEquals(List.of("initial", "waitForNoon", "noonSurvey", "waitFor6pm", "survey6pm", "waitForNoon"), stateSequence());
        assertEquals(2, services.smsMessages.size());
    }

    @Test
    void llmAnswerSavesSurveyWithStaticAnswers() {
        createMachine("2026-01-05T08:00:00");
        machine.restore("", false);
        advanceSeconds(4 * 3600);

        services.llmReply = "1-2 times";
        Map<String, String> sms = new HashMap<>();
        sms.put("Body", "I exercise twice a week");
        machine.submitSms(sms);

        assertEquals(1, services.surveyResponses.size());
        assertEquals("1-2 times", services.surveyResponses.get(0).getString("exercise"));
        assertEquals("Average", services.surveyResponses.get(0).getString("diet"));
        assertEquals("smoke-no", services.surveyResponses.get(0).getString("smoke"));
    }

    @Test
    void invalidLlmAnswerRetriesAndSendsClarification() {
        createMachine("2026-01-05T08:00:00");
        machine.restore("", false);
        advanceSeconds(4 * 3600);

        services.llmReply = "Please answer with one of: Never, 1-2 times, 3-4 times, 5+ times";
        Map<String, String> sms = new HashMap<>();
        sms.put("Body", "banana");
        machine.submitSms(sms);

        assertEquals(0, services.surveyResponses.size());
        assertTrue(services.smsMessages.get(services.smsMessages.size() - 1).startsWith("Please answer with one of"));

        services.llmReply = "3-4 times";
        sms.put("Body", "like every other day");
        machine.submitSms(sms);
        assertEquals(1, services.surveyResponses.size());
        assertEquals("3-4 times", services.surveyResponses.get(0).getString("exercise"));
    }

    @Test
    void restoreMorningLandsWaitForNoon() {
        createMachine("2026-01-05T10:00:00");
        machine.restore(savedJson("noonSurvey", "2026-01-04T12:00:05"), false);

        assertEquals("waitForNoon", machine.getCurrentState());
        assertEquals("true", services.stateLog.get(1).get("restored"));
    }

    @Test
    void restoreAfternoonLandsWaitFor6pm() {
        createMachine("2026-01-05T13:00:00");
        machine.restore(savedJson("waitForNoon", "2026-01-05T08:00:00"), false);

        assertEquals("waitFor6pm", machine.getCurrentState());
        assertEquals("true", services.stateLog.get(1).get("restored"));
    }

    @Test
    void restoreEveningLandsWaitForNoon() {
        createMachine("2026-01-05T19:00:00");
        machine.restore(savedJson("waitFor6pm", "2026-01-05T08:00:00"), false);

        assertEquals("waitForNoon", machine.getCurrentState());
    }

    @Test
    void terminalSavedStateStaysPut() {
        createMachine("2026-01-05T10:00:00");
        machine.restore(savedJson("endSurveyProtocol", "2026-01-04T12:00:00"), false);

        assertEquals(List.of("initial"), stateSequence());
        assertEquals("initial", machine.getCurrentState());
    }

    @Test
    void resetRecomputesWithResetMarker() {
        createMachine("2026-01-05T10:00:00");
        machine.restore("", true);

        assertEquals("waitForNoon", machine.getCurrentState());
        assertEquals("true", services.stateLog.get(1).get("RESET"));
    }

    @Test
    void noSaveStateRecomputesUnrestored() {
        createMachine("2026-01-05T13:00:00");
        machine.restore("", false);

        assertEquals("waitFor6pm", machine.getCurrentState());
        assertEquals(2, services.stateLog.size());
        assertEquals(null, services.stateLog.get(1).get("restored"));
    }

    private String savedJson(String state, String localDateTime) {
        long currentTime = LocalDateTime.parse(localDateTime).atZone(ZoneId.of(TZ)).toEpochSecond();
        JsonObject timersJson = new JsonObject();
        timersJson.addProperty("state", state);
        timersJson.addProperty("startTime", currentTime - 3600);
        timersJson.addProperty("currentTime", currentTime);
        timersJson.addProperty("deadlineNoon", 0);
        timersJson.addProperty("deadline6pm", 0);
        JsonObject root = new JsonObject();
        root.add("history", new JsonObject());
        root.add("timers", timersJson);
        return root.toString();
    }
}
