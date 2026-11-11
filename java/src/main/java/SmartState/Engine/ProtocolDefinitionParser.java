package SmartState.Engine;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedList;
import java.util.List;
import java.util.Map;
import java.util.Set;

public class ProtocolDefinitionParser {

    private static final Set<String> ACTION_TYPES = new HashSet<>(List.of(
            "sendSms", "notifyAdmins", "setTimer", "generateSurveyToken", "sendSurveyLink",
            "llmQuestion", "sendResultSms", "resetDeviceWindow", "setVar", "clearVars",
            "incrementCounter", "conditionalSms", "treEvaluateEndcal", "treDayOffRollback"));

    private static final Set<String> TEMPLATE_PLACEHOLDERS = new HashSet<>(List.of(
            "first_name", "last_name", "number", "survey_link", "study",
            "success_rate", "admin_phone", "finish_by", "eating_window"));

    public static class ParseResult {
        public ProtocolDefinition definition;
        public ValidationResult validation = new ValidationResult();
    }

    public ParseResult parse(String json) {
        ParseResult result = new ParseResult();
        JsonObject root;
        try {
            root = JsonParser.parseString(json).getAsJsonObject();
        } catch (Exception ex) {
            result.validation.addError("definition is not valid JSON: " + ex.getMessage());
            return result;
        }
        return parse(root);
    }

