package SmartState.Engine;

import java.util.ArrayList;
import java.util.List;

public class ValidationResult {
    private final List<String> errors = new ArrayList<>();

    public void addError(String error) {
        errors.add(error);
    }

    public boolean isOk() {
        return errors.isEmpty();
    }

    public List<String> getErrors() {
        return errors;
    }
}
