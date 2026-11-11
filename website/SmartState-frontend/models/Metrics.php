<?php
require_once __DIR__ . '/../utilities/db.php';

class Metrics implements JsonSerializable {
    protected string $metricId;
    protected string $participantId;
    protected string $ts;
    protected string $json;


    public static function getTotalCompliance(string $studyUuid): array {
        $query = "SELECT
                    TO_CHAR(ts, 'Dy') AS day_of_week,
                    SUM(CAST(metric_json->>'good_compliance' AS INTEGER)) AS total_good,
                    SUM(CAST(metric_json->>'total_compliance' AS INTEGER)) AS total_total
                  FROM metrics
                  WHERE study_uuid = :study_uuid
                    AND ts >= (NOW() AT TIME ZONE 'utc') - INTERVAL '7 days'
                  GROUP BY day_of_week
                  ORDER BY MIN(ts)";
        $stmt = PostgresDB::run($query, ['study_uuid' => $studyUuid]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = array(
                'day_of_week' => $row['day_of_week'],
                'total_good' => (int) $row['total_good'],
                'total_total' => (int) $row['total_total']
            );
        }
        return $results;
    }

    public static function getStateDistribution(string $studyUuid): array {
        $query = "SELECT log_json->>'state' AS state, COUNT(*) AS count
                  FROM (SELECT DISTINCT ON (participant_uuid, log_json->>'protocol') participant_uuid, log_json
                        FROM state_log
                        WHERE study_uuid = :study_uuid
                        ORDER BY participant_uuid, log_json->>'protocol', ts DESC) latest
                  GROUP BY 1
                  ORDER BY 2 DESC";
        $stmt = PostgresDB::run($query, ['study_uuid' => $studyUuid]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = array(
                'state' => $row['state'],
                'count' => (int) $row['count']
            );
        }
        return $results;
    }

    public static function getMessageVolume(string $studyUuid): array {
        $query = "SELECT TO_CHAR(date_trunc('day', ts), 'YYYY-MM-DD') AS day,
                         TRIM(message_direction) AS direction,
                         COUNT(*) AS count
                  FROM messages
                  WHERE study_uuid = :study_uuid
                    AND ts >= date_trunc('day', (NOW() AT TIME ZONE 'utc')) - INTERVAL '13 days'
                  GROUP BY 1, 2
                  ORDER BY 1";
        $stmt = PostgresDB::run($query, ['study_uuid' => $studyUuid]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = array(
                'day' => $row['day'],
                'direction' => $row['direction'],
                'count' => (int) $row['count']
            );
        }
        return $results;
    }


    /**
     * @return string
     */
    public function getMetricId(): string {
        return $this->metricId;
    }

    /**
     * @param string $id
     */
    public function setMetricId(string $id): void {
        $this->metricId = $id;
    }

    /**
     * @return string
     */
    public function getParticipantId(): string {
        return $this->participantId;
    }

    /**
     * @param string $id
     */
    public function setParticipantId(string $id): void {
        $this->participantId = $id;
    }

    /**
     * @return string
     */
    public function getTimestamp(): string {
        return $this->ts;
    }

    /**
     * @param string $ts
     */
    public function setTimestamp(string $ts): void {
        $this->ts = $ts;
    }

    /**
     * @return string
     */
    public function getJson(): string {
        return $this->json;
    }

    /**
     * @param string $json
     * @return void
     */
    public function setJson(string $json): void {
        $this->json = $json;
    }

    /**
     * Encodes User Object into a JSON string
     * @return array
     */
    public function jsonSerialize(): array {
        return [
            'metricId'          => $this->getMetricId(),
            'participantId'     => $this->getParticipantId(),
            'ts'                => $this->getTimestamp(),
            'json'              => $this->getJson()
        ];
    }

    /**
     * @return string
     */
    public function __toString(): string {
        return json_encode($this->jsonSerialize());
    }
}