    public ParseResult parse(JsonObject root) {
        ParseResult result = new ParseResult();
        ValidationResult v = result.validation;
        ProtocolDefinition def = new ProtocolDefinition();

        def.schemaVersion = intOf(root, "schemaVersion", 0);
        if (def.schemaVersion != 1) {
            v.addError("schemaVersion must be 1");
        }
        def.name = strOf(root, "name", null);
        if (def.name == null || def.name.isEmpty()) {
            v.addError("name is required");
        }
        def.initialState = strOf(root, "initialState", null);
        if (def.initialState == null) {
            v.addError("initialState is required");
        }

        if (root.has("timers")) {
            for (JsonElement e : root.getAsJsonArray("timers")) {
                def.timers.add(e.getAsString());
            }
        }

        JsonObject settings = root.has("settings") ? root.getAsJsonObject("settings") : new JsonObject();
        def.endProtocolEvent = strOf(settings, "endProtocolEvent", null);
        def.collection = strOf(settings, "collection", "none");
        if (!List.of("llm", "link", "none").contains(def.collection)) {
            v.addError("settings.collection must be llm, link, or none");
        }
        if (settings.has("help")) {
            JsonObject h = settings.getAsJsonObject("help");
            def.help = new ProtocolDefinition.HelpSpec();
            if (h.has("keywords")) {
                for (JsonElement e : h.getAsJsonArray("keywords")) {
                    def.help.keywords.add(e.getAsString().toLowerCase());
                }
            }
            def.help.adminTemplate = strOf(h, "adminTemplate", "");
            def.help.participantReply = strOf(h, "participantReply", "");
        }
        if (settings.has("deviceWindow")) {
            JsonObject d = settings.getAsJsonObject("deviceWindow");
            ProtocolDefinition.DeviceWindowSpec spec = new ProtocolDefinition.DeviceWindowSpec();
            spec.quietSeconds = intOf(d, "quietSeconds", 30);
            if (d.has("acceptStates")) {
                for (JsonElement e : d.getAsJsonArray("acceptStates")) {
                    spec.acceptStates.add(e.getAsString());
                }
            }
            spec.startEvent = strOf(d, "startEvent", null);
            spec.endEvent = strOf(d, "endEvent", null);
            if (spec.startEvent == null || spec.endEvent == null) {
                v.addError("deviceWindow requires startEvent and endEvent");
            }
            if (d.has("result")) {
                JsonObject r = d.getAsJsonObject("result");
                ProtocolDefinition.ResultRule rule = new ProtocolDefinition.ResultRule();
                rule.of = strOf(r, "of", "count");
                rule.mod = intOf(r, "mod", 0);
                rule.defaultValue = strOf(r, "default", "");
                if (r.has("map")) {
                    for (Map.Entry<String, JsonElement> entry : r.getAsJsonObject("map").entrySet()) {
                        rule.map.put(entry.getKey(), entry.getValue().getAsString());
                    }
                }
                spec.result = rule;
            }
            def.deviceWindow = spec;
        }

        if (root.has("restore")) {
            JsonObject r = root.getAsJsonObject("restore");
            ProtocolDefinition.RestoreSpec spec = new ProtocolDefinition.RestoreSpec();
            spec.policy = strOf(r, "policy", "exact");
            if (!List.of("recompute-from-clock", "same-day-else-reset", "exact").contains(spec.policy)) {
                v.addError("restore.policy must be recompute-from-clock, same-day-else-reset, or exact");
            }
            if (r.has("terminalStates")) {
                for (JsonElement e : r.getAsJsonArray("terminalStates")) {
                    spec.terminalStates.add(e.getAsString());
                }
            }
            spec.fallbackState = strOf(r, "fallbackState", null);
            spec.noSaveStateEvent = strOf(r, "noSaveStateEvent", null);
            if (r.has("recomputeRules")) {
                for (JsonElement e : r.getAsJsonArray("recomputeRules")) {
                    JsonObject o = e.getAsJsonObject();
                    ProtocolDefinition.RecomputeRule rule = new ProtocolDefinition.RecomputeRule();
                    rule.predicate = parsePredicate(o.getAsJsonObject("if"), v);
                    rule.fire = strOf(o, "fire", null);
                    spec.recomputeRules.add(rule);
                }
            }
            if (r.has("replay")) {
                for (Map.Entry<String, JsonElement> entry : r.getAsJsonObject("replay").entrySet()) {
                    JsonObject o = entry.getValue().getAsJsonObject();
                    ProtocolDefinition.ReplaySpec replay = new ProtocolDefinition.ReplaySpec();
                    if (o.has("restoring")) {
                        for (JsonElement e : o.getAsJsonArray("restoring")) {
                            replay.restoring.add(e.getAsString());
                        }
                    }
                    if (o.has("after")) {
                        for (JsonElement e : o.getAsJsonArray("after")) {
                            replay.after.add(e.getAsString());
                        }
                    }
                    spec.replay.put(entry.getKey(), replay);
                }
            }
            spec.resetFire = strOf(r, "resetFire", null);
            spec.dayBoundaryHour = intOf(r, "dayBoundaryHour", 0);
            spec.dayBoundaryMinute = intOf(r, "dayBoundaryMinute", 0);
            def.restore = spec;
        } else {
            def.restore = new ProtocolDefinition.RestoreSpec();
            def.restore.policy = "exact";
        }

        if (root.has("eventMappings")) {
            JsonObject m = root.getAsJsonObject("eventMappings");
            if (m.has("sms")) {
                JsonObject s = m.getAsJsonObject("sms");
                def.smsMapping = new ProtocolDefinition.SmsMapping();
                def.smsMapping.defaultAction = strOf(s, "defaultAction", "ignore");
                def.smsMapping.fallbackReply = strOf(s, "fallbackReply", null);
                if (s.has("inStates")) {
                    for (JsonElement e : s.getAsJsonArray("inStates")) {
                        def.smsMapping.inStates.add(e.getAsString());
                    }
                }
                if (s.has("ignoreKeywords")) {
                    for (JsonElement e : s.getAsJsonArray("ignoreKeywords")) {
                        def.smsMapping.ignoreKeywords.add(e.getAsString().toLowerCase());
                    }
                }
                if (s.has("rules")) {
                    for (JsonElement e : s.getAsJsonArray("rules")) {
                        JsonObject o = e.getAsJsonObject();
                        ProtocolDefinition.SmsRule rule = new ProtocolDefinition.SmsRule();
                        if (o.has("keywords")) {
                            for (JsonElement k : o.getAsJsonArray("keywords")) {
                                rule.keywords.add(k.getAsString().toLowerCase());
                            }
                        }
                        if (o.has("inStates")) {
                            for (JsonElement k : o.getAsJsonArray("inStates")) {
                                rule.inStates.add(k.getAsString());
                            }
                        }
                        if (o.has("condition")) {
                            rule.condition = parsePredicate(o.getAsJsonObject("condition"), v);
                        }
                        rule.parseTime = boolOf(o, "parseTime", false);
                        rule.saveAs = strOf(o, "saveAs", null);
                        rule.maxBareLength = intOf(o, "maxBareLength", 0);
                        rule.fire = strOf(o, "fire", null);
                        if (o.has("replies")) {
                            for (JsonElement k : o.getAsJsonArray("replies")) {
                                rule.replies.add(k.getAsString());
                            }
                        }
                        if (o.has("reply")) {
                            rule.replies.add(o.get("reply").getAsString());
                        }
                        rule.parseErrorReply = strOf(o, "parseErrorReply", null);
                        if (o.has("guard")) {
                            rule.guard = parsePredicate(o.getAsJsonObject("guard"), v);
                        }
                        rule.guardReply = strOf(o, "guardReply", null);
                        rule.notifyAdminsTemplate = strOf(o, "notifyAdmins", null);
                        rule.flagLastMessage = strOf(o, "flagLastMessage", null);
                        if (rule.keywords.isEmpty()) {
                            v.addError("eventMappings.sms rule requires keywords");
                        }
                        def.smsMapping.rules.add(rule);
                    }
                }
            }
            if (m.has("message")) {
                JsonObject s = m.getAsJsonObject("message");
                def.messageMapping = new ProtocolDefinition.MessageMapping();
                def.messageMapping.commandField = strOf(s, "commandField", "Command");
                if (s.has("commands")) {
                    for (JsonElement e : s.getAsJsonArray("commands")) {
                        def.messageMapping.commands.add(parseCommandRule(e.getAsJsonObject(), true, v));
                    }
                }
                if (s.has("otherwise")) {
                    for (JsonElement e : s.getAsJsonArray("otherwise")) {
                        def.messageMapping.otherwise.add(parseCommandRule(e.getAsJsonObject(), false, v));
                    }
                }
            }
        }

        if (!root.has("states") || root.getAsJsonObject("states").isEmpty()) {
            v.addError("states is required");
            result.definition = def;
            return result;
        }
        for (Map.Entry<String, JsonElement> entry : root.getAsJsonObject("states").entrySet()) {
            JsonObject o = entry.getValue().getAsJsonObject();
            ProtocolDefinition.StateDef state = new ProtocolDefinition.StateDef();
            state.name = entry.getKey();
            state.terminal = boolOf(o, "terminal", false);
            if (o.has("entry")) {
                for (JsonElement e : o.getAsJsonArray("entry")) {
                    ProtocolDefinition.ActionDef action = parseAction(e.getAsJsonObject(), state.name, def, v);
                    if (action != null) {
                        state.entry.add(action);
                    }
                }
            }
            int autoCount = 0;
            if (o.has("transitions")) {
                for (JsonElement e : o.getAsJsonArray("transitions")) {
                    JsonObject t = e.getAsJsonObject();
                    ProtocolDefinition.TransitionDef transition = new ProtocolDefinition.TransitionDef();
                    transition.to = strOf(t, "to", null);
                    if (transition.to == null) {
                        v.addError("state " + state.name + ": transition missing to");
                        continue;
                    }
                    if (t.has("event")) {
                        transition.kind = ProtocolDefinition.TransitionKind.EVENT;
                        transition.trigger = t.get("event").getAsString();
                    } else if (t.has("after")) {
                        transition.kind = ProtocolDefinition.TransitionKind.AFTER;
                        transition.trigger = t.get("after").getAsString();
                        if (!def.timers.contains(transition.trigger)) {
                            v.addError("state " + state.name + ": after() references undeclared timer " + transition.trigger);
                        }
                    } else if (boolOf(t, "auto", false)) {
                        transition.kind = ProtocolDefinition.TransitionKind.AUTO;
                        autoCount++;
                    } else {
                        v.addError("state " + state.name + ": transition must have event, after, or auto");
                        continue;
                    }
                    state.transitions.add(transition);
                }
            }
            if (autoCount > 1) {
                v.addError("state " + state.name + ": at most one auto transition is allowed");
            }
            if (o.has("manualMoves")) {
                for (JsonElement e : o.getAsJsonArray("manualMoves")) {
                    JsonObject t = e.getAsJsonObject();
                    ProtocolDefinition.ManualMove move = new ProtocolDefinition.ManualMove();
                    move.to = strOf(t, "to", null);
                    move.via = strOf(t, "via", null);
                    move.timeField = strOf(t, "timeField", null);
                    if (move.to == null || move.via == null) {
                        v.addError("state " + state.name + ": manualMove requires to and via");
                        continue;
                    }
                    state.manualMoves.add(move);
                }
            }
            def.states.put(state.name, state);
        }

        if (root.has("surveys")) {
            JsonArray surveys = root.getAsJsonArray("surveys");
            def.surveysJson = surveys.toString();
            for (JsonElement e : surveys) {
                JsonObject s = e.getAsJsonObject();
                String key = strOf(s, "key", null);
                if (key != null && s.has("questions")) {
                    def.surveyQuestionsByKey.put(key, s.getAsJsonArray("questions").toString());
                }
            }
        }

        if (def.initialState != null && !def.states.containsKey(def.initialState)) {
            v.addError("initialState " + def.initialState + " is not a defined state");
        }
        for (ProtocolDefinition.StateDef state : def.states.values()) {
            for (ProtocolDefinition.TransitionDef t : state.transitions) {
                if (!def.states.containsKey(t.to)) {
                    v.addError("state " + state.name + ": transition targets unknown state " + t.to);
                }
            }
            for (ProtocolDefinition.ManualMove m : state.manualMoves) {
                if (!def.states.containsKey(m.to)) {
                    v.addError("state " + state.name + ": manualMove targets unknown state " + m.to);
                }
                if (!m.via.equals("auto") && !m.via.startsWith("timeout:") && state.eventTransition(m.via) == null) {
                    v.addError("state " + state.name + ": manualMove via " + m.via + " is not an event of this state");
                }
                if (m.via.startsWith("timeout:") && state.afterTransition(m.via.substring(8)) == null) {
                    v.addError("state " + state.name + ": manualMove via " + m.via + " is not a timed transition of this state");
                }
            }
            if (state.terminal && !state.transitions.isEmpty()) {
                v.addError("state " + state.name + ": terminal states cannot have outgoing transitions");
            }
            if (!state.terminal && state.transitions.isEmpty()) {
                v.addError("state " + state.name + ": non-terminal states need at least one transition");
            }
        }

        if (def.initialState != null && def.states.containsKey(def.initialState)) {
            Set<String> reachable = new HashSet<>();
            LinkedList<String> queue = new LinkedList<>();
            queue.add(def.initialState);
            reachable.add(def.initialState);
            while (!queue.isEmpty()) {
                ProtocolDefinition.StateDef state = def.states.get(queue.poll());
                if (state == null) {
                    continue;
                }
                for (ProtocolDefinition.TransitionDef t : state.transitions) {
                    if (reachable.add(t.to)) {
                        queue.add(t.to);
                    }
                }
            }
            for (String name : def.states.keySet()) {
                if (!reachable.contains(name)) {
                    v.addError("state " + name + " is unreachable from " + def.initialState);
                }
            }
        }

        if (def.deviceWindow != null) {
            for (ProtocolDefinition.StateDef state : def.states.values()) {
                ProtocolDefinition.TransitionDef t = state.eventTransition(def.deviceWindow.startEvent);
                if (t != null) {
                    def.deviceWindow.readingState = t.to;
                    break;
                }
            }
        }

        result.definition = v.isOk() ? def : def;
        return result;
    }

