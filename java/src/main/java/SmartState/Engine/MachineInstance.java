package SmartState.Engine;

import SmartState.TimeUtils.TimezoneHelper;
import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import org.json.JSONArray;
import org.json.JSONObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.time.Clock;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.Executor;

public class MachineInstance {
    private static final Logger logger = LoggerFactory.getLogger(MachineInstance.class);
    private static final String DEVICE_WINDOW_TIMER = "__deviceWindow";

    private final ProtocolDefinition def;
    private final Map<String, String> participantMap;
    private final String enrollmentUuid;
    private final String definitionUuid;
    private final int protocolVersion;
    private final MachineServices services;
    private final TimerService timerService;
    private final SerializedExecutor mailbox;
    private final TimezoneHelper tzHelper;
    private final Gson gson = new Gson();
    private final Clock clock;

    private String currentState;
    private final Map<String, Long> history = new LinkedHashMap<>();
    private final Map<String, Long> timerDeadlines = new HashMap<>();
    private long startTimestamp;
    private boolean isRestoring;
    private boolean isReset;

    private String surveyToken;
    private JSONArray llmMessages = new JSONArray();
    private ProtocolDefinition.ActionDef pendingLlmQuestion;
    private int llmRetries;
    private boolean deviceStarted;
    private int deviceCount;
    private long deviceGeneration;
    private final Map<String, String> vars = new LinkedHashMap<>();
    private final Map<String, Long> eventFields = new LinkedHashMap<>();
    private final java.util.Random random;

    public MachineInstance(ProtocolDefinition def, Map<String, String> participantMap, String enrollmentUuid,
                           String definitionUuid, int protocolVersion, MachineServices services,
                           TimerService timerService, Executor pool, Clock clock) {
        this.def = def;
        this.participantMap = participantMap;
        this.enrollmentUuid = enrollmentUuid;
        this.definitionUuid = definitionUuid;
        this.protocolVersion = protocolVersion;
        this.services = services;
        this.timerService = timerService;
        this.mailbox = new SerializedExecutor(pool);
        this.clock = clock;
        this.tzHelper = new TimezoneHelper(participantMap.getOrDefault("time_zone", "UTC"), "GMT", clock);
        this.currentState = def.initialState;
        this.random = new java.util.Random(enrollmentUuid.hashCode());
    }

    public String getEnrollmentUuid() {
        return enrollmentUuid;
    }

    public String getParticipantUuid() {
        return participantMap.get("participant_uuid");
    }

    public String getStudyUuid() {
        return participantMap.get("study_uuid");
    }

    public String getStudyName() {
        return participantMap.get("study_name");
    }

    public String getProtocolName() {
        return def.name;
    }

    public String getDefinitionUuid() {
        return definitionUuid;
    }

    public int getProtocolVersion() {
        return protocolVersion;
    }

    public String getCurrentState() {
        return currentState;
    }

    public ProtocolDefinition getDefinition() {
        return def;
    }

    public TimezoneHelper getTzHelper() {
        return tzHelper;
    }

    public Map<String, String> getParticipantMap() {
        return participantMap;
    }

    public void start() {
        mailbox.submit(() -> enterState(def.initialState));
    }

    public void submitEvent(String event) {
        mailbox.submit(() -> fireEvent(event));
    }

    public void submitEndProtocol() {
        if (def.endProtocolEvent != null) {
            submitEvent(def.endProtocolEvent);
        }
    }

    public boolean matchesHelp(String body) {
        if (def.help == null || body == null) {
            return false;
        }
        String lower = body.toLowerCase();
        for (String keyword : def.help.keywords) {
            if (lower.contains(keyword)) {
                return true;
            }
        }
        return false;
    }

    public void submitHelp() {
        mailbox.submit(() -> {
            services.notifyAdmins(this, interpolate(def.help.adminTemplate));
            services.sendSms(this, interpolate(def.help.participantReply));
        });
    }

    public boolean smsIgnores(String body) {
        if (def.smsMapping == null || body == null) {
            return false;
        }
        String lower = body.toLowerCase();
        for (String keyword : def.smsMapping.ignoreKeywords) {
            if (lower.contains(keyword)) {
                return true;
            }
        }
        return false;
    }

