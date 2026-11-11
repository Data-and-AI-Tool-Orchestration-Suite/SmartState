<?php
require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class Participant {
    private const SORTABLE_COLUMNS = [
        '0' => "participant_json->>'first_name'",
        '1' => "participant_json->>'number'",
        '2' => "participant_json->>'email'",
        '4' => "participant_json->>'devEUI'"
    ];
    private const DEFAULT_SORT = "participant_json->>'first_name'";
    private const STATE_SORTABLE_COLUMNS = [
        '0' => 'ts',
        '1' => 'log_json'
    ];
    private const STATE_DEFAULT_SORT = 'ts';
    private const NOT_STAFF = "COALESCE(participant_json->>'staff', '') != 'true'";
    private const NOT_HIDDEN = "participant_json->>'hidden' IS NULL";
    private const IS_HIDDEN = "participant_json->>'hidden' IS NOT NULL";

    protected $uuid;
    protected $json;
    protected $studyUuid;
    protected $protocols;

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->json = null;
        $this->studyUuid = null;
        $this->protocols = null;
    }

    public static function create($json, $studyUuid, array $definitionUuids): ?Participant {
        if (empty($json) || !isset($json))
            throw new Exception('You must provide a JSON object to create a participant');
        if (empty($studyUuid) || !isset($studyUuid))
            throw new Exception('Participant must belong to a study');
        $instance = new self();
        $instance->setJSON($json);
        $instance->setStudyUuid($studyUuid);
        $participant = $instance->save();

        if (is_null($participant))
            return null;
        foreach ($definitionUuids as $definitionUuid)
            $participant->addEnrollment($definitionUuid);
        return $participant;
    }

    public static function update($uuid, $json, $studyUuid, array $definitionUuids): Participant {
        if (empty($uuid))
            throw new Exception('You must provide an id to update a participant');
        if (empty($studyUuid))
            throw new Exception('Participant must belong to a study');
        $instance = Participant::withID($uuid);
        if (is_null($instance))
            throw new Exception("No participant found with id [{$uuid}]");
        if ($instance->getStudyUuid() !== $studyUuid)
            throw new Exception("No participant found with id [{$uuid}] in the current study");
        $instance->setJSON($json);
        $instance->syncEnrollments($definitionUuids);
        return $instance->save();
    }

    public static function delete($uuid, $studyUuid): bool {
        if (empty($uuid) || is_null($uuid))
            throw new Exception('ID for participant not found.');
        if (empty($studyUuid))
            throw new Exception('Participant must belong to a study');
        $instance = Participant::withID($uuid);
        if (is_null($instance))
            throw new Exception("No participant found with id [{$uuid}]");
        if ($instance->getStudyUuid() !== $studyUuid)
            throw new Exception("No participant found with id [{$uuid}] in the current study");
        $instance->endAllActiveEnrollments('deleted');
        return $instance->deleteFromID($uuid);
    }

    public static function hide($uuid, $studyUuid): bool {
        if (empty($uuid) || is_null($uuid))
            throw new Exception('ID for participant not found.');
        if (empty($studyUuid))
            throw new Exception('Participant must belong to a study');
        $instance = Participant::withID($uuid);
        if (is_null($instance))
            throw new Exception("No participant found with id [{$uuid}]");
        if ($instance->getStudyUuid() !== $studyUuid)
            throw new Exception("No participant found with id [{$uuid}] in the current study");
        Message::hideForParticipant($uuid, $studyUuid);
        return $instance->hideFromID();
    }

    public static function unhide($uuid, $studyUuid): bool {
        if (empty($uuid) || is_null($uuid))
            throw new Exception('ID for participant not found.');
        if (empty($studyUuid))
            throw new Exception('Participant must belong to a study');
        $instance = Participant::withID($uuid);
        if (is_null($instance))
            throw new Exception("No participant found with id [{$uuid}]");
        if ($instance->getStudyUuid() !== $studyUuid)
            throw new Exception("No participant found with id [{$uuid}] in the current study");
        Message::unhideForParticipant($uuid, $studyUuid);
        return $instance->unhideFromID();
    }

    public static function getSuccessRate(string $uuid, string $studyUuid): array {
        $stmt = PostgresDB::run(
            "SELECT log_json->>'successful_TRE' AS success, log_json->>'after8pm' AS after8pm, log_json->>'total_TRE' AS total
            FROM state_log
            WHERE participant_uuid = :uuid AND study_uuid = :study_uuid
                AND log_json->>'state' = 'startcal' AND jsonb_exists(log_json, 'total_TRE')
            ORDER BY ts DESC LIMIT 1",
            ['uuid' => $uuid, 'study_uuid' => $studyUuid]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row == null || is_null($row['success']) || is_null($row['total']))
            return array();
        return array(
            'success'  => $row['success'],
            'after8pm' => is_null($row['after8pm']) ? 0 : $row['after8pm'],
            'total'    => $row['total']
        );
    }

    public static function updateSuccessRate(string $uuid, int $successful, int $total, int $after8pm, string $studyUuid): void {
        PostgresDB::run(
            "UPDATE state_log
            SET log_json = jsonb_set(jsonb_set(jsonb_set(log_json,
                '{successful_TRE}', to_jsonb(CAST(:successful AS INT))),
                '{total_TRE}', to_jsonb(CAST(:total AS INT))),
                '{after8pm}', to_jsonb(CAST(:after8pm AS INT)))
            WHERE participant_uuid = :uuid AND study_uuid = :study_uuid
                AND log_json->>'state' = 'startcal' AND jsonb_exists(log_json, 'total_TRE')
                AND ts = (SELECT ts FROM state_log
                    WHERE participant_uuid = :uuid_inner AND study_uuid = :study_uuid_inner
                        AND log_json->>'state' = 'startcal' AND jsonb_exists(log_json, 'total_TRE')
                    ORDER BY ts DESC LIMIT 1)",
            [
                'successful' => $successful,
                'total' => $total,
                'after8pm' => $after8pm,
                'uuid' => $uuid,
                'study_uuid' => $studyUuid,
                'uuid_inner' => $uuid,
                'study_uuid_inner' => $studyUuid
            ]
        );
    }

    public static function hasTREEnrollment(string $uuid, string $studyUuid): bool {
        $stmt = PostgresDB::run(
            "SELECT 1 FROM enrollments WHERE participant_uuid = :uuid AND study_uuid = :study_uuid AND protocol_name ILIKE '%TRE%' LIMIT 1",
            ['uuid' => $uuid, 'study_uuid' => $studyUuid]
        );
        return $stmt->fetchColumn() !== false;
    }

    public static function belongsToStudy(string $uuid, string $studyUuid): bool {
        try {
            $stmt = PostgresDB::run(
                "SELECT 1 FROM participants WHERE participant_uuid = :uuid AND study_uuid = :study_uuid",
                ['uuid' => $uuid, 'study_uuid' => $studyUuid]
            );
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $studyUuid, bool $showHidden = false): array {
        $matches = [];
        $query = "SELECT participants.*,
            (SELECT string_agg(e.protocol_name || ' v' || pd.version, ', ')
             FROM enrollments e
             JOIN protocol_definitions pd ON pd.definition_uuid = e.definition_uuid
             WHERE e.participant_uuid = participants.participant_uuid AND e.ended_at IS NULL) AS protocols
            FROM participants WHERE study_uuid = :study_uuid AND " . self::NOT_STAFF . " AND " . ($showHidden ? self::IS_HIDDEN : self::NOT_HIDDEN);
        $prune = '';
        $start = (int) $start;
        $length = (int) $length;
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        if (strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (participant_json::text ILIKE :filter)";
        }
        $query .= " ORDER BY LOWER({$order_by}) {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindValue('study_uuid', $studyUuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = Participant::withRow($row);
        return $matches;
    }

    public static function listStateForDatatable(string $uuid, string $protocol, int $start, int $length, string $order_by, string $order_dir, string $filter, string $studyUuid): array {
        $matches = [];
        $query = "SELECT ts, log_json FROM state_log";
        $prune = '';
        $start = (int) $start;
        $length = (int) $length;
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        $order_by = self::STATE_SORTABLE_COLUMNS[$order_by] ?? self::STATE_DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';

        $query .= " WHERE (participant_uuid = :uuid) AND (study_uuid = :study_uuid) AND (log_json->>'protocol' = :protocol)";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (log_json::text ILIKE :filter)";
        }
        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindValue('uuid', $uuid);
        $stmt->bindValue('study_uuid', $studyUuid);
        $stmt->bindValue('protocol', $protocol);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = ['ts' => $row['ts'], 'json' => $row['log_json']];
        return $matches;
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

    public static function getParticipantStats(string $studyUuid): array {
        $matches = array();
        $totalQuery = PostgresDB::run("SELECT COUNT(participant_uuid) AS total FROM participants WHERE study_uuid = :study_uuid AND " . self::NOT_STAFF . " AND " . self::NOT_HIDDEN,
            ['study_uuid' => $studyUuid]
        );
        if ($row = $totalQuery->fetch(PDO::FETCH_LAZY))
            $matches['total'] = $row['total'];
        else
            $matches['total'] = 'N/a';

        $activeQuery = PostgresDB::run("SELECT COUNT(DISTINCT participant_uuid) AS active FROM enrollments WHERE study_uuid = :study_uuid AND ended_at IS NULL",
            ['study_uuid' => $studyUuid]
        );
        if ($row = $activeQuery->fetch(PDO::FETCH_LAZY))
            $matches['active'] = $row['active'];
        else
            $matches['active'] = 'N/a';

        $devicesQuery = PostgresDB::run("SELECT COUNT(participant_uuid) AS devices FROM participants WHERE study_uuid = :study_uuid AND participant_json->>'devEUI' != '' AND " . self::NOT_STAFF . " AND " . self::NOT_HIDDEN,
            ['study_uuid' => $studyUuid]
        );
        if ($row = $devicesQuery->fetch(PDO::FETCH_LAZY))
            $matches['devices'] = $row['devices'];
        else
            $matches['devices'] = 'N/a';

        return $matches;
    }

    public static function allEnrollments(string $uuid): array {
        $enrollments = [];
        $query = "SELECT e.enrollment_uuid, e.definition_uuid, e.protocol_name, pd.version,
            CASE WHEN e.ended_at IS NULL THEN 1 ELSE 0 END AS active
            FROM enrollments e
            JOIN protocol_definitions pd ON pd.definition_uuid = e.definition_uuid
            WHERE e.participant_uuid = :uuid
            ORDER BY CASE WHEN e.ended_at IS NULL THEN 0 ELSE 1 END ASC, e.enrolled_at DESC";
        $stmt = PostgresDB::run($query, ['uuid' => $uuid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $enrollments[] = [
                'enrollment_uuid' => $row['enrollment_uuid'],
                'definition_uuid' => $row['definition_uuid'],
                'protocol_name'   => $row['protocol_name'],
                'version'         => (int) $row['version'],
                'active'          => ((int) $row['active']) === 1
            ];
        }
        return $enrollments;
    }

    public static function all(string $studyUuid): array {
        $participants = [];
        $query = "SELECT * FROM participants WHERE study_uuid = :study_uuid AND " . self::NOT_STAFF . " AND " . self::NOT_HIDDEN . " ORDER BY LOWER(participant_json->>'first_name') ASC, LOWER(participant_json->>'last_name') ASC";
        $stmt = PostgresDB::run($query, ['study_uuid' => $studyUuid]);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $participants[] = Participant::withRow($row);
        return $participants;
    }

    public static function allLocations(): array {
        $locations = [];
        $query = "SELECT DISTINCT short_zone FROM time_zones ORDER BY short_zone ASC";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $locations[] = ['location' => $row['short_zone']];
        return $locations;
    }

    public static function countForDatatable(string $studyUuid, bool $showHidden = false): int {
        $stmt = PostgresDB::run("SELECT count(participant_uuid) FROM participants WHERE study_uuid = :study_uuid AND " . self::NOT_STAFF . " AND " . ($showHidden ? self::IS_HIDDEN : self::NOT_HIDDEN),
            ['study_uuid' => $studyUuid]
        );
        return $stmt->fetchColumn();
    }

    public static function countFilteredForDatatable(string $filter, string $studyUuid, bool $showHidden = false): int {
        $query = "SELECT count(participant_uuid) FROM participants WHERE study_uuid = :study_uuid AND " . self::NOT_STAFF . " AND " . ($showHidden ? self::IS_HIDDEN : self::NOT_HIDDEN);
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (participant_json::text ILIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        $stmt->bindValue('study_uuid', $studyUuid);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countForStateDatatable(string $uuid, string $studyUuid): int {
        $stmt = PostgresDB::run("SELECT count(participant_uuid) FROM state_log WHERE participant_uuid = :uuid AND study_uuid = :study_uuid",
            ['uuid' => $uuid, 'study_uuid' => $studyUuid]
        );
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForStateDatatable(string $uuid, string $filter, string $studyUuid): int {
        $query = "SELECT count(participant_uuid) FROM state_log WHERE participant_uuid = :uuid AND study_uuid = :study_uuid";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (log_json::text ILIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('uuid', $uuid);
        $stmt->bindValue('study_uuid', $studyUuid);
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

    public static function getCurrentStateString($uuid, $protocol, $studyUuid): ?string {
        $stmt = PostgresDB::run("SELECT log_json->>'state' AS state FROM state_log WHERE participant_uuid = :uuid AND study_uuid = :study_uuid AND log_json->>'protocol' = :protocol ORDER BY ts DESC LIMIT 1",
            ['uuid' => $uuid, 'study_uuid' => $studyUuid, 'protocol' => $protocol]
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

    protected function addEnrollment(string $definitionUuid): void {
        $stmt = PostgresDB::run("SELECT name FROM protocol_definitions WHERE definition_uuid = :definition_uuid AND study_uuid = :study_uuid AND status = 'published'",
            ['definition_uuid' => $definitionUuid, 'study_uuid' => $this->studyUuid]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row == null)
            throw new Exception("No published protocol definition found with id [{$definitionUuid}] in the current study");
        PostgresDB::run("INSERT INTO enrollments (participant_uuid, definition_uuid, study_uuid, protocol_name) VALUES (?,?,?,?)",
            [$this->uuid, $definitionUuid, $this->studyUuid, $row['name']]
        );
    }

    protected function syncEnrollments(array $definitionUuids): void {
        $active = [];
        $stmt = PostgresDB::run("SELECT enrollment_uuid, definition_uuid FROM enrollments WHERE participant_uuid = :uuid AND ended_at IS NULL",
            ['uuid' => $this->uuid]
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $active[$row['definition_uuid']] = $row['enrollment_uuid'];

        foreach ($active as $definitionUuid => $enrollmentUuid) {
            if (!in_array($definitionUuid, $definitionUuids))
                $this->endEnrollment($enrollmentUuid, 'unenrolled');
        }
        foreach ($definitionUuids as $definitionUuid) {
            if (!array_key_exists($definitionUuid, $active))
                $this->addEnrollment($definitionUuid);
        }
    }

    protected function endEnrollment(string $enrollmentUuid, string $reason): void {
        PostgresDB::run("UPDATE enrollments SET ended_at = (NOW() AT TIME ZONE 'utc'), end_reason = :reason WHERE enrollment_uuid = :uuid AND ended_at IS NULL",
            ['reason' => $reason, 'uuid' => $enrollmentUuid]
        );
    }

    protected function endAllActiveEnrollments(string $reason): void {
        PostgresDB::run("UPDATE enrollments SET ended_at = (NOW() AT TIME ZONE 'utc'), end_reason = :reason WHERE participant_uuid = :uuid AND ended_at IS NULL",
            ['reason' => $reason, 'uuid' => $this->uuid]
        );
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
        $this->studyUuid = $row['study_uuid'];
        if (isset($row['protocols']))
            $this->protocols = $row['protocols'];
    }

    protected function save(): ?Participant {
        $exists = Participant::withID($this->uuid);
        if (is_null($exists)) {
            $insert = "INSERT INTO participants (participant_uuid, study_uuid, participant_json) VALUES (?,?,?)";
            PostgresDB::run($insert, [$this->uuid, $this->studyUuid, $this->json]);
        } else {
            $update = "UPDATE participants SET participant_json=? WHERE participant_uuid=? AND study_uuid=?";
            PostgresDB::run($update, [$this->json, $this->uuid, $this->studyUuid]);
        }
        return Participant::withID($this->uuid);
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

    protected function hideFromID(): bool {
        $stmt = PostgresDB::prepare("UPDATE participants SET participant_json = jsonb_set(participant_json, '{hidden}', '\"true\"'::jsonb) WHERE participant_uuid = :uuid AND study_uuid = :study_uuid");
        $stmt->bindParam('uuid', $this->uuid);
        $stmt->bindParam('study_uuid', $this->studyUuid);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    protected function unhideFromID(): bool {
        $stmt = PostgresDB::prepare("UPDATE participants SET participant_json = participant_json - 'hidden' WHERE participant_uuid = :uuid AND study_uuid = :study_uuid");
        $stmt->bindParam('uuid', $this->uuid);
        $stmt->bindParam('study_uuid', $this->studyUuid);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    public function isHidden(): bool {
        $decoded = json_decode($this->json ?? '', true);
        return is_array($decoded) && isset($decoded['hidden']);
    }

    public function getID(): string {
        return $this->uuid;
    }

    public function getJSON(): string {
        return $this->json;
    }

    public function setJSON(string $json): void {
        $this->json = $json;
    }

    public function getStudyUuid(): ?string {
        return $this->studyUuid;
    }

    public function setStudyUuid(string $studyUuid): void {
        $this->studyUuid = $studyUuid;
    }

    public function getProtocols(): ?string {
        return $this->protocols;
    }

    public function jsonSerialize(): array {
        return [
            'uuid'       => $this->getID(),
            'json'       => $this->getJSON(),
            'study_uuid' => $this->getStudyUuid()
        ];
    }
}
