<?php
require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class Participant {
    protected $uuid;
    protected $json;
    protected $study;

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->json = null;
        $this->study = null;
    }

    public static function create($json, $study): ?Participant {
        if (empty($json) || !isset($json))
            throw new Exception('You must provide a JSON object to create a participant');
        if (empty($study) || !isset($study))
            throw new Exception('Participant must belong to a study');
        $instance = new self();
        $instance->setJSON($json);
        $instance->setStudy($study);
        $participant = $instance->save();

        if(is_null($participant)) {
            return $participant;
        } else {
            if ($study != 'ADMIN') {
                $participant->enroll();
            }
            return $participant;
        }
    }

    public static function createAdmin($json, $study): ?Participant {
        if (empty($json) || !isset($json))
            throw new Exception('You must provide a JSON object to create a participant');
        if (empty($study) || !isset($study))
            throw new Exception('Participant must belong to a study');
        $instance = new self();
        $instance->setJSON($json);
        $instance->setStudy($study);
        $participant = $instance->save();

        return $participant;
    }

    public static function updateAdmin($json, $study): void {
        if (empty($json) || !isset($json))
            throw new Exception('You must provide a JSON object to create a participant');
        if (empty($study) || !isset($study))
            throw new Exception('Participant must belong to a study');
        $instance = new self();
        $instance->setJSON($json);
        $instance->setStudy($study);
        $instance->saveAdmin();
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study): array {
        $matches = [];
        $query = "SELECT * FROM participants WHERE study = :study";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if ($order_by == '' || $order_by == '0')
            $order_by = "participant_json->>'first_name'";
        
        // Sanitize order_by: Allow specific columns only
        $allowed_columns = [
            "participant_json->>'first_name'",
            "participant_json->>'last_name'",
            "study",
            "participant_uuid"
        ];
        if (!in_array($order_by, $allowed_columns)) {
            $order_by = "participant_json->>'first_name'";
        }

        if ($order_dir == '')
            $order_dir = 'desc';
        
        // Sanitize order_dir
        if (!in_array(strtoupper($order_dir), ['ASC', 'DESC'])) {
            $order_dir = 'DESC';
        }

        if (strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (participant_json->>'last_name' LIKE :filter)";
        }
        $query .= " ORDER BY LOWER({$order_by}) {$order_dir}{$prune}";
        error_log($query);
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        if (strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = Participant::withRow($row);
        return $matches;
    }

    public static function listStateForDatatable(string $uuid, string $protocol, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        $query = "SELECT ts, log_json FROM state_log";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'ts';
        
        // Sanitize order_by
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_by)) {
            $order_by = 'ts';
        }

        if (is_null($order_dir) || $order_dir == '')
            $order_dir = 'desc';
        
        // Sanitize order_dir
        if (!in_array(strtoupper($order_dir), ['ASC', 'DESC'])) {
            $order_dir = 'DESC';
        }

        $query .= " WHERE (participant_uuid = :uuid) AND (log_json->>'protocol' = :protocol)";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (log_json LIKE :filter)";
        }
        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindValue('uuid', $uuid);
        $stmt->bindValue('protocol', $protocol);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = ['ts' => $row['ts'], 'json' => $row['log_json']];
        return $matches;
    }

    public static function delete($uuid): bool {
        if (empty($uuid) || is_null($uuid))
            throw new Exception('ID for participant not found.');
        $instance = new self();
        $instance->deleteAllEnrollments();
        $status = $instance->deleteFromID($uuid);
        return $status;
    }

    public static function deleteAdmin($number): bool {
        if (empty($number) || is_null($number))
            throw new Exception('Number for admin not found.');
        $instance = new self();
        $status = $instance->deleteFromNumber($number);
        return $status;
    }

    public static function getName($uuid): ?string {
        try {
            $stmt = PostgresDB::run("SELECT (participant_json->>'first_name') || ' ' || (participant_json->>'last_name') AS name FROM participants WHERE participant_uuid = :uuid",
                ['uuid' => $uuid]
            );
            $row = $stmt->fetch();
            return $row['name'];
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function deleteEnrollment($enrollUUID): void {
        $query = "DELETE FROM enrollments WHERE enrollment_uuid = :uuid";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindValue('uuid', $enrollUUID);
        $stmt->execute();
    }

    public static function update($uuid, $json, $study): Participant {
        if (empty($uuid))
            throw new Exception('You must provide an id to update a participant');
        $instance = Participant::withID($uuid);
        if (is_null($instance))
            throw new Exception("No participant found with id [{$uuid}]");
        $instance->setJSON($json);
        $instance->setStudy($study);
        $instance->updateEnrollment();
        return $instance->save();
    }

    protected function enroll(): void {
        $groups = json_decode($this->json)->group;
        foreach($groups as $group) {
            $query = "INSERT INTO enrollments (enrollment_uuid, participant_uuid, protocol_type_uuid, status) VALUES (gen_random_uuid(), :participant_uuid, :group_uuid, TRUE)";
            $stmt = PostgresDB::prepare($query);
            $stmt->bindValue('participant_uuid', $this->uuid);
            $stmt->bindValue('group_uuid', Participant::getUUIDFromGroup($group));
            $stmt->execute();
        }
    }

    protected function updateEnrollment(): void {
        $enrollments = $this->getEnrollmentUUID();
        $groups = json_decode($this->json)->group;
        $groupsEnrollUUIDs = [];
        foreach($groups as $group) {
            $temp = Participant::getEnrollmentUUIDFromProtocolUUID(Participant::getUUIDFromGroup($group));
            $groupsEnrollUUIDs[] = $temp;
        }

        // removes enrollments that are not in updated group
        foreach($enrollments as $enrollment){
            if (!in_array($enrollment, $groupsEnrollUUIDs)){
                $this->deleteEnrollment($enrollment);
            }
        }

        $enrollments = $this->getEnrollmentUUID(); // update enrollments, if any were deleted

        // inserts new enrollment if it doesn't already exist
        foreach ($groups as $group){
            $newEnrollmentUUID = Participant::getEnrollmentUUIDFromProtocolUUID(Participant::getUUIDFromGroup($group));
            if (!in_array($newEnrollmentUUID, $enrollments)){
                $query = "INSERT INTO enrollments (enrollment_uuid, participant_uuid, protocol_type_uuid, status) VALUES (gen_random_uuid(), :participant_uuid, :group_uuid, TRUE)";
                $stmt = PostgresDB::prepare($query);
                $stmt->bindValue('participant_uuid', $this->uuid);
                $stmt->bindValue('group_uuid', Participant::getUUIDFromGroup($group));
                $stmt->execute();
            }
        }
    }

    public static function getParticipantStats(): array {
        $matches = array();
        $totalQuery = PostgresDB::run("SELECT COUNT(participant_uuid) AS total FROM participants WHERE study != 'ADMIN'");
        if ($row = $totalQuery->fetch(PDO::FETCH_LAZY))
            $matches['total'] = $row['total'];
        else
            $matches['total'] = 'N/a';

        $activeQuery = PostgresDB::run("SELECT COUNT(participant_uuid) AS active FROM enrollments WHERE status = true");
        if ($row = $activeQuery->fetch(PDO::FETCH_LAZY))
            $matches['active'] = $row['active'];
        else
            $matches['active'] = 'N/a';

        $activeQuery = PostgresDB::run("SELECT COUNT(participant_uuid) AS devices FROM participants WHERE participant_json->>'devEUI' != ''");
        if ($row = $activeQuery->fetch(PDO::FETCH_LAZY))
            $matches['devices'] = $row['devices'];
        else
            $matches['devices'] = 'N/a';

        return $matches;
    }

    protected function getEnrollmentUUID(): array {
        $enrollments = [];
        $query = "SELECT enrollment_uuid FROM enrollments WHERE participant_uuid = :participant_uuid";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindValue('participant_uuid', $this->uuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $enrollments[] = $row['enrollment_uuid'];
        return $enrollments;
    }

    protected function deleteAllEnrollments(): void{
        $query = "DELETE FROM enrollments WHERE participant_uuid = :uuid";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindValue('uuid', $this->uuid);
        $stmt->execute();
    }

    public static function all(): array {
        $participants = [];
        $query = "SELECT * FROM participants WHERE study != 'ADMIN' ORDER BY LOWER(participant_json->>'first_name') ASC, LOWER(participant_json->>'last_name') ASC";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $participants[] = Participant::withRow($row);
        return $participants;
    }

    public static function allProtocols(): array {
        $protocols = [];
        $query = "SELECT * FROM protocol_types ORDER BY LOWER(name) ASC";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $protocols[] = ProtocolType::withRow($row);
        return $protocols;
    }

    public static function allLocations(): array {
        $locations = [];
        $query = "SELECT DISTINCT short_zone FROM time_zones ORDER BY short_zone ASC";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $locations[] = ['location' => $row['short_zone']];
        return $locations;
    }

    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT count(participant_uuid) FROM participants");
        return $stmt->fetchColumn();
    }

    public static function countFilteredForDatatable(string $filter): int {
        $query = "SELECT count(participant_uuid) FROM participants";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " WHERE (participant_json LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countForStateDatatable(string $uuid): int {
        $stmt = PostgresDB::run("SELECT count(participant_uuid) FROM state_log WHERE participant_uuid = :uuid", ['uuid' => $uuid]);
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForStateDatatable(string $uuid, string $filter): int {
        $query = "SELECT count(participant_uuid) FROM state_log WHERE participant_uuid = :uuid";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (log_json LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('uuid', $uuid);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }


    public static function getConcatNameFromID($id): string {
        $stmt = PostgresDB::run("SELECT CONCAT_WS(' ', participant_json->>'first_name', participant_json->>'last_name') AS participant_name 
                                FROM participants 
                                WHERE participant_uuid = :id",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        return $row['participant_name'];
    }

    public static function getGroupFromUUID($uuid): string {
        $stmt = PostgresDB::run("SELECT participant_json->>'group' AS group_name FROM participants WHERE participant_uuid = :uuid",
            ['uuid' => $uuid]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        return $row['group_name'];
    }

    public static function getUUIDFromGroup($group): string {
        $stmt = PostgresDB::run("SELECT protocol_type_uuid FROM protocol_types WHERE name = :group",
            ['group' => $group]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        return $row['protocol_type_uuid'];
    }

    public static function getTimeZoneFromUUID($uuid): string {
        $stmt = PostgresDB::run("SELECT participant_json->>'time_zone' AS time_zone FROM participants WHERE participant_uuid = :uuid",
            ['uuid' => $uuid]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        return $row['time_zone'];
    }

    public static function getZonesWithLocation( $location ): array {
        $zones = [];
        $stmt = PostgresDB::run("SELECT time_zone FROM time_zones WHERE short_zone= :location ORDER BY time_zone ASC",
            ['location' => $location]
        );
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            array_push($zones, ['name' => $row['time_zone']]);
        return $zones;
    }

    public static function withID( $uuid ): ?Participant {
        try {
            $instance = new self();
            $instance->loadByID($uuid);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withRow( $row ): Participant {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function getPhoneNumber($uuid): ?string {
        try {
            $stmt = PostgresDB::run("SELECT participant_json->>'number' AS phone_number FROM participants WHERE participant_uuid = :uuid",
                ['uuid' => $uuid]
            );
            $row = $stmt->fetch(PDO::FETCH_LAZY);
            return $row['phone_number'];
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function getEnrollmentUUIDFromProtocolUUID($protocolUUID): ?string {

        try {
            $stmt = PostgresDB::run("SELECT enrollment_uuid FROM enrollments WHERE protocol_type_uuid = :id::uuid",
                ['id' => $protocolUUID]
            );
            $row = $stmt->fetch(PDO::FETCH_LAZY);
            if (empty($row['enrollment_uuid']))
                return null;
            return $row['enrollment_uuid'];
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function getCurrentStateString($uuid, $protocol): ?string {
        $stmt = PostgresDB::run("SELECT log_json->>'state' AS state FROM state_log WHERE participant_uuid = :uuid AND log_json->>'protocol' = :protocol ORDER BY ts DESC LIMIT 1",
            ['uuid' => $uuid, 'protocol' => $protocol]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if (empty($row['state']))
            return null;
        return $row['state'];
    }

    public static function getTimeZone($uuid): ?string {
        try {
            $stmt = PostgresDB::run("SELECT participant_json->>'time_zone' AS time_zone FROM participants WHERE participant_uuid = :uuid",
                ['uuid' => $uuid]
            );
            $row = $stmt->fetch(PDO::FETCH_LAZY);
            return $row['time_zone'];
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function loadByID( $uuid ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM participants WHERE participant_uuid = :id ORDER BY participant_uuid OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['id' => $uuid]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Participant with id [{$uuid}] not found");
    }

    protected function fill( $row ): void {
        $this->uuid = $row['participant_uuid'];
        $this->json = $row['participant_json'];
        $this->study = $row['study'];
    }

    protected function save(): ?Participant {
        $exists = Participant::withID($this->uuid);
        if (is_null($exists)) {
            $insert = "INSERT INTO participants (participant_uuid, study, participant_json) VALUES (?,?,?)";
            PostgresDB::run($insert, [$this->uuid, $this->study, $this->json]);
        } else {
            $update = "UPDATE participants SET participant_json=?, study=? WHERE participant_uuid=?";
            PostgresDB::run($update, [$this->json, $this->study, $this->uuid]);
        }
        return Participant::withID($this->uuid);
    }

    protected function saveAdmin(): void {
        $number = json_decode($this->json)->number;
        $update = "UPDATE participants SET participant_json=?, study=? WHERE participant_json->>'number'=?";
        PostgresDB::run($update, [$this->json, $this->study, $number]);
    }

    protected function deleteFromID( $uuid ): bool {
        $stmt = PostgresDB::prepare("DELETE FROM participants WHERE participant_uuid = :uuid");
        $stmt->bindParam('uuid', $uuid);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    protected function deleteFromNumber( $number ): bool {
        $stmt = PostgresDB::prepare("DELETE FROM participants WHERE participant_json->>'number' = :number");
        $stmt->bindParam('number', $number);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    /**
     * @return string
     */
    public function getID(): string {
        return $this->uuid;
    }

    /**
     * @return string
     */
    public function getJSON(): string {
        return $this->json;
    }

    /**
     * @param string $json
     */
    public function setJSON(string $json): void {
        $this->json = $json;
    }

    /**
     * @return string
     */
    public function getStudy(): string {
        return $this->study;
    }

    /**
     * @param string $study
     */
    public function setStudy(string $study): void {
        $this->study = $study;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'uuid'      => $this->getID(),
            'json'      => $this->getJSON(),
            'study'     => $this->getStudy()
        ];
    }
}