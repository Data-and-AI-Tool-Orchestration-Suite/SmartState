package SmartState.Engine;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.concurrent.Executor;
import java.util.concurrent.atomic.AtomicBoolean;

public class SerializedExecutor {
    private static final Logger logger = LoggerFactory.getLogger(SerializedExecutor.class);
    private final ConcurrentLinkedQueue<Runnable> queue = new ConcurrentLinkedQueue<>();
    private final AtomicBoolean draining = new AtomicBoolean(false);
    private final Executor pool;

    public SerializedExecutor(Executor pool) {
        this.pool = pool;
    }

    public void submit(Runnable task) {
        queue.add(task);
        drain();
    }

    private void drain() {
        if (draining.compareAndSet(false, true)) {
            pool.execute(() -> {
                try {
                    Runnable task;
                    while ((task = queue.poll()) != null) {
                        try {
                            task.run();
                        } catch (Exception ex) {
                            logger.error("machine task failed", ex);
                        }
                    }
                } finally {
                    draining.set(false);
                    if (!queue.isEmpty()) {
                        drain();
                    }
                }
            });
        }
    }
}
