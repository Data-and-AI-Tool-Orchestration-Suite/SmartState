<?php

class Study implements JsonSerializable {
    protected ?string $studyUuid = null;
    protected string $name;
    protected ?string $contactPhone = null;
    protected ?string $contactEmail = null;
    protected array $settings = [];
    protected ?string $createdAt = null;

    private const SORTABLE_COLUMNS = [
        '0' => 'name',
        '1' => 'contact_phone',
        '2' => 'contact_email',
        '4' => 'created_at'
    ];
    private const DEFAULT_SORT = 'name';

    public static function create(string $name, ?string $contactPhone, ?string $contactEmail, ?string $twilioFromNumber): ?Study {
        if (empty($name))
            throw new Exception('You must provide a name to create a study');
        $existing = self::withName($name);
        if (!is_null($existing))
            throw new Exception("A study named [$name] already exists");
        $instance = new self();
        $instance->setName($name);
        $instance->setContactPhone($contactPhone);
        $instance->setContactEmail($contactEmail);
        $instance->setTwilioFromNumber($twilioFromNumber);
        return $instance->save();
    }

    public static function update(string $studyUuid, string $name, ?string $contactPhone, ?string $contactEmail, ?string $twilioFromNumber): Study {
        if (empty($studyUuid))
            throw new Exception('You must provide a study uuid to update a study');
        $instance = self::withUUID($studyUuid);
        if (is_null($instance))
            throw new Exception("No study found with uuid [$studyUuid]");
        $instance->setName($name);
        $instance->setContactPhone($contactPhone);
        $instance->setContactEmail($contactEmail);
        $instance->setTwilioFromNumber($twilioFromNumber);
        return $instance->save();
    }

    public static function delete(string $studyUuid): bool {
        if (empty($studyUuid))
            throw new Exception('You must provide a study uuid to delete a study');
        $stmt = PostgresDB::prepare("DELETE FROM studies WHERE study_uuid = :study_uuid");
        $stmt->bindParam('study_uuid', $studyUuid);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public static function withUUID(string $studyUuid): ?Study {
        try {
            $stmt = PostgresDB::run(
                "SELECT * FROM studies WHERE study_uuid = :study_uuid OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
                ['study_uuid' => $studyUuid]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row == null)
                return null;
            return self::withRow($row);
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withName(string $name): ?Study {
        try {
            $stmt = PostgresDB::run(
                "SELECT * FROM studies WHERE name = :name OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
                ['name' => $name]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row == null)
                return null;
            return self::withRow($row);
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withRow(array $row): Study {
        $instance = new self();
        $instance->fill($row);
        return $instance;
    }

    public static function allForSelect(): array {
        $studies = [];
        $stmt = PostgresDB::run("SELECT study_uuid, name FROM studies ORDER BY name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $studies[] = ['study_uuid' => $row['study_uuid'], 'name' => $row['name']];
        }
        return $studies;
    }

    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT count(study_uuid) FROM studies");
        return $stmt->fetchColumn();
    }

    public static function countFilteredForDatatable(string $filter): int {
        $query = "SELECT count(study_uuid) FROM studies";
        $args = [];
        if (strlen($filter) > 0) {
            $query .= " WHERE (name LIKE :filter)";
            $args['filter'] = '%' . $filter . '%';
        }
        $stmt = PostgresDB::run($query, $args);
        return $stmt->fetchColumn();
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        $query = "SELECT * FROM studies";
        $prune = '';
        $start = (int) $start;
        $length = (int) $length;
        if ($length > 0)
            $prune .= " OFFSET $start ROWS FETCH NEXT $length ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        if (strlen($filter) > 0) {
            $query .= " WHERE (name LIKE :filter)";
        }
        $query .= " ORDER BY $order_by $order_dir$prune";
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', '%' . $filter . '%');
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $matches[] = self::withRow($row);
        return $matches;
    }

    protected function fill(array $row): void {
        $this->studyUuid = $row['study_uuid'];
        $this->name = $row['name'];
        if (array_key_exists('contact_phone', $row))
            $this->contactPhone = $row['contact_phone'];
        if (array_key_exists('contact_email', $row))
            $this->contactEmail = $row['contact_email'];
        if (array_key_exists('settings', $row)) {
            $decoded = json_decode($row['settings'] ?? '{}', true);
            $this->settings = is_array($decoded) ? $decoded : [];
        }
        if (array_key_exists('created_at', $row))
            $this->createdAt = $row['created_at'];
    }

    protected function save(): ?Study {
        if (is_null($this->studyUuid)) {
            $stmt = PostgresDB::run(
                "INSERT INTO studies (name, contact_phone, contact_email, settings) VALUES (?,?,?,?) RETURNING study_uuid",
                [$this->getName(), $this->getContactPhone(), $this->getContactEmail(), json_encode($this->settings, JSON_FORCE_OBJECT)]
            );
            $this->studyUuid = $stmt->fetchColumn();
        } else {
            PostgresDB::run(
                "UPDATE studies SET name=?, contact_phone=?, contact_email=?, settings=? WHERE study_uuid=?",
                [$this->getName(), $this->getContactPhone(), $this->getContactEmail(), json_encode($this->settings, JSON_FORCE_OBJECT), $this->studyUuid]
            );
        }
        return self::withUUID($this->studyUuid);
    }

    public function getStudyUuid(): ?string {
        return $this->studyUuid;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getContactPhone(): ?string {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): void {
        $this->contactPhone = $contactPhone;
    }

    public function getContactEmail(): ?string {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): void {
        $this->contactEmail = $contactEmail;
    }

    public function getSettings(): array {
        return $this->settings;
    }

    public function getTwilioFromNumber(): ?string {
        return $this->settings['twilio_from_number'] ?? null;
    }

    public function setTwilioFromNumber(?string $twilioFromNumber): void {
        if (is_null($twilioFromNumber) || $twilioFromNumber === '') {
            unset($this->settings['twilio_from_number']);
        } else {
            $this->settings['twilio_from_number'] = $twilioFromNumber;
        }
    }

    public function getCreatedAt(): ?string {
        return $this->createdAt;
    }

    public function jsonSerialize(): array {
        return [
            'study_uuid'         => $this->getStudyUuid(),
            'name'               => $this->getName(),
            'contact_phone'      => $this->getContactPhone(),
            'contact_email'      => $this->getContactEmail(),
            'twilio_from_number' => $this->getTwilioFromNumber(),
            'created_at'         => $this->getCreatedAt()
        ];
    }
}