    public boolean smsHandles(String body) {
        if (def.smsMapping == null || body == null) {
            return false;
        }
        if ("llmAnswer".equals(def.smsMapping.defaultAction) && pendingLlmQuestion != null) {
            return true;
        }
        String lower = body.toLowerCase();
        for (ProtocolDefinition.SmsRule rule : def.smsMapping.rules) {
            for (String keyword : rule.keywords) {
                if (lower.contains(keyword)) {
                    return true;
                }
            }
        }
        return false;
    }

    public boolean hasSmsFallback() {
        return def.smsMapping != null && def.smsMapping.fallbackReply != null;
    }

    public void submitSms(Map<String, String> incoming) {
        mailbox.submit(() -> {
            if (def.smsMapping == null) {
                return;
            }
            String body = incoming.get("Body");
            if (body == null) {
                return;
            }
            String lower = body.toLowerCase();

            for (ProtocolDefinition.SmsRule rule : def.smsMapping.rules) {
                String matchedKeyword = null;
                for (String keyword : rule.keywords) {
                    if (lower.contains(keyword)) {
                        matchedKeyword = keyword;
                        break;
                    }
                }
                if (matchedKeyword == null) {
                    continue;
                }
                if (!rule.inStates.isEmpty() && !rule.inStates.contains(currentState)) {
                    continue;
                }
                if (rule.condition != null && !DeadlineCalculator.evaluatePredicate(rule.condition, tzHelper, timerDeadlines, vars, eventFields)) {
                    continue;
                }
                applySmsRule(rule, lower, matchedKeyword);
                return;
            }

            if ("llmAnswer".equals(def.smsMapping.defaultAction) && pendingLlmQuestion != null) {
                handleLlmAnswer(body);
                return;
            }
            if (def.smsMapping.fallbackReply != null) {
                services.sendSms(this, interpolate(def.smsMapping.fallbackReply));
            }
        });
    }

    private void applySmsRule(ProtocolDefinition.SmsRule rule, String lowerBody, String keyword) {
        Long parsedTime = null;
        if (rule.parseTime) {
            String remainder = lowerBody.substring(lowerBody.indexOf(keyword) + keyword.length()).trim();
            if (remainder.isEmpty()) {
                parsedTime = tzHelper.nowEpochSeconds();
            } else {
                parsedTime = tzHelper.parseSmsTime(lowerBody, keyword);
                if (parsedTime == null && rule.maxBareLength > 0 && lowerBody.trim().length() <= rule.maxBareLength) {
                    parsedTime = tzHelper.nowEpochSeconds();
                }
                if (parsedTime == null) {
                    if (rule.parseErrorReply != null) {
                        services.sendSms(this, interpolate(rule.parseErrorReply));
                    }
                    return;
                }
            }
        }
        if (rule.guard != null) {
            Map<String, Long> candidate = new LinkedHashMap<>(eventFields);
            if (rule.saveAs != null && parsedTime != null) {
                candidate.put(rule.saveAs, parsedTime);
            }
            if (DeadlineCalculator.evaluatePredicate(rule.guard, tzHelper, timerDeadlines, vars, candidate)) {
                if (rule.guardReply != null) {
                    services.sendSms(this, interpolate(rule.guardReply));
                }
                return;
            }
        }
        if (rule.saveAs != null && parsedTime != null) {
            eventFields.put(rule.saveAs, parsedTime);
        }
        if (rule.notifyAdminsTemplate != null) {
            services.notifyAdmins(this, interpolate(rule.notifyAdminsTemplate));
        }
        if (rule.flagLastMessage != null) {
            services.flagLastIncomingMessage(this, rule.flagLastMessage);
        }
        if (!rule.replies.isEmpty()) {
            services.sendSms(this, interpolate(pickRandom(rule.replies)));
        }
        if (rule.fire != null) {
            fireEvent(rule.fire);
            if (rule.saveAs != null && parsedTime != null) {
                services.saveEventTime(this, currentState, rule.saveAs, parsedTime);
            }
        }
    }

    private String pickRandom(List<String> pool) {
        return pool.get(random.nextInt(pool.size()));
    }

    public void submitCommandMessage(Map<String, String> incoming) {
        mailbox.submit(() -> {
            if (def.messageMapping == null) {
                return;
            }
            String command = incoming.get(def.messageMapping.commandField);
            for (ProtocolDefinition.CommandRule rule : def.messageMapping.commands) {
                if (rule.equals.equals(command) && (rule.inStates.isEmpty() || rule.inStates.contains(currentState))) {
                    fireEvent(rule.fire);
                    return;
                }
            }
            for (ProtocolDefinition.CommandRule rule : def.messageMapping.otherwise) {
                if (rule.inStates.isEmpty() || rule.inStates.contains(currentState)) {
                    fireEvent(rule.fire);
                    return;
                }
            }
            logger.warn(getParticipantUuid() + " " + currentState + " unexpected message");
        });
    }

