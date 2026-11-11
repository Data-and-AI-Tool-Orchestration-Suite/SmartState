package SmartState.TimeUtils;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;

public class TimezoneHelper {
    private static final Logger logger = LoggerFactory.getLogger(TimezoneHelper.class);
    static final int SEC_IN_DAY = 86400;
    private String userTimezone;
    private final String machineTimezone;
    private final Clock clock;

    public TimezoneHelper(String userTimezone, String machineTimezone) {
        this(userTimezone, machineTimezone, Clock.systemUTC());
    }

    public TimezoneHelper(String userTimezone, String machineTimezone, Clock clock) {
        this.userTimezone = userTimezone;
        this.machineTimezone = machineTimezone;
        this.clock = clock;
        logger.info("TimezoneHelper initialized with user timezone: {} and machine timezone: {}",
                String.valueOf(userTimezone).replace('\r', '_').replace('\n', '_'),
                String.valueOf(machineTimezone).replace('\r', '_').replace('\n', '_'));
    }

    private LocalDateTime nowUserLocal() {
        return ZonedDateTime.ofInstant(Instant.now(clock), ZoneId.of(this.userTimezone)).toLocalDateTime();
    }

    private LocalDateTime epochUserLocal(long epochSeconds) {
        return ZonedDateTime.ofInstant(Instant.ofEpochSecond(epochSeconds), ZoneId.of(this.userTimezone)).toLocalDateTime();
    }

    public long nowEpochSeconds() {
        return Instant.now(clock).getEpochSecond();
    }

    public long secondsToClock(int hour, int minute, boolean addDayIfPast) {
        return secondsToClock(hour, minute, 0, addDayIfPast);
    }

