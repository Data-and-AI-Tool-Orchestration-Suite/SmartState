<?php
require_once __DIR__ . '/../utilities/db.php';

class Survey {
    private const SORTABLE_COLUMNS = [
        '0' => 'token',
        '2' => 'survey_key',
        '4' => 'created_at',
        '5' => 'finished_at'
    ];
    private const DEFAULT_SORT = 'finished_at';

    protected ?string $token;
    protected ?string $participant_uuid;
    protected ?string $study_uuid;
    protected ?string $enrollment_uuid;
    protected ?string $survey_key;
    protected ?string $questions_json;
    protected ?string $created_at;
    protected ?string $finished_at;
    protected ?string $survey_json;

    public function __construct() {
        $this->token = null;
        $this->participant_uuid = null;
        $this->study_uuid = null;
        $this->enrollment_uuid = null;
        $this->survey_key = null;
        $this->questions_json = null;
        $this->created_at = null;
        $this->finished_at = null;
        $this->survey_json = null;
    }

    public static function withID( $token ): ?Survey {
        try {
            $instance = new self();
            $instance->loadByID($token);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withRow( $row ): Survey {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function saveSurveyContent(array $result, string $token): bool {
        $survey_json = json_encode($result);
        $query = "UPDATE surveys SET finished_at=timezone('utc', now()), survey_json=:survey_json WHERE token::text=:token AND finished_at IS NULL";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('survey_json', $survey_json);
        $stmt->bindParam('token', $token);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public static function getStudyContactForToken(string $token): ?array {
        try {
            $stmt = PostgresDB::run(
                "SELECT st.name, st.contact_phone, st.contact_email
                 FROM surveys s
                 JOIN studies st ON st.study_uuid = s.study_uuid
                 WHERE s.token::text = :token",
                ['token' => $token]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false)
                return null;
            return $row;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function countForDatatable(string $study_uuid): int {
        $stmt = PostgresDB::run(
            "SELECT count(token) FROM surveys WHERE study_uuid = :study_uuid",
            ['study_uuid' => $study_uuid]
        );
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function countFilteredForDatatable(string $filter, string $study_uuid): int {
        $query = "SELECT count(token) FROM surveys WHERE study_uuid = :study_uuid";
        if (strlen($filter) > 0) {
            $filter = "%{$filter}%";
            $query .= " AND (survey_key ILIKE :filter OR CAST(created_at AS TEXT) ILIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('study_uuid', $study_uuid);
        if (strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count;
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $study_uuid): array {
        $matches = [];
        $query = "SELECT * FROM surveys WHERE study_uuid = :study_uuid";
        $start = (int) $start;
        $length = (int) $length;
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET {$start} ROWS FETCH NEXT {$length} ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        if (strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " AND (survey_key ILIKE :filter OR CAST(created_at AS TEXT) ILIKE :filter)";
        }
        $query .= " ORDER BY CASE WHEN {$order_by} IS NOT NULL THEN {$order_by} ELSE created_at END {$order_dir}{$prune}";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam('study_uuid', $study_uuid);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            array_push($matches, Survey::withRow($row));
        return $matches;
    }

    public static function getSurveyStats(?string $study_uuid = null): array {
        $matches = array();
        $where = '';
        $params = [];
        if (!is_null($study_uuid)) {
            $where = " WHERE study_uuid = :study_uuid";
            $params = ['study_uuid' => $study_uuid];
        }
        $totalQuery = PostgresDB::run("SELECT COUNT(token) AS total FROM surveys{$where}", $params);
        if ($row = $totalQuery->fetch(PDO::FETCH_LAZY))
            $matches['total'] = $row['total'];
        else
            $matches['total'] = 'N/a';

        $completedWhere = is_null($study_uuid) ? " WHERE finished_at IS NOT NULL" : "{$where} AND finished_at IS NOT NULL";
        $activeQuery = PostgresDB::run("SELECT COUNT(token) AS completed FROM surveys{$completedWhere}", $params);
        if ($row = $activeQuery->fetch(PDO::FETCH_LAZY))
            $matches['completed'] = $row['completed'];
        else
            $matches['completed'] = 'N/a';

        return $matches;
    }

    protected function loadByID( $token ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM surveys WHERE token::text = :token",
            ['token' => $token]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Survey with token [{$token}] not found");
    }

    protected function fill( $row ): void {
        $this->token = $row['token'];
        $this->participant_uuid = $row['participant_uuid'];
        $this->study_uuid = $row['study_uuid'];
        $this->enrollment_uuid = $row['enrollment_uuid'];
        $this->survey_key = $row['survey_key'];
        $this->questions_json = $row['questions_json'];
        $this->created_at = $row['created_at'];
        $this->finished_at = $row['finished_at'];
        $this->survey_json = $row['survey_json'];
    }

    public function getToken(): string {
        return $this->token;
    }

    public function getParticipant(): string {
        return $this->participant_uuid;
    }

    public function getStudyUUID(): ?string {
        return $this->study_uuid;
    }

    public function getEnrollmentUUID(): ?string {
        return $this->enrollment_uuid;
    }

    public function getSurveyKey(): ?string {
        return $this->survey_key;
    }

    public function getQuestionsJSON(): ?string {
        return $this->questions_json;
    }

    public function getCreatedAt(): string {
        return $this->created_at;
    }

    public function getFinishedAt(): ?string {
        return $this->finished_at;
    }

    public function getSurveyJSON(): ?string {
        return $this->survey_json;
    }

    public function jsonSerialize(): array {
        return [
            'token'             => $this->getToken(),
            'participant_uuid'  => $this->getParticipant(),
            'survey_key'        => $this->getSurveyKey(),
            'created_at'        => $this->getCreatedAt(),
            'finished_at'       => $this->getFinishedAt(),
            'survey_json'       => $this->getSurveyJSON()
        ];
    }
}