    public void submitDevicePacket(Map<String, String> decoded) {
        mailbox.submit(() -> handleDevicePacket());
    }

    public void onTimerFire(String timerName, long generation) {
        mailbox.submit(() -> {
            if (!timerService.isCurrent(this, timerName, generation)) {
                return;
            }
            timerService.cancel(enrollmentUuid, timerName);
            if (DEVICE_WINDOW_TIMER.equals(timerName)) {
                handleDeviceQuiet();
                return;
            }
            ProtocolDefinition.StateDef state = def.state(currentState);
            ProtocolDefinition.TransitionDef transition = state == null ? null : state.afterTransition(timerName);
            if (transition != null) {
                transitionTo(transition.to);
            }
        });
    }

    public CompletableFuture<String> moveTo(String toState) {
        return moveTo(toState, null);
    }

    public CompletableFuture<String> moveTo(String toState, String time) {
        CompletableFuture<String> future = new CompletableFuture<>();
        mailbox.submit(() -> {
            ProtocolDefinition.StateDef state = def.state(currentState);
            if (state == null) {
                future.complete(currentState + " invalid");
                return;
            }
            for (ProtocolDefinition.ManualMove move : state.manualMoves) {
                if (!move.to.equals(toState)) {
                    continue;
                }
                Long parsedTime = null;
                if (move.timeField != null && time != null && !time.isEmpty() && !time.equals("-1")) {
                    parsedTime = tzHelper.parseWebsiteTime(time);
                }
                if (move.timeField != null) {
                    eventFields.put(move.timeField, parsedTime == null ? tzHelper.nowEpochSeconds() : parsedTime);
                }
                if (move.via.equals("auto")) {
                    future.complete(move.to);
                    return;
                }
                if (move.via.startsWith("timeout:")) {
                    String timerName = move.via.substring(8);
                    timerService.cancel(enrollmentUuid, timerName);
                    ProtocolDefinition.TransitionDef transition = state.afterTransition(timerName);
                    if (transition != null) {
                        transitionTo(transition.to);
                        completeManualMove(move, future);
                        return;
                    }
                } else {
                    ProtocolDefinition.TransitionDef transition = state.eventTransition(move.via);
                    if (transition != null) {
                        transitionTo(transition.to);
                        completeManualMove(move, future);
                        return;
                    }
                }
            }
            future.complete(currentState + " invalid");
        });
        return future;
    }

    private void completeManualMove(ProtocolDefinition.ManualMove move, CompletableFuture<String> future) {
        if (move.timeField != null && eventFields.containsKey(move.timeField)) {
            services.saveEventTime(this, currentState, move.timeField, eventFields.get(move.timeField));
        }
        future.complete(move.to);
    }

    public String validNextStates() {
        ProtocolDefinition.StateDef state = def.state(currentState);
        if (state == null) {
            return "";
        }
        StringBuilder sb = new StringBuilder();
        for (ProtocolDefinition.ManualMove move : state.manualMoves) {
            if (sb.length() > 0) {
                sb.append(",");
            }
            sb.append(move.to);
        }
        return sb.toString();
    }

    public void updateTimezone(String tz) {
        mailbox.submit(() -> {
            participantMap.put("time_zone", tz);
            tzHelper.setUserTimezone(tz);
        });
    }

    public void submitPeriodicSave() {
        mailbox.submit(() -> {
            if (startTimestamp > 0) {
                services.uploadSaveState(this, saveStateJson());
            }
            String currentTimezone = services.getParticipantTimezone(getParticipantUuid());
            if (currentTimezone != null && !currentTimezone.isEmpty()
                    && !currentTimezone.equals(participantMap.get("time_zone"))) {
                participantMap.put("time_zone", currentTimezone);
                tzHelper.setUserTimezone(currentTimezone);
            }
        });
    }

    public void shutdown() {
        timerService.cancelAll(enrollmentUuid);
    }

