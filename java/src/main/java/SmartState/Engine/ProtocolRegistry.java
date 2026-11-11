package SmartState.Engine;

import SmartState.Launcher;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class ProtocolRegistry {
    private static final Logger logger = LoggerFactory.getLogger(ProtocolRegistry.class);
    private final Map<String, ProtocolDefinition> byDefinitionUuid = new ConcurrentHashMap<>();
    private final ProtocolDefinitionParser parser = new ProtocolDefinitionParser();

    public ProtocolDefinition get(String definitionUuid) {
        return byDefinitionUuid.computeIfAbsent(definitionUuid, uuid -> {
            String json = Launcher.dbEngine.getProtocolDefinitionJson(uuid);
            if (json == null) {
                logger.error("protocol definition not found: " + uuid);
                return null;
            }
            ProtocolDefinitionParser.ParseResult result = parser.parse(json);
            if (!result.validation.isOk()) {
                logger.error("protocol definition " + uuid + " failed validation: " + result.validation.getErrors());
                return null;
            }
            return result.definition;
        });
    }
}
