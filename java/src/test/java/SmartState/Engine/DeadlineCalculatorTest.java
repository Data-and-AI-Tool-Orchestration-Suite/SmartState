package SmartState.Engine;

import SmartState.TimeUtils.TimezoneHelper;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.util.HashMap;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

public class DeadlineCalculatorTest {

    private static final String TZ = "America/New_York";

    private TimezoneHelper helperAt(String localDateTime) {
        Instant instant = LocalDateTime.parse(localDateTime).atZone(ZoneId.of(TZ)).toInstant();
        return new TimezoneHelper(TZ, "GMT", new Simulation.MutableClock(instant));
    }

    private ProtocolDefinition.DeadlineExpr clockExpr(int hour, int minute, boolean addDay) {
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_CLOCK;
        expr.hour = hour;
        expr.minute = minute;
        expr.ifPastAddDay = addDay;
        return expr;
    }

    @Test
    void secondsToNoonBeforeNoon() {
        TimezoneHelper tz = helperAt("2026-01-05T08:00:00");
        assertEquals(4 * 3600, DeadlineCalculator.evaluate(clockExpr(12, 0, true), tz, new HashMap<>()));
    }

    @Test
    void secondsToNoonAfterNoonAddsDay() {
        TimezoneHelper tz = helperAt("2026-01-05T13:00:00");
        assertEquals(23 * 3600, DeadlineCalculator.evaluate(clockExpr(12, 0, true), tz, new HashMap<>()));
    }

    @Test
    void secondsTo6pmAfter6pmClampsToZero() {
        TimezoneHelper tz = helperAt("2026-01-05T19:00:00");
        assertEquals(0, DeadlineCalculator.evaluate(clockExpr(18, 0, false), tz, new HashMap<>()));
    }

    @Test
    void noonBoundaryExactlyNoon() {
        TimezoneHelper tz = helperAt("2026-01-05T12:00:00");
        assertEquals(0, DeadlineCalculator.evaluate(clockExpr(12, 0, true), tz, new HashMap<>()));
        assertFalse(tz.isBeforeClock(12, 0));
    }

    @Test
    void secondsToNextMinuteMark() {
        TimezoneHelper tz = helperAt("2026-01-05T08:05:00");
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_NEXT_MINUTE_MARK;
        expr.mark = 15;
        assertEquals(600, DeadlineCalculator.evaluate(expr, tz, new HashMap<>()));
    }

    @Test
    void secondsToNextMinuteMarkPastMarkClampsToZero() {
        TimezoneHelper tz = helperAt("2026-01-05T08:20:00");
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_NEXT_MINUTE_MARK;
        expr.mark = 15;
        assertEquals(0, DeadlineCalculator.evaluate(expr, tz, new HashMap<>()));
    }

    @Test
    void secondsToHourBoundaryPlusTwo() {
        TimezoneHelper tz = helperAt("2026-01-05T08:30:00");
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_HOUR_BOUNDARY_PLUS;
        expr.hours = 2;
        assertEquals(90 * 60, DeadlineCalculator.evaluate(expr, tz, new HashMap<>()));
    }

    @Test
    void timerWithinMinuteWindowPredicate() {
        TimezoneHelper tz = helperAt("2026-01-05T08:10:00");
        ProtocolDefinition.Predicate predicate = new ProtocolDefinition.Predicate();
        predicate.type = ProtocolDefinition.PredicateType.TIMER_WITHIN_MINUTE_WINDOW;
        predicate.timer = "t";
        predicate.fromMinute = 0;
        predicate.toMinute = 15;

        Map<String, Long> deadlines = new HashMap<>();
        long epochAt805 = LocalDateTime.parse("2026-01-05T08:05:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        deadlines.put("t", epochAt805);
        assertTrue(DeadlineCalculator.evaluatePredicate(predicate, tz, deadlines));

        deadlines.put("t", 0L);
        assertFalse(DeadlineCalculator.evaluatePredicate(predicate, tz, deadlines));
    }

    @Test
    void isSameLocalDateAcrossDays() {
        TimezoneHelper tz = helperAt("2026-01-05T08:00:00");
        long sameDayEvening = LocalDateTime.parse("2026-01-05T22:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        long previousDay = LocalDateTime.parse("2026-01-04T22:00:00").atZone(ZoneId.of(TZ)).toEpochSecond();
        assertTrue(tz.isSameLocalDate(sameDayEvening));
        assertFalse(tz.isSameLocalDate(previousDay));
    }

    @Test
    void dstSpringForwardStillComputesNoon() {
        TimezoneHelper tz = helperAt("2026-03-08T08:00:00");
        long seconds = DeadlineCalculator.evaluate(clockExpr(12, 0, true), tz, new HashMap<>());
        assertEquals(4 * 3600, seconds);
    }
}
