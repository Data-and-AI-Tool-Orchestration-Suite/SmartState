package SmartState.Engine;

import SmartState.TimeUtils.TimezoneHelper;

import java.util.Map;

public class DeadlineCalculator {

    public static long evaluate(ProtocolDefinition.DeadlineExpr expr, TimezoneHelper tz, Map<String, Long> timerDeadlines) {
        return evaluate(expr, tz, timerDeadlines, Map.of(), Map.of());
    }

    public static long evaluate(ProtocolDefinition.DeadlineExpr expr, TimezoneHelper tz, Map<String, Long> timerDeadlines,
                                Map<String, String> vars, Map<String, Long> fields) {
        long seconds = evaluateRaw(expr, tz, timerDeadlines, vars, fields);
        long floor = expr.minSeconds == null ? 0 : expr.minSeconds;
        return Math.max(floor, seconds);
    }

    private static long evaluateRaw(ProtocolDefinition.DeadlineExpr expr, TimezoneHelper tz, Map<String, Long> timerDeadlines,
                                    Map<String, String> vars, Map<String, Long> fields) {
        switch (expr.type) {
            case FIXED_SECONDS:
                return expr.seconds;
            case SECONDS_TO_CLOCK:
                long seconds = tz.secondsToClock(expr.hour, expr.minute, expr.second, expr.ifPastAddDay);
                if (expr.addDayIfPredicate != null && seconds < 0
                        && evaluatePredicate(expr.addDayIfPredicate, tz, timerDeadlines, vars, fields)) {
                    seconds += 86400;
                }
                return seconds;
            case SECONDS_TO_NEXT_MINUTE_MARK:
                return tz.secondsToNextMinuteMark(expr.mark);
            case SECONDS_TO_HOUR_BOUNDARY_PLUS:
                return tz.secondsToHourBoundaryPlus(expr.hours);
            case SECONDS_TO_WEEKDAY:
                return tz.secondsToWeekday(expr.dayOfWeek, expr.hour, expr.minute);
            case CONDITIONAL:
                return evaluatePredicate(expr.ifPredicate, tz, timerDeadlines, vars, fields)
                        ? evaluateRaw(expr.thenExpr, tz, timerDeadlines, vars, fields)
                        : evaluateRaw(expr.elseExpr, tz, timerDeadlines, vars, fields);
            default:
                return 0;
        }
    }

    public static boolean evaluatePredicate(ProtocolDefinition.Predicate predicate, TimezoneHelper tz, Map<String, Long> timerDeadlines) {
        return evaluatePredicate(predicate, tz, timerDeadlines, Map.of(), Map.of());
    }

    public static boolean evaluatePredicate(ProtocolDefinition.Predicate predicate, TimezoneHelper tz, Map<String, Long> timerDeadlines,
                                            Map<String, String> vars, Map<String, Long> fields) {
        switch (predicate.type) {
            case BEFORE_CLOCK:
                return tz.isBeforeClock(predicate.hour, predicate.minute);
            case AFTER_CLOCK:
                return tz.isAfterClock(predicate.hour, predicate.minute);
            case TIMER_WITHIN_MINUTE_WINDOW:
                long epoch = timerDeadlines.getOrDefault(predicate.timer, 0L);
                return tz.timerWithinMinuteWindow(epoch, predicate.fromMinute, predicate.toMinute);
            case VAR_EQUALS:
                return predicate.value == null
                        ? vars.get(predicate.key) == null
                        : predicate.value.equals(vars.get(predicate.key));
            case COUNTER_AT_LEAST:
                return parseCounter(vars.get(predicate.key)) >= predicate.count;
            case FIELD_AFTER_CLOCK:
                Long afterValue = fields.get(predicate.field);
                return afterValue != null && tz.epochAfterClock(afterValue, predicate.hour, predicate.minute);
            case FIELD_BETWEEN_CLOCK:
                Long betweenValue = fields.get(predicate.field);
                return betweenValue != null && tz.epochBetweenClock(betweenValue, predicate.fromHour, predicate.toHour);
            case FIELD_WAS_YESTERDAY:
                Long yesterdayValue = fields.get(predicate.field);
                return yesterdayValue != null && tz.epochWasYesterday(yesterdayValue, predicate.boundaryHour, predicate.boundaryMinute);
            case NOT:
                return !evaluatePredicate(predicate.inner, tz, timerDeadlines, vars, fields);
            case ALWAYS:
            default:
                return true;
        }
    }

    private static long parseCounter(String value) {
        try {
            return value == null ? 0 : Long.parseLong(value);
        } catch (NumberFormatException ex) {
            return 0;
        }
    }
}
