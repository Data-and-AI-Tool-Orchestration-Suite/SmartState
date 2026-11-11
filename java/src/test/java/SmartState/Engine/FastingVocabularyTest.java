package SmartState.Engine;

import SmartState.TimeUtils.TimezoneHelper;
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

public class FastingVocabularyTest {

    private static final String TZ = "America/New_York";

    private TimezoneHelper helperAt(String localDateTime) {
        Instant instant = LocalDateTime.parse(localDateTime).atZone(ZoneId.of(TZ)).toInstant();
        return new TimezoneHelper(TZ, "GMT", new Simulation.MutableClock(instant));
    }

    @Test
    void secondsToFriday5pm() {
        TimezoneHelper tz = helperAt("2026-01-05T12:00:00");
        assertEquals(4 * 86400 + 5 * 3600, tz.secondsToWeekday(5, 17, 0));
    }

    @Test
    void secondsToFriday5pmOnFridayEveningRollsAWeek() {
        TimezoneHelper tz = helperAt("2026-01-09T18:00:00");
        assertEquals(7 * 86400 - 3600, tz.secondsToWeekday(5, 17, 0));
    }

    @Test
    void conditionalDayRolloverOnlyAfterThreshold() {
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_CLOCK;
        expr.hour = 11;
        expr.minute = 59;
        expr.second = 30;
        ProtocolDefinition.Predicate after445 = new ProtocolDefinition.Predicate();
        after445.type = ProtocolDefinition.PredicateType.AFTER_CLOCK;
        after445.hour = 16;
        after445.minute = 45;
        expr.addDayIfPredicate = after445;
        expr.minSeconds = 300L;

        assertEquals(3 * 3600 + 59 * 60 + 30,
                DeadlineCalculator.evaluate(expr, helperAt("2026-01-05T08:00:00"), new HashMap<>()));
        assertEquals(300,
                DeadlineCalculator.evaluate(expr, helperAt("2026-01-05T14:00:00"), new HashMap<>()));
        long expected = java.time.Duration.between(
                LocalDateTime.parse("2026-01-05T18:00:00"),
                LocalDateTime.parse("2026-01-06T11:59:30")).getSeconds();
        assertEquals(expected,
                DeadlineCalculator.evaluate(expr, helperAt("2026-01-05T18:00:00"), new HashMap<>()));
    }

