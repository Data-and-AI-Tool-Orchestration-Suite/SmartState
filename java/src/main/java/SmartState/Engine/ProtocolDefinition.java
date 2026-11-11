package SmartState.Engine;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

public class ProtocolDefinition {

    public enum TransitionKind { EVENT, AFTER, AUTO }

    public enum ActionType { SEND_SMS, NOTIFY_ADMINS, SET_TIMER, GENERATE_SURVEY_TOKEN, SEND_SURVEY_LINK, LLM_QUESTION, SEND_RESULT_SMS, RESET_DEVICE_WINDOW, SET_VAR, CLEAR_VARS, INCREMENT_COUNTER, CONDITIONAL_SMS, TRE_EVALUATE_ENDCAL, TRE_DAY_OFF_ROLLBACK }

    public enum DeadlineType { FIXED_SECONDS, SECONDS_TO_CLOCK, SECONDS_TO_NEXT_MINUTE_MARK, SECONDS_TO_HOUR_BOUNDARY_PLUS, SECONDS_TO_WEEKDAY, CONDITIONAL }

    public enum PredicateType { BEFORE_CLOCK, AFTER_CLOCK, TIMER_WITHIN_MINUTE_WINDOW, VAR_EQUALS, COUNTER_AT_LEAST, FIELD_AFTER_CLOCK, FIELD_BETWEEN_CLOCK, FIELD_WAS_YESTERDAY, NOT, ALWAYS }

    public static class Predicate {
        public PredicateType type;
        public int hour;
        public int minute;
        public String timer;
        public int fromMinute;
        public int toMinute;
        public String key;
        public String value;
        public int count;
        public String field;
        public int fromHour;
        public int toHour;
        public int boundaryHour;
        public int boundaryMinute;
        public Predicate inner;
    }

    public static class DeadlineExpr {
        public DeadlineType type;
        public long seconds;
        public int hour;
        public int minute;
        public int second;
        public boolean ifPastAddDay;
        public Predicate addDayIfPredicate;
        public Long minSeconds;
        public int mark;
        public int hours;
        public int dayOfWeek;
        public Predicate ifPredicate;
        public DeadlineExpr thenExpr;
        public DeadlineExpr elseExpr;
    }

    public static class DeliverAt {
        public int hour;
        public int minute;
        public String timezone = "participant";
    }

    public static class TreEvaluation {
        public String startField = "start_cal_time";
        public String endField = "end_cal_time";
        public int minSeconds = 32400;
        public int maxSeconds = 39600;
        public int lateHour = 20;
        public int lateMinute = 5;
        public int trialPeriodCycles = 7;
        public String cycleCounter = "cycles";
        public String repeatCounter = "endcalRepeats";
        public String dayOffVar = "isDayOff";
        public List<String> ackMessages = new ArrayList<>();
        public List<String> successMessages = new ArrayList<>();
        public List<String> shortTrialMessages = new ArrayList<>();
        public List<String> shortMessages = new ArrayList<>();
        public List<String> longTrialMessages = new ArrayList<>();
        public List<String> longMessages = new ArrayList<>();
        public List<String> lateMessages = new ArrayList<>();
        public List<String> neutralMessages = new ArrayList<>();
    }

    public static class ActionDef {
        public ActionType type;
        public String template;
        public List<String> templates = new ArrayList<>();
        public boolean suppressOnRestore;
        public Predicate suppressIf;
        public DeliverAt deliverAt;
        public String timer;
        public DeadlineExpr deadline;
        public String surveyKey;
        public String question;
        public String systemPrompt;
        public List<String> validCategories = new ArrayList<>();
        public String answerKey;
        public Map<String, String> staticAnswers = new LinkedHashMap<>();
        public String linkTemplate;
        public String source;
        public Map<String, String> messages = new LinkedHashMap<>();
        public String key;
        public String value;
        public List<String> keys = new ArrayList<>();
        public Predicate condition;
        public List<String> elseTemplates = new ArrayList<>();
        public TreEvaluation treEvaluation;
    }