    public void restore(String savedJson, boolean reset) {
        mailbox.submit(() -> {
            if (reset) {
                isReset = true;
                runRestorePolicyReset();
                isReset = false;
                return;
            }
            if (savedJson == null || savedJson.isEmpty()) {
                runRestoreNoSave();
                return;
            }
            JsonObject saved;
            try {
                saved = JsonParser.parseString(savedJson).getAsJsonObject();
            } catch (Exception ex) {
                logger.error("restore: unparseable save state for " + getParticipantUuid(), ex);
                runRestoreNoSave();
                return;
            }
            loadSavedState(saved);
            String policy = def.restore.policy;
            if ("recompute-from-clock".equals(policy)) {
                String savedState = savedStateName(saved);
                if (savedState != null && def.restore.terminalStates.contains(savedState)) {
                    return;
                }
                isRestoring = true;
                fireRecomputeRules();
                isRestoring = false;
            } else if ("same-day-else-reset".equals(policy)) {
                String savedState = savedStateName(saved);
                long savedTime = savedLong(saved, "currentTime", 0);
                if (savedState == null) {
                    runRestoreNoSave();
                    return;
                }
                boolean sameDay = def.restore.dayBoundaryHour == 0 && def.restore.dayBoundaryMinute == 0
                        ? tzHelper.isSameLocalDate(savedTime)
                        : tzHelper.isSameLocalDateWithBoundary(savedTime, def.restore.dayBoundaryHour, def.restore.dayBoundaryMinute);
                if (!sameDay && !def.restore.terminalStates.contains(savedState)) {
                    savedState = def.restore.fallbackState;
                    vars.clear();
                }
                ProtocolDefinition.ReplaySpec replay = def.restore.replay.get(savedState);
                if (replay == null) {
                    return;
                }
                isRestoring = true;
                for (String event : replay.restoring) {
                    fireEvent(event);
                }
                isRestoring = false;
                for (String event : replay.after) {
                    fireEvent(event);
                }
            } else {
                String savedState = savedStateName(saved);
                if (savedState == null || def.state(savedState) == null) {
                    runRestoreNoSave();
                    return;
                }
                isRestoring = true;
                currentState = savedState;
                logState(savedState);
                history.put(savedState, tzHelper.nowEpochSeconds());
                ProtocolDefinition.StateDef state = def.state(savedState);
                for (ProtocolDefinition.ActionDef action : state.entry) {
                    if (action.type == ProtocolDefinition.ActionType.SET_TIMER) {
                        long deadline = timerDeadlines.getOrDefault(action.timer, 0L);
                        long remaining = Math.max(0, deadline - tzHelper.nowEpochSeconds());
                        timerDeadlines.put(action.timer, tzHelper.nowEpochSeconds() + remaining);
                        timerService.schedule(this, action.timer, remaining);
                    }
                }
                services.uploadSaveState(this, saveStateJson());
                isRestoring = false;
            }
        });
    }

    private void runRestorePolicyReset() {
        if ("recomputeRules".equals(def.restore.resetFire) || def.restore.resetFire == null) {
            fireRecomputeRules();
        } else {
            fireEvent(def.restore.resetFire);
        }
    }

    private void runRestoreNoSave() {
        if (!def.restore.recomputeRules.isEmpty()) {
            fireRecomputeRules();
        } else if (def.restore.noSaveStateEvent != null) {
            fireEvent(def.restore.noSaveStateEvent);
        }
    }

    private void fireRecomputeRules() {
        for (ProtocolDefinition.RecomputeRule rule : def.restore.recomputeRules) {
            if (DeadlineCalculator.evaluatePredicate(rule.predicate, tzHelper, timerDeadlines)) {
                fireEvent(rule.fire);
                return;
            }
        }
    }