    private ProtocolDefinition.ActionDef parseAction(JsonObject o, String stateName, ProtocolDefinition def, ValidationResult v) {
        String type = strOf(o, "type", null);
        if (type == null || !ACTION_TYPES.contains(type)) {
            v.addError("state " + stateName + ": unknown action type " + type);
            return null;
        }
        ProtocolDefinition.ActionDef action = new ProtocolDefinition.ActionDef();
        if (o.has("templates")) {
            for (JsonElement e : o.getAsJsonArray("templates")) {
                action.templates.add(e.getAsString());
                checkTemplate(e.getAsString(), stateName, v);
            }
        }
        if (o.has("deliverAt")) {
            JsonObject d = o.getAsJsonObject("deliverAt");
            action.deliverAt = new ProtocolDefinition.DeliverAt();
            action.deliverAt.hour = intOf(d, "hour", 8);
            action.deliverAt.minute = intOf(d, "minute", 0);
            action.deliverAt.timezone = strOf(d, "timezone", "participant");
        }
        if (o.has("suppressIf")) {
            action.suppressIf = parsePredicate(o.getAsJsonObject("suppressIf"), v);
        }
        switch (type) {
            case "sendSms":
                action.type = ProtocolDefinition.ActionType.SEND_SMS;
                action.template = strOf(o, "template", "");
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                checkTemplate(action.template, stateName, v);
                break;
            case "notifyAdmins":
                action.type = ProtocolDefinition.ActionType.NOTIFY_ADMINS;
                action.template = strOf(o, "template", "");
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", false);
                checkTemplate(action.template, stateName, v);
                break;
            case "setVar":
                action.type = ProtocolDefinition.ActionType.SET_VAR;
                action.key = strOf(o, "key", null);
                action.value = strOf(o, "value", "true");
                if (action.key == null) {
                    v.addError("state " + stateName + ": setVar requires key");
                }
                break;
            case "clearVars":
                action.type = ProtocolDefinition.ActionType.CLEAR_VARS;
                if (o.has("keys")) {
                    for (JsonElement e : o.getAsJsonArray("keys")) {
                        action.keys.add(e.getAsString());
                    }
                }
                break;
            case "incrementCounter":
                action.type = ProtocolDefinition.ActionType.INCREMENT_COUNTER;
                action.key = strOf(o, "key", null);
                if (action.key == null) {
                    v.addError("state " + stateName + ": incrementCounter requires key");
                }
                break;
            case "conditionalSms":
                action.type = ProtocolDefinition.ActionType.CONDITIONAL_SMS;
                action.condition = parsePredicate(o.getAsJsonObject("if"), v);
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                if (o.has("else")) {
                    for (JsonElement e : o.getAsJsonArray("else")) {
                        action.elseTemplates.add(e.getAsString());
                        checkTemplate(e.getAsString(), stateName, v);
                    }
                }
                break;
            case "treEvaluateEndcal":
                action.type = ProtocolDefinition.ActionType.TRE_EVALUATE_ENDCAL;
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                action.treEvaluation = parseTreEvaluation(o, stateName, v);
                break;
            case "treDayOffRollback":
                action.type = ProtocolDefinition.ActionType.TRE_DAY_OFF_ROLLBACK;
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                break;
            case "setTimer":
                action.type = ProtocolDefinition.ActionType.SET_TIMER;
                action.timer = strOf(o, "timer", null);
                if (action.timer == null || !def.timers.contains(action.timer)) {
                    v.addError("state " + stateName + ": setTimer references undeclared timer " + action.timer);
                }
                action.deadline = parseDeadline(o.getAsJsonObject("deadline"), stateName, def, v);
                break;
            case "generateSurveyToken":
                action.type = ProtocolDefinition.ActionType.GENERATE_SURVEY_TOKEN;
                action.surveyKey = strOf(o, "surveyKey", null);
                break;
            case "sendSurveyLink":
                action.type = ProtocolDefinition.ActionType.SEND_SURVEY_LINK;
                action.template = strOf(o, "template", "");
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                checkTemplate(action.template, stateName, v);
                break;
            case "llmQuestion":
                action.type = ProtocolDefinition.ActionType.LLM_QUESTION;
                action.question = strOf(o, "question", "");
                action.systemPrompt = strOf(o, "systemPrompt", "");
                action.answerKey = strOf(o, "answerKey", "answer");
                action.linkTemplate = strOf(o, "linkTemplate", "");
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                if (o.has("validCategories")) {
                    for (JsonElement e : o.getAsJsonArray("validCategories")) {
                        action.validCategories.add(e.getAsString().toLowerCase());
                    }
                }
                if (o.has("staticAnswers")) {
                    for (Map.Entry<String, JsonElement> entry : o.getAsJsonObject("staticAnswers").entrySet()) {
                        action.staticAnswers.put(entry.getKey(), entry.getValue().getAsString());
                    }
                }
                checkTemplate(action.linkTemplate, stateName, v);
                break;
            case "sendResultSms":
                action.type = ProtocolDefinition.ActionType.SEND_RESULT_SMS;
                action.source = strOf(o, "source", "deviceResult");
                action.suppressOnRestore = boolOf(o, "suppressOnRestore", true);
                if (o.has("messages")) {
                    for (Map.Entry<String, JsonElement> entry : o.getAsJsonObject("messages").entrySet()) {
                        action.messages.put(entry.getKey(), entry.getValue().getAsString());
                        checkTemplate(entry.getValue().getAsString(), stateName, v);
                    }
                }
                break;
            case "resetDeviceWindow":
                action.type = ProtocolDefinition.ActionType.RESET_DEVICE_WINDOW;
                break;
        }
        return action;
    }

