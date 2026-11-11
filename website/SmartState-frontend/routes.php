<?php
    const CONFIG_FILE = __DIR__ . '/config.php';
    const MODELS_DIR = __DIR__ . '/models/';
    const UTILITIES_DIR = __DIR__ . '/utilities/';
    const VIEWS_DIR = __DIR__ . '/views/';
    const MESSAGES_VIEWS_DIR = __DIR__ . '/views/messages/';
    const PARTICIPANTS_VIEWS_DIR = __DIR__ . '/views/participants/';
    const PROTOCOLS_VIEWS_DIR = __DIR__ . '/views/protocols/';
    const STUDIES_VIEWS_DIR = __DIR__ . '/views/studies/';
    const SURVEY_VIEWS_DIR = __DIR__ . '/views/survey/';

    include_once __DIR__ . '/controllers/RootController.php';
    include_once __DIR__ . '/controllers/MessagesController.php';
    include_once __DIR__ . '/controllers/UsersController.php';
    include_once __DIR__ . '/controllers/ParticipantsController.php';
    include_once __DIR__ . '/controllers/ProtocolsController.php';
    include_once __DIR__ . '/controllers/StudiesController.php';
    include_once __DIR__ . '/controllers/SurveyController.php';
    include_once __DIR__ . '/controllers/MetricsController.php';

    $CONFIG = include(CONFIG_FILE);
    $rootURL = $CONFIG['rootURL'];

    function isJsonRequest(): bool {
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return false;
    }

    function unauthorized(int $responseCode=401, string $error="Unauthorized", string $unauthorized_redirect="/no-access"): bool {
        global $rootURL;
        if (isJsonRequest()) {
            http_response_code($responseCode);
            header('Content-Type: application/json');
            $response = ['error' => $error];
            echo json_encode($response);
            die();
        } else {
            http_response_code($responseCode);
            header('Location: ' . $rootURL.$unauthorized_redirect);
            die();
        }
    }

    function get_session(array $requiredRoles=[], bool $allowAPI=false, string $login_redirect = '/login', string $unauthorized_redirect = '/no-access'): ?User {
        global $rootURL;

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'];
            if (strpos($acceptHeader, 'text/html') !== false) {
                if ($_SERVER['REQUEST_URI'] != $rootURL."/"){
                    $_SESSION['redirect'] = $_SERVER['REQUEST_URI'];
                }
            }
        }

        if (!isset($_SESSION['caai_session_id']) && count($requiredRoles) > 0) {
            $apiKeysActive = false;
            $authorizationHeaderSet = isset($_SERVER["HTTP_AUTHORIZATION"]) && $_SERVER["HTTP_AUTHORIZATION"];
            if (!$apiKeysActive || !$authorizationHeaderSet) {
                header('Location: ' . $rootURL.$login_redirect);
                die();
            } else {
                if (strpos($_SERVER["HTTP_AUTHORIZATION"], "Bearer ") === 0) {
                    $apiKeyVal = substr($_SERVER["HTTP_AUTHORIZATION"], 7);
                    if ($apiKeyVal) {
                        $apiKey = false;
                        if ($apiKey){
                            $user = $apiKey->getUser();
                            if ($user) {
                                return $user;
                            } else {
                                error_log("Invalid API Key");
                                unauthorized();
                            }
                        } else {
                            error_log("Invalid token");
                            unauthorized();
                        }
                    } else {
                        error_log("Invalid token");
                        unauthorized();
                    }

                }
            }
        } else if (!isset($_SESSION['caai_session_id']) && count($requiredRoles) == 0) {
            $newSession = UserSession::createEmptyUserSession();
            return $newSession->getUser();
        } else {
            $session_id = $_SESSION['caai_session_id'];
            $session = UserSession::withSessionID($session_id);
            if ($session === null) {
                unset($_SESSION['caai_session_id']);
                header('Location: ' . $rootURL.$login_redirect);
                die();
            }
            $expiration_time = new DateTime($session->getExpires(), new DateTimeZone('UTC'));
            $current_time = new DateTime('now', new DateTimeZone('UTC'));

            if ($current_time > $expiration_time) {
                UserSession::delete($session_id);
                header('Location: ' . $rootURL.$login_redirect);
                die();
            } else {
                $config = include(CONFIG_FILE);
                $current_time->add(new DateInterval('PT' . $config['sessions']['max-age'] . 'S'));
                $expireTime = $current_time->format('Y-m-d H:i:s');
                $session->setExpires($expireTime);
                $session->save();
            }

            $user = $session->getUser();
            PostgresDB::setSessionContext($user->getId(), $user->isSuperAdmin());

            $currentStudy = $_SESSION['current_study'] ?? $session->getCurrentStudy();
            if (is_null($currentStudy)) {
                $memberships = $user->getMemberships();
                if (count($memberships) > 0) {
                    $currentStudy = $memberships[0]['study_uuid'];
                }
            }
            if (is_null($currentStudy) && $user->isSuperAdmin()) {
                $studies = Study::allForSelect();
                if (count($studies) > 0) {
                    $currentStudy = $studies[0]['study_uuid'];
                }
            }
            if (!is_null($currentStudy)) {
                $_SESSION['current_study'] = $currentStudy;
                $session->setCurrentStudy($currentStudy);
            }
            $user->setCurrentStudy($currentStudy);

            $routeRoleAccess = count($requiredRoles) == 0;
            foreach ($requiredRoles as $role){
                $routeRoleAccess = $routeRoleAccess || $user->hasRole($role);
            }
            if (!$routeRoleAccess) {
                unauthorized();
            }
            return $user;
        }
        return null;
    }

    $router = new AltoRouter();
    $router->addMatchTypes(array('uuid' => '([A-Za-z0-9-]+)'));