    private void loadSavedState(JsonObject saved) {
        if (saved.has("history")) {
            for (Map.Entry<String, JsonElement> entry : saved.getAsJsonObject("history").entrySet()) {
                history.put(entry.getKey(), entry.getValue().getAsLong());
            }
        }
        if (saved.has("timers")) {
            JsonObject timers = saved.getAsJsonObject("timers");
            startTimestamp = timers.has("startTime") ? timers.get("startTime").getAsLong() : 0;
            for (String timerName : def.timers) {
                if (timers.has(timerName)) {
                    timerDeadlines.put(timerName, timers.get(timerName).getAsLong());
                }
            }
        }
        if (saved.has("context")) {
            JsonObject context = saved.getAsJsonObject("context");
            if (context.has("surveyToken") && !context.get("surveyToken").isJsonNull()) {
                surveyToken = context.get("surveyToken").getAsString();
            }
            if (context.has("llmRetries")) {
                llmRetries = context.get("llmRetries").getAsInt();
            }
            if (context.has("deviceStarted")) {
                deviceStarted = context.get("deviceStarted").getAsBoolean();
            }
            if (context.has("deviceCount")) {
                deviceCount = context.get("deviceCount").getAsInt();
            }
            if (context.has("vars")) {
                for (Map.Entry<String, JsonElement> entry : context.getAsJsonObject("vars").entrySet()) {
                    vars.put(entry.getKey(), entry.getValue().getAsString());
                }
            }
            if (context.has("fields")) {
                for (Map.Entry<String, JsonElement> entry : context.getAsJsonObject("fields").entrySet()) {
                    eventFields.put(entry.getKey(), entry.getValue().getAsLong());
                }
            }
        }
    }

    private String savedStateName(JsonObject saved) {
        if (saved.has("timers")) {
            JsonObject timers = saved.getAsJsonObject("timers");
            if (timers.has("state")) {
                return timers.get("state").getAsString();
            }
        }
        return null;
    }

    private long savedLong(JsonObject saved, String key, long def) {
        if (saved.has("timers")) {
            JsonObject timers = saved.getAsJsonObject("timers");
            if (timers.has(key)) {
                return timers.get(key).getAsLong();
            }
        }
        return def;
    }

    private void fireEvent(String event) {
        ProtocolDefinition.StateDef state = def.state(currentState);
        if (state == null) {
            return;
        }
        ProtocolDefinition.TransitionDef transition = state.eventTransition(event);
        if (transition == null) {
            return;
        }
        transitionTo(transition.to);
    }

    private void transitionTo(String toState) {
        for (String timerName : def.timers) {
            timerService.cancel(enrollmentUuid, timerName);
        }
        currentState = toState;
        enterState(toState);
    }

    private void enterState(String state) {
        currentState = state;
        logState(state);
        history.put(state, tzHelper.nowEpochSeconds());
        if (startTimestamp == 0) {
            startTimestamp = tzHelper.nowEpochSeconds();
        }
        ProtocolDefinition.StateDef stateDef = def.state(state);
        if (stateDef == null) {
            return;
        }
        for (ProtocolDefinition.ActionDef action : stateDef.entry) {
            executeAction(action);
        }
        services.uploadSaveState(this, saveStateJson());
        ProtocolDefinition.TransitionDef auto = stateDef.autoTransition();
        if (auto != null) {
            transitionTo(auto.to);
        }
    }

    private boolean actionSuppressed(ProtocolDefinition.ActionDef action) {
        if (isRestoring && action.suppressOnRestore) {
            return true;
        }
        return action.suppressIf != null
                && DeadlineCalculator.evaluatePredicate(action.suppressIf, tzHelper, timerDeadlines, vars, eventFields);
    }

    private void deliverSms(ProtocolDefinition.ActionDef action, String body) {
        if (action.deliverAt == null) {
            services.sendSms(this, body);
        } else {
            String timezone = "admin".equals(action.deliverAt.timezone) ? services.adminTimezone() : null;
            services.sendScheduledSms(this, body, tzHelper.nextClockEpoch(action.deliverAt.hour, action.deliverAt.minute, timezone));
        }
    }

    private void deliverAdmin(ProtocolDefinition.ActionDef action, String body) {
        if (action.deliverAt == null) {
            services.notifyAdmins(this, body);
        } else {
            String timezone = "participant".equals(action.deliverAt.timezone) ? null : services.adminTimezone();
            services.notifyAdminsScheduled(this, body, tzHelper.nextClockEpoch(action.deliverAt.hour, action.deliverAt.minute, timezone));
        }
    }

    private String actionTemplate(ProtocolDefinition.ActionDef action) {
        if (!action.templates.isEmpty()) {
            return pickRandom(action.templates);
        }
        return action.template;
    }

