<?php

const USERS_VIEWS_DIR = __DIR__ . '/../views/users/';

require_once MODELS_DIR . 'User.php';
require_once MODELS_DIR . 'Study.php';

class UsersController {
    public static function index(User $user): void {
        require USERS_VIEWS_DIR . 'index.php';
    }

    public static function listForDatatable(User $user): void {
        header("Content-Type: application/json");
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
        $studyFilter = $user->isSuperAdmin() ? null : $user->getCurrentStudy();
        $data = array();
        $idx = $start;
        $results = User::listForDatatable($start, $length, $order_by, $order_dir, $filter, $studyFilter);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            $data[] = $data_row;
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => User::countForDatatable($studyFilter),
                'recordsFiltered' => User::countFilteredForDatatable($filter, $studyFilter),
                'data' => $data,
            )
        );
    }

    public static function submit(User $user): void {
        header("Content-Type: application/json");
        $success = false;
        $error_message = null;
        $target = null;
        $action = "update";
        if (empty($_POST['id'])) {
            $action = "create";
        }
        $email = trim($_POST['email'] ?? '');
        $number = $_POST['number'] ?? '';
        $timezone = $_POST['timezone'] ?? '';
        $memberships = $_POST['memberships'] ?? [];
        if (!is_array($memberships))
            $memberships = [];
        $isSuperAdmin = ($_POST['is_super_admin'] ?? 'false') === 'true';

        $actorIsSuper = $user->isSuperAdmin();
        $currentStudy = $user->getCurrentStudy();
        $onlyStudy = $actorIsSuper ? null : $currentStudy;
        if (!$actorIsSuper) {
            $isSuperAdmin = false;
            $memberships = array_values(array_filter($memberships, function($membership) use ($currentStudy) {
                return isset($membership['study_uuid']) && $membership['study_uuid'] === $currentStudy;
            }));
        }

        if ($email === '') {
            $error_message = "Please enter a valid email address.";
        } else if (count($memberships) == 0 && !$isSuperAdmin) {
            $error_message = "Please add at least one study membership for this user.";
        } else if (!$actorIsSuper && is_null($currentStudy)) {
            $error_message = "No study selected.";
        } else {
            try {
                if ($action == "create") {
                    $target = User::withEPPN($email);
                    if ($target != null) {
                        $error_message = "User with email [{$target->getEPPN()}] already exists";
                        $target = null;
                    } else {
                        $target = User::createBeforeLogin($email, $number, $timezone, $memberships, $isSuperAdmin);
                        $success = !is_null($target);
                        if (!$success)
                            $error_message = "User could not be created.";
                    }
                } else {
                    $target = User::withId($_POST['id']);
                    if (is_null($target)) {
                        $error_message = "User not found.";
                    } else if (!$actorIsSuper && !self::hasMembershipInStudy($target, $currentStudy)) {
                        $error_message = "You may only manage users in your current study.";
                        $target = null;
                    } else if (!$actorIsSuper && $target->isSuperAdmin()) {
                        $error_message = "You may not manage a Super Admin.";
                        $target = null;
                    } else {
                        $target = User::update($_POST['id'], $email, $number, $timezone, $memberships, $isSuperAdmin, $onlyStudy);
                        $success = true;
                    }
                }
                if ($success && !is_null($target) && $number !== '') {
                    $studyAdminRoleId = User::getRoleIdFromName("Study Admin");
                    foreach ($memberships as $membership) {
                        if (intval($membership['role_id']) === $studyAdminRoleId) {
                            User::ensureStaffParticipant(
                                $membership['study_uuid'],
                                $target->getFirstName(),
                                $target->getLastName(),
                                $number,
                                $timezone
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'action' => $action, 'error_message' => $error_message, 'user' => $target);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function getRoles(User $user): void {
        header("Content-Type: application/json");
        $success = false;
        $error_message = null;
        $roles = null;

        try {
            $roles = User::getAllRoles();
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'roles' => $roles);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function deleteUser(User $user): void {
        header("Content-Type: application/json");
        $success = false;
        $error_message = null;

        if (empty($_POST['id'])) {
            $error_message = "User ID not found.";
        }
        else {
            $id = $_POST['id'];
            try {
                $target = User::withId($id);
                if ($target != null) {
                    if (!$user->isSuperAdmin() && !self::hasMembershipInStudy($target, $user->getCurrentStudy())) {
                        $error_message = "You may only manage users in your current study.";
                    } else if (!$user->isSuperAdmin() && $target->isSuperAdmin()) {
                        $error_message = "You may not delete a Super Admin.";
                    } else {
                        $status = User::delete($id);
                        if ($status) {
                            $success = true;
                        } else {
                            $error_message = "User could not be deleted.";
                        }
                    }
                } else {
                    $error_message = "User not found.";
                }

            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    private static function hasMembershipInStudy(User $target, ?string $studyUuid): bool {
        if (is_null($studyUuid))
            return false;
        foreach ($target->getMemberships() as $membership) {
            if ($membership['study_uuid'] === $studyUuid)
                return true;
        }
        return false;
    }
}