    @Test
    void parseSmsTimeVariants() {
        TimezoneHelper tz = helperAt("2026-01-05T09:00:00");
        long expected730am = LocalDateTime.parse("2026-01-05T07:30:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expected730am, tz.parseSmsTime("startcal 7:30 am", "startcal"));
        assertEquals(expected730am, tz.parseSmsTime("STARTCAL 7:30AM".toLowerCase(), "startcal"));
        long expected8pmLastNight = LocalDateTime.parse("2026-01-04T20:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expected8pmLastNight, tz.parseSmsTime("endcal 8 pm", "endcal"));
        TimezoneHelper evening = helperAt("2026-01-05T20:15:00");
        long expected8pmTonight = LocalDateTime.parse("2026-01-05T20:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expected8pmTonight, evening.parseSmsTime("endcal 8 pm", "endcal"));
        assertEquals(tz.nowEpochSeconds(), tz.parseSmsTime("endcal", "endcal"));
        assertNull(tz.parseSmsTime("startcal 7:30", "startcal"));
        long expected2amToday = LocalDateTime.parse("2026-01-05T02:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expected2amToday, tz.parseSmsTime("endcal 2:00 am", "endcal"));
        TimezoneHelper lateNight = helperAt("2026-01-06T00:30:00");
        long expectedLastNight = LocalDateTime.parse("2026-01-05T23:30:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expectedLastNight, lateNight.parseSmsTime("endcal 11:30 pm", "endcal"));
    }

    private ProtocolDefinition treDefinition() throws Exception {
        String json = "{\"schemaVersion\":1,\"name\":\"MiniTRE\",\"initialState\":\"initial\",\"timers\":[\"t\"],"
                + "\"settings\":{\"endProtocolEvent\":\"end\"},"
                + "\"restore\":{\"policy\":\"same-day-else-reset\",\"terminalStates\":[\"done\"],\"fallbackState\":\"waitStart\",\"dayBoundaryHour\":4,\"dayBoundaryMinute\":5,"
                + "\"noSaveStateEvent\":\"go\",\"replay\":{\"waitStart\":{\"restoring\":[\"go\"]}}},"
                + "\"eventMappings\":{\"sms\":{\"fallbackReply\":\"Not understood\",\"ignoreKeywords\":[\"snack\"],\"rules\":["
                + "{\"keywords\":[\"startcal\"],\"inStates\":[\"waitStart\"],\"parseTime\":true,\"saveAs\":\"start_cal_time\",\"maxBareLength\":8,"
                + "\"fire\":\"gotStart\",\"parseErrorReply\":\"Bad start time\"},"
                + "{\"keywords\":[\"endcal\"],\"inStates\":[\"startcal\"],\"parseTime\":true,\"saveAs\":\"end_cal_time\",\"maxBareLength\":6,"
                + "\"guard\":{\"type\":\"fieldBetweenClock\",\"field\":\"end_cal_time\",\"fromHour\":3,\"toHour\":15},"
                + "\"guardReply\":\"Too early, call {admin_phone}\",\"fire\":\"gotEnd\",\"parseErrorReply\":\"Bad end time\"}]}},"
                + "\"states\":{"
                + "\"initial\":{\"transitions\":[{\"event\":\"go\",\"to\":\"waitStart\"},{\"event\":\"end\",\"to\":\"done\"}]},"
                + "\"waitStart\":{\"transitions\":[{\"event\":\"gotStart\",\"to\":\"startcal\"},{\"event\":\"end\",\"to\":\"done\"}]},"
                + "\"startcal\":{\"transitions\":[{\"event\":\"gotEnd\",\"to\":\"endcal\"},{\"event\":\"end\",\"to\":\"done\"}]},"
                + "\"endcal\":{\"entry\":[{\"type\":\"treEvaluateEndcal\","
                + "\"ackMessages\":[\"Thanks!\"],\"successMessages\":[\"Success {success_rate}\"],"
                + "\"shortTrialMessages\":[\"Short trial\"],\"shortMessages\":[\"Short {success_rate}\"],"
                + "\"longTrialMessages\":[\"Long trial\"],\"longMessages\":[\"Long {success_rate}\"],"
                + "\"lateMessages\":[\"Too late\"],\"neutralMessages\":[\"Neutral {success_rate}\"]}],"
                + "\"transitions\":[{\"auto\":true,\"to\":\"cycleEnd\"}]},"
                + "\"cycleEnd\":{\"entry\":[{\"type\":\"incrementCounter\",\"key\":\"cycles\"},{\"type\":\"clearVars\",\"keys\":[\"endcalRepeats\",\"isDayOff\",\"wasSuccessfulFast\",\"wasAfter8pm\"]}],"
                + "\"transitions\":[{\"event\":\"next\",\"to\":\"waitStart\"},{\"event\":\"end\",\"to\":\"done\"}]},"
                + "\"done\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertTrue(result.validation.isOk(), String.join("; ", result.validation.getErrors()));
        return result.definition;
    }

    private Simulation.MutableClock clock;
    private Simulation.SimTimerService timers;
    private Simulation.CollectingServices services;
    private MachineInstance machine;

    private void createMachine(ProtocolDefinition def, String localStart) {
        Instant start = LocalDateTime.parse(localStart).atZone(ZoneId.of(TZ)).toInstant();
        clock = new Simulation.MutableClock(start);
        timers = new Simulation.SimTimerService(clock);
        services = new Simulation.CollectingServices();
        Map<String, String> participantMap = new HashMap<>();
        participantMap.put("participant_uuid", UUID.randomUUID().toString());
        participantMap.put("study_uuid", UUID.randomUUID().toString());
        participantMap.put("study_name", "TIMED");
        participantMap.put("first_name", "Pat");
        participantMap.put("number", "+15550000009");
        participantMap.put("time_zone", TZ);
        machine = new MachineInstance(def, participantMap, UUID.randomUUID().toString(),
                UUID.randomUUID().toString(), 1, services, timers, Runnable::run, clock);
        machine.start();
        machine.restore("", false);
    }

    private void sms(String body) {
        Map<String, String> map = new HashMap<>();
        map.put("Body", body);
        machine.submitSms(map);
    }

    @Test
    void smsRuleParsesSavesAndFires() throws Exception {
        createMachine(treDefinition(), "2026-01-05T07:00:00");
        assertEquals("waitStart", machine.getCurrentState());

        sms("startcal 7:30 am");
        assertEquals("startcal", machine.getCurrentState());
        long expected = LocalDateTime.parse("2026-01-05T07:30:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertEquals(expected, services.eventTimes.get("start_cal_time"));
    }

    @Test
    void smsRuleParseErrorReplies() throws Exception {
        createMachine(treDefinition(), "2026-01-05T07:00:00");
        sms("startcal 7:30 tomorrow");
        assertEquals("waitStart", machine.getCurrentState());
        assertEquals("Bad start time", services.smsMessages.get(services.smsMessages.size() - 1));
    }

    @Test
    void smsGuardBlocksEarlyEndcal() throws Exception {
        createMachine(treDefinition(), "2026-01-05T07:00:00");
        sms("startcal 7:30 am");
        sms("endcal 10:00 am");
        assertEquals("startcal", machine.getCurrentState());
        assertTrue(services.smsMessages.get(services.smsMessages.size() - 1).startsWith("Too early, call +1555"));
    }

    @Test
    void fallbackAndIgnoreKeywords() throws Exception {
        createMachine(treDefinition(), "2026-01-05T07:00:00");
        assertTrue(machine.smsIgnores("I had a snack"));
        assertTrue(machine.smsHandles("STARTCAL 7:00 am".toLowerCase()) || machine.smsHandles("startcal"));
        sms("hello there");
        assertEquals("Not understood", services.smsMessages.get(services.smsMessages.size() - 1));
    }

    private void advanceHours(double hours) {
        clock.advanceSeconds((long) (hours * 3600));
        timers.fireDue(machine);
    }

    @Test
    void treEvaluationBucketsAndCounters() throws Exception {
        ProtocolDefinition def = treDefinition();
        createMachine(def, "2026-01-05T07:00:00");

        for (int cycle = 0; cycle < 7; cycle++) {
            sms("startcal 8:00 am");
            advanceHours(11.5);
            sms("endcal 6:00 pm");
            assertEquals("cycleEnd", machine.getCurrentState());
            machine.submitEvent("next");
            advanceHours(12.5);
        }
        assertEquals(0, services.treCounters[1]);
        assertTrue(services.smsMessages.contains("Thanks!"));

        sms("startcal 8:00 am");
        advanceHours(11.5);
        sms("endcal 6:00 pm");
        assertEquals(1, services.treCounters[1]);
        assertEquals(1, services.treCounters[0]);
        assertTrue(services.smsMessages.contains("Success 100.00%"));
        machine.submitEvent("next");
        advanceHours(12.5);

        sms("startcal 8:00 am");
        advanceHours(9.5);
        sms("endcal 4:30 pm");
        assertEquals(2, services.treCounters[1]);
        assertEquals(1, services.treCounters[0]);
        assertTrue(services.smsMessages.contains("Short 50.00%"));
        machine.submitEvent("next");
        advanceHours(14.5);

        sms("startcal 10:30 am");
        advanceHours(11);
        sms("endcal 9:00 pm");
        assertEquals(3, services.treCounters[1]);
        assertEquals(1, services.treCounters[0]);
        assertEquals(1, services.treCounters[2]);
        assertTrue(services.smsMessages.contains("Too late"));
        assertTrue(services.smsMessages.stream().anyMatch(m -> m.startsWith("Neutral")));
    }

    @Test
    void boundaryRestoreKeepsSameDayUntil4am() throws Exception {
        ProtocolDefinition def = treDefinition();
        createMachine(def, "2026-01-06T02:00:00");
        List<String> states = services.stateLog.stream().map(m -> m.get("state")).collect(Collectors.toList());
        assertEquals(List.of("initial", "waitStart"), states);

        Instant saved = LocalDateTime.parse("2026-01-05T22:00:00").atZone(ZoneId.of(TZ)).toInstant();
        String savedJson = "{\"history\":{},\"timers\":{\"state\":\"startcal\",\"startTime\":" + (saved.getEpochSecond() - 3600)
                + ",\"currentTime\":" + saved.getEpochSecond() + ",\"t\":0}}";

        createMachineWithSaved(def, "2026-01-06T02:00:00", savedJson);
        assertEquals("initial", machine.getCurrentState());

        createMachineWithSaved(def, "2026-01-06T09:00:00", savedJson);
        assertEquals("waitStart", machine.getCurrentState());
    }

    private void createMachineWithSaved(ProtocolDefinition def, String localStart, String savedJson) {
        Instant start = LocalDateTime.parse(localStart).atZone(ZoneId.of(TZ)).toInstant();
        clock = new Simulation.MutableClock(start);
        timers = new Simulation.SimTimerService(clock);
        services = new Simulation.CollectingServices();
        Map<String, String> participantMap = new HashMap<>();
        participantMap.put("participant_uuid", UUID.randomUUID().toString());
        participantMap.put("study_uuid", UUID.randomUUID().toString());
        participantMap.put("study_name", "TIMED");
        participantMap.put("first_name", "Pat");
        participantMap.put("number", "+15550000009");
        participantMap.put("time_zone", TZ);
        machine = new MachineInstance(def, participantMap, UUID.randomUUID().toString(),
                UUID.randomUUID().toString(), 1, services, timers, Runnable::run, clock);
        machine.start();
        machine.restore(savedJson, false);
    }
}
