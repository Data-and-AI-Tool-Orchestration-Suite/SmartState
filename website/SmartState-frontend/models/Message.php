<?php
require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';
include_once MODELS_DIR . 'Participant.php';
include_once MODELS_DIR . 'Study.php';

use Twilio\Rest\Client;

class Message {
    protected ?string $uuid;
    protected ?string $participant_uuid;
    protected ?string $timestamp;
    protected ?string $direction;
    protected ?string $json;
    protected ?string $study_uuid;

    const OUTGOING = 'outgoing';
    const INCOMING = 'incoming';

    private const SORTABLE_COLUMNS = [
        '0' => 'ts',
        '2' => 'ts',
        'ts' => 'ts',
        'message_uuid' => 'message_uuid',
        'participant_uuid' => 'participant_uuid',
        'message_direction' => 'message_direction'
    ];
    private const DEFAULT_SORT = 'ts';
    private const NOT_HIDDEN = "message_json->>'hidden' IS NULL";

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->participant_uuid = null;
        $this->timestamp = null;
        $this->direction = null;
        $this->json = null;
        $this->study_uuid = null;
    }

    public static function create($participant_uuid, $timestamp, $direction, $json, $study_uuid): ?Message {
        if (empty($participant_uuid) || empty($timestamp) || empty($direction) || empty($json) || empty($study_uuid))
            throw new Exception('You must provide participant_uuid, timestamp, direction, JSON, and study_uuid to create a message');
        $instance = new self();
        $instance->setParticipant($participant_uuid);
        $instance->setTimestamp($timestamp);
        $instance->setDirection($direction);
        $instance->setJSON($json);
        $instance->setStudyUuid($study_uuid);
        return $instance->save();
    }

    public static function delete($id): bool {
        if (empty($id) || is_null($id))
            throw new Exception('ID for message not found.');
        $instance = new self();
        $status = $instance->deleteFromID($id);
        return $status;
    }

    public static function update($id, $participant_uuid, $timestamp, $direction, $json, $study_uuid): Message {
        if (empty($id))
            throw new Exception('You must provide an id to update a message');
        $instance = Message::withID($id);
        if (is_null($instance))
            throw new Exception("No message found with id [{$id}]");
        $instance->setParticipant($participant_uuid);
        $instance->setTimestamp($timestamp);
        $instance->setDirection($direction);
        $instance->setJSON($json);
        $instance->setStudyUuid($study_uuid);
        return $instance->save();
    }

    public static function all(): array {
        $messages = [];
        $query = "SELECT * FROM messages";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            array_push($messages, Message::withRow($row));
        return $messages;
    }

    public static function countForDatatable(string $study_uuid): int {
        $stmt = PostgresDB::run("SELECT count(message_uuid) FROM messages WHERE study_uuid = :study_uuid AND " . self::NOT_HIDDEN, ['study_uuid' => $study_uuid]);
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, string $study_uuid): int {
        $query = "SELECT count(message_uuid) FROM messages WHERE study_uuid = :study_uuid AND " . self::NOT_HIDDEN;
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (ts::text LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->bindParam('study_uuid', $study_uuid);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study_uuid): array {
        $matches = [];

        $query = "SELECT * FROM messages WHERE study_uuid = :study_uuid AND " . self::NOT_HIDDEN;
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET " . (int) $start . " ROWS FETCH NEXT " . (int) $length . " ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (ts::text LIKE :filter)";
        }

        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindParam('study_uuid', $study_uuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = Message::withRow($row);
        return $matches;
    }

    public static function countIndividualForDatatable(string $uuid): int {
        $stmt = PostgresDB::run("SELECT count(message_uuid) FROM messages WHERE participant_uuid = :uuid AND " . self::NOT_HIDDEN, ['uuid' => $uuid]);
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countIndividualFilteredForDatatable(string $uuid, string $filter): int {
        $query = "SELECT count(message_uuid) FROM messages WHERE (participant_uuid = :uuid) AND " . self::NOT_HIDDEN;
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (ts::text LIKE :filter)";
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

    public static function listIndividualForDatatable(string $uuid, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        $query = "SELECT * FROM messages";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET " . (int) $start . " ROWS FETCH NEXT " . (int) $length . " ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';

        $query .= " WHERE (participant_uuid = :uuid) AND " . self::NOT_HIDDEN;
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (message_json->>'Body' LIKE :filter)";
        }
        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindValue('uuid', $uuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = Message::withRow($row);
        return $matches;
    }

    public static function withID( $id ): ?Message {
        try {
            $instance = new self();
            $instance->loadByID($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function allForCSV(string $study_uuid, bool $redact = false): array {
        $messages = [];
        $messages[] = [
            'Message UUID',
            'Participant UUID',
            'Participant Name',
            'Timestamp',
            'Direction',
            'Body',
            'To/From'
        ];
        $query = "SELECT
                        m.message_uuid,
                        p.participant_uuid,
                        m.ts,
                        m.message_direction,
                        (m.message_json::jsonb ->> 'Body') AS body,
                        (p.participant_json::jsonb ->> 'number') AS number,
                        ((p.participant_json::jsonb ->> 'first_name') || ' ' || (p.participant_json::jsonb ->> 'last_name')) AS participant_name
                    FROM messages AS m
                    INNER JOIN participants AS p ON p.participant_uuid = m.participant_uuid
                    WHERE m.study_uuid = ?
                        AND (m.message_json->>'hidden') IS NULL
                        AND (p.participant_json->>'hidden') IS NULL";
        $stmt = PostgresDB::run($query, [$study_uuid]);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $messages[] = Message::fillCSVRow($row, $redact);
        return $messages;
    }

    public static function allForCSVParticipant(string $participant_uuid, string $study_uuid, bool $isTre, bool $redact = false): array {
        $messages = [];
        $header = [
            'Message UUID',
            'Participant UUID',
            'Participant Name',
            'Timestamp',
            'Timezone',
            'Direction'
        ];
        if ($isTre) {
            $header[] = 'Eating Window Duration';
            $header[] = 'Success Rate';
        }
        $header[] = 'Body';
        $header[] = 'To/From';
        $messages[] = $header;

        $query = "SELECT
                        m.message_uuid,
                        p.participant_uuid,
                        m.ts,
                        m.message_direction,
                        (m.message_json::jsonb ->> 'Body') AS body,
                        (p.participant_json::jsonb ->> 'number') AS number,
                        (p.participant_json::jsonb ->> 'time_zone') AS tz,
                        ((p.participant_json::jsonb ->> 'first_name') || ' ' || (p.participant_json::jsonb ->> 'last_name')) AS participant_name,
                        sl.eating_window_duration,
                        sl.successful_tre,
                        sl.total_tre
                    FROM messages AS m
                    INNER JOIN participants AS p ON p.participant_uuid = m.participant_uuid
                    LEFT JOIN LATERAL (
                        SELECT
                            (s.log_json->>'eating_window_duration') AS eating_window_duration,
                            (s.log_json->>'successful_TRE') AS successful_tre,
                            (s.log_json->>'total_TRE') AS total_tre
                        FROM state_log s
                        WHERE s.participant_uuid = m.participant_uuid
                            AND s.study_uuid = m.study_uuid
                            AND s.log_json->>'state' = 'startcal'
                            AND ABS(EXTRACT(EPOCH FROM (s.ts - m.ts))) < 43200
                        ORDER BY ABS(EXTRACT(EPOCH FROM (s.ts - m.ts))) ASC
                        LIMIT 1
                    ) AS sl ON TRUE
                    WHERE m.participant_uuid = ?
                        AND m.study_uuid = ?
                        AND (m.message_json->>'hidden') IS NULL
                    ORDER BY m.ts DESC";
        $stmt = PostgresDB::run($query, [$participant_uuid, $study_uuid]);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $messages[] = Message::fillParticipantCSVRow($row, $isTre, $redact);
        return $messages;
    }

    public static function fillCSVRow($row, bool $redact = false): array {
        return [
            $row['message_uuid'],
            $row['participant_uuid'],
            $redact ? '[redacted]' : $row['participant_name'],
            date('m/d/Y, H:i:s', strtotime($row['ts'])),
            $row['message_direction'],
            $redact ? '[redacted]' : $row['body'],
            $redact ? '[redacted]' : $row['number']
        ];
    }

    public static function fillParticipantCSVRow($row, bool $isTre, bool $redact = false): array {
        $formatted_date = date('m/d/Y, H:i:s', strtotime($row['ts']));
        try {
            if (!empty($row['tz'])) {
                $date = new DateTime($row['ts'], new DateTimeZone('UTC'));
                $date = $date->setTimezone(new DateTimeZone($row['tz']));
                $formatted_date = $date->format('m/d/Y, H:i:s');
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
        }

        $csvRow = [
            $row['message_uuid'],
            $row['participant_uuid'],
            $redact ? '[redacted]' : $row['participant_name'],
            $formatted_date,
            $row['tz'],
            $row['message_direction']
        ];
        if ($isTre) {
            $duration = '';
            if (isset($row['eating_window_duration']) && is_numeric($row['eating_window_duration']))
                $duration = round($row['eating_window_duration'] / 3600, 2);
            $successRate = '';
            if (isset($row['successful_tre']) && isset($row['total_tre']) && $row['total_tre'] > 0) {
                $percent = round(($row['successful_tre'] / $row['total_tre']) * 100);
                $successRate = $percent . "% (" . $row['successful_tre'] . "/" . $row['total_tre'] . ")";
            } elseif (isset($row['successful_tre'])) {
                $successRate = $row['successful_tre'];
            }
            $csvRow[] = $duration;
            $csvRow[] = $successRate;
        }
        $csvRow[] = $redact ? '[redacted]' : $row['body'];
        $csvRow[] = $redact ? '[redacted]' : $row['number'];
        return $csvRow;
    }

    public static function hideForParticipant(string $participant_uuid, string $study_uuid): void {
        PostgresDB::run(
            "UPDATE messages SET message_json = jsonb_set(message_json, '{hidden}', '\"true\"'::jsonb) WHERE participant_uuid = :uuid AND study_uuid = :study_uuid",
            ['uuid' => $participant_uuid, 'study_uuid' => $study_uuid]
        );
    }

    public static function unhideForParticipant(string $participant_uuid, string $study_uuid): void {
        PostgresDB::run(
            "UPDATE messages SET message_json = message_json - 'hidden' WHERE participant_uuid = :uuid AND study_uuid = :study_uuid",
            ['uuid' => $participant_uuid, 'study_uuid' => $study_uuid]
        );
    }

    public static function send($messageObj, $participantNumber): ?bool {
        global $CONFIG;
        try {
            if(is_null($messageObj) || is_null($participantNumber))
                throw new Exception('You must provide a message object and a participant number to send a message');
            $sid = $CONFIG['twilio']['account_sid'];
            $token = $CONFIG['twilio']['auth_token'];
            $fromNumber = null;
            $study = Study::withUUID($messageObj->getStudyUuid());
            if (!is_null($study))
                $fromNumber = $study->getTwilioFromNumber();
            if (is_null($fromNumber) || strlen($fromNumber) === 0)
                $fromNumber = $CONFIG['twilio']['from_number'];
            $participantNumber = Participant::getPhoneNumber($messageObj->getParticipant());
            $message = $messageObj->getMessage();
            $client = new Client($sid, $token);

            $response = $client->messages->create(
                $participantNumber,
                [
                    'from' => $fromNumber,
                    'body' => $message
                ]
            );

            $messageObj->save();
            return true;

        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public static function withRow( $row ): Message {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    protected function loadByID( $id ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM messages WHERE message_uuid = :id ORDER BY message_uuid OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Message with id [{$id}] not found");
    }

    protected function fill( $row ): void {
        $this->uuid = $row['message_uuid'];
        $this->participant_uuid = $row['participant_uuid'];
        $this->timestamp = $row['ts'];
        $this->direction = $row['message_direction'];
        $this->json = $row['message_json'];
        $this->study_uuid = $row['study_uuid'];
    }

    protected function save(): ?Message {
        $exists = Message::withID($this->getID());
        if (is_null($exists)) {
            $insert = "INSERT INTO messages (message_uuid, participant_uuid, ts, message_direction, message_json, study_uuid) VALUES (?,?,?,?,?,?)";
            PostgresDB::run($insert, [$this->getID(), $this->getParticipant(), $this->getTimestamp(), $this->getDirection(), $this->getJSON(), $this->getStudyUuid()]);
        } else {
            $update = "UPDATE messages SET participant_uuid=?, ts=?, message_direction=?, message_json=?, study_uuid=? WHERE message_uuid=?";
            PostgresDB::run($update, [$this->getParticipant(), $this->getTimestamp(), $this->getDirection(), $this->getJSON(), $this->getStudyUuid(), $this->getID()]);
        }
        return Message::withID($this->getID());
    }

    protected function getMessage(): string {
        $json = json_decode(Message::getJSON(), true);
        $body = $json['Body'];
        return $body;
    }

    protected function deleteFromID( $id ): bool {
        $stmt = PostgresDB::prepare("DELETE FROM messages WHERE message_uuid = :id");
        $stmt->bindParam('id', $id);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    public function getID(): string {
        return $this->uuid;
    }

    public function getParticipant(): string {
        return $this->participant_uuid;
    }

    public function getTimestamp(): string {
        return $this->timestamp;
    }

    public function getDirection(): string {
        return $this->direction;
    }

    public function getJSON(): string {
        return $this->json;
    }

    public function getStudyUuid(): string {
        return $this->study_uuid;
    }

    public function setParticipant(string $participant_uuid): void {
        $this->participant_uuid = $participant_uuid;
    }

    public function setTimestamp(string $timestamp): void {
        $this->timestamp = $timestamp;
    }

    public function setDirection(string $direction): void {
        $this->direction = $direction;
    }

    public function setJSON(string $json): void {
        $this->json = $json;
    }

    public function setStudyUuid(string $study_uuid): void {
        $this->study_uuid = $study_uuid;
    }

    public function jsonSerialize(): array {
        return [
            'uuid'              => $this->getID(),
            'participant_uuid'  => $this->getParticipant(),
            'ts'                => $this->getTimestamp(),
            'direction'         => $this->getDirection(),
            'study_uuid'        => $this->getStudyUuid(),
            'json'              => $this->getJSON()
        ];
    }
}


class ScheduledMessage {
    protected ?string $uuid;
    protected ?string $participant_uuid;
    protected ?string $toNumber;
    protected ?string $fromNumber;
    protected ?string $scheduledFor;
    protected ?string $messageJson;
    protected ?string $study_uuid;

    private const SORTABLE_COLUMNS = [
        '0' => 'scheduledFor',
        'scheduledFor' => 'scheduledFor',
        'message_uuid' => 'message_uuid',
        'participant_uuid' => 'participant_uuid',
        'toNumber' => 'toNumber',
        'fromNumber' => 'fromNumber'
    ];
    private const DEFAULT_SORT = 'scheduledFor';

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->participant_uuid = null;
        $this->toNumber = null;
        $this->fromNumber = null;
        $this->scheduledFor = null;
        $this->messageJson = null;
        $this->study_uuid = null;
    }

    public static function create($participant_uuid, $scheduledFor, $messageJson, $toNumber, $fromNumber, $study_uuid): ?ScheduledMessage {
        if (empty($participant_uuid) || empty($scheduledFor) || empty($messageJson) || empty($toNumber) || empty($fromNumber) || empty($study_uuid))
            throw new Exception('You are missing parameters for creating a scheduled message object.');
        $instance = new self();
        $instance->setParticipantUUID($participant_uuid);
        $instance->setScheduledFor($scheduledFor);
        $instance->setMessageJson($messageJson);
        $instance->setToNumber($toNumber);
        $instance->setFromNumber($fromNumber);
        $instance->setStudyUuid($study_uuid);

        return $instance->save();
    }

    protected function save(): ?ScheduledMessage {
        $exists = ScheduledMessage::withID($this->getMessageUUID());
        if (is_null($this->getFromNumber())){
            return null;
        }
        if (is_null($exists)) {
            $insert = "INSERT INTO queued_messages (message_uuid, participant_uuid, toNumber, fromNumber, scheduledFor, message_json, study_uuid) VALUES (?,?,?,?,?,?,?)";
            PostgresDB::run($insert, [$this->getMessageUUID(), $this->getParticipantUUID(), $this->getToNumber(), $this->getFromNumber(), $this->getScheduledFor(), $this->getMessageJson(), $this->getStudyUuid()]);
        } else {
            $update = "UPDATE queued_messages SET participant_uuid=?, toNumber=?, fromNumber=?, scheduledFor=?, message_json=?, study_uuid=? WHERE message_uuid=?";
            PostgresDB::run($update, [$this->getParticipantUUID(), $this->getToNumber(), $this->getFromNumber(), $this->getScheduledFor(), $this->getMessageJson(), $this->getStudyUuid(), $this->getMessageUUID()]);
        }
        return ScheduledMessage::withID($this->getMessageUUID());
    }

    public static function withID( $uuid ): ?ScheduledMessage {
        try {
            $instance = new self();
            $instance->loadByUUID($uuid);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function loadByUUID( $uuid ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM queued_messages WHERE message_uuid = :uuid",
            ['uuid' => $uuid]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Scheduled message with uuid [{$uuid}] not found");
    }

    protected function fill( $row ): void {
        $this->uuid = $row['message_uuid'];
        $this->participant_uuid = $row['participant_uuid'];
        $this->toNumber = $row['tonumber'] ?? $row['toNumber'];
        $this->fromNumber = $row['fromnumber'] ?? $row['fromNumber'];
        $this->scheduledFor = $row['scheduledfor'] ?? $row['scheduledFor'];
        $this->messageJson = $row['message_json'];
        $this->study_uuid = $row['study_uuid'];
    }

    public static function countForDatatable(string $study_uuid): int {
        $stmt = PostgresDB::run("SELECT count(message_uuid) FROM queued_messages WHERE study_uuid = :study_uuid", ['study_uuid' => $study_uuid]);
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, string $study_uuid): int {
        $query = "SELECT count(message_uuid) FROM queued_messages WHERE study_uuid = :study_uuid";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (scheduledFor::text LIKE :filter OR message_json::text LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->bindParam('study_uuid', $study_uuid);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study_uuid): array {
        $matches = [];

        $query = "SELECT * FROM queued_messages WHERE study_uuid = :study_uuid";

        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET " . (int) $start . " ROWS FETCH NEXT " . (int) $length . " ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (scheduledFor::text LIKE :filter OR message_json::text LIKE :filter)";
        }
        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->bindParam('study_uuid', $study_uuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = ScheduledMessage::withRow($row);
        return $matches;
    }

    public static function withRow( $row ): ScheduledMessage {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function deleteMessage(string $uuid, string $study_uuid): bool {
        $stmt = PostgresDB::prepare("DELETE FROM queued_messages WHERE message_uuid = :uuid AND study_uuid = :study_uuid");
        $stmt->bindParam('uuid', $uuid);
        $stmt->bindParam('study_uuid', $study_uuid);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    public function getMessageUUID(): string {
        return $this->uuid;
    }

    public function getParticipantUUID(): string {
        return $this->participant_uuid;
    }

    public function getToNumber(): string {
        return $this->toNumber;
    }

    public function getFromNumber(): ?string {
        return $this->fromNumber;
    }

    public function getMessageJson(): string {
        return $this->messageJson;
    }

    public function getScheduledFor(): string {
        return $this->scheduledFor;
    }

    public function getStudyUuid(): string {
        return $this->study_uuid;
    }

    public function setParticipantUUID(string $participant_uuid): void {
        $this->participant_uuid = $participant_uuid;
    }

    public function setToNumber(string $toNumber): void {
        $this->toNumber = $toNumber;
    }

    public function setFromNumber(?string $fromNumber): void {
        $this->fromNumber = $fromNumber;
    }

    public function setMessageJson(string $messageJson): void {
        $this->messageJson = $messageJson;
    }

    public function setScheduledFor(string $scheduledFor): void {
        $this->scheduledFor = $scheduledFor;
    }

    public function setStudyUuid(string $study_uuid): void {
        $this->study_uuid = $study_uuid;
    }

    public function jsonSerialize(): array {
        return [
            'message_uuid'      => $this->getMessageUUID(),
            'participant_uuid'  => $this->getParticipantUUID(),
            'to_number'         => $this->getToNumber(),
            'from_number'       => $this->getFromNumber(),
            'message_json'      => $this->getMessageJson(),
            'scheduled_for'     => $this->getScheduledFor(),
            'study_uuid'        => $this->getStudyUuid()
        ];
    }

}
