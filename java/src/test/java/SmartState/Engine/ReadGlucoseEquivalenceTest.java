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
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

public class ReadGlucoseEquivalenceTest {

    private static final String TZ = "America/New_York";

    private ProtocolDefinition def;
    private Simulation.MutableClock clock;
    private Simulation.SimTimerService timers;
    private Simulation.CollectingServices services;
    private MachineInstance machine;

    @BeforeEach
    void setUp() throws Exception {
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser()
                .parse(ProtocolDefinitionParserTest.fixture("ReadGlucose.v1.json"));
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
        participantMap.put("number", "+15550000001");
        participantMap.put("time_zone", TZ);

        machine = new MachineInstance(def, participantMap, UUID.randomUUID().toString(),
                UUID.randomUUID().toString(), 1, services, timers, Runnable::run, clock);
        machine.start();
    }

    private void advanceSeconds(long total) {
        long step = 60;
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
    void noInteractionPathReachesNotifyAdminAndCycles() {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", false);

        assertEquals(List.of("initial", "waitStart"), stateSequence());
        assertEquals(1, services.smsMessages.size());
        assertTrue(services.smsMessages.get(0).startsWith("Hi Pat, it's time to check"));

        advanceSeconds(900);
        assertEquals("warnStartGlucose", machine.getCurrentState());
        assertEquals(2, services.smsMessages.size());

        advanceSeconds(900);
        assertEquals(List.of("initial", "waitStart", "warnStartGlucose", "missedStart", "notifyAdmin", "endOfEpisode"), stateSequence());
        assertEquals(3, services.smsMessages.size());
        assertEquals(1, services.adminMessages.size());
        assertTrue(services.adminMessages.get(0).contains("did not successfully interact"));

        advanceSeconds(5160);
        assertEquals("waitStart", machine.getCurrentState());
        assertEquals(4, services.smsMessages.size());
    }

    @Test
    void devicePacketsProduceReadingAndResultMessage() {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", false);

        machine.submitDevicePacket(new HashMap<>());
        assertEquals("startReading", machine.getCurrentState());
        assertTrue(services.smsMessages.get(services.smsMessages.size() - 1).startsWith("Your glucose readings are being received"));

        machine.submitDevicePacket(new HashMap<>());
        machine.submitDevicePacket(new HashMap<>());
        advanceSeconds(30);

        assertEquals("low", services.deviceResult);
        assertEquals("endOfEpisode", machine.getCurrentState());
        assertTrue(stateSequence().containsAll(List.of("startReading", "finishedReading", "endOfEpisode")));
        assertTrue(services.smsMessages.get(services.smsMessages.size() - 1).contains("Your blood glucose is low"));
    }

    @Test
    void manualMovesFollowLegacyWatcherTable() throws Exception {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", false);

        assertEquals("warnStartGlucose,startReading,endReadGlucoseProtocol", machine.validNextStates());

        assertEquals("warnStartGlucose", machine.moveTo("warnStartGlucose").get());
        assertEquals("warnStartGlucose", machine.getCurrentState());

        assertEquals("startReading", machine.moveTo("startReading").get());
        assertEquals("finishedReading", machine.moveTo("finishedReading").get());
        assertEquals("endOfEpisode", machine.getCurrentState());

        assertEquals("endOfEpisode invalid", machine.moveTo("missedStart").get());
        assertEquals("endReadGlucoseProtocol", machine.moveTo("endReadGlucoseProtocol").get());
        assertEquals("endReadGlucoseProtocol", machine.getCurrentState());
    }

    @Test
    void sameDayRestoreReplaysWithSuppressedMessages() {
        createMachine("2026-01-05T14:00:00");
        long savedTime = LocalDateTime.parse("2026-01-05T08:30:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        String saved = savedJson("waitStart", savedTime);
        machine.restore(saved, false);

        assertEquals(List.of("initial", "waitStart"), stateSequence());
        assertEquals("true", services.stateLog.get(1).get("restored"));
        assertEquals(0, services.smsMessages.size());
    }

    @Test
    void nextDayRestoreFallsBackToWaitStart() {
        createMachine("2026-01-06T09:00:00");
        long savedTime = LocalDateTime.parse("2026-01-05T20:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        String saved = savedJson("endOfEpisode", savedTime);
        machine.restore(saved, false);

        assertEquals("waitStart", machine.getCurrentState());
        assertEquals("true", services.stateLog.get(1).get("restored"));
    }

    @Test
    void terminalStateSurvivesNextDayRestore() {
        createMachine("2026-01-06T09:00:00");
        long savedTime = LocalDateTime.parse("2026-01-05T20:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        String saved = savedJson("endReadGlucoseProtocol", savedTime);
        machine.restore(saved, false);

        assertEquals(List.of("initial"), stateSequence());
        assertEquals("initial", machine.getCurrentState());
    }

    @Test
    void startReadingRestoreNotifiesAdminUnsuppressed() {
        createMachine("2026-01-05T14:00:00");
        long savedTime = LocalDateTime.parse("2026-01-05T13:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        String saved = savedJson("startReading", savedTime);
        machine.restore(saved, false);

        List<String> states = stateSequence();
        assertEquals(List.of("initial", "startReading", "notifyAdmin", "endOfEpisode"), states);
        assertEquals("true", services.stateLog.get(1).get("restored"));
        assertNull(services.stateLog.get(2).get("restored"));
        assertEquals(1, services.adminMessages.size());
        assertEquals(0, services.smsMessages.size());
    }

    @Test
    void resetLogsResetMarkerAndSendsMessage() {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", true);

        assertEquals(List.of("initial", "waitStart"), stateSequence());
        assertEquals("true", services.stateLog.get(1).get("RESET"));
        assertEquals(1, services.smsMessages.size());
    }

    @Test
    void helpKeywordNotifiesAdminsAndReplies() {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", false);

        assertTrue(machine.matchesHelp("I need Help me please"));
        machine.submitHelp();
        assertEquals(1, services.adminMessages.size());
        assertTrue(services.adminMessages.get(0).startsWith("ReadGlucose:"));
        assertTrue(services.smsMessages.get(services.smsMessages.size() - 1).startsWith("We've notified study administrators"));
    }

    @Test
    void endProtocolFromWatcherLogsTerminalState() {
        createMachine("2026-01-05T08:05:00");
        machine.restore("", false);
        machine.submitEndProtocol();

        assertEquals("endReadGlucoseProtocol", machine.getCurrentState());
        assertEquals("endReadGlucoseProtocol", stateSequence().get(stateSequence().size() - 1));
    }

    private String savedJson(String state, long currentTime) {
        JsonObject timersJson = new JsonObject();
        timersJson.addProperty("state", state);
        timersJson.addProperty("startTime", currentTime - 3600);
        timersJson.addProperty("currentTime", currentTime);
        timersJson.addProperty("startDeadline", 0);
        timersJson.addProperty("startWarnDeadline", 0);
        timersJson.addProperty("endOfEpisodeDeadline", 0);
        JsonObject root = new JsonObject();
        root.add("history", new JsonObject());
        root.add("timers", timersJson);
        return root.toString();
    }
}
