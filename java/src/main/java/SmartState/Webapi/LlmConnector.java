package SmartState.Webapi;

import SmartState.Launcher;
import org.json.JSONArray;
import org.json.JSONObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;

public class LlmConnector {
    private static final Logger logger = LoggerFactory.getLogger(LlmConnector.class);
    private final String apiUrl;
    private final String apiKey;
    private final String model;
    private final HttpClient client;

    public LlmConnector() {
        this.apiUrl = Launcher.config.getStringParam("llm_api_url");
        this.apiKey = Launcher.config.getStringParam("llm_api_key");
        this.model = Launcher.config.getStringParam("llm_model", "");
        this.client = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(15)).build();
    }

    public JSONObject query(JSONArray messages) {
        try {
            JSONObject json = new JSONObject();
            json.put("model", model);
            json.put("messages", messages);
            json.put("max_tokens", 500);
            json.put("temperature", 0.5);

            HttpRequest request = HttpRequest.newBuilder()
                    .uri(URI.create(apiUrl))
                    .timeout(Duration.ofSeconds(60))
                    .header("Content-Type", "application/json")
                    .header("Authorization", "Bearer " + apiKey)
                    .POST(HttpRequest.BodyPublishers.ofString(json.toString()))
                    .build();

            HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());

            if (response.statusCode() != 200) {
                logger.error("LLM request failed with HTTP " + response.statusCode());
                return new JSONObject().put("error", response.body());
            }

            JSONObject jsonResponse = new JSONObject(response.body());
            return jsonResponse.getJSONArray("choices").getJSONObject(0).getJSONObject("message");

        } catch (Exception e) {
            logger.error("LLM request failed", e);
            return new JSONObject().put("error", "Unable to complete request due to an exception.");
        }
    }
}