    private ProtocolDefinition.DeadlineExpr parseDeadline(JsonObject o, String stateName, ProtocolDefinition def, ValidationResult v) {
        if (o == null) {
            v.addError("state " + stateName + ": setTimer requires a deadline expression");
            return null;
        }
        ProtocolDefinition.DeadlineExpr expr = new ProtocolDefinition.DeadlineExpr();
        String type = strOf(o, "type", "");
        switch (type) {
            case "fixedSeconds":
                expr.type = ProtocolDefinition.DeadlineType.FIXED_SECONDS;
                expr.seconds = intOf(o, "seconds", 0);
                break;
            case "secondsToClock":
                expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_CLOCK;
                expr.hour = intOf(o, "hour", 0);
                expr.minute = intOf(o, "minute", 0);
                expr.second = intOf(o, "second", 0);
                expr.ifPastAddDay = boolOf(o, "ifPastAddDay", false);
                if (o.has("addDayIf")) {
                    expr.addDayIfPredicate = parsePredicate(o.getAsJsonObject("addDayIf"), v);
                }
                break;
            case "secondsToNextMinuteMark":
                expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_NEXT_MINUTE_MARK;
                expr.mark = intOf(o, "mark", 0);
                break;
            case "secondsToHourBoundaryPlus":
                expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_HOUR_BOUNDARY_PLUS;
                expr.hours = intOf(o, "hours", 0);
                break;
            case "secondsToWeekday":
                expr.type = ProtocolDefinition.DeadlineType.SECONDS_TO_WEEKDAY;
                expr.dayOfWeek = intOf(o, "dayOfWeek", 1);
                expr.hour = intOf(o, "hour", 0);
                expr.minute = intOf(o, "minute", 0);
                break;
            case "conditional":
                expr.type = ProtocolDefinition.DeadlineType.CONDITIONAL;
                expr.ifPredicate = parsePredicate(o.getAsJsonObject("if"), v);
                expr.thenExpr = parseDeadline(o.getAsJsonObject("then"), stateName, def, v);
                expr.elseExpr = parseDeadline(o.getAsJsonObject("else"), stateName, def, v);
                break;
            default:
                v.addError("state " + stateName + ": unknown deadline type " + type);
                return null;
        }
        if (o.has("minSeconds")) {
            expr.minSeconds = o.get("minSeconds").getAsLong();
        }
        return expr;
    }

