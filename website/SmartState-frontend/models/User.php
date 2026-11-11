<?php

class User implements JsonSerializable {
    protected string $id;
    protected ?string $email = null;
    protected ?string $number = null;
    protected ?string $firstName = null;
    protected ?string $lastName = null;
    protected ?string $fullName = null;
    protected string $eppn;
    protected ?string $idp = null;
    protected ?string $idpName = null;
    protected ?string $affiliation = null;
    protected ?string $timezone = null;
    protected bool $superAdmin = false;
    protected ?array $memberships = null;
    protected ?string $currentStudy = null;

    private const SORTABLE_COLUMNS = [
        '0' => 'full_name',
        '1' => 'first_name',
        '2' => 'last_name',
        '3' => 'full_name',
        '4' => 'eppn',
        '5' => 'email',
        '6' => 'phone_number',
        '7' => 'idp_name',
        '8' => 'affiliation',
        '9' => 'idp'
    ];
    private const DEFAULT_SORT = 'full_name';

    public static function create(array $userProfile): ?User {
        if (is_null($userProfile['eppn'])) {
            $existing = self::withEPPN($userProfile['email']);
            if (is_null($existing)) {
                $instance = new self();
                $instance->updateWithProfile($userProfile);
                return $instance->save();
            }
        }
        else {
            $existing = self::withEPPN($userProfile['eppn']);
            if (is_null($existing)) {
                $existing = self::withEPPN($userProfile['email']);
                if (is_null($existing)) {
                    $instance = new self();
                    $instance->updateWithProfile($userProfile);
                    return $instance->save();
                }
            }
        }
        $existing->updateWithProfile($userProfile);
        return $existing->save();
    }

    public static function createEmptyUser(): User {
        $instance = new self();
        $instance->setId("");
        $instance->setEPPN("");
        return $instance;
    }

    public static function createBeforeLogin(string $email, string $number, string $timezone, array $memberships, bool $isSuperAdmin): ?User {
        $existing = self::withEPPN($email);
        if (!is_null($existing)) {
            return $existing;
        }
        $instance = new self();
        $instance->setId('notloggedin_'. self::generateRandomId());
        $instance->setEPPN($email);
        $instance->setNumber($number);
        $instance->setTimeZone($timezone);
        $instance->setSuperAdmin($isSuperAdmin);
        $saved = $instance->save();
        if (!is_null($saved)) {
            $saved->replaceMemberships($memberships);
        }
        return $saved;
    }

    public static function updateProfile(string $id, array $userProfile): User {
        if (empty($id))
            throw new Exception('You must provide an id to update a user\'s profile');
        $instance = User::withId($id);
        if (is_null($instance))
            throw new Exception("No user found with id [$id]");
        $instance->updateWithProfile($userProfile);
        return $instance->save();
    }

    public static function update(string $id, string $email, string $number, string $timezone, array $memberships, bool $isSuperAdmin, ?string $onlyStudy = null): User {
        if (!isset($id) || !isset($email) || !isset($number))
            throw new Exception('You must provide an id, email, and phone number to update a user');
        $instance = User::withId($id);
        if (is_null($instance))
            throw new Exception("No user found with id [$id]");

        $instance->setEPPN($email);
        $instance->setNumber($number);
        $instance->setTimeZone($timezone);
        if (is_null($onlyStudy)) {
            $instance->setSuperAdmin($isSuperAdmin);
        }
        $saved = $instance->save();
        if (!is_null($saved)) {
            $saved->replaceMemberships($memberships, $onlyStudy);
        }
        return $saved;
    }

    public static function delete(string $id): bool {
        if (empty($id))
            throw new Exception('ID for user not found.');
        $instance = new self();
        return $instance->deleteFromId($id);
    }

    public static function all(): array {
        $users = [];
        $query = "SELECT * FROM users";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $users[] = User::withRow($row);
        return $users;
    }

