<?php
require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';
include_once MODELS_DIR . 'Participant.php';

use Twilio\Rest\Client;

class Message {
    protected $uuid;
    protected $participant_uuid;
    protected $timestamp;
    protected $direction;
    protected $json;
    protected $study;

    const OUTGOING = 'outgoing';
    const INCOMING = 'incoming';

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->participant_uuid = null;
        $this->timestamp = null;
        $this->direction = null;
        $this->json = null;
        $this->study = null;
    }

    public static function create($participant_uuid, $timestamp, $direction, $json, $study): ?Message {
        if (empty($participant_uuid) || empty($timestamp) || empty($direction) || empty($json))
            throw new Exception('You must provide participant_uuid, timestamp, direction, and JSON to create a message');
        $instance = new self();
        $instance->setParticipant($participant_uuid);
        $instance->setTimestamp($timestamp);
        $instance->setDirection($direction);
        $instance->setJSON($json);
        $instance->setStudy($study);
        return $instance->save();
    }

    public static function delete($id): bool {
        if (empty($id) || is_null($id))
            throw new Exception('ID for message not found.');
        $instance = new self();
        $status = $instance->deleteFromID($id);
        return $status;
    }

    public static function update($id, $participant_uuid, $timestamp, $direction, $json, $study): Message {
        if (empty($id))
            throw new Exception('You must provide an id to update a message');
        $instance = Message::withID($id);
        if (is_null($instance))
            throw new Exception("No message found with id [{$id}]");
            $instance->setParticipant($participant_uuid);
            $instance->setTimestamp($timestamp);
            $instance->setDirection($direction);
            $instance->setJSON($json);
            $instance->setStudy($study);
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

    public static function countForDatatable(string $study): int {
        $query = "SELECT count(message_uuid) FROM messages WHERE (study = :study OR study = 'ADMIN')";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, string $study): int {
        $query = "SELECT count(message_uuid) FROM messages WHERE (study = :study OR study = 'ADMIN')";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (ts LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study): array {
        $matches = [];

        $query = "SELECT * FROM messages WHERE (study = :study OR study = 'ADMIN')";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'ts';
        if ($order_by == '2') {
            $order_by = 'ts';
        }

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

        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (ts LIKE :filter)";
        }

        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $matches[] = Message::withRow($row);
        return $matches;
    }

    public static function countIndividualForDatatable(string $uuid): int {
        $stmt = PostgresDB::run("SELECT count(message_uuid) FROM messages WHERE participant_uuid = :uuid", ['uuid' => $uuid]);
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countIndividualFilteredForDatatable(string $uuid, string $filter): int {
        $query = "SELECT count(message_uuid) FROM messages WHERE (participant_uuid = :uuid)";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (ts LIKE :filter)";
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
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'ts';
        if (is_null($order_dir) || $order_dir == '')
            $order_dir = 'desc';

        if ($order_by == '2') {
            $order_by = 'ts';
        }

        // Sanitize order_by
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_by)) {
            $order_by = 'ts';
        }

        $query .= " WHERE (participant_uuid = :uuid)";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (message_json->>'Body' LIKE :filter)";
        }
        
        // Sanitize order_dir
        if (!in_array(strtoupper($order_dir), ['ASC', 'DESC'])) {
            $order_dir = 'DESC';
        }

        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        error_log($query);
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

    public static function allForCSV(string $study): array {
    $messages = [];
    // append column names
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
                        ((p.participant_json::jsonb ->> 'first_name') || ' ' || (p.participant_json::jsonb ->> 'last_name')) AS participant_name,
                        m.study
                    FROM messages AS m 
                    INNER JOIN participants AS p ON p.participant_uuid = m.participant_uuid
                    WHERE p.study = ? OR p.study = 'ADMIN'";
        $stmt = PostgresDB::run($query, [$study]);
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $messages[] = Message::fillCSVRow($row);
        return $messages;

    }

    public static function fillCSVRow($row): array {
        return [
            $row['message_uuid'],
            $row['participant_uuid'],
            is_null($row['participant_name']) && $row['study'] == 'ADMIN' ? 'Study Admin' : $row['participant_name'],
            date('m/d/Y, H:i:s', strtotime($row['ts'])),
            $row['message_direction'],
            $row['body'],
            $row['number']
        ];

    }

    public static function send($messageObj, $participantNumber): ?bool {
        global $CONFIG;
        try {
            if(is_null($messageObj) || is_null($participantNumber))
                throw new Exception('You must provide a message object and a participant number to send a message');
            $sid = $CONFIG['twilio']['account_sid'];
            $token = $CONFIG['twilio']['auth_token'];
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
        $this->study = $row['study'];
    }

    protected function save(): ?Message {
        $exists = Message::withID($this->getID());
        if (is_null($exists)) {
            $insert = "INSERT INTO messages (message_uuid, participant_uuid, ts, message_direction, message_json, study) VALUES (?,?,?,?,?,?)";
            PostgresDB::run($insert, [$this->getID(), $this->getParticipant(), $this->getTimestamp(), $this->getDirection(), $this->getJSON(), $this->getStudy()]);
        } else {
            $update = "UPDATE messages SET participant_uuid=?, ts=?, message_direction=?, message_json=?, study =? WHERE message_uuid=?";
            PostgresDB::run($update, [$this->getParticipant(), $this->getTimestamp(), $this->getDirection(), $this->getJSON(), $this->getStudy(), $this->getID()]);
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

    /**
     * @return string
     */
    public function getID(): string {
        return $this->uuid;
    }

    /**
     * @return string
     */
    public function getParticipant(): string {
        return $this->participant_uuid;
    }

    /**
     * @return string
     */
    public function getTimestamp(): string {
        return $this->timestamp;
    }

    /**
     * @return string
     */
    public function getDirection(): string {
        return $this->direction;
    }

    /**
     * @return string
     */
    public function getJSON(): string {
        return $this->json;
    }

    /**
     * @return string
     */
    public function getStudy(): string {
        return $this->study;
    }

    /**
     * @param string $participant_uuid
     */
    public function setParticipant(string $participant_uuid): void {
        $this->participant_uuid = $participant_uuid;
    }

    /**
     * @param string $timestamp
     */
    public function setTimestamp(string $timestamp): void {
        $this->timestamp = $timestamp;
    }

    /**
     * @param string $direction
     */
    public function setDirection(string $direction): void {
        $this->direction = $direction;
    }

    /**
     * @param string $json
     */
    public function setJSON(string $json): void {
        $this->json = $json;
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
            'uuid'              => $this->getID(),
            'participant_uuid'  => $this->getParticipant(),
            'ts'                => $this->getTimestamp(),
            'direction'         => $this->getDirection(),
            'study'             => $this->getStudy(),
            'json'              => $this->getJSON()
        ];
    }
}



//------------------------------//
//      ScheduledMessage        //
//------------------------------//
class ScheduledMessage {
    protected string $uuid;
    protected string $participant_uuid;
    protected string $toNumber;
    protected string $fromNumber;
    protected string $scheduledFor;
    protected string $messageJson;
    protected string $study;

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->participant_uuid = null;
        $this->toNumber = null;
        $this->fromNumber = null;
        $this->scheduledFor = null;
        $this->messageJson = null;
        $this->study = null;
    }

    public static function create($participant_uuid, $scheduledFor, $messageJson, $toNumber, $fromNumber, $study): ?ScheduledMessage {
        if (empty($participant_uuid) || empty($scheduledFor) || empty($messageJson) || empty($toNumber) || empty($fromNumber) || empty($study))
            throw new Exception('You are missing parameters for creating a scheduled message object.');
        $instance = new self();
        $instance->setParticipantUUID($participant_uuid);
        $instance->setScheduledFor($scheduledFor);
        $instance->setMessageJson($messageJson);
        $instance->setToNumber($toNumber);
        $instance->setFromNumber($fromNumber);
        $instance->setStudy($study);

        return $instance->save();
    }

    protected function save(): ?ScheduledMessage {
        $exists = ScheduledMessage::withID($this->getMessageUUID());
        if (is_null($this->getFromNumber())){
            return null;
        }
        if (is_null($exists)) {
            $insert = "INSERT INTO queued_messages (message_uuid, participant_uuid, toNumber, fromNumber, scheduledFor, message_json, study) VALUES (?,?,?,?,?,?,?)";
            PostgresDB::run($insert, [$this->getMessageUUID(), $this->getParticipantUUID(), $this->getToNumber(), $this->getFromNumber(), $this->getScheduledFor(), $this->getMessageJson(), $this->getStudy()]);
        } else {
            $update = "UPDATE queued_messages SET participant_uuid=?, toNumber=?, fromNumber=?, scheduledFor=?, message_json=?, study=? WHERE message_uuid=?";
            PostgresDB::run($update, [$this->getParticipantUUID(), $this->getToNumber(), $this->getFromNumber(), $this->getScheduledFor(), $this->getMessageJson(), $this->getStudy(), $this->getMessageUUID()]);
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
        $this->toNumber = $row['toNumber'];
        $this->fromNumber = $row['fromNumber'];
        $this->scheduledFor = $row['scheduledFor'];
        $this->messageJson = $row['message_json'];
        $this->study = $row['study'];
    }

    public static function countForDatatable($study): int {
        $query = "SELECT count(message_uuid) FROM queued_messages WHERE study = :study";

        $stmt = PostgresDB::prepare($query);
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, string $study): int {
        $query = "";

        if ($study == "SEC"){
            $query = "SELECT count(message_uuid) FROM queued_messages WHERE study = :study OR study = 'Sleep'";
        } else {
            $query = "SELECT count(message_uuid) FROM queued_messages WHERE study = :study";
        }
        $query = "SELECT count(message_uuid) FROM queued_messages WHERE study = :study";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (scheduledFor LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study): array {
        $matches = [];

        $query = "SELECT * FROM queued_messages WHERE (study = :study OR study = 'ADMIN')";

        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'scheduledFor';
        if (is_null($order_dir) || $order_dir == '')
            $order_dir = 'DESC';
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (message_json LIKE :filter)";
        }
        $query .= " ORDER BY {$order_by} {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
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

    public static function deleteMessage(string $uuid): bool {
        $stmt = PostgresDB::prepare("DELETE FROM queued_messages WHERE message_uuid = :uuid");
        $stmt->bindParam('uuid', $uuid);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    /**
     * @return string
     */
    public function getMessageUUID(): string {
        return $this->uuid;
    }

    /**
     * @return string
     */
    public function getParticipantUUID(): string {
        return $this->participant_uuid;
    }

    /**
     * @return string
     */
    public function getToNumber(): string {
        return $this->toNumber;
    }

    /**
     * @return string
     */
    public function getFromNumber(): string {
        return $this->fromNumber;
    }

    /**
     * @return string
     */
    public function getMessageJson(): string {
        return $this->messageJson;
    }

    public function getScheduledFor(): string {
        return $this->scheduledFor;
    }

    public function getStudy(): string {
        return $this->study;
    }

    /**
     * @param string $participant_uuid
     */
    public function setParticipantUUID(string $participant_uuid): void {
        $this->participant_uuid = $participant_uuid;
    }

    /**
     * @param string $toNumber
     */
    public function setToNumber(string $toNumber): void {
        $this->toNumber = $toNumber;
    }

    /**
     * @param string $fromNumber
     */
    public function setFromNumber(string $fromNumber): void {
        $this->fromNumber = $fromNumber;
    }

    /**
     * @param string $messageJson
     */
    public function setMessageJson(string $messageJson): void {
        $this->messageJson = $messageJson;
    }

    public function setScheduledFor(string $scheduledFor): void {
        $this->scheduledFor = $scheduledFor;
    }

    public function setStudy(string $study): void {
        $this->study = $study;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'message_uuid'      => $this->getMessageUUID(),
            'participant_uuid'  => $this->getParticipantUUID(),
            'to_number'         => $this->getToNumber(),
            'from_number'       => $this->getFromNumber(),
            'message_json'      => $this->getMessageJson(),
            'scheduled_for'     => $this->getScheduledFor(),
            'study'             => $this->getStudy()
        ];
    }

}