    private ProtocolDefinition.TreEvaluation parseTreEvaluation(JsonObject o, String stateName, ValidationResult v) {
        ProtocolDefinition.TreEvaluation tre = new ProtocolDefinition.TreEvaluation();
        tre.startField = strOf(o, "startField", tre.startField);
        tre.endField = strOf(o, "endField", tre.endField);
        tre.minSeconds = intOf(o, "minSeconds", tre.minSeconds);
        tre.maxSeconds = intOf(o, "maxSeconds", tre.maxSeconds);
        tre.lateHour = intOf(o, "lateHour", tre.lateHour);
        tre.lateMinute = intOf(o, "lateMinute", tre.lateMinute);
        tre.trialPeriodCycles = intOf(o, "trialPeriodCycles", tre.trialPeriodCycles);
        tre.cycleCounter = strOf(o, "cycleCounter", tre.cycleCounter);
        tre.repeatCounter = strOf(o, "repeatCounter", tre.repeatCounter);
        tre.dayOffVar = strOf(o, "dayOffVar", tre.dayOffVar);
        readTemplates(o, "ackMessages", tre.ackMessages, stateName, v);
        readTemplates(o, "successMessages", tre.successMessages, stateName, v);
        readTemplates(o, "shortTrialMessages", tre.shortTrialMessages, stateName, v);
        readTemplates(o, "shortMessages", tre.shortMessages, stateName, v);
        readTemplates(o, "longTrialMessages", tre.longTrialMessages, stateName, v);
        readTemplates(o, "longMessages", tre.longMessages, stateName, v);
        readTemplates(o, "lateMessages", tre.lateMessages, stateName, v);
        readTemplates(o, "neutralMessages", tre.neutralMessages, stateName, v);
        return tre;
    }