    public static function countForDatatable(?string $studyUuid = null): int {
        $query = "SELECT count(id) FROM users";
        $args = [];
        if (!is_null($studyUuid)) {
            $query .= " WHERE EXISTS (SELECT 1 FROM user_study_roles m WHERE m.user_id = users.id AND m.study_uuid = :study_uuid)";
            $args['study_uuid'] = $studyUuid;
        }
        $stmt = PostgresDB::run($query, $args);
        return $stmt->fetchColumn();
    }

    public static function countFilteredForDatatable(string $filter, ?string $studyUuid = null): int {
        $query = "SELECT count(id) FROM users";
        $conditions = [];
        $args = [];
        if (strlen($filter) > 0) {
            $conditions[] = "(full_name LIKE :filter)";
            $args['filter'] = '%' . $filter . '%';
        }
        if (!is_null($studyUuid)) {
            $conditions[] = "EXISTS (SELECT 1 FROM user_study_roles m WHERE m.user_id = users.id AND m.study_uuid = :study_uuid)";
            $args['study_uuid'] = $studyUuid;
        }
        if (count($conditions) > 0)
            $query .= " WHERE " . implode(" AND ", $conditions);
        $stmt = PostgresDB::run($query, $args);
        return $stmt->fetchColumn();
    }

    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, ?string $studyUuid = null): array {
        $matches = [];
        $query = "SELECT u.*, COALESCE(json_agg(json_build_object('study_uuid', usr.study_uuid, 'study_name', s.name, 'role_id', usr.role_id, 'role_name', r.role_name) ORDER BY s.name) FILTER (WHERE usr.study_uuid IS NOT NULL), '[]') AS memberships"
            . " FROM users u"
            . " LEFT JOIN user_study_roles usr ON usr.user_id = u.id"
            . " LEFT JOIN studies s ON s.study_uuid = usr.study_uuid"
            . " LEFT JOIN user_roles r ON r.role_id = usr.role_id";
        $prune = '';
        $start = (int) $start;
        $length = (int) $length;
        if ($length > 0)
            $prune .= " OFFSET $start ROWS FETCH NEXT $length ROWS ONLY";
        $order_by = self::SORTABLE_COLUMNS[$order_by] ?? self::DEFAULT_SORT;
        $order_dir = strtolower($order_dir) === 'asc' ? 'ASC' : 'DESC';
        $conditions = [];
        if (strlen($filter) > 0) {
            $conditions[] = "(u.full_name LIKE :filter)";
        }
        if (!is_null($studyUuid)) {
            $conditions[] = "EXISTS (SELECT 1 FROM user_study_roles m WHERE m.user_id = u.id AND m.study_uuid = :study_uuid)";
        }
        if (count($conditions) > 0)
            $query .= " WHERE " . implode(" AND ", $conditions);
        $query .= " GROUP BY u.id ORDER BY $order_by $order_dir$prune";
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', '%' . $filter . '%');
        }
        if (!is_null($studyUuid)) {
            $stmt->bindValue('study_uuid', $studyUuid);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $matches[] = User::withRow($row);
        return $matches;
    }

    public static function withId( string $id ): ?User {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withEPPN( ?string $eppn ): ?User {
        if (is_null($eppn)){
            return null;
        }
        try {
            $instance = new self();
            $instance->loadByEPPN($eppn);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public static function getAllRoles(): ?array {
        $roles = [];
        $query = "SELECT * FROM user_roles WHERE role_name NOT IN ('Default', 'Super Admin') ORDER BY role_id";
        try{
            $stmt = PostgresDB::run($query);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
                $roles[] = array($row['role_id'], $row['role_name']);
        } catch (PDOException $e){
            return null;
        }
        return $roles;
    }

    public static function withRow(array $row ): User {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function getRoleIdFromName(string $role_name): int {
        $stmt = PostgresDB::run("SELECT role_id FROM user_roles WHERE role_name = :name", ['name' => $role_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row <> null)
            return $row['role_id'];
        else
            throw new PDOException("Could not find role id for role name [$role_name]");
    }

    public static function ensureStaffParticipant(string $studyUuid, ?string $firstName, ?string $lastName, string $number, ?string $timezone): void {
        if (empty($studyUuid) || empty($number))
            return;
        $stmt = PostgresDB::run(
            "SELECT participant_uuid FROM participants WHERE study_uuid = :study_uuid AND participant_json->>'number' = :number",
            ['study_uuid' => $studyUuid, 'number' => $number]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row != null)
            return;
        $json = json_encode([
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? '',
            'number' => $number,
            'time_zone' => $timezone ?? '',
            'staff' => 'true'
        ]);
        PostgresDB::run(
            "INSERT INTO participants (participant_uuid, study_uuid, participant_json) VALUES (?, ?, ?)",
            [self::generateUUIDv4(), $studyUuid, $json]
        );
    }

    protected function updateWithProfile(array $userProfile): void {
        $this->setID($userProfile['id']);
        $this->setEmail($userProfile['email'] ?? null);
        $this->setFirstName($userProfile['firstName'] ?? null);
        $this->setLastName($userProfile['lastName'] ?? null);
        if (is_null($userProfile['name'])) {
            if (!is_null($userProfile['firstName']) && !is_null($userProfile['lastName'])) {
                $full_name = $userProfile['lastName'] . ', ' . $userProfile['firstName'];
                $this->setFullName($full_name);
            }
            else {
                $this->setFullName(null);
            }
        }
        else {
            $this->setFullName($userProfile['name']);
        }
        if (is_null($userProfile['eppn'])) {
            $this->setEPPN($userProfile['email'] ?? null);
        }
        else {
            $this->setEPPN($userProfile['eppn']);
        }
        $this->setIDP($userProfile['idp'] ?? null);
        $this->setIdpName($userProfile['idpName'] ?? null);
        $this->setAffiliation($userProfile['affiliation'] ?? null);
    }

    protected function loadById( string $id ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM users WHERE id = :id ORDER BY id OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("User with id [$id] not found");
    }

    protected function loadByEPPN( string $eppn ): void {
        $stmt = PostgresDB::run(
            "SELECT * FROM users WHERE eppn = :eppn ORDER BY id OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY",
            ['eppn' => $eppn]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row <> null) {
            $this->fill($row);
        }
        else
            throw new PDOException("User with EPPN [$eppn] not found");
    }

    protected function fill(array $row ): void {
        $this->id = $row['id'];
        if (array_key_exists('email', $row))
            $this->email = $row['email'];
        if (array_key_exists('phone_number', $row))
            $this->number = $row['phone_number'];
        if (array_key_exists('timezone', $row))
            $this->timezone = $row['timezone'];
        if (array_key_exists('first_name', $row))
            $this->firstName = $row['first_name'];
        if (array_key_exists('last_name', $row))
            $this->lastName = $row['last_name'];
        if (array_key_exists('full_name', $row))
            $this->fullName = $row['full_name'];
        if (array_key_exists('eppn', $row))
            $this->eppn = $row['eppn'];
        if (array_key_exists('idp', $row))
            $this->idp = $row['idp'];
        if (array_key_exists('idp_name', $row))
            $this->idpName = $row['idp_name'];
        if (array_key_exists('affiliation', $row))
            $this->affiliation = $row['affiliation'];
        if (array_key_exists('is_super_admin', $row))
            $this->superAdmin = self::toBool($row['is_super_admin']);
        if (array_key_exists('memberships', $row) && is_string($row['memberships'])) {
            $decoded = json_decode($row['memberships'], true);
            if (is_array($decoded))
                $this->memberships = $decoded;
        }
    }

    protected function save(): ?User {
        $exists = User::withId($this->getId());
        if (is_null($exists)) {
            $exists_email = (User::withEPPN($this->getEPPN()) ?? User::withEPPN($this->getEmail()));
            if (is_null($exists_email)) {
                $insert = "INSERT INTO users (id, email, phone_number, timezone, first_name, last_name, full_name, eppn, idp, idp_name, affiliation, is_super_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
                PostgresDB::run($insert, [
                    $this->getId(),
                    $this->getEmail(),
                    $this->getNumber(),
                    $this->getTimeZone(),
                    $this->getFirstName(),
                    $this->getLastName(),
                    $this->getFullName(),
                    $this->getEPPN(),
                    $this->getIDP(),
                    $this->getIDPName(),
                    $this->getAffiliation(),
                    $this->isSuperAdmin() ? 'true' : 'false'
                ]);
            } else {
                if (is_null($this->getNumber()) && !is_null($exists_email->getNumber()))
                    $this->setNumber($exists_email->getNumber());
                if (is_null($this->getTimeZone()) && !is_null($exists_email->getTimeZone()))
                    $this->setTimeZone($exists_email->getTimeZone());
                $this->setSuperAdmin($this->isSuperAdmin() || $exists_email->isSuperAdmin());
                $update = "UPDATE users SET id=?, email=?, phone_number=?, timezone=?, first_name=?, last_name=?, full_name=?, eppn=?, idp=?, idp_name=?, affiliation=?, is_super_admin=? WHERE id=?";
                PostgresDB::run($update, [
                    $this->getId(),
                    $this->getEmail(),
                    $this->getNumber(),
                    $this->getTimeZone(),
                    $this->getFirstName(),
                    $this->getLastName(),
                    $this->getFullName(),
                    $this->getEPPN(),
                    $this->getIDP(),
                    $this->getIDPName(),
                    $this->getAffiliation(),
                    $this->isSuperAdmin() ? 'true' : 'false',
                    $exists_email->getId()
                ]);
            }
        } else {
            $update = "UPDATE users SET email=?, phone_number=?, timezone=?, first_name=?, last_name=?, full_name=?, eppn=?, idp=?, idp_name=?, affiliation=?, is_super_admin=? WHERE id=?";
            PostgresDB::run($update, [
                $this->getEmail(),
                $this->getNumber(),
                $this->getTimeZone(),
                $this->getFirstName(),
                $this->getLastName(),
                $this->getFullName(),
                $this->getEPPN(),
                $this->getIDP(),
                $this->getIDPName(),
                $this->getAffiliation(),
                $this->isSuperAdmin() ? 'true' : 'false',
                $this->getId()
            ]);
        }
        return User::withId($this->getId());
    }

    protected function deleteFromId(string $id): bool {
        $stmt = PostgresDB::prepare("DELETE FROM users WHERE id = :id");
        $stmt->bindParam('id', $id);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    public function replaceMemberships(array $memberships, ?string $onlyStudy = null): void {
        if (is_null($onlyStudy)) {
            PostgresDB::run("DELETE FROM user_study_roles WHERE user_id = :user_id", ['user_id' => $this->getId()]);
        } else {
            PostgresDB::run(
                "DELETE FROM user_study_roles WHERE user_id = :user_id AND study_uuid = :study_uuid",
                ['user_id' => $this->getId(), 'study_uuid' => $onlyStudy]
            );
        }
        foreach ($memberships as $membership) {
            if (!isset($membership['study_uuid']) || !isset($membership['role_id']))
                continue;
            if (!is_null($onlyStudy) && $membership['study_uuid'] !== $onlyStudy)
                continue;
            PostgresDB::run(
                "INSERT INTO user_study_roles (user_id, study_uuid, role_id) VALUES (?,?,?) ON CONFLICT (user_id, study_uuid) DO UPDATE SET role_id = EXCLUDED.role_id",
                [$this->getId(), $membership['study_uuid'], (int) $membership['role_id']]
            );
        }
        $this->memberships = null;
    }

    public function getMemberships(): array {
        if (!is_null($this->memberships))
            return $this->memberships;
        if ($this->getId() === "") {
            $this->memberships = [];
            return $this->memberships;
        }
        $memberships = [];
        $stmt = PostgresDB::run(
            "SELECT usr.study_uuid, s.name AS study_name, usr.role_id, r.role_name FROM user_study_roles usr JOIN studies s ON s.study_uuid = usr.study_uuid JOIN user_roles r ON r.role_id = usr.role_id WHERE usr.user_id = :user_id ORDER BY s.name",
            ['user_id' => $this->getId()]
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $memberships[] = [
                'study_uuid' => $row['study_uuid'],
                'study_name' => $row['study_name'],
                'role_id' => (int) $row['role_id'],
                'role_name' => $row['role_name']
            ];
        }
        $this->memberships = $memberships;
        return $this->memberships;
    }

    public function getCurrentStudy(): ?string {
        return $this->currentStudy;
    }

    public function setCurrentStudy(?string $studyUuid): void {
        $this->currentStudy = $studyUuid;
    }

    public function getId(): string {
        return $this->id;
    }

    public function setId(?string $id): void {
        $this->id = $id;
    }

    public function getEmail(): ?string {
        return $this->email;
    }

    public function setEmail(?string $email): void {
        $this->email = $email;
    }

    public function getFirstName(): ?string {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void {
        $this->lastName = $lastName;
    }

    public function getFullName(): ?string {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): void {
        $this->fullName = $fullName;
    }

    public function getEPPN(): string {
        return $this->eppn;
    }

    public function setEPPN(?string $eppn): void {
        $this->eppn = $eppn;
    }

    public function getNumber(): ?string {
        return $this->number;
    }

    public function setNumber(?string $number): void {
        $this->number = $number;
    }

    public function getTimeZone(): ?string {
        return $this->timezone;
    }

    public function setTimeZone(?string $timezone): void {
        $this->timezone = $timezone;
    }

    public function getIDP(): ?string {
        return $this->idp;
    }

    public function setIDP(?string $idp): void {
        $this->idp = $idp;
    }

    public function getIDPName(): ?string {
        return $this->idpName;
    }

    public function setIDPName(?string $idpName): void {
        $this->idpName = $idpName;
    }

    public function getAffiliation(): ?string {
        return $this->affiliation;
    }

    public function setAffiliation(?string $affiliation): void {
        $this->affiliation = $affiliation;
    }

    public function isSuperAdmin(): bool {
        return $this->superAdmin;
    }

    public function setSuperAdmin(bool $superAdmin): void {
        $this->superAdmin = $superAdmin;
    }

    public function hasRole(string $roleName): bool {
        if ($this->isSuperAdmin())
            return true;
        $currentStudy = $this->getCurrentStudy();
        if (is_null($currentStudy))
            return false;
        foreach ($this->getMemberships() as $membership) {
            if ($membership['study_uuid'] === $currentStudy && $membership['role_name'] === $roleName)
                return true;
        }
        return false;
    }

    public function roleInCurrentStudy(): ?string {
        $currentStudy = $this->getCurrentStudy();
        if (is_null($currentStudy))
            return null;
        foreach ($this->getMemberships() as $membership) {
            if ($membership['study_uuid'] === $currentStudy)
                return $membership['role_name'];
        }
        return null;
    }

    public function jsonSerialize(): array {
        return [
            'id'                => $this->getId(),
            'email'             => $this->getEmail(),
            'number'            => $this->getNumber(),
            'firstname'         => $this->getFirstName(),
            'lastname'          => $this->getLastname(),
            'fullname'          => $this->getFullName(),
            'eppn'              => $this->getEPPN(),
            'idp'               => $this->getIDP(),
            'idpname'           => $this->getIDPName(),
            'affiliation'       => $this->getaffiliation(),
            'timezone'          => $this->getTimeZone(),
            'is_super_admin'    => $this->isSuperAdmin(),
            'memberships'       => $this->getMemberships()
        ];
    }

    public function __toString(): string {
        return json_encode($this->jsonSerialize());
    }

    public static function generateRandomId(): string {
        return bin2hex(random_bytes(6));
    }

    public static function generateUUIDv4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function toBool($value): bool {
        return $value === true || $value === 't' || $value === 'true' || $value === '1' || $value === 1;
    }
}
