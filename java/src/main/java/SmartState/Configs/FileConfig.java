package SmartState.Configs;

import java.io.BufferedReader;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.HashMap;
import java.util.Map;

/**
 * Configuration file handler
 * @author V.K. Cody Bumgardner
 * @author Caylin Hickey
 * @since 0.1.0
 */

public class FileConfig {

    private final Map<String, Map<String, Object>> sections = new HashMap<>();

    public FileConfig(String configFile) throws IOException {
        try (BufferedReader reader = Files.newBufferedReader(Paths.get(configFile))) {
            String section = "general";
            String line;
            while ((line = reader.readLine()) != null) {
                line = line.trim();
                if (line.isEmpty() || line.startsWith("#") || line.startsWith(";")) {
                    continue;
                }
                if (line.startsWith("[") && line.endsWith("]")) {
                    section = line.substring(1, line.length() - 1).trim();
                    continue;
                }
                int eq = line.indexOf('=');
                if (eq > 0) {
                    String key = line.substring(0, eq).trim();
                    String value = line.substring(eq + 1).trim();
                    sections.computeIfAbsent(section, s -> new HashMap<>()).put(key, value);
                }
            }
        }
    }

    public Map<String, Object> getConfigMap() {
        return getConfigMap("general");
    }

    public Map<String, Object> getConfigMap(String section) {
        return new HashMap<>(sections.getOrDefault(section, new HashMap<>()));
    }
}