    public static class TransitionDef {
        public TransitionKind kind;
        public String trigger;
        public String to;
    }

    public static class ManualMove {
        public String to;
        public String via;
        public String timeField;
    }

    public static class StateDef {
        public String name;
        public boolean terminal;
        public List<ActionDef> entry = new ArrayList<>();
        public List<TransitionDef> transitions = new ArrayList<>();
        public List<ManualMove> manualMoves = new ArrayList<>();

        public TransitionDef eventTransition(String event) {
            for (TransitionDef t : transitions) {
                if (t.kind == TransitionKind.EVENT && t.trigger.equals(event)) {
                    return t;
                }
            }
            return null;
        }

        public TransitionDef afterTransition(String timer) {
            for (TransitionDef t : transitions) {
                if (t.kind == TransitionKind.AFTER && t.trigger.equals(timer)) {
                    return t;
                }
            }
            return null;
        }

        public TransitionDef autoTransition() {
            for (TransitionDef t : transitions) {
                if (t.kind == TransitionKind.AUTO) {
                    return t;
                }
            }
            return null;
        }
    }

    public static class HelpSpec {
        public List<String> keywords = new ArrayList<>();
        public String adminTemplate;
        public String participantReply;
    }

    public static class ResultRule {
        public String of;
        public int mod;
        public Map<String, String> map = new LinkedHashMap<>();
        public String defaultValue;
    }

    public static class DeviceWindowSpec {
        public int quietSeconds;
        public List<String> acceptStates = new ArrayList<>();
        public String startEvent;
        public String endEvent;
        public ResultRule result;
        public String readingState;
    }

    public static class SmsRule {
        public List<String> keywords = new ArrayList<>();
        public List<String> inStates = new ArrayList<>();
        public Predicate condition;
        public boolean parseTime;
        public String saveAs;
        public int maxBareLength;
        public String fire;
        public List<String> replies = new ArrayList<>();
        public String parseErrorReply;
        public Predicate guard;
        public String guardReply;
        public String notifyAdminsTemplate;
        public String flagLastMessage;
    }

    public static class SmsMapping {
        public String defaultAction = "ignore";
        public List<String> inStates = new ArrayList<>();
        public List<String> ignoreKeywords = new ArrayList<>();
        public List<SmsRule> rules = new ArrayList<>();
        public String fallbackReply;
    }

    public static class CommandRule {
        public String equals;
        public List<String> inStates = new ArrayList<>();
        public String fire;
    }

    public static class MessageMapping {
        public String commandField;
        public List<CommandRule> commands = new ArrayList<>();
        public List<CommandRule> otherwise = new ArrayList<>();
    }

    public static class RecomputeRule {
        public Predicate predicate;
        public String fire;
    }

    public static class ReplaySpec {
        public List<String> restoring = new ArrayList<>();
        public List<String> after = new ArrayList<>();
    }

    public static class RestoreSpec {
        public String policy;
        public List<String> terminalStates = new ArrayList<>();
        public String fallbackState;
        public String noSaveStateEvent;
        public List<RecomputeRule> recomputeRules = new ArrayList<>();
        public Map<String, ReplaySpec> replay = new LinkedHashMap<>();
        public String resetFire;
        public int dayBoundaryHour;
        public int dayBoundaryMinute;
    }

    public int schemaVersion;
    public String name;
    public String initialState;
    public List<String> timers = new ArrayList<>();
    public String endProtocolEvent;
    public HelpSpec help;
    public String collection = "none";
    public DeviceWindowSpec deviceWindow;
    public RestoreSpec restore;
    public SmsMapping smsMapping;
    public MessageMapping messageMapping;
    public LinkedHashMap<String, StateDef> states = new LinkedHashMap<>();
    public String surveysJson;
    public Map<String, String> surveyQuestionsByKey = new LinkedHashMap<>();

    public StateDef state(String name) {
        return states.get(name);
    }
}
