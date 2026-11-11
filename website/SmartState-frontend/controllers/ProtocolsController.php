<?php

require_once MODELS_DIR . 'ProtocolDefinition.php';
require_once __DIR__ . '/../utilities/UmpleParser.php';

class ProtocolsController {

    public static function index(User $user): void {
        require PROTOCOLS_VIEWS_DIR . 'index.php';
    }

    public static function listDT(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $data = [];
        foreach (ProtocolDefinition::listForDatatable($studyUuid) as $row) {
            $row['latest_published_version'] = is_null($row['latest_published_version']) ? null : intval($row['latest_published_version']);
            $row['version_count'] = intval($row['version_count']);
            $row['active_enrollments'] = intval($row['active_enrollments']);
            $row['has_draft'] = ($row['has_draft'] === true || $row['has_draft'] === 't' || $row['has_draft'] === 1);
            $data[] = $row;
        }
        echo json_encode(['data' => $data]);
    }

    public static function listPublished(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        echo json_encode(['success' => true, 'protocols' => ProtocolDefinition::listPublished($studyUuid)]);
    }

    public static function create(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $success = false;
        $error_message = null;
        $definitionUuid = null;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error_message = 'Please enter a name for the protocol.';
        } else {
            try {
                $protocol = ProtocolDefinition::createDraft($name, $studyUuid, $user->getId());
                $success = !is_null($protocol);
                if ($success)
                    $definitionUuid = $protocol->getDefinitionUuid();
                else
                    $error_message = 'Protocol could not be created.';
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'definition_uuid' => $definitionUuid);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function edit(User $user): void {
        require PROTOCOLS_VIEWS_DIR . 'edit.php';
    }

    public static function get(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $definitionUuid = $_GET['definition'] ?? '';
        $protocol = $definitionUuid === '' ? null : ProtocolDefinition::withID($definitionUuid, $studyUuid);
        if (is_null($protocol)) {
            echo json_encode(['success' => false, 'error_message' => 'No protocol definition found.']);
            return;
        }
        echo json_encode(['success' => true, 'protocol' => $protocol]);
    }

    public static function saveDraft(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $success = false;
        $error_message = null;
        $definitionUuid = $_POST['definition_uuid'] ?? '';
        $definition = json_decode($_POST['definition'] ?? '', true);
        if ($definitionUuid === '') {
            $error_message = 'No protocol definition provided.';
        } else if (!is_array($definition)) {
            $error_message = 'The definition is not valid JSON.';
        } else {
            try {
                ProtocolDefinition::saveDraft($definitionUuid, $studyUuid, $definition);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function validate(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $definition = json_decode($_POST['definition'] ?? '', true);
        if (!is_array($definition)) {
            echo json_encode(['ok' => false, 'errors' => ['The definition is not valid JSON.']]);
            return;
        }
        echo json_encode(self::engineValidate($definition));
    }

    public static function publish(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $definitionUuid = $_POST['definition_uuid'] ?? '';
        $protocol = $definitionUuid === '' ? null : ProtocolDefinition::withID($definitionUuid, $studyUuid);
        if (is_null($protocol)) {
            echo json_encode(['success' => false, 'error_message' => 'No protocol definition found.']);
            return;
        }
        if ($protocol->getStatus() !== 'draft') {
            echo json_encode(['success' => false, 'error_message' => 'Only drafts can be published.']);
            return;
        }
        $validation = self::engineValidate($protocol->getDefinition());
        if (!$validation['ok']) {
            echo json_encode(['success' => false, 'error_message' => 'The definition failed validation.', 'errors' => $validation['errors']]);
            return;
        }
        try {
            $protocol = ProtocolDefinition::publish($definitionUuid, $studyUuid);
            echo json_encode(['success' => true, 'protocol' => $protocol]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error_message' => $e->getMessage()]);
        }
    }

    public static function cloneProtocol(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $success = false;
        $error_message = null;
        $newDefinitionUuid = null;
        $definitionUuid = $_POST['definition_uuid'] ?? '';
        if ($definitionUuid === '') {
            $error_message = 'No protocol definition provided.';
        } else {
            try {
                $draft = ProtocolDefinition::cloneToDraft($definitionUuid, $studyUuid, $user->getId());
                $success = !is_null($draft);
                if ($success)
                    $newDefinitionUuid = $draft->getDefinitionUuid();
                else
                    $error_message = 'Draft could not be created.';
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'definition_uuid' => $newDefinitionUuid);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function delete(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $success = false;
        $error_message = null;
        $deleted = null;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error_message = 'No protocol name provided.';
        } else {
            try {
                $deleted = ProtocolDefinition::deleteByName($name, $studyUuid);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'deleted' => $deleted);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function import(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['success' => false, 'error_message' => 'Please enter a name for the protocol.']);
            return;
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['success' => false, 'error_message' => 'No file was uploaded.']);
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error_message' => 'The file upload failed (code ' . $file['error'] . ').']);
            return;
        }
        if ($file['size'] > 1048576) {
            echo json_encode(['success' => false, 'error_message' => 'The file is too large — state machine files should be under 1 MB.']);
            return;
        }
        $content = is_uploaded_file($file['tmp_name']) ? file_get_contents($file['tmp_name']) : false;
        if ($content === false) {
            echo json_encode(['success' => false, 'error_message' => 'The uploaded file could not be read.']);
            return;
        }
        if (strpos($content, "\0") !== false) {
            echo json_encode(['success' => false, 'error_message' => 'The file does not look like plain text. Upload the Umple source as a text file (any extension).']);
            return;
        }
        if (!mb_check_encoding($content, 'UTF-8'))
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        $parsed = UmpleParser::parse($content);
        if (count($parsed['errors']) > 0 || is_null($parsed['definition'])) {
            echo json_encode([
                'success'       => false,
                'error_message' => 'The file could not be imported as an Umple state machine.',
                'errors'        => $parsed['errors']
            ]);
            return;
        }
        try {
            $protocol = ProtocolDefinition::createDraft($name, $studyUuid, $user->getId());
            $protocol = ProtocolDefinition::saveDraft($protocol->getDefinitionUuid(), $studyUuid, $parsed['definition']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error_message' => $e->getMessage()]);
            return;
        }
        $warnings = $parsed['warnings'];
        $validation = self::engineValidate($protocol->getDefinition());
        if (!$validation['ok'])
            foreach ($validation['errors'] as $error)
                $warnings[] = 'Engine validation: ' . $error;
        echo json_encode([
            'success'         => true,
            'definition_uuid' => $protocol->getDefinitionUuid(),
            'warnings'        => $warnings
        ]);
    }

    public static function versions(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $name = $_GET['name'] ?? '';
        if ($name === '') {
            echo json_encode(['success' => false, 'error_message' => 'No protocol name provided.']);
            return;
        }
        echo json_encode(['success' => true, 'versions' => ProtocolDefinition::versionsForName($name, $studyUuid)]);
    }

    public static function view(User $user): void {
        $studyUuid = $user->getCurrentStudy();
        $definitionUuid = $_GET['definition'] ?? '';
        $protocol = null;
        if (!is_null($studyUuid) && $definitionUuid !== '')
            $protocol = ProtocolDefinition::withID($definitionUuid, $studyUuid);
        require PROTOCOLS_VIEWS_DIR . 'view.php';
    }

    public static function migrate(User $user): void {
        require PROTOCOLS_VIEWS_DIR . 'migrate.php';
    }

    public static function migrateList(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $name = $_GET['name'] ?? '';
        if ($name === '') {
            echo json_encode(['success' => false, 'error_message' => 'No protocol name provided.']);
            return;
        }
        echo json_encode(['success' => true, 'enrollments' => ProtocolDefinition::activeEnrollmentsForName($name, $studyUuid)]);
    }

    public static function migrateEnrollments(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $enrollmentUuids = $_POST['enrollment_uuids'] ?? [];
        $targetDefinitionUuid = $_POST['target_definition_uuid'] ?? '';
        if (!is_array($enrollmentUuids) || count($enrollmentUuids) === 0) {
            echo json_encode(['success' => false, 'error_message' => 'No enrollments selected.']);
            return;
        }
        if ($targetDefinitionUuid === '') {
            echo json_encode(['success' => false, 'error_message' => 'No target version selected.']);
            return;
        }
        $migrated = 0;
        $errors = [];
        foreach ($enrollmentUuids as $enrollmentUuid) {
            try {
                if (ProtocolDefinition::migrateEnrollment($enrollmentUuid, $targetDefinitionUuid, $studyUuid))
                    $migrated++;
                else
                    $errors[] = "Enrollment [$enrollmentUuid] is not active.";
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        $ret = ['success' => count($errors) === 0, 'migrated' => $migrated];
        if (count($errors) > 0)
            $ret['error_message'] = implode(' ', $errors);
        echo json_encode($ret);
    }

    public static function definition(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = self::requireStudy($user);
        if (is_null($studyUuid))
            return;
        $enrollmentUuid = $_GET['enrollment'] ?? '';
        $protocol = $enrollmentUuid === '' ? null : ProtocolDefinition::getByEnrollment($enrollmentUuid, $studyUuid);
        if (is_null($protocol)) {
            echo json_encode(['success' => false, 'error_message' => 'No definition found for that enrollment.']);
            return;
        }
        echo json_encode([
            'success'         => true,
            'definition'      => $protocol->getDefinition(),
            'current_version' => $protocol->getVersion(),
            'protocol_name'   => $protocol->getName()
        ]);
    }

    private static function engineValidate(array $definition): array {
        global $CONFIG;
        $result = RootController::makeAPIcallPOST($CONFIG['java_api_url'], '/api/definitions/validate', $definition);
        if (!$result['success'])
            return ['ok' => false, 'errors' => [$result['error_message'] ?? 'Could not reach the validation service.']];
        $response = $result['response'];
        if (!is_array($response))
            return ['ok' => false, 'errors' => ['The validation service returned an invalid response.']];
        $errors = $response['errors'] ?? [];
        if (!is_array($errors))
            $errors = [$errors];
        return ['ok' => (bool) ($response['ok'] ?? false), 'errors' => $errors];
    }

    private static function requireStudy(User $user): ?string {
        $studyUuid = $user->getCurrentStudy();
        if (is_null($studyUuid)) {
            echo json_encode(['success' => false, 'error_message' => 'No study selected.']);
            return null;
        }
        return $studyUuid;
    }
}
