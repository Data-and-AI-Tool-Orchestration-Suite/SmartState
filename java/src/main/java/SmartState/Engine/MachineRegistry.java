package SmartState.Engine;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class MachineRegistry {
    private final Map<String, MachineInstance> byEnrollment = new ConcurrentHashMap<>();

    public void register(MachineInstance machine) {
        byEnrollment.put(machine.getEnrollmentUuid(), machine);
    }

    public MachineInstance deregister(String enrollmentUuid) {
        return byEnrollment.remove(enrollmentUuid);
    }

    public MachineInstance byEnrollment(String enrollmentUuid) {
        return byEnrollment.get(enrollmentUuid);
    }

    public List<MachineInstance> byParticipant(String participantUuid) {
        List<MachineInstance> machines = new ArrayList<>();
        for (MachineInstance machine : byEnrollment.values()) {
            if (machine.getParticipantUuid().equals(participantUuid)) {
                machines.add(machine);
            }
        }
        machines.sort(Comparator.comparing(MachineInstance::getProtocolName));
        return machines;
    }

    public MachineInstance byParticipantAndProtocol(String participantUuid, String protocolName) {
        for (MachineInstance machine : byEnrollment.values()) {
            if (machine.getParticipantUuid().equals(participantUuid) && machine.getProtocolName().equals(protocolName)) {
                return machine;
            }
        }
        return null;
    }

    public List<MachineInstance> byStudyName(String studyName) {
        List<MachineInstance> machines = new ArrayList<>();
        for (MachineInstance machine : byEnrollment.values()) {
            if (machine.getStudyName().equals(studyName)) {
                machines.add(machine);
            }
        }
        machines.sort(Comparator.comparing(MachineInstance::getProtocolName).thenComparing(MachineInstance::getParticipantUuid));
        return machines;
    }

    public List<MachineInstance> all() {
        return new ArrayList<>(byEnrollment.values());
    }

    public int size() {
        return byEnrollment.size();
    }
}
