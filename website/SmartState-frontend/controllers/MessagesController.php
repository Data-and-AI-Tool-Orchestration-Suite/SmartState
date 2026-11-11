<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'Message.php';
include_once MODELS_DIR . 'Participant.php';
include_once MODELS_DIR . 'Study.php';

class MessagesController {
    private static function isNonPHI(User $user): bool {
        return !$user->isSuperAdmin() && $user->roleInCurrentStudy() === 'Non-PHI';
    }

    private static function redactMessageJson(?string $json): string {
        $decoded = json_decode($json ?? '', true);
        if (!is_array($decoded))
            $decoded = array();
        $decoded['Body'] = '[redacted]';
        return json_encode($decoded);
    }

    public static function index(User $user) {
        require MESSAGES_VIEWS_DIR . 'index.php';
    }

    public static function logIndex(User $user) {
        require MESSAGES_VIEWS_DIR . 'logs.php';
    }

    public static function listDT(User $user) {
        header('Content-Type: application/json');

        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid)) {
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
        $messages = array();
        $idx = $start;
        $redact = self::isNonPHI($user);
        $results = Message::listForDatatable($start, $length, $order_by, $order_dir, $filter, $study_uuid);
        $error_message = '';
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            if ($redact)
                $data_row['json'] = self::redactMessageJson($data_row['json']);
            $data_row['participant_name'] = Participant::getConcatNameFromID($result->getParticipant());
            $data_row['DT_RowId'] = $idx++;
            $participantTimeZone = Participant::getTimeZoneFromUUID($data_row['participant_uuid']);
            $data_row['time_zone'] = $participantTimeZone;
            try {
                $date = new DateTime($data_row['ts'], new DateTimeZone('UTC'));
                $date = $date->setTimezone(new DateTimeZone($participantTimeZone));
                $data_row['ts'] = $date->format('M j, Y g:i:s A');
                $data_row['time_zone'] = $date->format('T');
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
            $messages[] = $data_row;
        }

        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => Message::countForDatatable($study_uuid),
                'recordsFiltered' => Message::countFilteredForDatatable($filter, $study_uuid),
                'data' => $messages,
                'error_message' => $error_message,
            )
        );
    }

    public static function listIndividualMessagesDT(User $user) {
        header('Content-Type: application/json');

        if (empty($_GET['uuid'])){
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
        $messages = array();
        $error_message = "";
        $idx = $start;
        $redact = self::isNonPHI($user);
        $results = Message::listIndividualForDatatable($_GET['uuid'], $start, $length, $order_by, $order_dir, $filter);

        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            if ($redact)
                $data_row['json'] = self::redactMessageJson($data_row['json']);
            $data_row['DT_RowId'] = $idx++;
            $participantTimeZone = Participant::getTimeZoneFromUUID($data_row['participant_uuid']);

            try {
                $date = new DateTime($data_row['ts'], new DateTimeZone('UTC'));
                $date->setTimezone(new DateTimeZone($participantTimeZone));
                $data_row['ts'] = $date->format('M j, Y g:i:s A T');
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
            $messages[] = $data_row;
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => Message::countIndividualForDatatable($_GET['uuid']),
                'recordsFiltered' => Message::countIndividualFilteredForDatatable($_GET['uuid'], $filter),
                'data' => $messages,
                'error_message' => $error_message,
            )
        );
    }

    public static function sendMessage(User $user) {
        header('Content-Type: application/json');
        global $CONFIG;
        $success = false;
        $error_message = null;
        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid)) {
            $error_message = "No study is currently selected. Please refresh and try again.";
        } else if (empty($_POST['participant_uuid'])){
            $error_message = "1 or more participants must be selected.";
        } else if (empty($_POST['body'])) {
            $error_message = "Your message must not be empty.";
        } else if (empty($_POST['time_to_send'])) {
            $error_message = "Scheduled message time is not formatted properly.";
        } else {
            $participant_uuid = $_POST['participant_uuid'];
            $message_json = $_POST['body'];
            $message_json = str_replace(array("\n", "\r", "\t", "\\t", "\\n", "\\r"), " ", $message_json);
            $message_json = json_encode($message_json);
            $message_json = '{"Body":'.$message_json.'}';
            $scheduledTime = $_POST['time_to_send'];

            try {
                foreach($participant_uuid as $uuid) {
                    if ($scheduledTime == -1){
                        $date = new DateTime();
                        $date = $date->format('Y-m-d H:i:s');
                        $message = Message::create($uuid, $date, Message::OUTGOING, $message_json, $study_uuid);
                        if (is_null($message)) {
                            throw new Exception('Failed to send message.');
                        }
                        $participantNumber = Participant::getPhoneNumber($uuid);
                        $result = Message::send($message, $participantNumber);
                        if ($result) {
                            $success = true;
                        } else {
                            throw new Exception('Failed to send message.');
                        }
                    } else {
                        $scheduledFor = new DateTime($scheduledTime, new DateTimeZone(Participant::getTimeZone($uuid)));
                        $scheduledFor->setTimezone(new DateTimeZone('UTC'));
                        $scheduledFor = $scheduledFor->format('Y-m-d H:i:s');
                        $toNumber = Participant::getPhoneNumber($uuid);
                        $fromNumber = null;
                        $study = Study::withUUID($study_uuid);
                        if (!is_null($study))
                            $fromNumber = $study->getTwilioFromNumber();
                        if (is_null($fromNumber) || strlen($fromNumber) === 0)
                            $fromNumber = $CONFIG['twilio']['from_number'];

                        $message = ScheduledMessage::create($uuid, $scheduledFor, $message_json, $toNumber, $fromNumber, $study_uuid);

                        if (is_null($message)) {
                            throw new Exception('Failed to schedule message.');
                        } else {
                            $success = true;
                        }

                    }
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function scheduled(User $user) {
        header('Content-Type: application/json');

        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid)) {
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
        $order_dir = 'asc';
        if (isset($_GET['order'][0]['dir']))
            $order_dir = $_GET['order'][0]['dir'];
        $messages = array();
        $error_message = '';
        $redact = self::isNonPHI($user);
        $results = ScheduledMessage::listForDatatable($start, $length, $order_by, $order_dir, $filter, $study_uuid);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            if ($redact) {
                $data_row['message_json'] = self::redactMessageJson($data_row['message_json']);
                $data_row['to_number'] = '[redacted]';
            }
            $data_row['participant_name'] = Participant::getConcatNameFromID($result->getParticipantUUID());
            $data_row['DT_RowId'] = $result->getMessageUUID();
            $participantTimeZone = Participant::getTimeZoneFromUUID($data_row['participant_uuid']);
            $data_row['time_zone'] = $participantTimeZone;
            try {
                $date = new DateTime($data_row['scheduled_for'], new DateTimeZone('UTC'));
                $date = $date->setTimezone(new DateTimeZone($participantTimeZone));
                $data_row['scheduled_for'] = $date->format('M j, Y g:i:s A');
                $data_row['time_zone'] = $date->format('T');
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
            $messages[] = $data_row;
        }

        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => ScheduledMessage::countForDatatable($study_uuid),
                'recordsFiltered' => ScheduledMessage::countFilteredForDatatable($filter, $study_uuid),
                'data' => $messages,
                'error_message' => $error_message,
            )
        );
    }

    public static function cancelScheduled(User $user) {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid)) {
            $error_message = "No study is currently selected. Please refresh and try again.";
        } else if (empty($_POST['message_uuid'])) {
            $error_message = "You must provide a scheduled message to cancel.";
        } else {
            try {
                $success = ScheduledMessage::deleteMessage($_POST['message_uuid'], $study_uuid);
                if (!$success)
                    $error_message = "Scheduled message not found.";
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function exportAsCSV(User $user) {
        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid)){
            header('Content-Type: application/json');
            $ret = array('success' => false, 'error_message' => "No study is currently selected.");
            echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="messages_export_'.date('m-d-Y_H.i.s').'.csv";');

            $messageData = Message::allForCSV($study_uuid, self::isNonPHI($user));

            $delimiter = ",";
            $f = fopen('php://memory', 'w');

            foreach ($messageData as $row) {
                $lineData = array();
                foreach($row as $key) {
                    $lineData[] = $key;
                }
                fputcsv($f, $lineData, $delimiter);
            }

            fseek($f, 0);

            fpassthru($f);

            fclose($f);
        }
    }

    public static function exportParticipantCSV(User $user) {
        $study_uuid = $user->getCurrentStudy();
        if (empty($study_uuid) || empty($_GET['uuid']) || !Participant::belongsToStudy($_GET['uuid'], $study_uuid)) {
            header('Content-Type: application/json');
            $error = empty($_GET['uuid']) ? "No participant UUID provided." : "Participant not found in the current study.";
            if (empty($study_uuid))
                $error = "No study is currently selected.";
            $ret = array('success' => false, 'error_message' => $error);
            echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
        } else {
            $participant_uuid = $_GET['uuid'];
            $isTre = Participant::hasTREEnrollment($participant_uuid, $study_uuid);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="messages_export_'.$participant_uuid.'_'.date('m-d-Y_H.i.s').'.csv";');

            $messageData = Message::allForCSVParticipant($participant_uuid, $study_uuid, $isTre, self::isNonPHI($user));

            $delimiter = ",";
            $f = fopen('php://memory', 'w');

            foreach ($messageData as $row) {
                $lineData = array();
                foreach($row as $key) {
                    $lineData[] = $key;
                }
                fputcsv($f, $lineData, $delimiter);
            }

            fseek($f, 0);

            fpassthru($f);

            fclose($f);
        }
    }
}