    public long secondsToClock(int hour, int minute, int second, boolean addDayIfPast) {
        LocalDateTime now = nowUserLocal();
        LocalDateTime target = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), hour, minute, second);
        long seconds = Duration.between(now, target).getSeconds();
        if (seconds < 0 && addDayIfPast) {
            seconds += SEC_IN_DAY;
        }
        return seconds;
    }

    public long secondsToWeekday(int isoDayOfWeek, int hour, int minute) {
        LocalDateTime now = nowUserLocal();
        LocalDateTime target = LocalDateTime.of(now.toLocalDate(), java.time.LocalTime.of(hour, minute));
        int daysAhead = isoDayOfWeek - now.getDayOfWeek().getValue();
        if (daysAhead < 0) {
            daysAhead += 7;
        }
        target = target.plusDays(daysAhead);
        long seconds = Duration.between(now, target).getSeconds();
        if (seconds <= 0) {
            seconds += 7L * SEC_IN_DAY;
        }
        return seconds;
    }

    public boolean isAfterClock(int hour, int minute) {
        LocalDateTime now = nowUserLocal();
        LocalDateTime target = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), hour, minute, 0);
        return now.isAfter(target);
    }

    public boolean epochAfterClock(long epochSeconds, int hour, int minute) {
        LocalDateTime then = epochUserLocal(epochSeconds);
        LocalDateTime target = LocalDateTime.of(then.getYear(), then.getMonth(), then.getDayOfMonth(), hour, minute, 0);
        return then.isAfter(target);
    }

    public boolean epochBetweenClock(long epochSeconds, int fromHour, int toHour) {
        int hour = epochUserLocal(epochSeconds).getHour();
        return hour >= fromHour && hour < toHour;
    }

    public boolean isSameLocalDateWithBoundary(long epochSeconds, int boundaryHour, int boundaryMinute) {
        long boundarySeconds = (long) boundaryHour * 3600 + (long) boundaryMinute * 60;
        LocalDate then = epochUserLocal(epochSeconds).minusSeconds(boundarySeconds).toLocalDate();
        LocalDate today = nowUserLocal().minusSeconds(boundarySeconds).toLocalDate();
        return then.equals(today);
    }

    public boolean epochWasYesterday(long epochSeconds, int boundaryHour, int boundaryMinute) {
        long boundarySeconds = (long) boundaryHour * 3600 + (long) boundaryMinute * 60;
        LocalDate then = epochUserLocal(epochSeconds).minusSeconds(boundarySeconds).toLocalDate();
        LocalDate today = nowUserLocal().minusSeconds(boundarySeconds).toLocalDate();
        return then.equals(today.minusDays(1));
    }

    public long nextClockEpoch(int hour, int minute, String timezone) {
        ZoneId zone = ZoneId.of(timezone == null || timezone.isEmpty() ? this.userTimezone : timezone);
        ZonedDateTime now = ZonedDateTime.ofInstant(Instant.now(clock), zone);
        ZonedDateTime target = now.withHour(hour).withMinute(minute).withSecond(0).withNano(0);
        if (!target.isAfter(now)) {
            target = target.plusDays(1);
        }
        return target.toEpochSecond();
    }

    public Long parseSmsTime(String body, String keyword) {
        try {
            String lower = body.toLowerCase();
            int idx = lower.indexOf(keyword);
            if (idx < 0) {
                return null;
            }
            String remainder = lower.substring(idx + keyword.length()).trim();
            if (remainder.isEmpty()) {
                return nowEpochSeconds();
            }
            remainder = remainder.replaceAll("\\.", "").replaceAll("\\s+", " ");
            java.util.regex.Matcher matcher = java.util.regex.Pattern
                    .compile("(\\d{1,2})(?::(\\d{2}))?\\s*([ap])m?")
                    .matcher(remainder);
            if (!matcher.find()) {
                return null;
            }
            int hour = Integer.parseInt(matcher.group(1));
            int minute = matcher.group(2) == null ? 0 : Integer.parseInt(matcher.group(2));
            boolean pm = matcher.group(3).equals("p");
            if (hour < 1 || hour > 12 || minute > 59) {
                return null;
            }
            if (hour == 12) {
                hour = pm ? 12 : 0;
            } else if (pm) {
                hour += 12;
            }
            LocalDateTime now = nowUserLocal();
            LocalDateTime parsed = LocalDateTime.of(now.toLocalDate(), java.time.LocalTime.of(hour, minute));
            if (Duration.between(now, parsed).getSeconds() > 4 * 3600) {
                parsed = parsed.minusDays(1);
            }
            return parsed.atZone(ZoneId.of(this.userTimezone)).toEpochSecond();
        } catch (Exception ex) {
            return null;
        }
    }

    public Long parseWebsiteTime(String time) {
        try {
            java.util.regex.Matcher matcher = java.util.regex.Pattern
                    .compile("(\\d{1,2}):(\\d{2})")
                    .matcher(time.trim());
            if (!matcher.find()) {
                return null;
            }
            int hour = Integer.parseInt(matcher.group(1));
            int minute = Integer.parseInt(matcher.group(2));
            if (hour > 23 || minute > 59) {
                return null;
            }
            LocalDateTime now = nowUserLocal();
            LocalDateTime parsed = LocalDateTime.of(now.toLocalDate(), java.time.LocalTime.of(hour, minute));
            return parsed.atZone(ZoneId.of(this.userTimezone)).toEpochSecond();
        } catch (Exception ex) {
            return null;
        }
    }

    public String formatEpochShortTime(long epochSeconds) {
        return epochUserLocal(epochSeconds).format(DateTimeFormatter.ofPattern("h:mm a"));
    }

    public String formatHoursMinutes(long seconds) {
        long hours = seconds / 3600;
        long minutes = (seconds % 3600) / 60;
        return hours + "h " + String.format("%02dm", minutes);
    }

    public long secondsToNextMinuteMark(int mark) {
        LocalDateTime now = nowUserLocal();
        LocalDateTime target = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), now.getHour(), mark, 0);
        long seconds = Duration.between(now, target).getSeconds();
        if (seconds < 0) {
            seconds = 0;
        }
        return seconds;
    }

    public long secondsToHourBoundaryPlus(int hours) {
        LocalDateTime now = nowUserLocal();
        LocalDateTime target = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), now.getHour(), 0, 0).plusHours(hours);
        return Duration.between(now, target).getSeconds();
    }

    public boolean timerWithinMinuteWindow(long epochSeconds, int fromMinute, int toMinute) {
        LocalDateTime old = epochUserLocal(epochSeconds);
        LocalDateTime now = nowUserLocal();
        LocalDateTime windowStart = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), now.getHour(), fromMinute, 0);
        LocalDateTime windowEnd = LocalDateTime.of(now.getYear(), now.getMonth(), now.getDayOfMonth(), now.getHour(), toMinute, 0);
        return !old.isBefore(windowStart) && !old.isAfter(windowEnd);
    }

    public boolean isSameLocalDate(long epochSeconds) {
        LocalDate then = epochUserLocal(epochSeconds).toLocalDate();
        LocalDate today = nowUserLocal().toLocalDate();
        return then.equals(today);
    }

    public boolean isBeforeClock(int hour, int minute) {
        return secondsToClock(hour, minute, false) > 0;
    }

    public String getDateFromAddingSeconds(long seconds) {
        ZonedDateTime target = ZonedDateTime.ofInstant(Instant.now(clock), ZoneId.of(this.userTimezone)).plusSeconds(seconds);
        return target.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss z"));
    }

    public String getUserLocalTime() {
        ZonedDateTime now = ZonedDateTime.ofInstant(Instant.now(clock), ZoneId.of(this.userTimezone));
        return now.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss z"));
    }

    public String getUserTimezone() {
        return this.userTimezone;
    }

    public String getMachineTimezone() {
        return this.machineTimezone;
    }

    public void setUserTimezone(String userTimezone) {
        this.userTimezone = userTimezone;
    }
}
