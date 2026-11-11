<?php

require_once __DIR__ . '/../utilities/CiLogonProvider.php';
require_once MODELS_DIR . 'User.php';
require_once MODELS_DIR . 'UserSession.php';
require_once MODELS_DIR . 'Study.php';

class RootController {
    public static function index(User $user): void {
        require VIEWS_DIR . 'home.php';
    }

    public static function no_access(?User $user): void {
        require VIEWS_DIR . 'no_access.php';
    }

    public static function login(): void {
        $provider = CiLogonProvider::getProvider();
        $authorizationUrl = $provider->getAuthorizationUrl();
        header('Location: ' . $authorizationUrl);
        die();
    }

    /**
     * @throws Exception
     */
    public static function callback(): void {
        $cilogonOAuth = CiLogonProvider::getProvider();
        global $rootURL;

        if (isset($_GET['error']) && $_GET['error'] == 'unauthorized_client') {
            // User denied access
            echo 'CiLogon client not authorized yet. Contact an administrator.';
            die();
        }

        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            // If code or state is missing, redirect to log in
            $authorizationUrl = $cilogonOAuth->getAuthorizationUrl();
            header('Location: ' . $authorizationUrl);
            die();
        }

        $state = $_GET['state'];

        // Validate state
        if (!$cilogonOAuth->validateState($state)) {
            //Invalid state parameter
            header('Location: ' . $rootURL . '/unauthorized');
            die();
        }

        try{
            $authorizationCode = $_GET['code'];
            $accessToken = $cilogonOAuth->getAccessToken($authorizationCode);
        } catch (Exception $e) {
            // Stale authorization code
            $authorizationUrl = $cilogonOAuth->getAuthorizationUrl();
            header('Location: ' . $authorizationUrl);
            die();
        }

        $info = $cilogonOAuth->getUserInfo();

        $user = User::withId($info['id']);
        if (is_null($user)) {
            $user = User::create($info);
        } else {
            $user = User::updateProfile($user->getId(), $info);
        }
        if (is_null($user)) {
            header('Location: '.$rootURL.'/no-access');
            die();
        }
        $session = UserSession::create(session_id(), $user, $accessToken);
        if (is_null($session)) {
            header('Location: '.$rootURL.'/no-access');
            die();
        }
        self::post_login_redirect();
    }

    public static function selectStudy(User $user): void {
        header('Content-Type: application/json');
        $studyUuid = $_POST['study_uuid'] ?? '';
        if ($studyUuid === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'error_message' => 'No study provided.']);
            return;
        }
        $allowed = false;
        if ($user->isSuperAdmin()) {
            $allowed = !is_null(Study::withUUID($studyUuid));
        } else {
            foreach ($user->getMemberships() as $membership) {
                if ($membership['study_uuid'] === $studyUuid) {
                    $allowed = true;
                    break;
                }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error_message' => 'You do not have access to that study.']);
            return;
        }
        $_SESSION['current_study'] = $studyUuid;
        $user->setCurrentStudy($studyUuid);
        if (isset($_SESSION['caai_session_id'])) {
            $session = UserSession::withSessionID($_SESSION['caai_session_id']);
            if (!is_null($session)) {
                $session->setCurrentStudy($studyUuid);
            }
        }
        echo json_encode(['status' => 'ok']);
    }

    public static function logout($redirect = null, $logoutWithProvider = true): void {
        UserSession::delete(session_id());
        session_destroy();
        session_start();
        session_regenerate_id();
        $_SESSION['redirect'] = $redirect;
        if ($logoutWithProvider) {
            $cilogon = new CiLogonProvider();
            header('Location: ' . $cilogon->getLogoutUrl());
            die();
        }
    }

    private static function post_login_redirect(): void {
        global $rootURL;
        if (!isset($_SESSION['redirect']))
            header('Location: '.$rootURL.'/');
        else {
            $redirect = $_SESSION['redirect'];
            $_SESSION['redirect'] = null;
            header('Location: ' .$redirect);
        }
        die();
    }

    public static function makeAPIcallGET(string $server, string $apiPath, array $customHeaders=[], int $timeout=60): array {
        $url = $server . $apiPath;

        // Initialize cURL session
        $ch = curl_init($url);

        // Set cURL options
        $headers = array_merge(['Content-Type: application/json'], $customHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // Execute cURL session and get the response
        $response = curl_exec($ch);
        if (curl_errno($ch) == CURLE_OPERATION_TIMEOUTED) {
            // Handle timeout error
            error_log("Curl error: Operation timed out");
            return ["success" => false, "error_message" => "Request timed out"];
        } elseif (curl_errno($ch)) {
            error_log("Curl error: " . curl_error($ch));
            return ["success" => false, "error_message" => "Failed to complete GET to $server"];
        }

        // Close the cURL session

        $decodedResponse = json_decode($response, true);
        return ["success" => true, "response" => $decodedResponse];
    }

    public static function makeAPIcallPOST(string $server, string $apiPath, array $messagePayload, array $customHeaders=[], $timeout=60): array {
        $url = $server . $apiPath;

        // Initialize cURL session
        $ch = curl_init($url);

        // Set cURL options
        $headers = array_merge(['Content-Type: application/json'], $customHeaders);
        curl_setopt($ch, CURLOPT_POST, 1);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messagePayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // Execute cURL session and get the response
        $response = curl_exec($ch);


        if (curl_errno($ch) == CURLE_OPERATION_TIMEOUTED) {
            // Handle timeout error
            error_log("Curl error: Operation timed out");
            return ["success" => false, "error_message" => "Request timed out"];
        } elseif (curl_errno($ch)) {
            error_log("Curl error: " . curl_error($ch));
            return ["success" => false, "error_message" => "Failed to complete POST to $server"];
        }

        // Close the cURL session

        // Decode and return the response
        $decodedResponse = json_decode($response, true);
        return ["success" => true, "response" => $decodedResponse];
    }

    public static function makeAPIcallPOSTWithFile(string $server, string $apiPath, array $messagePayload, array $customHeaders=[], $timeout=60, $filePath=null): array {
        $url = $server . $apiPath;

        // Initialize cURL session
        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Verbose mode to see headers
        $headers = array_merge(['Content-Type: multipart/form-data'], $customHeaders);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // If a file path is provided, include it in the POST fields
        if ($filePath) {
            if (file_exists($filePath)) {
                $messagePayload['file'] = new CURLFile($filePath);
            } else {
                return ["success" => false, "error_message" => "File not found: $filePath"];
            }
        }

        // Set the POST fields
        curl_setopt($ch, CURLOPT_POSTFIELDS, $messagePayload);

        // Capture response headers
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            return $len;
        });

        // Execute cURL session and get the response
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            $error_message = curl_error($ch);
            return ["success" => false, "error_message" => "Curl error: " . $error_message];
        }

        // Separate headers and body
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseBody = substr($response, $header_size);

        // Close the cURL session

        // Decode and return the response
        $decodedResponse = json_decode($responseBody, true);

        // Check if JSON decoding failed
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["success" => false, "error_message" => "Failed to decode JSON response"];
        }

        return ["success" => true, "response" => $decodedResponse];
    }
}