try {
    $router->map('GET', $rootURL.'/', function() {
        RootController::index(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
    }, 'dashboard');
    $router->map('GET', $rootURL.'/login', function() {
        RootController::login();
    }, 'login');
    $router->map('GET', $rootURL.'/callback', function() {
        RootController::callback();
    }, 'callback');
    $router->map('GET', $rootURL.'/logout', function() {
        RootController::logout();
    }, 'logout');
    $router->map('GET', $rootURL.'/no-access', function() {
        RootController::no_access(get_session());
    }, 'no-access');
    $router->map('POST', $rootURL.'/study/select', function() {
        RootController::selectStudy(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
    }, 'study-select');
} catch (Exception $e) {
    die("Failed to create route(s) from RootController section: " . $e->getMessage());
}

    try {
        $router->map('GET', $rootURL.'/studies', function() {
            StudiesController::index(get_session(["Super Admin"]));
        }, 'studies-index');
        $router->map('GET', $rootURL.'/studies/list', function() {
            StudiesController::listDT(get_session(["Super Admin"]));
        }, 'studies-list');
        $router->map('POST', $rootURL.'/studies/create', function() {
            StudiesController::create(get_session(["Super Admin"]));
        }, 'studies-create');
        $router->map('POST', $rootURL.'/studies/update', function() {
            StudiesController::update(get_session(["Super Admin"]));
        }, 'studies-update');
        $router->map('POST', $rootURL.'/studies/delete', function() {
            StudiesController::delete(get_session(["Super Admin"]));
        }, 'studies-delete');
    } catch (Exception $e) {
        die("Failed to create route(s) from StudiesController section: " . $e->getMessage());
    }

    try {
        $router->map('GET', $rootURL.'/messages', function() {
            MessagesController::index(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-index');
        $router->map('GET', $rootURL.'/messages/log', function() {
            MessagesController::logIndex(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-log');
        $router->map('GET', $rootURL.'/messages/get-message-log', function() {
            MessagesController::listIndividualMessagesDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-getlogs');
        $router->map('GET', $rootURL.'/messages/list', function() {
            MessagesController::listDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-list');
        $router->map('POST', $rootURL.'/messages/send', function() {
            MessagesController::sendMessage(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'messages-send');
        $router->map('GET', $rootURL.'/messages/export', function() {
            MessagesController::exportAsCSV(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-export');
        $router->map('GET', $rootURL.'/messages/export-participant', function() {
            MessagesController::exportParticipantCSV(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-export-participant');
        $router->map('GET', $rootURL.'/messages/scheduled', function() {
            MessagesController::scheduled(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'messages-scheduled');
        $router->map('POST', $rootURL.'/messages/cancel-scheduled', function() {
            MessagesController::cancelScheduled(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'messages-cancel-scheduled');
    } catch (Exception $e) {
        die("Failed to create route(s) from MessageController section: " . $e->getMessage());
    }

    try {
        $router->map('GET', $rootURL.'/participants', function() {
            ParticipantsController::index(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-index');
        $router->map('GET', $rootURL.'/participants/state-log', function() {
            ParticipantsController::stateIndex(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-statelog');
        $router->map('GET', $rootURL.'/participants/all', function() {
            ParticipantsController::getAllParticipants(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-all');
        $router->map('GET', $rootURL.'/participants/get-state-log', function() {
            ParticipantsController::listStateDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-liststatedt');
        $router->map('GET', $rootURL.'/participants/get-current-state', function() {
            ParticipantsController::getCurrentState(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-getcurrstate');
        $router->map('GET', $rootURL.'/participants/success-rate', function() {
            ParticipantsController::getSuccessRate(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-getsuccessrate');
        $router->map('POST', $rootURL.'/participants/update-success-rate', function() {
            ParticipantsController::updateSuccessRate(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-updatesuccessrate');
        $router->map('GET', $rootURL.'/participants/fill-location-dropdown', function() {
            ParticipantsController::fillLocationDropdown(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-filllocationdropdown');
        $router->map('GET', $rootURL.'/participants/fill-timezone-dropdown', function() {
            ParticipantsController::fillTimeZoneDropdown(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-filltimezonedropdown');
        $router->map('POST', $rootURL.'/participants/add-participant', function() {
            ParticipantsController::addParticipant(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-addparticipant');
        $router->map('GET', $rootURL.'/participants/list', function() {
            ParticipantsController::listDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-list');
        $router->map('GET', $rootURL.'/participants/get-participant', function() {
            ParticipantsController::getParticipant(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-getparticipant');
        $router->map('POST', $rootURL.'/participants/update-participant', function() {
            ParticipantsController::updateParticipant(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-updateparticipant');
        $router->map('POST', $rootURL.'/participants/delete-participant', function() {
            ParticipantsController::deleteParticipant(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-deleteparticipant');
        $router->map('POST', $rootURL.'/participants/hide', function() {
            ParticipantsController::hideParticipant(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-hide');
        $router->map('POST', $rootURL.'/participants/unhide', function() {
            ParticipantsController::unhideParticipant(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-unhide');
        $router->map('GET', $rootURL.'/participants/get-time-zone', function() {
            ParticipantsController::getTimeZone(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-gettimezone');
        $router->map('GET', $rootURL.'/participants/next-states', function() {
            ParticipantsController::nextStates(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-nextstates');
        $router->map('POST', $rootURL.'/participants/move-state', function() {
            ParticipantsController::moveState(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'participants-movestate');
        $router->map('GET', $rootURL.'/participants/home-stats', function() {
            ParticipantsController::getHomeStats(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'participants-gethomestats');
    } catch (Exception $e) {
        die("Failed to create route(s) from ParticipantsController section: " . $e->getMessage());
    }

    try {
        $router->map('GET', $rootURL.'/protocols', function() {
            ProtocolsController::index(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'protocols-index');
        $router->map('GET', $rootURL.'/protocols/list', function() {
            ProtocolsController::listDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'protocols-list');
        $router->map('GET', $rootURL.'/protocols/list-published', function() {
            ProtocolsController::listPublished(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'protocols-list-published');
        $router->map('POST', $rootURL.'/protocols/create', function() {
            ProtocolsController::create(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-create');
        $router->map('GET', $rootURL.'/protocols/edit', function() {
            ProtocolsController::edit(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-edit');
        $router->map('GET', $rootURL.'/protocols/get', function() {
            ProtocolsController::get(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-get');
        $router->map('POST', $rootURL.'/protocols/save-draft', function() {
            ProtocolsController::saveDraft(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-save-draft');
        $router->map('POST', $rootURL.'/protocols/validate', function() {
            ProtocolsController::validate(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-validate');
        $router->map('POST', $rootURL.'/protocols/publish', function() {
            ProtocolsController::publish(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-publish');
        $router->map('POST', $rootURL.'/protocols/clone', function() {
            ProtocolsController::cloneProtocol(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-clone');
        $router->map('POST', $rootURL.'/protocols/delete', function() {
            ProtocolsController::delete(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-delete');
        $router->map('POST', $rootURL.'/protocols/import', function() {
            ProtocolsController::import(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-import');
        $router->map('GET', $rootURL.'/protocols/versions', function() {
            ProtocolsController::versions(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-versions');
        $router->map('GET', $rootURL.'/protocols/view', function() {
            ProtocolsController::view(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'protocols-view');
        $router->map('GET', $rootURL.'/protocols/migrate', function() {
            ProtocolsController::migrate(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-migrate');
        $router->map('GET', $rootURL.'/protocols/migrate-list', function() {
            ProtocolsController::migrateList(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-migrate-list');
        $router->map('POST', $rootURL.'/protocols/migrate-enrollments', function() {
            ProtocolsController::migrateEnrollments(get_session(["User", "Study Admin", "Super Admin"]));
        }, 'protocols-migrate-enrollments');
        $router->map('GET', $rootURL.'/protocols/definition', function() {
            ProtocolsController::definition(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'protocols-definition');
    } catch (Exception $e) {
        die("Failed to create route(s) from ProtocolsController section: " . $e->getMessage());
    }

    try {
        $router->map('GET', $rootURL.'/survey/take-survey/[*:token]/[*:partUUID]', function($token, $partUUID) {
            SurveyController::index($token, $partUUID);
        }, 'survey-index');
        $router->map('POST', $rootURL.'/survey/done', function() {
            SurveyController::saveSurvey();
        }, 'survey-done');
        $router->map('GET', $rootURL.'/survey/thank-you', function() {
            SurveyController::thankYou();
        }, 'survey-thankyou');
        $router->map('GET', $rootURL.'/survey/view', function() {
            SurveyController::view(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'survey-view');
        $router->map('GET', $rootURL.'/survey/list', function() {
            SurveyController::listDT(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
        }, 'survey-dt');
    } catch (Exception $e) {
        die("Failed to create route(s) from SurveyController section: " . $e->getMessage());
    }

    try {
        $router->map('GET', $rootURL.'/users', function() {
            UsersController::index(get_session(["Study Admin", "Super Admin"]));
        }, 'users-index');
        $router->map('GET', $rootURL.'/users/list', function() {
            UsersController::listForDatatable(get_session(["Study Admin", "Super Admin"]));
        }, 'users-for-datatable');
        $router->map('POST', $rootURL.'/users/submit', function() {
            UsersController::submit(get_session(["Study Admin", "Super Admin"]));
        }, 'users-submit');
        $router->map('GET', $rootURL.'/users/get-roles', function() {
            UsersController::getRoles(get_session(["Study Admin", "Super Admin"]));
        }, 'users-get-roles');
        $router->map('POST', $rootURL.'/users/delete', function() {
            UsersController::deleteUser(get_session(["Study Admin", "Super Admin"]));
        }, 'users-delete');

    } catch (Exception $e) {
        die("Failed to create route(s) from UsersController section: " . $e->getMessage());
    }

try {
    $router->map('GET', $rootURL.'/metrics/compliance', function() {
        MetricsController::getCompliance(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
    }, 'metrics-compliance');
    $router->map('GET', $rootURL.'/metrics/state-distribution', function() {
        MetricsController::stateDistribution(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
    }, 'metrics-state-distribution');
    $router->map('GET', $rootURL.'/metrics/message-volume', function() {
        MetricsController::messageVolume(get_session(["User", "PI", "Non-PHI", "Study Admin", "Super Admin"]));
    }, 'metrics-message-volume');
} catch (Exception $e) {
    die("Failed to create route(s) from MetricsController section: " . $e->getMessage());
}

    $match = $router->match();

    if ($match && is_callable($match['target'])) {
        call_user_func_array($match['target'], $match['params']);
    } else {
        $from = $_SERVER['REQUEST_URI'];
        error_log("ROUTE NOT FOUND: " . $from
            . " (referer: " . ($_SERVER['HTTP_REFERER'] ?? '-')
            . ", dest: " . ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '-') . ")");

        $path = parse_url($from, PHP_URL_PATH);
        if (is_string($path) && preg_match('/\.(?:js|mjs|css|map|json|ico|png|jpe?g|gif|svg|webp|woff2?|ttf|otf|eot|txt|xml|webmanifest)$/i', $path)) {
            // Missing static assets get a plain 404 — flashing an error and
            // redirecting would queue a toast for the next page view on every
            // stray asset request (e.g. probes for /sw.js)
            http_response_code(404);
        } else if (isJsonRequest()){
            http_response_code(404);
            header('Content-Type: application/json');
            $response = [
                'success' => false,
                'error' => 'Not Found',
                'error_message' => 'The requested resource was not found on this server.'
            ];
            echo json_encode($response);
        } else {
            if (strlen($from) > 20)
                $from = substr($from, 0, 20) . '...';
            $_SESSION['FLASH_ERROR'] = "No page exists at $from";
            if(isset($_SERVER['HTTP_REFERER'])) {
                header('Location: ' . $_SERVER['HTTP_REFERER']);
            } else {
                header('Location: ' . $rootURL . '/');
            }
        }
    }