    private void executeAction(ProtocolDefinition.ActionDef action) {
        switch (action.type) {
            case SEND_SMS:
                if (!actionSuppressed(action)) {
                    deliverSms(action, interpolate(actionTemplate(action)));
                }
                break;
            case NOTIFY_ADMINS:
                if (!actionSuppressed(action)) {
                    deliverAdmin(action, interpolate(actionTemplate(action)));
                }
                break;
            case SET_VAR:
                vars.put(action.key, action.value);
                break;
            case CLEAR_VARS:
                for (String key : action.keys) {
                    vars.remove(key);
                }
                break;
            case INCREMENT_COUNTER:
                vars.put(action.key, String.valueOf(counterValue(action.key) + 1));
                break;
            case CONDITIONAL_SMS:
                if (!actionSuppressed(action)) {
                    boolean matched = DeadlineCalculator.evaluatePredicate(action.condition, tzHelper, timerDeadlines, vars, eventFields);
                    List<String> pool = matched ? action.templates : action.elseTemplates;
                    if (!pool.isEmpty()) {
                        deliverSms(action, interpolate(pickRandom(pool)));
                    }
                }
                break;
            case TRE_EVALUATE_ENDCAL:
                if (!actionSuppressed(action)) {
                    evaluateTreEndcal(action.treEvaluation);
                }
                break;
            case TRE_DAY_OFF_ROLLBACK:
                if (!actionSuppressed(action)) {
                    rollbackSuccessForDayOff(null);
                }
                break;
            case SET_TIMER:
                long seconds = DeadlineCalculator.evaluate(action.deadline, tzHelper, timerDeadlines);
                timerDeadlines.put(action.timer, tzHelper.nowEpochSeconds() + seconds);
                timerService.schedule(this, action.timer, seconds);
                logger.info(def.name + " " + getParticipantUuid() + " in " + currentState + " -> " + action.timer + " = " + tzHelper.getDateFromAddingSeconds(seconds));
                break;
            case GENERATE_SURVEY_TOKEN:
                String questionsJson = action.surveyKey == null ? null : def.surveyQuestionsByKey.get(action.surveyKey);
                surveyToken = services.generateSurveyToken(this, action.surveyKey, questionsJson);
                break;
            case SEND_SURVEY_LINK:
                if (!isRestoring || !action.suppressOnRestore) {
                    services.sendSms(this, interpolate(action.template));
                }
                break;
            case LLM_QUESTION:
                executeLlmQuestion(action);
                break;
            case SEND_RESULT_SMS:
                String result = services.getDeviceResult(this);
                String message = action.messages.get(result);
                if (message != null && (!isRestoring || !action.suppressOnRestore)) {
                    services.sendSms(this, interpolate(message));
                }
                break;
            case RESET_DEVICE_WINDOW:
                deviceStarted = false;
                deviceCount = 0;
                timerService.cancel(enrollmentUuid, DEVICE_WINDOW_TIMER);
                break;
        }
    }

    private void executeLlmQuestion(ProtocolDefinition.ActionDef action) {
        if ("llm".equals(def.collection)) {
            if (!isRestoring || !action.suppressOnRestore) {
                services.sendSms(this, interpolate(action.question));
            }
            llmMessages = new JSONArray();
            JSONObject systemMessage = new JSONObject();
            systemMessage.put("role", "system");
            systemMessage.put("content", action.systemPrompt);
            llmMessages.put(systemMessage);
            JSONObject assistantMessage = new JSONObject();
            assistantMessage.put("role", "assistant");
            assistantMessage.put("content", action.question);
            llmMessages.put(assistantMessage);
            pendingLlmQuestion = action;
        } else if ("link".equals(def.collection)) {
            if (!isRestoring || !action.suppressOnRestore) {
                services.sendSms(this, interpolate(action.linkTemplate));
            }
        }
    }

    private long counterValue(String key) {
        try {
            String value = vars.get(key);
            return value == null ? 0 : Long.parseLong(value);
        } catch (NumberFormatException ex) {
            return 0;
        }
    }

