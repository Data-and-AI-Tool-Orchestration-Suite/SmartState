<?php

include_once MODELS_DIR . 'Metrics.php';

class MetricsController {

    public static function getCompliance(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = "";
        $values = array();

        try {
            $studyUuid = $user->getCurrentStudy();
            if (!is_null($studyUuid)) {
                $values = Metrics::getTotalCompliance($studyUuid);
            }
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'values' => $values);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function stateDistribution(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = "";
        $values = array();

        try {
            $studyUuid = $user->getCurrentStudy();
            if (!is_null($studyUuid)) {
                $values = Metrics::getStateDistribution($studyUuid);
            }
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'values' => $values);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function messageVolume(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = "";
        $values = array();

        try {
            $studyUuid = $user->getCurrentStudy();
            if (!is_null($studyUuid)) {
                $values = Metrics::getMessageVolume($studyUuid);
            }
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'values' => $values);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

}
