package SmartState.Engine;

import org.junit.jupiter.api.Test;

import java.io.File;
import java.nio.file.Files;
import java.util.Objects;

import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

public class FastingDefinitionsParseTest {

    @Test
    void allFastingDefinitionsParseAndValidate() throws Exception {
        File dir = new File(Objects.requireNonNull(
                getClass().getResource("/protocols/fasting")).toURI());
        File[] files = dir.listFiles((d, name) -> name.endsWith(".json"));
        assertNotNull(files);
        assertTrue(files.length >= 9, "expected at least 9 fasting definitions, found " + files.length);

        ProtocolDefinitionParser parser = new ProtocolDefinitionParser();
        for (File file : files) {
            String json = Files.readString(file.toPath());
            ProtocolDefinitionParser.ParseResult result = parser.parse(json);
            assertTrue(result.validation.isOk(),
                    file.getName() + " failed validation: " + String.join("; ", result.validation.getErrors()));
            assertNotNull(result.definition.name, file.getName() + " missing name");
            assertNotNull(result.definition.initialState, file.getName() + " missing initialState");
        }
    }
}
