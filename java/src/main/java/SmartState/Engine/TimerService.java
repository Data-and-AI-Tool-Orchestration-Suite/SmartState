package SmartState.Engine;

import java.util.Map;
import java.util.Objects;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicLong;

public class TimerService {

    private static class TimerKey {
        final String enrollmentUuid;
        final String timerName;

        TimerKey(String enrollmentUuid, String timerName) {
            this.enrollmentUuid = enrollmentUuid;
            this.timerName = timerName;
        }

        @Override
        public boolean equals(Object o) {
            if (this == o) return true;
            if (!(o instanceof TimerKey)) return false;
            TimerKey key = (TimerKey) o;
            return enrollmentUuid.equals(key.enrollmentUuid) && timerName.equals(key.timerName);
        }

        @Override
        public int hashCode() {
            return Objects.hash(enrollmentUuid, timerName);
        }
    }

    private static class Scheduled {
        final ScheduledFuture<?> future;
        final long generation;

        Scheduled(ScheduledFuture<?> future, long generation) {
            this.future = future;
            this.generation = generation;
        }
    }

    private final ScheduledExecutorService scheduler = Executors.newScheduledThreadPool(4);
    private final Map<TimerKey, Scheduled> timers = new ConcurrentHashMap<>();
    private final AtomicLong generations = new AtomicLong();

    public long schedule(MachineInstance machine, String timerName, long delaySeconds) {
        TimerKey key = new TimerKey(machine.getEnrollmentUuid(), timerName);
        long generation = generations.incrementAndGet();
        ScheduledFuture<?> future = scheduler.schedule(
                () -> machine.onTimerFire(timerName, generation), delaySeconds, TimeUnit.SECONDS);
        Scheduled previous = timers.put(key, new Scheduled(future, generation));
        if (previous != null) {
            previous.future.cancel(false);
        }
        return generation;
    }

    public boolean isCurrent(MachineInstance machine, String timerName, long generation) {
        Scheduled scheduled = timers.get(new TimerKey(machine.getEnrollmentUuid(), timerName));
        return scheduled != null && scheduled.generation == generation;
    }

    public void cancel(String enrollmentUuid, String timerName) {
        Scheduled scheduled = timers.remove(new TimerKey(enrollmentUuid, timerName));
        if (scheduled != null) {
            scheduled.future.cancel(false);
        }
    }

    public void cancelAll(String enrollmentUuid) {
        timers.keySet().removeIf(key -> {
            if (key.enrollmentUuid.equals(enrollmentUuid)) {
                Scheduled scheduled = timers.get(key);
                if (scheduled != null) {
                    scheduled.future.cancel(false);
                }
                return true;
            }
            return false;
        });
    }

    public ScheduledFuture<?> scheduleAtFixedRate(Runnable task, long initialDelayMillis, long periodMillis) {
        return scheduler.scheduleAtFixedRate(task, initialDelayMillis, periodMillis, TimeUnit.MILLISECONDS);
    }

    public void shutdown() {
        scheduler.shutdownNow();
    }
}
