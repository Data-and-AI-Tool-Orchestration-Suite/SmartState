package SmartState.Webapi;

import SmartState.Engine.MachineInstance;
import SmartState.Engine.ProtocolDefinition;
import SmartState.Engine.ProtocolDefinitionParser;
import SmartState.Engine.Simulation;
import SmartState.Launcher;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.google.gson.Gson;
import com.google.gson.JsonArray;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.twilio.security.RequestValidator;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import jakarta.inject.Inject;
import jakarta.inject.Provider;
import jakarta.ws.rs.Consumes;
import jakarta.ws.rs.GET;
import jakarta.ws.rs.POST;
import jakarta.ws.rs.Path;
import jakarta.ws.rs.PathParam;
import jakarta.ws.rs.Produces;
import jakarta.ws.rs.core.MediaType;
import jakarta.ws.rs.core.MultivaluedMap;
import jakarta.ws.rs.core.Response;
import java.io.PrintWriter;
import java.io.StringWriter;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;

@Path("/api")
public class API {

    @Inject
    private Provider<org.glassfish.grizzly.http.server.Request> request;
    private final Gson gson;
    private final Logger logger = LoggerFactory.getLogger(API.class);

    public API() {
        gson = new Gson();
    }

    @POST
    @Path("/incoming")
    @Consumes({MediaType.APPLICATION_FORM_URLENCODED})
    @Produces(MediaType.APPLICATION_JSON)
    public Response incomingText(MultivaluedMap<String, String> formParams) {
        String responseString;
        try {
            if (!validTwilioSignature(formParams, "/api/incoming")) {
                return Response.status(403).entity("{\"status\":\"error\",\"status_desc\":\"invalid signature\"}").build();
            }

            List<Map<String, String>> participants = Launcher.dbEngine.getParticipantsByPhone(first(formParams, "From"));

            if (!participants.isEmpty()) {
                Map<String, String> formsMap = convertMultiToRegularMap(formParams);
                String json_string = gson.toJson(formsMap);

                for (Map<String, String> participant : participants) {
                    Launcher.dbEngine.insertIncomingMessage(participant.get("participant_uuid"), participant.get("study_uuid"), json_string);

                    List<MachineInstance> machines = Launcher.machineRegistry.byParticipant(participant.get("participant_uuid"));
                    if (machines.isEmpty()) {
                        logger.error("Text from participant with no active protocols");
                        continue;
                    }
                    String body = formsMap.get("Body");
                    MachineInstance helpHandler = null;
                    for (MachineInstance machine : machines) {
                        if (machine.matchesHelp(body)) {
                            helpHandler = machine;
                            break;
                        }
                    }
                    if (helpHandler != null) {
                        helpHandler.submitHelp();
                        continue;
                    }
                    boolean ignored = false;
                    for (MachineInstance machine : machines) {
                        if (machine.smsIgnores(body)) {
                            ignored = true;
                            break;
                        }
                    }
                    if (ignored) {
                        continue;
                    }
                    List<MachineInstance> handlers = new java.util.ArrayList<>();
                    for (MachineInstance machine : machines) {
                        if (machine.smsHandles(body)) {
                            handlers.add(machine);
                        }
                    }
                    if (!handlers.isEmpty()) {
                        for (MachineInstance machine : handlers) {
                            machine.submitSms(formsMap);
                        }
                    } else {
                        for (MachineInstance machine : machines) {
                            if (machine.hasSmsFallback()) {
                                machine.submitSms(formsMap);
                                break;
                            }
                        }
                    }
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "incomingText");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @POST
    @Path("/message")
    @Consumes({MediaType.APPLICATION_FORM_URLENCODED})
    @Produces(MediaType.APPLICATION_JSON)
    public Response incomingMessage(MultivaluedMap<String, String> formParams) {
        String responseString;
        try {
            List<Map<String, String>> participants = Launcher.dbEngine.getParticipantsByPhone(first(formParams, "From"));

            if (!participants.isEmpty()) {
                Map<String, String> formsMap = convertMultiToRegularMap(formParams);
                String json_string = gson.toJson(formsMap);

                for (Map<String, String> participant : participants) {
                    Launcher.dbEngine.insertIncomingMessage(participant.get("participant_uuid"), participant.get("study_uuid"), json_string);

                    List<MachineInstance> machines = Launcher.machineRegistry.byParticipant(participant.get("participant_uuid"));
                    if (machines.isEmpty()) {
                        logger.error("Device message from participant with no active protocols");
                        continue;
                    }
                    for (MachineInstance machine : machines) {
                        machine.submitCommandMessage(formsMap);
                    }
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "incomingMessage");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @GET
    @Path("/check")
    @Produces(MediaType.APPLICATION_JSON)
    public Response getAccessLog() {
        String responseString;
        try {
            String remoteIP = request.get().getRemoteAddr();
            long access_ts = System.currentTimeMillis();
            logger.info("IP: " + remoteIP + " Timestamp: " + access_ts);

            Map<String, String> responseMap = new HashMap<>();
            responseMap.put("ip", remoteIP);
            responseMap.put("timestamp", String.valueOf(access_ts));
            responseString = gson.toJson(responseMap);
        } catch (Exception ex) {
            return serverError(ex, "check");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @GET
    @Path("/health")
    @Produces(MediaType.APPLICATION_JSON)
    public Response health() {
        Map<String, String> responseMap = new HashMap<>();
        boolean dbUp = Launcher.dbEngine.isHealthy();
        responseMap.put("status", dbUp ? "ok" : "degraded");
        responseMap.put("db", dbUp ? "up" : "down");
        responseMap.put("machines", String.valueOf(Launcher.machineRegistry.size()));
        return Response.status(dbUp ? 200 : 503).entity(gson.toJson(responseMap)).build();
    }

    @GET
    @Path("/get-valid-next-states/{participant_uuid}/{participant_study}/{participant_protocol}")
    @Produces(MediaType.APPLICATION_JSON)
    public Response getNextStates(@PathParam("participant_uuid") String participantId,
                                  @PathParam("participant_study") String participantStudy,
                                  @PathParam("participant_protocol") String participantProtocol) {
        String responseString;
        try {
            if (!participantId.equals("")) {
                String validNextStates = "";
                MachineInstance machine = Launcher.machineRegistry.byParticipantAndProtocol(participantId, participantProtocol);
                if (machine != null) {
                    validNextStates = machine.validNextStates();
                } else {
                    Map<String, String> enrollment = Launcher.dbEngine.getActiveEnrollment(participantId, participantProtocol);
                    if (enrollment != null) {
                        ProtocolDefinition def = Launcher.protocolRegistry.get(enrollment.get("definition_uuid"));
                        String currentState = Launcher.dbEngine.getParticipantCurrentState(participantId, participantProtocol);
                        if (def != null && def.state(currentState) != null) {
                            StringBuilder sb = new StringBuilder();
                            for (ProtocolDefinition.ManualMove move : def.state(currentState).manualMoves) {
                                if (sb.length() > 0) {
                                    sb.append(",");
                                }
                                sb.append(move.to);
                            }
                            validNextStates = sb.toString();
                        }
                    }
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                response.put("valid_states", validNextStates);
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "getNextStates");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @POST
    @Consumes(MediaType.APPLICATION_FORM_URLENCODED)
    @Path("/next-state")
    @Produces(MediaType.APPLICATION_JSON)
    public Response moveToNextState(MultivaluedMap<String, String> data) {
        String responseString;
        try {
            String participantId = first(data, "participantUUID");
            String nextState = first(data, "toState");
            String protocol = first(data, "protocol");
            String time = first(data, "time");

            if (participantId != null) {
                MachineInstance machine = Launcher.machineRegistry.byParticipantAndProtocol(participantId, protocol);
                String newState = "";
                if (machine != null) {
                    newState = machine.moveTo(nextState, time).get(30, TimeUnit.SECONDS);
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                response.put("moved_to_state", newState);
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "moveToNextState");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @POST
    @Path("/reset-machine")
    @Consumes(MediaType.APPLICATION_FORM_URLENCODED)
    @Produces(MediaType.APPLICATION_JSON)
    public Response resetStateMachine(MultivaluedMap<String, String> data) {
        String responseString;
        try {
            String participantId = first(data, "uuid");
            String protocol = first(data, "protocol");
            if (participantId != null) {
                String enrollmentUuid = Launcher.enrollmentWatcher.resetMachine(participantId, protocol);
                if (enrollmentUuid == null) {
                    logger.error("Cannot reset machine, participant not in an active protocol.");
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "resetStateMachine");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @POST
    @Path("/update-timezone")
    @Consumes(MediaType.APPLICATION_FORM_URLENCODED)
    @Produces(MediaType.APPLICATION_JSON)
    public Response updateTimeZone(MultivaluedMap<String, String> data) {
        String responseString;
        try {
            String participantId = first(data, "uuid");
            String tz = data.getFirst("tz");
            String protocolsJson = data.getFirst("protocols");
            ObjectMapper objectMapper = new ObjectMapper();
            List<String> protocols = objectMapper.readValue(protocolsJson, List.class);
            if (participantId != null) {
                for (String protocol : protocols) {
                    MachineInstance machine = Launcher.machineRegistry.byParticipantAndProtocol(participantId, protocol);
                    if (machine != null) {
                        machine.updateTimezone(tz);
                    }
                }

                Map<String, String> response = new HashMap<>();
                response.put("status", "ok");
                responseString = gson.toJson(response);
            } else {
                Map<String, String> response = new HashMap<>();
                response.put("status", "error");
                response.put("status_desc", "participant not found");
                responseString = gson.toJson(response);
            }
        } catch (Exception ex) {
            return serverError(ex, "updateTimeZone");
        }
        return Response.ok(responseString).header("Access-Control-Allow-Origin", "*").build();
    }

    @POST
    @Path("/definitions/validate")
    @Consumes(MediaType.APPLICATION_JSON)
    @Produces(MediaType.APPLICATION_JSON)
    public Response validateDefinition(String body) {
        try {
            ProtocolDefinitionParser parser = new ProtocolDefinitionParser();
            ProtocolDefinitionParser.ParseResult result = parser.parse(body);

            JsonObject response = new JsonObject();
            response.addProperty("ok", result.validation.isOk());
            JsonArray errors = new JsonArray();
            for (String error : result.validation.getErrors()) {
                errors.add(error);
            }
            response.add("errors", errors);
            return Response.ok(response.toString()).build();
        } catch (Exception ex) {
            return serverError(ex, "validateDefinition");
        }
    }

    @POST
    @Path("/simulate")
    @Consumes(MediaType.APPLICATION_JSON)
    @Produces(MediaType.APPLICATION_JSON)
    public Response simulate(String body) {
        try {
            JsonObject root = JsonParser.parseString(body).getAsJsonObject();
            ProtocolDefinitionParser parser = new ProtocolDefinitionParser();
            ProtocolDefinitionParser.ParseResult result = parser.parse(root.getAsJsonObject("definition"));
            if (!result.validation.isOk()) {
                JsonObject response = new JsonObject();
                response.addProperty("ok", false);
                JsonArray errors = new JsonArray();
                for (String error : result.validation.getErrors()) {
                    errors.add(error);
                }
                response.add("errors", errors);
                return Response.ok(response.toString()).build();
            }

            String timezone = root.has("timezone") ? root.get("timezone").getAsString() : "America/New_York";
            String startLocalTime = root.has("startTime") ? root.get("startTime").getAsString() : "2026-01-05T08:00:00";
            JsonArray script = root.has("script") ? root.getAsJsonArray("script") : new JsonArray();

            JsonObject simulation = Simulation.run(result.definition, timezone, startLocalTime, script);
            simulation.addProperty("ok", true);
            return Response.ok(simulation.toString()).build();
        } catch (Exception ex) {
            return serverError(ex, "simulate");
        }
    }

    @GET
    @Path("/machines/{study}")
    @Produces(MediaType.APPLICATION_JSON)
    public Response listMachines(@PathParam("study") String study) {
        try {
            JsonArray machines = new JsonArray();
            for (MachineInstance machine : Launcher.machineRegistry.byStudyName(study)) {
                JsonObject o = new JsonObject();
                o.addProperty("enrollment_uuid", machine.getEnrollmentUuid());
                o.addProperty("participant_uuid", machine.getParticipantUuid());
                o.addProperty("protocol", machine.getProtocolName());
                o.addProperty("version", machine.getProtocolVersion());
                o.addProperty("state", machine.getCurrentState());
                machines.add(o);
            }
            JsonObject response = new JsonObject();
            response.addProperty("status", "ok");
            response.add("machines", machines);
            return Response.ok(response.toString()).build();
        } catch (Exception ex) {
            return serverError(ex, "listMachines");
        }
    }

    private boolean validTwilioSignature(MultivaluedMap<String, String> formParams, String path) {
        Boolean validate = Launcher.config.getBooleanParam("twilio_validate_webhook", true);
        Boolean messagingDisabled = Launcher.config.getBooleanParam("disable_messaging", false);
        if (!validate || messagingDisabled) {
            return true;
        }
        try {
            String signature = request.get().getHeader("X-Twilio-Signature");
            if (signature == null) {
                return false;
            }
            String url = Launcher.config.getStringParam("public_base_url", "") + path;
            Map<String, String> params = convertMultiToRegularMap(formParams);
            RequestValidator validator = new RequestValidator(Launcher.config.getStringParam("twilio_auth_token", ""));
            return validator.validate(url, params, signature);
        } catch (Exception ex) {
            logger.error("Twilio signature validation failed", ex);
            return false;
        }
    }

    private Response serverError(Exception ex, String context) {
        StringWriter sw = new StringWriter();
        ex.printStackTrace(new PrintWriter(sw));
        String exceptionAsString = sw.toString();
        ex.printStackTrace();
        logger.error(context);
        logger.error(exceptionAsString);
        return Response.status(500).entity(exceptionAsString).build();
    }

    private String first(MultivaluedMap<String, String> map, String key) {
        List<String> values = map.get(key);
        return values == null || values.isEmpty() ? null : values.get(0);
    }

    private Map<String, String> convertMultiToRegularMap(MultivaluedMap<String, String> m) {
        Map<String, String> map = new HashMap<>();
        if (m == null) {
            return map;
        }
        for (Map.Entry<String, List<String>> entry : m.entrySet()) {
            StringBuilder sb = new StringBuilder();
            for (String s : entry.getValue()) {
                if (sb.length() > 0) {
                    sb.append(',');
                }
                sb.append(s);
            }
            map.put(entry.getKey(), sb.toString());
        }
        return map;
    }
}
