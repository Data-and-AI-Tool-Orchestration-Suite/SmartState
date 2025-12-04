<?php
require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class ProtocolType {
    protected $uuid;
    protected $name;
    protected $study;

    public function __construct() {
        $this->uuid = UUID::v4();
        $this->name = null;
        $this->study = null;
    }

    public static function create($name, $study): ?ProtocolType {
        if (empty($name) || !isset($name))
            throw new Exception('You must provide a name to create a protocol type');
        if (empty($study) || !isset($study))
            throw new Exception('Protocol must belong to a study');
        $instance = new self();
        $instance->setName($name);
        $instance->setStudy($study);
        try {
            $newProtocol = $instance->save();
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage( ) , $e->getCode( ));
        }
        return $newProtocol;
    }

    public static function delete($id): bool {
        if (empty($id) || is_null($id))
            throw new Exception('ID for protocol type not found.');
        $instance = new self();
        $object = $instance->withID($id);
        $name = $object->getName();
        $status = $instance->deleteFromID($id);
        $instance->deleteParticipantGroup($name);
        return $status;
    }

    public static function update($uuid, $name): ProtocolType {
        if (empty($uuid))
            throw new Exception('You must provide an id to update a protocol');
        $instance = ProtocolType::withID($uuid);
        if (is_null($instance))
            throw new Exception("No protocol found with id [{$uuid}]");
        $oldName = $instance->getName();
        $instance->setName($name);

        try {
            $updatedProtocol = $instance->save();
            $instance->updateParticipantGroup($oldName);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int) $e->getCode());
        }
        return $updatedProtocol;
    }

    protected function updateParticipantGroup($oldName): void {
        $newName = $this->getName();
        $stmt = PostgresDB::run("UPDATE participants SET participant_json = jsonb_set(participant_json, '{group}', (participant_json->'group') - ? || to_jsonb(?::text)::jsonb) WHERE participant_json->'group' ?? ?",
            [$oldName, $newName, $oldName]);
    }

    protected function deleteParticipantGroup($oldName): void {
        $newName = 'UNASSIGNED';
        $stmt = PostgresDB::run("UPDATE participants SET participant_json = jsonb_set(participant_json, '{group}', (participant_json->'group') - ? || to_jsonb(?::text)::jsonb) WHERE participant_json->'group' ?? ?",
            [$oldName,$newName, $oldName]);
    }

    public static function all($study): array {
        $types = [];
        $query = "SELECT * FROM protocol_types WHERE study = :study ORDER BY name ASC";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            array_push($types, ProtocolType::withRow($row));
        return $types;
    }

    public static function getActives(string $uuid) {
        $actives = [];
        $query = "SELECT name FROM protocol_types WHERE protocol_type_uuid IN (SELECT protocol_type_uuid FROM enrollments WHERE participant_uuid = :uuid AND status = true)";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('uuid', $uuid);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $actives[] = $row['name'];
        return $actives;
    }

    public static function getProtocolStudyAndName(string $protocol_id): ?array {
        $result = array();
        $query = "SELECT study, name FROM protocol_types WHERE protocol_type_uuid = :id";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('id', $protocol_id);
        $stmt->execute();
        if ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $result['name'] = $row['name'];
        $result['study'] = $row['study'];
        return $result;

        return null;
    }

    public static function countForDatatable($study): int {
        $query = "SELECT count(protocol_type_uuid) FROM protocol_types WHERE study = :study";
        $stmt = PostgresDB::prepare($query);
        if (!is_null($study) && strlen($study) > 0) {
            $stmt->bindParam('study', $study);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, $study): int {
        $query = "SELECT count(protocol_type_uuid) FROM protocol_types WHERE study = :study";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (name LIKE :filter)";
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
        $query = "SELECT * FROM protocol_types WHERE study = :study";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'name';
        
        // Sanitize order_by
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_by)) {
            $order_by = 'name';
        }

        if (is_null($order_dir) || $order_dir == '')
            $order_dir = 'desc';
        
        // Sanitize order_dir
        if (!in_array(strtoupper($order_dir), ['ASC', 'DESC'])) {
            $order_dir = 'DESC';
        }

        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (name LIKE :filter)";
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
            array_push($matches, ProtocolType::withRow($row));
        return $matches;
    }

    public static function withID( $id ): ?ProtocolType {
        try {
            $instance = new self();
            $instance->loadByID($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withName( $name ): ?ProtocolType {
        try {
            $instance = new self();
            $instance->loadByName($name);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withRow( $row ): ProtocolType {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    protected function loadByID( $id ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM protocol_types WHERE protocol_type_uuid = :id ORDER BY protocol_type_uuid OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Protocol with id [{$id}] not found");
    }

    protected function fill( $row ): void {
        $this->uuid = $row['protocol_type_uuid'];
        $this->name = $row['name'];
        $this->study = $row['study'];
    }

    protected function loadByName( $name ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM protocol_types WHERE name = :name ORDER BY name OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['name' => $name]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Protocol with id [{$name}] not found");
    }

    protected function save(): ?ProtocolType {
        $exists = ProtocolType::withID($this->getID());
        if (is_null($exists)) {
            $insert = "INSERT INTO protocol_types (protocol_type_uuid, study, name) VALUES (?::uuid,?,?)";
            PostgresDB::run($insert, [$this->getID(), $this->getStudy(), $this->getName()]);
        } else {
            $update = "UPDATE protocol_types SET name=?, study=? WHERE protocol_type_uuid=?";
            PostgresDB::run($update, [$this->getName(), $this->getStudy(), $this->getID()]);
        }
        return ProtocolType::withID($this->getID());
    }

    protected function deleteFromID( $id ): bool {
        $stmt = PostgresDB::prepare("DELETE FROM protocol_types WHERE protocol_type_uuid = :id");
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
    public function getName(): string {
        return $this->name;
    }

    public function getStudy(): string {
        return $this->study;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void {
        $this->name = $name;
    }

    public function setStudy(string $study): void {
        $this->study = $study;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'id'        => $this->getID(),
            'name'      => $this->getName(),
            'study'     => $this->getStudy(),
        ];
    }
}