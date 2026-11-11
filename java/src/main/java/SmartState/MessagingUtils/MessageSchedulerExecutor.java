package SmartState.MessagingUtils;

import SmartState.Launcher;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

public class MessageSchedulerExecutor {

    private static final Logger logger = LoggerFactory.getLogger(MessageSchedulerExecutor.class);
    private static final ScheduledExecutorService SCHEDULER = Executors.newSingleThreadScheduledExecutor();
    private static final int INITIAL_DELAY = 0;
    private static final int INTERVAL = 5;

    public static void startWatcher() {
        SCHEDULER.scheduleAtFixedRate(MessageSchedulerExecutor::checkDatabase, INITIAL_DELAY, INTERVAL, TimeUnit.SECONDS);
    }

    public static void stopWatcher() {
        SCHEDULER.shutdownNow();
    }

    private static void checkDatabase() {
        try {
            Launcher.dbEngine.processDueQueuedMessages();
        } catch (Exception e) {
            logger.error("Error checking queued messages", e);
        }
    }
}
