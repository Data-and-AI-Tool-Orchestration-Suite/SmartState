package SmartState.Engine;

import org.junit.jupiter.api.Test;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

public class ProtocolDefinitionParserTest {

    static String fixture(String name) throws Exception {
        try (InputStream in = ProtocolDefinitionParserTest.class.getResourceAsStream("/protocols/" + name)) {
            assertNotNull(in, "missing fixture " + name);
            return new String(in.readAllBytes(), StandardCharsets.UTF_8);
        }
    }

    @Test
    void parsesReadGlucoseFixture() throws Exception {
        ProtocolDefinitionParser parser = new ProtocolDefinitionParser();
        ProtocolDefinitionParser.ParseResult result = parser.parse(fixture("ReadGlucose.v1.json"));
        assertTrue(result.validation.isOk(), String.join("; ", result.validation.getErrors()));
        ProtocolDefinition def = result.definition;
        assertEquals("ReadGlucose", def.name);
        assertEquals("initial", def.initialState);
        assertEquals(9, def.states.size());
        assertEquals(3, def.timers.size());
        assertEquals("receivedEndProtocol", def.endProtocolEvent);
        assertNotNull(def.deviceWindow);
        assertEquals("startReading", def.deviceWindow.readingState);
        assertEquals("same-day-else-reset", def.restore.policy);
        assertTrue(def.state("endReadGlucoseProtocol").terminal);
        assertEquals("warnStartGlucose", def.state("waitStart").afterTransition("startDeadline").to);
        assertEquals("endOfEpisode", def.state("notifyAdmin").autoTransition().to);
    }

    @Test
    void parsesSurveyFixture() throws Exception {
        ProtocolDefinitionParser parser = new ProtocolDefinitionParser();
        ProtocolDefinitionParser.ParseResult result = parser.parse(fixture("Survey.v1.json"));
        assertTrue(result.validation.isOk(), String.join("; ", result.validation.getErrors()));
        ProtocolDefinition def = result.definition;
        assertEquals("Survey", def.name);
        assertEquals(6, def.states.size());
        assertEquals("recompute-from-clock", def.restore.policy);
        assertEquals(3, def.restore.recomputeRules.size());
        assertEquals("llm", def.collection);
        assertNotNull(def.surveyQuestionsByKey.get("health_habits"));
    }

    @Test
    void rejectsUnknownActionType() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"entry\":[{\"type\":\"explode\"}],\"transitions\":[{\"event\":\"go\",\"to\":\"b\"}]},\"b\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("unknown action type")));
    }

    @Test
    void rejectsUndeclaredTimer() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"transitions\":[{\"after\":\"ghost\",\"to\":\"b\"}]},\"b\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("undeclared timer")));
    }

    @Test
    void rejectsUnreachableState() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"transitions\":[{\"event\":\"go\",\"to\":\"b\"}]},\"b\":{\"terminal\":true,\"transitions\":[]},\"c\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("unreachable")));
    }

    @Test
    void rejectsUnknownTransitionTarget() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"transitions\":[{\"event\":\"go\",\"to\":\"nowhere\"}]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("unknown state")));
    }

    @Test
    void rejectsMultipleAutoTransitions() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"transitions\":[{\"auto\":true,\"to\":\"b\"},{\"auto\":true,\"to\":\"c\"}]},\"b\":{\"terminal\":true,\"transitions\":[]},\"c\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("at most one auto")));
    }

    @Test
    void rejectsMissingInitialState() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"states\":{\"a\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("initialState is required")));
    }

    @Test
    void rejectsUnknownPlaceholder() {
        String json = "{\"schemaVersion\":1,\"name\":\"X\",\"initialState\":\"a\",\"states\":{\"a\":{\"entry\":[{\"type\":\"sendSms\",\"template\":\"hi {nickname}\"}],\"transitions\":[{\"event\":\"go\",\"to\":\"b\"}]},\"b\":{\"terminal\":true,\"transitions\":[]}}}";
        ProtocolDefinitionParser.ParseResult result = new ProtocolDefinitionParser().parse(json);
        assertFalse(result.validation.isOk());
        assertTrue(result.validation.getErrors().stream().anyMatch(e -> e.contains("placeholder")));
    }
}
