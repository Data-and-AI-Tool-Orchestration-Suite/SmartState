<?php

require_once MODELS_DIR . 'Study.php';

class StudiesController {
    public static function index(User $user): void {
        require STUDIES_VIEWS_DIR . 'index.php';
    }

    public static function listDT(User $user): void {
        header('Content-Type: application/json');
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
        $data = array();
        $idx = $start;
        $results = Study::listForDatatable($start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            $data[] = $data_row;
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Study::countForDatatable()),
                'recordsFiltered' => intval(Study::countFilteredForDatatable($filter)),
                'data' => $data,
            )
        );
    }

    public static function create(User $user): void {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $study = null;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error_message = 'Please enter a name for the study.';
        } else {
            try {
                $study = Study::create(
                    $name,
                    self::nullable($_POST['contact_phone'] ?? null),
                    self::nullable($_POST['contact_email'] ?? null),
                    self::nullable($_POST['twilio_from_number'] ?? null)
                );
                $success = !is_null($study);
                if (!$success)
                    $error_message = 'Study could not be created.';
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'study' => $study);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function update(User $user): void {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $study = null;
        $studyUuid = $_POST['study_uuid'] ?? '';
        $name = trim($_POST['name'] ?? '');
        if ($studyUuid === '') {
            $error_message = 'Study not found.';
        } else if ($name === '') {
            $error_message = 'Please enter a name for the study.';
        } else {
            try {
                $study = Study::update(
                    $studyUuid,
                    $name,
                    self::nullable($_POST['contact_phone'] ?? null),
                    self::nullable($_POST['contact_email'] ?? null),
                    self::nullable($_POST['twilio_from_number'] ?? null)
                );
                $success = true;
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'study' => $study);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function delete(User $user): void {
        header('Content-Type: application/json');
        $success = false;
        $error_message = null;
        $studyUuid = $_POST['study_uuid'] ?? '';
        if ($studyUuid === '') {
            $error_message = 'Study not found.';
        } else {
            try {
                $success = Study::delete($studyUuid);
                if (!$success)
                    $error_message = 'Study could not be deleted.';
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    private static function nullable(?string $value): ?string {
        if (is_null($value))
            return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
