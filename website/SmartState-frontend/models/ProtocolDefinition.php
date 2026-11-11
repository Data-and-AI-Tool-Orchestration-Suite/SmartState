<?php
require_once __DIR__ . '/../utilities/db.php';

class ProtocolDefinition implements JsonSerializable {
    protected ?string $definitionUuid = null;
    protected string $studyUuid;
    protected string $name;
    protected int $version = 1;
    protected string $status = 'draft';
    protected array $definition = [];
    protected ?string $createdBy = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;
    protected ?string $publishedAt = null;

    public static function withID(string $definitionUuid, string $studyUuid): ?ProtocolDefinition {
        try {
            $stmt = PostgresDB::run(
                "SELECT * FROM protocol_definitions WHERE definition_uuid = :definition_uuid AND study_uuid = :study_uuid OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
                ['definition_uuid' => $definitionUuid, 'study_uuid' => $studyUuid]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row == null)
                return null;
            return self::withRow($row);
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withRow(array $row): ProtocolDefinition {
        $instance = new self();
        $instance->fill($row);
        return $instance;
    }

    public static function listForDatatable(string $studyUuid): array {
        $matches = [];
        $query = "SELECT g.name,
                         g.latest_published_version,
                         g.latest_published_uuid,
                         g.latest_published_at,
                         g.has_draft,
                         g.draft_uuid,
                         g.version_count,
                         (SELECT COUNT(*) FROM enrollments e
                          WHERE e.study_uuid = :study_uuid_enr AND e.protocol_name = g.name AND e.ended_at IS NULL) AS active_enrollments
                  FROM (
                      SELECT d.name,
                             MAX(d.version) FILTER (WHERE d.status = 'published') AS latest_published_version,
                             (array_agg(d.definition_uuid ORDER BY d.version DESC) FILTER (WHERE d.status = 'published'))[1] AS latest_published_uuid,
                             MAX(d.published_at) FILTER (WHERE d.status = 'published') AS latest_published_at,
                             BOOL_OR(d.status = 'draft') AS has_draft,
                             (array_agg(d.definition_uuid) FILTER (WHERE d.status = 'draft'))[1] AS draft_uuid,
                             COUNT(*) AS version_count
                      FROM protocol_definitions d
                      WHERE d.study_uuid = :study_uuid
                      GROUP BY d.name
                  ) g
                  ORDER BY g.name ASC";
        $stmt = PostgresDB::run($query, ['study_uuid' => $studyUuid, 'study_uuid_enr' => $studyUuid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $matches[] = $row;
        return $matches;
    }

    public static function listPublished(string $studyUuid): array {
        $matches = [];
        $stmt = PostgresDB::run(
            "SELECT definition_uuid, name, version FROM protocol_definitions
             WHERE study_uuid = :study_uuid AND status = 'published'
             ORDER BY name ASC, version DESC",
            ['study_uuid' => $studyUuid]
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $matches[] = [
                'definition_uuid' => $row['definition_uuid'],
                'name'            => $row['name'],
                'version'         => intval($row['version']),
                'label'           => $row['name'] . ' v' . $row['version']
            ];
        }
        return $matches;
    }

    public static function createDraft(string $name, string $studyUuid, ?string $createdBy): ?ProtocolDefinition {
        if (empty($name))
            throw new Exception('You must provide a name to create a protocol');
        if (empty($studyUuid))
            throw new Exception('A protocol must belong to a study');
        $stmt = PostgresDB::run(
            "SELECT count(definition_uuid) FROM protocol_definitions WHERE study_uuid = :study_uuid AND name = :name",
            ['study_uuid' => $studyUuid, 'name' => $name]
        );
        if (intval($stmt->fetchColumn()) > 0)
            throw new Exception("A protocol named [$name] already exists. Create a new draft from one of its versions instead.");
        $instance = new self();
        $instance->studyUuid = $studyUuid;
        $instance->name = $name;
        $instance->version = 1;
        $instance->status = 'draft';
        $instance->createdBy = $createdBy;
        $instance->definition = self::skeletonDefinition($name);
        return $instance->save();
    }

    public static function saveDraft(string $definitionUuid, string $studyUuid, array $definition): ProtocolDefinition {
        $instance = self::withID($definitionUuid, $studyUuid);
        if (is_null($instance))
            throw new Exception('No protocol definition found.');
        if ($instance->getStatus() !== 'draft')
            throw new Exception('Only drafts can be edited.');
        $definition['name'] = $instance->getName();
        PostgresDB::run(
            "UPDATE protocol_definitions SET definition = :definition, updated_at = (NOW() AT TIME ZONE 'utc')
             WHERE definition_uuid = :definition_uuid AND study_uuid = :study_uuid AND status = 'draft'",
            ['definition' => json_encode($definition), 'definition_uuid' => $definitionUuid, 'study_uuid' => $studyUuid]
        );
        return self::withID($definitionUuid, $studyUuid);
    }

    public static function publish(string $definitionUuid, string $studyUuid): ProtocolDefinition {
        $instance = self::withID($definitionUuid, $studyUuid);
        if (is_null($instance))
            throw new Exception('No protocol definition found.');
        if ($instance->getStatus() !== 'draft')
            throw new Exception('Only drafts can be published.');
        PostgresDB::run(
            "UPDATE protocol_definitions SET status = 'published', published_at = (NOW() AT TIME ZONE 'utc'), updated_at = (NOW() AT TIME ZONE 'utc')
             WHERE definition_uuid = :definition_uuid AND study_uuid = :study_uuid AND status = 'draft'",
            ['definition_uuid' => $definitionUuid, 'study_uuid' => $studyUuid]
        );
        return self::withID($definitionUuid, $studyUuid);
    }

    public static function cloneToDraft(string $definitionUuid, string $studyUuid, ?string $createdBy): ?ProtocolDefinition {
        $source = self::withID($definitionUuid, $studyUuid);
        if (is_null($source))
            throw new Exception('No protocol definition found.');
        $stmt = PostgresDB::run(
            "SELECT count(definition_uuid) FROM protocol_definitions WHERE study_uuid = :study_uuid AND name = :name AND status = 'draft'",
            ['study_uuid' => $studyUuid, 'name' => $source->getName()]
        );
        if (intval($stmt->fetchColumn()) > 0)
            throw new Exception("A draft already exists for [{$source->getName()}].");
        $stmt = PostgresDB::run(
            "SELECT MAX(version) FROM protocol_definitions WHERE study_uuid = :study_uuid AND name = :name",
            ['study_uuid' => $studyUuid, 'name' => $source->getName()]
        );
        $nextVersion = intval($stmt->fetchColumn()) + 1;
        $instance = new self();
        $instance->studyUuid = $studyUuid;
        $instance->name = $source->getName();
        $instance->version = $nextVersion;
        $instance->status = 'draft';
        $instance->createdBy = $createdBy;
        $instance->definition = $source->getDefinition();
        return $instance->save();
    }

    public static function deleteByName(string $name, string $studyUuid): int {
        // enrollments.definition_uuid has no ON DELETE clause, so versions with
        // enrollment history cannot be removed without destroying participant
        // history — refuse up front with a clear message (the FK is the backstop)
        $stmt = PostgresDB::run(
            "SELECT COUNT(*) FROM enrollments e
             JOIN protocol_definitions d ON d.definition_uuid = e.definition_uuid
             WHERE d.study_uuid = :study_uuid AND d.name = :name",
            ['study_uuid' => $studyUuid, 'name' => $name]
        );
        if (intval($stmt->fetchColumn()) > 0)
            throw new Exception("Protocol [$name] has enrollment history and cannot be deleted. Participants must never have been enrolled in a protocol to delete it.");
        $stmt = PostgresDB::run(
            "DELETE FROM protocol_definitions WHERE study_uuid = :study_uuid AND name = :name",
            ['study_uuid' => $studyUuid, 'name' => $name]
        );
        $deleted = $stmt->rowCount();
        if ($deleted === 0)
            throw new Exception("No protocol named [$name] found.");
        return $deleted;
    }

    public static function versionsForName(string $name, string $studyUuid): array {
        $matches = [];
        $stmt = PostgresDB::run(
            "SELECT definition_uuid, name, version, status, created_at, updated_at, published_at
             FROM protocol_definitions
             WHERE study_uuid = :study_uuid AND name = :name
             ORDER BY version DESC",
            ['study_uuid' => $studyUuid, 'name' => $name]
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['version'] = intval($row['version']);
            $matches[] = $row;
        }
        return $matches;
    }

    public static function getByEnrollment(string $enrollmentUuid, string $studyUuid): ?ProtocolDefinition {
        try {
            $stmt = PostgresDB::run(
                "SELECT d.* FROM protocol_definitions d
                 JOIN enrollments e ON e.definition_uuid = d.definition_uuid
                 WHERE e.enrollment_uuid = :enrollment_uuid AND e.study_uuid = :study_uuid
                 OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
                ['enrollment_uuid' => $enrollmentUuid, 'study_uuid' => $studyUuid]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row == null)
                return null;
            return self::withRow($row);
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function activeEnrollmentsForName(string $name, string $studyUuid): array {
        $matches = [];
        $stmt = PostgresDB::run(
            "SELECT e.enrollment_uuid,
                    e.enrolled_at,
                    d.version,
                    d.definition_uuid,
                    p.participant_json->>'first_name' AS first_name,
                    p.participant_json->>'last_name' AS last_name,
                    (SELECT sl.log_json->>'state' FROM state_log sl
                     WHERE sl.participant_uuid = e.participant_uuid AND sl.log_json->>'protocol' = e.protocol_name
                     ORDER BY sl.ts DESC LIMIT 1) AS current_state
             FROM enrollments e
             JOIN protocol_definitions d ON d.definition_uuid = e.definition_uuid
             JOIN participants p ON p.participant_uuid = e.participant_uuid
             WHERE e.study_uuid = :study_uuid AND e.protocol_name = :name AND e.ended_at IS NULL
             ORDER BY p.participant_json->>'last_name' ASC, p.participant_json->>'first_name' ASC",
            ['study_uuid' => $studyUuid, 'name' => $name]
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['version'] = intval($row['version']);
            $matches[] = $row;
        }
        return $matches;
    }

    public static function migrateEnrollment(string $enrollmentUuid, string $targetDefinitionUuid, string $studyUuid): bool {
        $target = self::withID($targetDefinitionUuid, $studyUuid);
        if (is_null($target))
            throw new Exception('No target protocol definition found.');
        if ($target->getStatus() !== 'published')
            throw new Exception('Enrollments can only be migrated to a published version.');
        PostgresDB::beginTransaction();
        try {
            $stmt = PostgresDB::run(
                "UPDATE enrollments SET ended_at = (NOW() AT TIME ZONE 'utc'), end_reason = 'version_migration'
                 WHERE enrollment_uuid = :enrollment_uuid AND study_uuid = :study_uuid AND ended_at IS NULL
                 RETURNING participant_uuid",
                ['enrollment_uuid' => $enrollmentUuid, 'study_uuid' => $studyUuid]
            );
            $participantUuid = $stmt->fetchColumn();
            if ($participantUuid === false) {
                PostgresDB::rollBack();
                return false;
            }
            $stmt = PostgresDB::run(
                "INSERT INTO enrollments (participant_uuid, definition_uuid, study_uuid, protocol_name) VALUES (?,?,?,?) RETURNING enrollment_uuid",
                [$participantUuid, $target->getDefinitionUuid(), $studyUuid, $target->getName()]
            );
            $newEnrollmentUuid = $stmt->fetchColumn();
            // Carry the participant's position into the new version: the engine
            // restores from save_state keyed by enrollment_uuid, and falls back
            // to a fresh start when the saved state has no match in the new
            // definition
            PostgresDB::run(
                "INSERT INTO save_state (enrollment_uuid, study_uuid, ts, state_json)
                 SELECT :new_enrollment_uuid, study_uuid, ts, state_json FROM save_state
                 WHERE enrollment_uuid = :old_enrollment_uuid
                 ORDER BY ts DESC LIMIT 1",
                ['new_enrollment_uuid' => $newEnrollmentUuid, 'old_enrollment_uuid' => $enrollmentUuid]
            );
            PostgresDB::commit();
            return true;
        } catch (Exception $e) {
            PostgresDB::rollBack();
            throw $e;
        }
    }

    protected static function skeletonDefinition(string $name): array {
        return [
            'schemaVersion' => 1,
            'name'          => $name,
            'initialState'  => 'initial',
            'timers'        => [],
            'settings'      => [
                'endProtocolEvent' => 'receivedEndProtocol',
                'collection'       => 'none'
            ],
            'eventMappings' => [
                'sms' => ['defaultAction' => 'ignore']
            ],
            'states'        => [
                'initial'     => [
                    'transitions' => [
                        ['event' => 'receivedEndProtocol', 'to' => 'endProtocol']
                    ],
                    'manualMoves' => [
                        ['to' => 'endProtocol', 'via' => 'receivedEndProtocol']
                    ]
                ],
                'endProtocol' => [
                    'terminal'    => true,
                    'transitions' => [],
                    'manualMoves' => []
                ]
            ],
            'surveys'       => []
        ];
    }

    protected function fill(array $row): void {
        $this->definitionUuid = $row['definition_uuid'];
        $this->studyUuid = $row['study_uuid'];
        $this->name = $row['name'];
        $this->version = intval($row['version']);
        $this->status = $row['status'];
        $decoded = json_decode($row['definition'] ?? '{}', true);
        $this->definition = is_array($decoded) ? $decoded : [];
        if (array_key_exists('created_by', $row))
            $this->createdBy = $row['created_by'];
        if (array_key_exists('created_at', $row))
            $this->createdAt = $row['created_at'];
        if (array_key_exists('updated_at', $row))
            $this->updatedAt = $row['updated_at'];
        if (array_key_exists('published_at', $row))
            $this->publishedAt = $row['published_at'];
    }

    protected function save(): ?ProtocolDefinition {
        if (is_null($this->definitionUuid)) {
            $stmt = PostgresDB::run(
                "INSERT INTO protocol_definitions (study_uuid, name, version, status, definition, created_by) VALUES (?,?,?,?,?,?) RETURNING definition_uuid",
                [$this->studyUuid, $this->name, $this->version, $this->status, json_encode($this->definition), $this->createdBy]
            );
            $this->definitionUuid = $stmt->fetchColumn();
        } else {
            PostgresDB::run(
                "UPDATE protocol_definitions SET definition = ?, status = ?, updated_at = (NOW() AT TIME ZONE 'utc') WHERE definition_uuid = ? AND study_uuid = ?",
                [json_encode($this->definition), $this->status, $this->definitionUuid, $this->studyUuid]
            );
        }
        return self::withID($this->definitionUuid, $this->studyUuid);
    }

    public function getDefinitionUuid(): ?string {
        return $this->definitionUuid;
    }

    public function getStudyUuid(): string {
        return $this->studyUuid;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getVersion(): int {
        return $this->version;
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function getDefinition(): array {
        return $this->definition;
    }

    public function getCreatedBy(): ?string {
        return $this->createdBy;
    }

    public function getCreatedAt(): ?string {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string {
        return $this->updatedAt;
    }

    public function getPublishedAt(): ?string {
        return $this->publishedAt;
    }

    public function jsonSerialize(): array {
        return [
            'definition_uuid' => $this->getDefinitionUuid(),
            'name'            => $this->getName(),
            'version'         => $this->getVersion(),
            'status'          => $this->getStatus(),
            'definition'      => $this->getDefinition(),
            'created_at'      => $this->getCreatedAt(),
            'updated_at'      => $this->getUpdatedAt(),
            'published_at'    => $this->getPublishedAt()
        ];
    }
}
