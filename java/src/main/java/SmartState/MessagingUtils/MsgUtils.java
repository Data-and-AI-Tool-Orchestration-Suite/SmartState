package SmartState.MessagingUtils;

import SmartState.Launcher;
import com.google.gson.Gson;
import com.twilio.Twilio;
import com.twilio.rest.api.v2010.account.Message;
import com.twilio.type.PhoneNumber;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.text.SimpleDateFormat;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.util.Date;
import java.util.HashMap;
import java.util.Map;
import java.util.TimeZone;
import java.util.UUID;

public class MsgUtils {
    private final String textFrom;
    private final Logger logger;
    private final Gson gson;

    public MsgUtils() {
        logger = LoggerFactory.getLogger(MsgUtils.class);
        textFrom = Launcher.config.getStringParam("twilio_from_number");
        gson = new Gson();
        Twilio.init(Launcher.config.getStringParam("twilio_account_sid"), Launcher.config.getStringParam("twilio_auth_token"));
    }

    public void sendMessage(String textTo, String body, String participantUuid, String studyUuid) {
        try {
            Boolean isMessagingDisabled = Launcher.config.getBooleanParam("disable_messaging");

            if (isMessagingDisabled != null && isMessagingDisabled) {
                logger.warn("Messaging is disabled. Messages will be saved, but not sent.");
            } else {
                String fromNumber = resolveFromNumber(studyUuid);
                Message.creator(
                                new PhoneNumber(textTo),
                                new PhoneNumber(fromNumber),
                                body)
                        .create();
            }

            String messageId = UUID.randomUUID().toString();

            Date date = new Date();
            SimpleDateFormat format = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss.SSS");
            format.setTimeZone(TimeZone.getTimeZone("UTC"));
            String timestamp = format.format(date);

            Map<String, String> messageMap = new HashMap<>();
            String strippedBody = body.replaceAll("[\\n\\r\\t]", "");
            messageMap.put("Body", strippedBody);
            String json_string = gson.toJson(messageMap);
            logger.info(json_string);

            Launcher.dbEngine.insertOutgoingMessage(messageId, participantUuid, studyUuid, timestamp, json_string);
        } catch (Exception e) {
            logger.error("Exception occurred trying to send a message...");
            e.printStackTrace();
        }
    }

    public void sendScheduledMessage(String textTo, String body, ZonedDateTime dateTime, String participantUuid, String studyUuid) {
        try {
            String messageId = UUID.randomUUID().toString();

            ZonedDateTime dateTimeWithTimezone = dateTime.withZoneSameInstant(ZoneId.of("UTC"));
            String scheduledFor = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").format(dateTimeWithTimezone);

            Map<String, String> messageMap = new HashMap<>();
            String strippedBody = body.replaceAll("[\\n\\r\\t]", "");
            messageMap.put("Body", strippedBody);
            String json_string = gson.toJson(messageMap);
            logger.info("Message queued for: " + scheduledFor + ", Message: " + json_string);

            Launcher.dbEngine.insertQueuedMessage(messageId, participantUuid, studyUuid, textTo,
                    resolveFromNumber(studyUuid), scheduledFor, json_string);
        } catch (Exception e) {
            logger.error("Exception occurred trying to send scheduled message...");
            e.printStackTrace();
        }
    }

    private String resolveFromNumber(String studyUuid) {
        String fromNumber = studyUuid == null ? null : Launcher.dbEngine.getStudyFromNumber(studyUuid);
        if (fromNumber == null || fromNumber.isEmpty()) {
            fromNumber = textFrom;
        }
        return fromNumber;
    }
}