    private void readTemplates(JsonObject o, String key, List<String> target, String stateName, ValidationResult v) {
        if (o.has(key)) {
            for (JsonElement e : o.getAsJsonArray(key)) {
                target.add(e.getAsString());
                checkTemplate(e.getAsString(), stateName, v);
            }
        }
    }

    private ProtocolDefinition.Predicate parsePredicate(JsonObject o, ValidationResult v) {
        ProtocolDefinition.Predicate p = new ProtocolDefinition.Predicate();
        if (o == null) {
            p.type = ProtocolDefinition.PredicateType.ALWAYS;
            return p;
        }
        String type = strOf(o, "type", "always");
        switch (type) {
            case "beforeClock":
                p.type = ProtocolDefinition.PredicateType.BEFORE_CLOCK;
                p.hour = intOf(o, "hour", 0);
                p.minute = intOf(o, "minute", 0);
                break;
            case "afterClock":
                p.type = ProtocolDefinition.PredicateType.AFTER_CLOCK;
                p.hour = intOf(o, "hour", 0);
                p.minute = intOf(o, "minute", 0);
                break;
            case "timerWithinMinuteWindow":
                p.type = ProtocolDefinition.PredicateType.TIMER_WITHIN_MINUTE_WINDOW;
                p.timer = strOf(o, "timer", null);
                p.fromMinute = intOf(o, "fromMinute", 0);
                p.toMinute = intOf(o, "toMinute", 0);
                break;
            case "varEquals":
                p.type = ProtocolDefinition.PredicateType.VAR_EQUALS;
                p.key = strOf(o, "key", null);
                p.value = strOf(o, "value", null);
                break;
            case "counterAtLeast":
                p.type = ProtocolDefinition.PredicateType.COUNTER_AT_LEAST;
                p.key = strOf(o, "key", null);
                p.count = intOf(o, "count", 0);
                break;
            case "fieldAfterClock":
                p.type = ProtocolDefinition.PredicateType.FIELD_AFTER_CLOCK;
                p.field = strOf(o, "field", null);
                p.hour = intOf(o, "hour", 0);
                p.minute = intOf(o, "minute", 0);
                break;
            case "fieldBetweenClock":
                p.type = ProtocolDefinition.PredicateType.FIELD_BETWEEN_CLOCK;
                p.field = strOf(o, "field", null);
                p.fromHour = intOf(o, "fromHour", 0);
                p.toHour = intOf(o, "toHour", 0);
                break;
            case "fieldWasYesterday":
                p.type = ProtocolDefinition.PredicateType.FIELD_WAS_YESTERDAY;
                p.field = strOf(o, "field", null);
                p.boundaryHour = intOf(o, "boundaryHour", 4);
                p.boundaryMinute = intOf(o, "boundaryMinute", 0);
                break;
            case "not":
                p.type = ProtocolDefinition.PredicateType.NOT;
                p.inner = parsePredicate(o.getAsJsonObject("inner"), v);
                break;
            case "always":
                p.type = ProtocolDefinition.PredicateType.ALWAYS;
                break;
            default:
                v.addError("unknown predicate type " + type);
                p.type = ProtocolDefinition.PredicateType.ALWAYS;
        }
        return p;
    }

