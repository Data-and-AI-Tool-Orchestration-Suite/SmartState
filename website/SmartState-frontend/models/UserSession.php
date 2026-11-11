<?php

require_once __DIR__ . '/../utilities/CiLogonProvider.php';

require_once __DIR__ . '/User.php';

use League\OAuth2\Client\Token\AccessToken;

class UserSession implements JsonSerializable {
    protected string $session_id;
    protected ?User $user;
    protected string $expires;
    protected ?AccessToken $token;
    protected ?string $currentStudy = null;

    public function __construct() { }

    /**
     * @param string $session_id id for a session (created by PHP)
     * @param User $user User object
     * @param AccessToken $token Token returned from CiLogon
     * @return ?UserSession
     */
    public static function create(string $session_id, User $user, AccessToken $token): ?UserSession {
        try {
            self::deleteOldSessions();
            $config = include CONFIG_FILE;
            $_SESSION['caai_session_id'] = $session_id;
            $existing = UserSession::withSessionID($session_id);
            if (!is_null($existing)){
                $dateTime = new DateTime('now', new DateTimeZone('UTC'));
                $dateTime->add(new DateInterval('PT' . $config['sessions']['max-age'] . 'S'));
                $expireTime = $dateTime->format('Y-m-d H:i:s');
                $existing->setExpires($expireTime);
                error_log(print_r($existing, true));
                return $existing->save();
            }
            $instance = new self();
            $instance->setSessionId($session_id);
            $instance->setUser($user);

            $dateTime = new DateTime('now', new DateTimeZone('UTC'));
            $dateTime->add(new DateInterval('PT' . $config['sessions']['max-age'] . 'S'));
            $expireTime = $dateTime->format('Y-m-d H:i:s');
            $instance->setExpires($expireTime);

            $instance->setToken($token);
            return $instance->save();
        } catch (Exception $ex) {
            error_log($ex->getMessage());
            return null;
        }
    }

    /**
     * Creates an empty UserSession for when the user is not logged in
     * @return UserSession
     */
    public static function createEmptyUserSession() {
        $instance = new self();
        $instance->setSessionId("");
        $user = User::createEmptyUser();
        $instance->setUser($user);
        $instance->setExpires("");
        $instance->setToken(null);
        return $instance;
    }

    /**
     * Get user with their session ID
     * @param string $session_id session ID from PHP
     * @return UserSession|null
     *@throws PDOException if something DB goes wrong
     */
    public static function withSessionID(string $session_id): ?UserSession {
        try {
            $instance = new self();
            $instance->loadBySessionID($session_id);
            return $instance;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Wrapper to convert DB row object to UserSession object
     * @param array $row single row from DB query
     * @return UserSession
     */
    public static function withRow(array $row): UserSession {
        $instance = new self();
        $instance->fill($row);
        return $instance;
    }

    /**
     * Deletes a session using a session ID
     * @param string $session_id the id to delete
     * @return void
     */
    public static function delete(string $session_id): void {
        $delete = "DELETE FROM  user_sessions WHERE session_id = :session_id";
        PostgresDB::run($delete, ['session_id' => $session_id]);
    }

    /**
     * Deletes all expired sessions for a given user_id
     * @return void
     */
    public static function deleteOldSessions(): void {
        $delete = "DELETE FROM  user_sessions WHERE expires < NOW()";
        PostgresDB::run($delete);
    }

    /**
     * DB function to get a users session
     * @param string $session_id the session id to load
     * @return void
     */
    protected function loadBySessionID(string $session_id): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM user_sessions WHERE session_id = :session_id ORDER BY session_id OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['session_id' => $session_id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row != null) {
            $this->fill($row);
        } else
            throw new PDOException("Session with id [$session_id] not found");
    }

    /**
     * Create UserSession object from Database Row
     * @param array $row database row
     * @return void
     */
    protected function fill(array $row): void {
        $this->session_id = $row['session_id'];
        $this->user = User::withId($row['user_id']);
        $this->expires = $row['expires'];
        if (array_key_exists('current_study', $row))
            $this->currentStudy = $row['current_study'];
        try {
            $this->token = new AccessToken(json_decode($row['token'], true));
        } catch (Firebase\JWT\ExpiredException) {
            $this->token = null;
        }
    }

    /** Save or Updates a User Session in DB
     * @return UserSession|null
     */
    public function save(): ?UserSession {
        $exists = UserSession::withSessionID($this->getSessionId());
        $token = $this->getToken();
        $token = json_encode($token);
        if (is_null($exists)) {
            $insert = "INSERT INTO  user_sessions (session_id, user_id, expires, token, current_study) VALUES (?,?,?,?,?)";
            PostgresDB::run($insert, [$this->getSessionId(), $this->getUser()->getId(), $this->getExpires(), $token, $this->currentStudy]);
        } else {
            $update = "UPDATE  user_sessions SET user_id=?, expires = ?, token = ?, current_study = ?  WHERE session_id=?";
            PostgresDB::run($update, [$this->getUser()->getId(), $this->getExpires(), $token, $this->currentStudy, $this->getSessionId()]);
        }
        return UserSession::withSessionID($this->getSessionId());
    }

    /**
     * @return string
     */
    public function getSessionId(): string {
        return $this->session_id;
    }
    /**
     * @param string $session_id
     */
    public function setSessionId(string $session_id): void {
        $this->session_id = $session_id;
    }

    /**
     * @return ?User
     */
    public function getUser(): ?User {
        return $this->user;
    }
    /**
     * @param ?User $user
     */
    public function setUser(?User $user): void {
        $this->user = $user;
    }

    public function setExpires(string $expireTime): void {
        $this->expires = $expireTime;
    }

    public function getExpires(): string {
        return $this->expires;
    }

    public function getCurrentStudy(): ?string {
        return $this->currentStudy;
    }

    public function setCurrentStudy(?string $studyUuid): void {
        $this->currentStudy = $studyUuid;
        if ($this->getSessionId() !== "") {
            PostgresDB::run(
                "UPDATE user_sessions SET current_study = ? WHERE session_id = ?",
                [$studyUuid, $this->getSessionId()]
            );
        }
    }

    /**
     * @return ?AccessToken
     */
    public function getToken(): ?AccessToken {
        return $this->token;
    }
    public function setToken(?AccessToken $token): void {
        $this->token = $token;
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array {
        return [
            'session_id'    => $this->getSessionId(),
            'user'          => (!is_null($this->getUser())) ? $this->getUser()->jsonSerialize() : null,
            'expires'        => $this->expires,
            'token'         => $this->getToken()->jsonSerialize(),
        ];
    }
}