    private void evaluateTreEndcal(ProtocolDefinition.TreEvaluation tre) {
        vars.put(tre.repeatCounter, String.valueOf(counterValue(tre.repeatCounter) + 1));
        boolean isRepeat = counterValue(tre.repeatCounter) > 1;
        boolean isDayOff = "true".equals(vars.get(tre.dayOffVar));
        boolean trialDone = counterValue(tre.cycleCounter) >= tre.trialPeriodCycles;

        if (!tre.ackMessages.isEmpty()) {
            services.sendSms(this, interpolate(pickRandom(tre.ackMessages)));
        }

        Long start = eventFields.get(tre.startField);
        Long end = eventFields.get(tre.endField);
        if (start == null || end == null) {
            return;
        }
        long duration = end - start;
        if (trialDone) {
            services.setEatingWindowDuration(this, "startcal", duration);
        }

        if (duration < tre.minSeconds) {
            if (trialDone) {
                applySuccessCounters(tre, false, isRepeat, false);
                sendPool(tre.shortMessages);
            } else {
                sendPool(tre.shortTrialMessages);
            }
        } else if (duration > tre.maxSeconds) {
            if (trialDone) {
                applySuccessCounters(tre, false, isRepeat, false);
                sendPool(tre.longMessages);
            } else {
                sendPool(tre.longTrialMessages);
            }
        } else if (tzHelper.epochAfterClock(end, tre.lateHour, tre.lateMinute) && !isDayOff) {
            if (trialDone) {
                applySuccessCounters(tre, false, isRepeat, true);
                vars.put("wasAfter8pm", "true");
            }
            sendPool(tre.lateMessages);
            if (trialDone) {
                sendPool(tre.neutralMessages);
            }
        } else {
            if (trialDone && !isDayOff) {
                applySuccessCounters(tre, true, isRepeat, false);
                vars.put("wasSuccessfulFast", "true");
                sendPool(tre.successMessages);
            }
        }
    }

    private void applySuccessCounters(ProtocolDefinition.TreEvaluation tre, boolean success, boolean isRepeat, boolean after8pm) {
        long[] counters = services.getTreCounters(this, "startcal");
        long successful = counters[0];
        long total = counters[1];
        long late = counters[2];
        if (isRepeat) {
            if ("true".equals(vars.get("wasSuccessfulFast"))) {
                successful = Math.max(0, successful - 1);
                vars.remove("wasSuccessfulFast");
            }
            if ("true".equals(vars.get("wasAfter8pm"))) {
                late = Math.max(0, late - 1);
                vars.remove("wasAfter8pm");
            }
        } else {
            total += 1;
        }
        if (success) {
            successful += 1;
        }
        if (after8pm) {
            late += 1;
        }
        services.setTreCounters(this, "startcal", successful, total, late);
    }

    public void rollbackSuccessForDayOff(String dayOffVar) {
        long[] counters = services.getTreCounters(this, "startcal");
        long successful = counters[0];
        long total = counters[1];
        long late = counters[2];
        if ("true".equals(vars.get("wasSuccessfulFast"))) {
            successful = Math.max(0, successful - 1);
        }
        if ("true".equals(vars.get("wasAfter8pm"))) {
            late = Math.max(0, late - 1);
        }
        total = Math.max(0, total - 1);
        services.setTreCounters(this, "startcal", successful, total, late);
    }

    private void sendPool(List<String> pool) {
        if (!pool.isEmpty()) {
            services.sendSms(this, interpolate(pickRandom(pool)));
        }
    }

    private void handleLlmAnswer(String body) {
        if (pendingLlmQuestion == null || body == null) {
            return;
        }
        ProtocolDefinition.ActionDef action = pendingLlmQuestion;
        JSONObject userMessage = new JSONObject();
        userMessage.put("role", "user");
        userMessage.put("content", body);
        llmMessages.put(userMessage);

        JSONObject response = services.llmQuery(llmMessages);
        if (response == null || !response.has("content")) {
            llmRetries++;
            return;
        }
        String content = response.getString("content");
        if (action.validCategories.contains(content.toLowerCase())) {
            JSONObject answers = new JSONObject();
            for (Map.Entry<String, String> entry : action.staticAnswers.entrySet()) {
                answers.put(entry.getKey(), entry.getValue());
            }
            answers.put(action.answerKey, content);
            services.saveSurveyResponses(answers, surveyToken);
            surveyToken = null;
            llmMessages = new JSONArray();
            pendingLlmQuestion = null;
            llmRetries = 0;
        } else {
            llmRetries++;
            services.sendSms(this, content);
        }
    }

    private void handleDevicePacket() {
        if (def.deviceWindow == null) {
            return;
        }
        ProtocolDefinition.DeviceWindowSpec window = def.deviceWindow;
        if (!window.acceptStates.contains(currentState)) {
            logger.error("Received device packet in wrong state.");
            return;
        }
        if (!deviceStarted && !currentState.equals(window.readingState)) {
            deviceStarted = true;
            fireEvent(window.startEvent);
            deviceCount = 1;
            deviceGeneration = timerService.schedule(this, DEVICE_WINDOW_TIMER, window.quietSeconds);
        } else if (deviceStarted && currentState.equals(window.readingState)) {
            deviceCount++;
            deviceGeneration = timerService.schedule(this, DEVICE_WINDOW_TIMER, window.quietSeconds);
        } else {
            logger.error("Received device packet in wrong state.");
        }
    }

