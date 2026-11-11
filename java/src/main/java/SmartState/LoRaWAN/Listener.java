package SmartState.LoRaWAN;

import SmartState.Launcher;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import org.fusesource.hawtbuf.Buffer;
import org.fusesource.hawtbuf.UTF8Buffer;
import org.fusesource.mqtt.client.*;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.URISyntaxException;
import java.util.Base64;
import java.util.HashMap;
import java.util.Map;

public class Listener {
    private final MQTT mqtt;
    private static Logger logger;

    public Listener(String user, String pass, String host, int port) throws URISyntaxException {
        logger = LoggerFactory.getLogger(Launcher.class);
        this.mqtt = new MQTT();
        this.mqtt.setHost(host, port);
        this.mqtt.setUserName(user);
        this.mqtt.setPassword(pass);
    }

    public void subscribeToTopic (String topic) {
        final CallbackConnection connection = this.mqtt.callbackConnection();
        connection.listener(new org.fusesource.mqtt.client.Listener() {

            public void onConnected() {
                logger.warn("Connected to "+topic+" Topic.");
            }
            public void onDisconnected() {
                logger.warn("Disconnected from "+topic+" Topic.");
            }
            public void onFailure(Throwable value) {
                value.printStackTrace();
            }
            public void onPublish(UTF8Buffer topic, Buffer msg, Runnable ack) {
                String body = msg.utf8().toString();
                JsonObject bodyJson = JsonParser.parseString(body).getAsJsonObject();
                String topicStr = topic.toString().split("\\.")[2];
                logger.info("Topic: "+topicStr+", Received: " + body);

                switch(topicStr){
                    case "join":
                        logger.warn("NEW JOIN FROM DEVICE:");
                        logger.warn("\tDevice Name: "+ bodyJson.get("deviceName"));
                        logger.warn("\tDevice EUI: "+ bodyJson.get("devEUI"));
                        logger.warn("\tTime Joined: "+ bodyJson.get("time"));
                        break;
                    case "up":
                        Map<String, String> decoded = decodePayload(bodyJson.get("data").getAsString());
                        logger.warn("NEW UPLINK FROM DEVICE:");
                        logger.warn("\tDevice Name: "+ bodyJson.get("deviceName"));
                        logger.warn("\tDevice EUI: "+ bodyJson.get("devEUI"));
                        logger.warn("\tApp Name: "+ bodyJson.get("applicationName"));
                        logger.warn("\tTime Received: "+ bodyJson.get("time"));
                        logger.warn("\tDecoded:");
                        logger.warn("\t\tBattery: " + decoded.get("Battery"));
                        logger.warn("\t\tTimesOpened: " + decoded.get("TimesOpened"));
                        logger.warn("\t\tOpenDuration: " + decoded.get("OpenDuration"));
                        logger.warn("\t\tMod: " + decoded.get("Mod"));
                        logger.warn("\t\tAlarm: " + decoded.get("Alarm"));

                        // get participant uuid
                        String participantUUID = Launcher.dbEngine.getParticipantIdFromDevice(bodyJson.get("devEUI").getAsString());
                        if (participantUUID.equals("")){
                            logger.error("Received packet from unknown/unregistered device.");
                        } else {
                            for (SmartState.Engine.MachineInstance machine : Launcher.machineRegistry.byParticipant(participantUUID)) {
                                machine.submitDevicePacket(decoded);
                            }
                        }
                        break;
                    default:
                        logger.info(topicStr + " not implemented");
                }
                ack.run();
            }
        });
        connection.connect(new Callback<Void>() {
            @Override
            public void onSuccess(Void value) {
                Topic[] topics = {new Topic(topic, QoS.AT_LEAST_ONCE)};
                connection.subscribe(topics, new Callback<byte[]>() {
                    public void onSuccess(byte[] bytes) {
                    }
                    public void onFailure(Throwable value) {
                        value.printStackTrace();
                    }
                });
            }
            @Override
            public void onFailure(Throwable value) {
                value.printStackTrace();
            }
        });
    }

    public static Map<String, String> decodePayload(String payload) {
        //example bytes: DAABAAAAAAAAAA==

        HashMap<String, String> decodedPayload = new HashMap<>();

        byte[] decoded = Base64.getDecoder().decode(payload);
        int value=(decoded[0]<<8 | decoded[1])&0x3FFF;
        double bat= (double) value/1000;//Battery,units:V

        int door_open_status= decoded[0] & 0x80;//1:open,0:close
        if (door_open_status > 0) {
            door_open_status = 1;
        }

        byte mod=decoded[2];
        int alarm=decoded[9]&0x01;

        if(mod==1){
            int open_times=decoded[3]<<16 | decoded[4]<<8 | decoded[5];
            int open_duration=decoded[6]<<16 | decoded[7]<<8 | decoded[8];//units:min
            if(decoded.length==10) { // && decoded[0] < 0x07 && decoded[0] < 0x0f
                decodedPayload.put ("Battery", String.valueOf(bat));
                decodedPayload.put ("isDoorOpen", String.valueOf(door_open_status));
                decodedPayload.put ("TimesOpened", String.valueOf(open_times));
                decodedPayload.put ("OpenDuration", open_duration + " min(s)");
                decodedPayload.put ("Mod", String.valueOf(mod));
                decodedPayload.put ("Alarm", String.valueOf(alarm));
            }
        }
        // mod can be 2 or 3 depending on which sensor is used (water, air, whatever)
        // we only have a door sensor so ignore these options
        else{
            decodedPayload.put ("Battery", String.valueOf(bat));
            decodedPayload.put ("Mod", String.valueOf(mod));
        }

        return decodedPayload;
    }
}
