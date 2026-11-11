<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'Participant.php';
include_once MODELS_DIR . 'Message.php';
include_once MODELS_DIR . 'Study.php';

class ParticipantsController {
    public static function index(User $user) {
        require PARTICIPANTS_VIEWS_DIR . 'index.php';
    }

    public static function stateIndex(User $user) {
        require PARTICIPANTS_VIEWS_DIR . 'state-log.php';
    }

    public static function getAllParticipants(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $participants = array();
        $studyUuid = $user->getCurrentStudy();
        try {
            if (is_null($studyUuid))
                throw new Exception('No study selected.');
            $results = Participant::all($studyUuid);
            $success = true;
            foreach ($results as $result) {
                $data_row = $result->jsonSerialize();
                array_push($participants, $data_row);
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'participants' => $participants);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function listStateDT(User $user) {
        header('Content-Type: application/json');

        $studyUuid = $user->getCurrentStudy();
        if (empty($_GET['uuid']) || empty($_GET['protocol']) || is_null($studyUuid) || !Participant::belongsToStudy($_GET['uuid'], $studyUuid)) {
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => array(),
                )
            );
            return;
        }

        $start = 0;
        if (isset($_GET['start']))
            $start = intval($_GET['start']);
        $length = 0;
        if (isset($_GET['length']))
            $length = intval($_GET['length']);
        $filter = '';
        if (isset($_GET['search']['value']))
            $filter = $_GET['search']['value'];
        $order_by = '';
        if (isset($_GET['order'][0]['column']))
            $order_by = $_GET['order'][0]['column'];
        $order_dir = 'desc';
        if (isset($_GET['order'][0]['dir']))
            $order_dir = $_GET['order'][0]['dir'];
        $logs = array();
        $error_message = "";
        $idx = $start;
        $results = Participant::listStateForDatatable($_GET['uuid'], $_GET['protocol'], $start, $length, $order_by, $order_dir, $filter, $studyUuid);

        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            $participantTimeZone = Participant::getTimeZoneFromUUID($_GET['uuid']);

            try {
                $date = new DateTime($data_row['ts'], new DateTimeZone('UTC'));
                $date->setTimezone(new DateTimeZone($participantTimeZone));
                $data_row['ts'] = $date->format('M j, Y g:i:s A T');
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
            $logs[] = $data_row;
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => Participant::countForStateDatatable($_GET['uuid'], $studyUuid),
                'recordsFiltered' => Participant::countFilteredForStateDatatable($_GET['uuid'], $filter, $studyUuid),
                'data' => $logs,
                'error_message' => $error_message
            )
        );
    }

    public static function getCurrentState(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $state = null;

        $studyUuid = $user->getCurrentStudy();
        if (empty($_GET['uuid']) || empty($_GET['protocol'])) {
            $error_message = 'No participant UUID/protocol provided';
        } else if (is_null($studyUuid) || !Participant::belongsToStudy($_GET['uuid'], $studyUuid)) {
            $error_message = 'Participant not found in the current study';
        } else {
            $state = Participant::getCurrentStateString($_GET['uuid'], $_GET['protocol'], $studyUuid);
            if(!is_null($state)) {
                $success = true;
            } else {
                $error_message = 'No participant state found';
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'state' => $state);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function getTimeZone(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $timezone = null;

        if(empty($_GET['uuid'])) {
            $error_message = 'No participant UUID provided';
        } else {
            $timezone = Participant::getTimeZone($_GET['uuid']);
            if(!is_null($timezone)) {
                $success = true;
            } else {
                $error_message = 'No participant time zone found';
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'time_zone' => $timezone);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function fillLocationDropdown(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $locations = [];

        try {
            $results = Participant::allLocations();
            foreach ($results as $result) {
                $locations[] = $result['location'];
            }
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }


        $ret = array('success' => $success, 'error_message' => $error_message, 'data' => $locations);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function fillTimeZoneDropdown(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $timeZones = [];
        if (isset($_GET['location']) && !empty($_GET['location'])) {
            $location = $_GET['location'];
            $results = Participant::getZonesWithLocation($location);
            foreach ($results as $result) {
                array_push($timeZones, $result['name']);
            }
            $success = true;
        } else {
            $error_message = "No location specified";
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'data' => $timeZones);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function addParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $studyUuid = $user->getCurrentStudy();
        if (isset($_POST['info'])) {
            $info = $_POST['info'];
            $definitionUuids = isset($_POST['definition_uuids']) && is_array($_POST['definition_uuids']) ? $_POST['definition_uuids'] : [];
            $json = json_encode($info);

            try {
                if (is_null($studyUuid))
                    throw new Exception('No study selected.');
                Participant::create($json, $studyUuid, $definitionUuids);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "Please provide info for the participant.";
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function getParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = "";
        $json = null;
        $participants = [];
        $enrollments = [];

        $studyUuid = $user->getCurrentStudy();
        if (!empty($_GET['id'])) {
            $uuid = $_GET['id'];
            try {
                if (is_null($studyUuid) || !Participant::belongsToStudy($uuid, $studyUuid))
                    throw new Exception("Participant with ID [{$uuid}] not found");
                $participant = Participant::withID($uuid);
                if ($participant != null) {
                    $json = $participant->getJSON();
                    $enrollments = Participant::allEnrollments($uuid);
                    $success = true;
                } else {
                    $error_message = "Participant with ID [{$uuid}] not found";
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "No ID specified";
        }

        $decoded = json_decode($json, true);

        $participants[] = array(
            'first_name'    => $decoded['first_name'] ?? null,
            'last_name'     => $decoded['last_name'] ?? null,
            'number'        => isset($decoded['number']) ? substr($decoded['number'], 2) : null,
            'email'         => $decoded['email'] ?? null,
            'devEUI'        => $decoded['devEUI'] ?? null,
            'time_zone'     => $decoded['time_zone'] ?? null,
            'location'      => isset($decoded['time_zone']) ? explode("/", $decoded['time_zone'])[0] : null
        );

        $ret = array('success' => $success, 'error_message' => $error_message, 'data' => $participants, 'enrollments' => $enrollments);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function updateParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $studyUuid = $user->getCurrentStudy();
        if (!empty($_POST['id']) && !empty($_POST['info'])) {
            $uuid = $_POST['id'];
            $info = $_POST['info'];
            $definitionUuids = isset($_POST['definition_uuids']) && is_array($_POST['definition_uuids']) ? $_POST['definition_uuids'] : [];

            try {
                if (is_null($studyUuid))
                    throw new Exception('No study selected.');
                Participant::update($uuid, $info, $studyUuid, $definitionUuids);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "No ID or info specified";
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function deleteParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $studyUuid = $user->getCurrentStudy();
        if (isset($_POST['id'])) {
            $uuid = $_POST['id'];
            try {
                if (is_null($studyUuid))
                    throw new Exception('No study selected.');
                $isDeleted = Participant::delete($uuid, $studyUuid);
                if ($isDeleted) {
                    $success = true;
                } else {
                    $error_message = "Could not delete participant.";
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "No ID specified";
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function listDT(User $user) {
        header('Content-Type: application/json');

        $studyUuid = $user->getCurrentStudy();
        if (is_null($studyUuid)) {
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => array(),
                )
            );
            return;
        }

        $start = 0;
        if (isset($_GET['start']))
            $start = intval($_GET['start']);
        $length = 0;
        if (isset($_GET['length']))
            $length = intval($_GET['length']);
        $filter = '';
        if (isset($_GET['search']['value']))
            $filter = $_GET['search']['value'];
        $order_by = '';
        if (isset($_GET['order'][0]['column']))
            $order_by = $_GET['order'][0]['column'];
        $order_dir = 'desc';
        if (isset($_GET['order'][0]['dir']))
            $order_dir = $_GET['order'][0]['dir'];
        $showHidden = isset($_GET['showHidden']) && $_GET['showHidden'] === 'true';

        $participants = [];
        $idx = $start;

        $results = Participant::listForDatatable($start, $length, $order_by, $order_dir, $filter, $studyUuid, $showHidden);

        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $decoded = json_decode($data_row['json'], true);

            $name = "{$decoded['first_name']} {$decoded['last_name']}";

            $participants[] = [
                'name'      => $name,
                'number'    => $decoded['number'],
                'email'     => $decoded['email'],
                'protocols' => $result->getProtocols() ?? '',
                'dev_eui'   => $decoded['devEUI'],
                'hidden'    => isset($decoded['hidden']),
                'id'        => $data_row['uuid'],
                'DT_RowId'  => $idx++
            ];
        }

        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => Participant::countForDatatable($studyUuid, $showHidden),
                'recordsFiltered' => Participant::countFilteredForDatatable($filter, $studyUuid, $showHidden),
                'data' => $participants,
            )
        );
    }

    public static function hideParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $studyUuid = $user->getCurrentStudy();
        if (isset($_POST['id'])) {
            $uuid = $_POST['id'];
            try {
                if (is_null($studyUuid))
                    throw new Exception('No study selected.');
                $isHidden = Participant::hide($uuid, $studyUuid);
                if ($isHidden) {
                    $success = true;
                } else {
                    $error_message = "Could not hide participant.";
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "No ID specified";
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function unhideParticipant(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;

        $studyUuid = $user->getCurrentStudy();
        if (isset($_POST['id'])) {
            $uuid = $_POST['id'];
            try {
                if (is_null($studyUuid))
                    throw new Exception('No study selected.');
                $isUnhidden = Participant::unhide($uuid, $studyUuid);
                if ($isUnhidden) {
                    $success = true;
                } else {
                    $error_message = "Could not unhide participant.";
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        } else {
            $error_message = "No ID specified";
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function getSuccessRate(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $success_json = array();

        $studyUuid = $user->getCurrentStudy();
        if (empty($_GET['uuid'])) {
            $error_message = 'No participant UUID provided';
        } else if (is_null($studyUuid) || !Participant::belongsToStudy($_GET['uuid'], $studyUuid)) {
            $error_message = 'Participant not found in the current study';
        } else {
            try {
                $success_json = Participant::getSuccessRate($_GET['uuid'], $studyUuid);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'success_json' => $success_json);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function updateSuccessRate(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $success_json = array();

        $studyUuid = $user->getCurrentStudy();
        if (empty($_POST['uuid'])) {
            $error_message = 'No participant UUID provided.';
        } else if (!isset($_POST['successful']) || !is_numeric($_POST['successful'])) {
            $error_message = 'No successful count provided.';
        } else if (!isset($_POST['total']) || !is_numeric($_POST['total'])) {
            $error_message = 'No total count provided.';
        } else if (!isset($_POST['after8pm']) || !is_numeric($_POST['after8pm'])) {
            $error_message = 'No after 8pm count provided.';
        } else if (is_null($studyUuid) || !Participant::belongsToStudy($_POST['uuid'], $studyUuid)) {
            $error_message = 'Participant not found in the current study';
        } else {
            try {
                Participant::updateSuccessRate($_POST['uuid'], intval($_POST['successful']), intval($_POST['total']), intval($_POST['after8pm']), $studyUuid);
                $success_json = Participant::getSuccessRate($_POST['uuid'], $studyUuid);
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'success_json' => $success_json);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function nextStates(User $user) {
        header('Content-Type: application/json');
        global $CONFIG;

        $studyUuid = $user->getCurrentStudy();
        if (empty($_GET['uuid']) || empty($_GET['protocol'])) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'status_desc' => 'You must provide a participant UUID and protocol.'));
            return;
        }
        $uuid = $_GET['uuid'];
        $protocol = $_GET['protocol'];
        if (is_null($studyUuid) || !Participant::belongsToStudy($uuid, $studyUuid)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Participant not found in the current study.'));
            return;
        }
        $study = Study::withUUID($studyUuid);
        if (is_null($study)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Current study not found.'));
            return;
        }
        $result = RootController::makeAPIcallGET(
            $CONFIG['java_api_url'],
            '/api/get-valid-next-states/' . $uuid . '/' . rawurlencode($study->getName()) . '/' . rawurlencode($protocol)
        );
        if (!$result['success']) {
            http_response_code(502);
            echo json_encode(array('status' => 'error', 'status_desc' => $result['error_message']));
            return;
        }
        echo json_encode($result['response']);
    }

    public static function moveState(User $user) {
        header('Content-Type: application/json');
        global $CONFIG;

        $studyUuid = $user->getCurrentStudy();
        if (empty($_POST['uuid']) || empty($_POST['toState']) || empty($_POST['protocol'])) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'status_desc' => 'You must provide a participant UUID, state, and protocol.'));
            return;
        }
        $uuid = $_POST['uuid'];
        $toState = $_POST['toState'];
        $protocol = $_POST['protocol'];
        $time = isset($_POST['time']) ? $_POST['time'] : -1;
        if (is_null($studyUuid) || !Participant::belongsToStudy($uuid, $studyUuid)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Participant not found in the current study.'));
            return;
        }
        $study = Study::withUUID($studyUuid);
        if (is_null($study)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Current study not found.'));
            return;
        }

        $ch = curl_init($CONFIG['java_api_url'] . '/api/next-state/');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'participantUUID' => $uuid,
            'toState'         => $toState,
            'time'            => $time,
            'study'           => $study->getName(),
            'protocol'        => $protocol
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log("Curl error: " . curl_error($ch));
            http_response_code(502);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Failed to contact the state engine.'));
            return;
        }
        $decoded = json_decode($response, true);
        if (is_null($decoded)) {
            http_response_code(502);
            echo json_encode(array('status' => 'error', 'status_desc' => 'Invalid response from the state engine.'));
            return;
        }
        echo json_encode($decoded);
    }

    public static function getHomeStats(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = "";
        $participantStats = array();
        $surveyStats = array();

        $studyUuid = $user->getCurrentStudy();
        try {
            if (is_null($studyUuid))
                throw new Exception('No study selected.');
            $participantStats = Participant::getParticipantStats($studyUuid);
            $surveyStats = Survey::getSurveyStats();
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $ret = array(
            'success' => $success,
            'error_message' => $error_message,
            'patientStats' => $participantStats,
            'surveyStats' => $surveyStats
        );
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }
}