    private void handleDeviceQuiet() {
        ProtocolDefinition.DeviceWindowSpec window = def.deviceWindow;
        if (window == null || window.result == null) {
            return;
        }
        int remainder = window.result.mod > 0 ? deviceCount % window.result.mod : deviceCount;
        String result = window.result.map.getOrDefault(String.valueOf(remainder), window.result.defaultValue);
        services.saveDeviceResult(this, window.readingState, result);
        fireEvent(window.endEvent);
    }

    private void logState(String state) {
        Map<String, String> logMap = new LinkedHashMap<>();
        logMap.put("state", state);
        if (isRestoring) {
            logMap.put("restored", "true");
        }
        if (isReset) {
            logMap.put("RESET", "true");
        }
        logMap.put("protocol", def.name);
        logMap.put("protocol_version", String.valueOf(protocolVersion));
        logMap.put("definition_id", definitionUuid);
        services.insertStateLog(this, gson.toJson(logMap));
    }

    public String saveStateJson() {
        JsonObject timers = new JsonObject();
        timers.addProperty("state", currentState);
        timers.addProperty("startTime", startTimestamp);
        timers.addProperty("currentTime", tzHelper.nowEpochSeconds());
        for (String timerName : def.timers) {
            timers.addProperty(timerName, timerDeadlines.getOrDefault(timerName, 0L));
        }

        JsonObject context = new JsonObject();
        if (surveyToken != null) {
            context.addProperty("surveyToken", surveyToken);
        }
        context.addProperty("llmRetries", llmRetries);
        context.addProperty("deviceStarted", deviceStarted);
        context.addProperty("deviceCount", deviceCount);
        JsonObject varsJson = new JsonObject();
        for (Map.Entry<String, String> entry : vars.entrySet()) {
            varsJson.addProperty(entry.getKey(), entry.getValue());
        }
        context.add("vars", varsJson);
        JsonObject fieldsJson = new JsonObject();
        for (Map.Entry<String, Long> entry : eventFields.entrySet()) {
            fieldsJson.addProperty(entry.getKey(), entry.getValue());
        }
        context.add("fields", fieldsJson);

        JsonObject root = new JsonObject();
        JsonObject historyJson = new JsonObject();
        for (Map.Entry<String, Long> entry : history.entrySet()) {
            historyJson.addProperty(entry.getKey(), entry.getValue());
        }
        root.add("history", historyJson);
        root.add("timers", timers);
        root.add("context", context);
        return root.toString();
    }

    public String interpolate(String template) {
        if (template == null) {
            return "";
        }
        String result = template;
        result = result.replace("{first_name}", participantMap.getOrDefault("first_name", ""));
        result = result.replace("{last_name}", participantMap.getOrDefault("last_name", ""));
        result = result.replace("{number}", participantMap.getOrDefault("number", ""));
        result = result.replace("{study}", participantMap.getOrDefault("study_name", ""));
        if (result.contains("{survey_link}")) {
            String link = services.surveyUrl() + (surveyToken == null ? "" : surveyToken) + "/" + getParticipantUuid();
            result = result.replace("{survey_link}", link);
        }
        if (result.contains("{admin_phone}")) {
            result = result.replace("{admin_phone}", services.adminPhone(this));
        }
        if (result.contains("{success_rate}")) {
            long[] counters = services.getTreCounters(this, "startcal");
            String rate = counters[1] == 0 ? "0.00%"
                    : String.format("%.2f%%", 100.0 * counters[0] / counters[1]);
            result = result.replace("{success_rate}", rate);
        }
        if (result.contains("{finish_by}")) {
            Long start = eventFields.get("start_cal_time");
            result = result.replace("{finish_by}",
                    start == null ? "" : tzHelper.formatEpochShortTime(start + 36000));
        }
        if (result.contains("{eating_window}")) {
            Long start = eventFields.get("start_cal_time");
            Long end = eventFields.get("end_cal_time");
            result = result.replace("{eating_window}",
                    start == null || end == null ? "" : tzHelper.formatHoursMinutes(end - start));
        }
        return result;
    }
}
