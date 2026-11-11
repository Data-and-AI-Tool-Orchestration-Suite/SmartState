<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'Survey.php';
include_once MODELS_DIR . 'Participant.php';


class SurveyController {
    public static function index(?string $token = null, ?string $participantUUID = null) {
        if (is_null($token) || is_null($participantUUID)) {
            require SURVEY_VIEWS_DIR . 'invalid.php';
            return;
        }
        try {
            PostgresDB::setSurveyToken($token);
            $survey = Survey::withID($token);
        } catch (Exception $e) {
            $survey = null;
        }
        $isValid = !is_null($survey)
            && is_null($survey->getFinishedAt())
            && $survey->getParticipant() === $participantUUID;
        if (!$isValid) {
            require SURVEY_VIEWS_DIR . 'invalid.php';
            return;
        }
        $surveyKey = $survey->getSurveyKey();
        $questions = null;
        if (!is_null($survey->getQuestionsJSON())) {
            $decoded = json_decode($survey->getQuestionsJSON(), true);
            if (is_array($decoded)) {
                if (isset($decoded['questions']) && is_array($decoded['questions']))
                    $questions = $decoded['questions'];
                else
                    $questions = $decoded;
            }
        }
        if (is_array($questions) && count($questions) === 0)
            $questions = null;
        require SURVEY_VIEWS_DIR . 'index.php';
    }

    public static function view(User $user) {
        require SURVEY_VIEWS_DIR . 'view.php';
    }

    public static function thankYou() {
        $contact = null;
        if (!empty($_GET['token'])) {
            try {
                PostgresDB::setSurveyToken($_GET['token']);
                $contact = Survey::getStudyContactForToken($_GET['token']);
            } catch (Exception $e) {
                $contact = null;
            }
        }
        require SURVEY_VIEWS_DIR . 'thanks.php';
    }

    public static function listDT(User $user) {
        header('Content-Type: application/json');

        try{
            $studyUUID = $user->getCurrentStudy();
            if (is_null($studyUUID))
                throw new Exception('No study selected.');
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
            $surveys = array();
            $idx = $start;
            $results = Survey::listForDatatable($start, $length, $order_by, $order_dir, $filter, $studyUUID);
            foreach ($results as $result) {
                $data_row = $result->jsonSerialize();
                $data_row['DT_RowId'] = $idx++;
                try {
                    $data_row['participant_name'] = Participant::getConcatNameFromID($result->getParticipant());
                } catch (Exception $e) {
                    $data_row['participant_name'] = '';
                }
                try {
                    $participantTimeZone = Participant::getTimeZoneFromUUID($data_row['participant_uuid']);
                } catch (Exception $e) {
                    $participantTimeZone = null;
                }
                if (empty($participantTimeZone))
                    $participantTimeZone = 'UTC';

                $date_created = new DateTime($data_row['created_at'], new DateTimeZone('UTC'));
                $date_created = $date_created->setTimezone(new DateTimeZone($participantTimeZone));
                $data_row['created_at'] = $date_created->format('M j, Y g:i:s A');
                if (!is_null($data_row['finished_at'])) {
                    $date_finished = new DateTime($data_row['finished_at'], new DateTimeZone('UTC'));
                    $date_finished = $date_finished->setTimezone(new DateTimeZone($participantTimeZone));
                    $data_row['finished_at'] = $date_finished->format('M j, Y g:i:s A');
                }

                $data_row['time_zone'] = $date_created->format('T');
                array_push($surveys, $data_row);
            }

            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => intval(Survey::countForDatatable($studyUUID)),
                    'recordsFiltered' => intval(Survey::countFilteredForDatatable($filter, $studyUUID)),
                    'data' => $surveys
                )
            );
        } catch (Exception $e) {
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => array(),
                    'error_message' => $e->getMessage()
                )
            );
        }
    }

    public static function saveSurvey() {
        header('Content-Type: application/json');
        $success = false;
        $error_message = "";

        if(empty($_POST['answers'])){
            $error_message = "Please continue to take the survey. Click \"Submit\", when you're finished.";
        } else if (empty($_POST['token'])){
            $error_message = "Survey is invalid or has expired, please check your link or refresh the page.";
        } else {
            $surveyAnswers = $_POST['answers'];
            $token = $_POST['token'];
            try {
                PostgresDB::setSurveyToken($token);
                $saved = Survey::saveSurveyContent($surveyAnswers, $token);
                if ($saved)
                    $success = true;
                else
                    $error_message = "Survey is invalid or has expired, please check your link or refresh the page.";
            } catch (Exception $ex){
                $error_message = $ex->getMessage();
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

}
