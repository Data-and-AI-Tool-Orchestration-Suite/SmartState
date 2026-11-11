package SmartState.Engine;

import SmartState.Launcher;
import com.google.gson.Gson;
import com.google.gson.reflect.TypeToken;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.lang.reflect.Type;
import java.time.Clock;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class EnrollmentWatcher {
    private static final Logger logger = LoggerFactory.getLogger(EnrollmentWatcher.class);
    private static final Type MAP_TYPE = new TypeToken<Map<String, Object>>() { }.getType();

    private final MachineRegistry registry;
    private final ProtocolRegistry protocols;
    private final TimerService timerService;
    private final MachineServices services;
    private final ExecutorService enginePool = Executors.newFixedThreadPool(8);
    private final Gson gson = new Gson();
    private final Clock clock = Clock.systemUTC();

    public EnrollmentWatcher(MachineRegistry registry, ProtocolRegistry protocols, TimerService timerService, MachineServices services) {
        this.registry = registry;
        this.protocols = protocols;
        this.timerService = timerService;
        this.services = services;
        long checkdelay = Launcher.config.getLongParam("checkdelay", 5000L);
        long checktimer = Launcher.config.getLongParam("checktimer", 30000L);
        timerService.scheduleAtFixedRate(this::poll, checkdelay, checktimer);
        timerService.scheduleAtFixedRate(this::periodicSave, 30000L, 900000L);
    }

    private void poll() {
        try {
            List<Map<String, String>> active = Launcher.dbEngine.getActiveEnrollments();
            Set<String> activeUuids = new HashSet<>();
            for (Map<String, String> row : active) {
                activeUuids.add(row.get("enrollment_uuid"));
            }

            for (MachineInstance machine : registry.all()) {
                if (!activeUuids.contains(machine.getEnrollmentUuid())) {
                    logger.info("Ending machine for enrollment " + machine.getEnrollmentUuid());
                    machine.submitEndProtocol();
                    machine.shutdown();
                    registry.deregister(machine.getEnrollmentUuid());
                }
            }

            for (Map<String, String> row : active) {
                MachineInstance existing = registry.byEnrollment(row.get("enrollment_uuid"));
                if (existing != null && !existing.getDefinitionUuid().equals(row.get("definition_uuid"))) {
                    logger.info("Definition changed for enrollment " + row.get("enrollment_uuid") + ", recreating machine");
                    existing.shutdown();
                    registry.deregister(existing.getEnrollmentUuid());
                    existing = null;
                }
                if (existing == null) {
                    createMachine(row, false);
                }
            }
        } catch (Exception ex) {
            logger.error("enrollment poll failed", ex);
        }
    }

    private void createMachine(Map<String, String> row, boolean reset) {
        ProtocolDefinition def = protocols.get(row.get("definition_uuid"));
        if (def == null) {
            return;
        }
        Map<String, String> participantMap = buildParticipantMap(row);
        logger.info("Creating state machine for participant_uuid=" + participantMap.get("participant_uuid")
                + " protocol=" + row.get("protocol_name") + " v" + row.get("version"));
        MachineInstance machine = new MachineInstance(def, participantMap, row.get("enrollment_uuid"),
                row.get("definition_uuid"), Integer.parseInt(row.get("version")), services, timerService, enginePool, clock);
        machine.start();
        String saved = Launcher.dbEngine.getSaveStateByEnrollment(row.get("enrollment_uuid"));
        machine.restore(saved, reset);
        registry.register(machine);
    }

    public String resetMachine(String participantUuid, String protocolName) {
        MachineInstance machine = registry.byParticipantAndProtocol(participantUuid, protocolName);
        if (machine == null) {
            return null;
        }
        machine.submitEndProtocol();
        machine.shutdown();
        registry.deregister(machine.getEnrollmentUuid());
        Map<String, String> row = Launcher.dbEngine.getActiveEnrollment(participantUuid, protocolName);
        if (row == null) {
            return null;
        }
        createMachine(row, true);
        return row.get("enrollment_uuid");
    }

    private Map<String, String> buildParticipantMap(Map<String, String> row) {
        Map<String, String> participantMap = new HashMap<>();
        Map<String, Object> json = gson.fromJson(row.get("participant_json"), MAP_TYPE);
        if (json != null) {
            for (Map.Entry<String, Object> entry : json.entrySet()) {
                if (entry.getValue() instanceof String) {
                    participantMap.put(entry.getKey(), (String) entry.getValue());
                }
            }
        }
        participantMap.put("participant_uuid", row.get("participant_uuid"));
        participantMap.put("study_uuid", row.get("study_uuid"));
        participantMap.put("study_name", row.get("study_name"));
        return participantMap;
    }

    private void periodicSave() {
        for (MachineInstance machine : registry.all()) {
            machine.submitPeriodicSave();
        }
    }

    public void shutdown() {
        enginePool.shutdownNow();
    }
}