    private ProtocolDefinition.CommandRule parseCommandRule(JsonObject o, boolean requiresEquals, ValidationResult v) {
        ProtocolDefinition.CommandRule rule = new ProtocolDefinition.CommandRule();
        rule.equals = strOf(o, "equals", null);
        rule.fire = strOf(o, "fire", null);
        if (requiresEquals && rule.equals == null) {
            v.addError("eventMappings.message command rule requires equals");
        }
        if (rule.fire == null) {
            v.addError("eventMappings.message rule requires fire");
        }
        if (o.has("inStates")) {
            for (JsonElement e : o.getAsJsonArray("inStates")) {
                rule.inStates.add(e.getAsString());
            }
        }
        return rule;
    }

    private void checkTemplate(String template, String stateName, ValidationResult v) {
        if (template == null) {
            return;
        }
        int idx = 0;
        while ((idx = template.indexOf('{', idx)) >= 0) {
            int end = template.indexOf('}', idx);
            if (end < 0) {
                break;
            }
            String placeholder = template.substring(idx + 1, end);
            if (!placeholder.isEmpty() && placeholder.matches("[a-z_]+") && !TEMPLATE_PLACEHOLDERS.contains(placeholder)) {
                v.addError("state " + stateName + ": unknown template placeholder {" + placeholder + "}");
            }
            idx = end + 1;
        }
    }

    private static String strOf(JsonObject o, String key, String def) {
        return o != null && o.has(key) && !o.get(key).isJsonNull() ? o.get(key).getAsString() : def;
    }

    private static int intOf(JsonObject o, String key, int def) {
        return o != null && o.has(key) && !o.get(key).isJsonNull() ? o.get(key).getAsInt() : def;
    }

    private static boolean boolOf(JsonObject o, String key, boolean def) {
        return o != null && o.has(key) && !o.get(key).isJsonNull() ? o.get(key).getAsBoolean() : def;
    }
}
