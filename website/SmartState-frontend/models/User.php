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
    protected array $roles = [];

    /**
     * Creates new user given the user profile object from CiLogon
     * @param array $userProfile object returned by CiLogon
     * @return User|null
     * @throws Exception if you do not provide a valid user profile
     */
    public static function create(array $userProfile): ?User {
        if (is_null($userProfile['eppn'])) { //If no EPPN field, use email field instead
            $existing = self::withEPPN($userProfile['email']);
            if (is_null($existing)) {
                $instance = new self();
                $instance->updateWithProfile($userProfile);
                $instance->setRoles([self::getRoleIdFromName("Default")]);
                Participant::updateAdmin(json_encode(['first_name' => $instance->getFirstName(), 'last_name' => $instance->getLastName(), 'number' => $instance->getNumber(), 'time_zone' => $instance->getTimeZone()]), 'ADMIN');
                return $instance->save();
            }
        }
        else {
            $existing = self::withEPPN($userProfile['eppn']);
            if (is_null($existing)) { //If EPPN doesn't work, try email field instead
                $existing = self::withEPPN($userProfile['email']);
                if (is_null($existing)) {
                    $instance = new self();
                    $instance->updateWithProfile($userProfile);
                    $instance->setRoles([self::getRoleIdFromName("Default")]);
                    Participant::updateAdmin(json_encode(['first_name' => $instance->getFirstName(), 'last_name' => $instance->getLastName(), 'number' => $instance->getNumber(), 'time_zone' => $instance->getTimeZone()]), 'ADMIN');
                    return $instance->save();
                }
            }
        }
        // If already existing user
        $existing->updateWithProfile($userProfile);
        if (!$existing->getRoles()){
            $existing->setRoles([self::getRoleIdFromName("Default")]);
        }
        Participant::updateAdmin(json_encode(['first_name' => $existing->getFirstName(), 'last_name' => $existing->getLastName(), 'number' => $existing->getNumber(), 'time_zone' => $existing->getTimeZone()]), 'ADMIN');
        return $existing->save();
    }

    /**
     * Creates an empty user for when the user is not logged in
     * @return User
     */
    public static function createEmptyUser(): User {
        $instance = new self();
        $instance->setId("");
        $instance->setEPPN("");
        return $instance;
    }

    /**
     * Creates new user before they log in to the site for the first time
     * @param string $email the user's email
     * @param array $roles  the ids of the user roles
     * @return User|null
     * @throws Exception if you do not provide a valid email or roles
     */
    public static function createBeforeLogin(string $email, string $number, string $timezone, array $roles): ?User {
        $existing = self::withEPPN($email);
        if (!is_null($existing)) {
            return $existing;
        }
        $instance = new self();
        $instance->setId('notloggedin_'. self::generateRandomId());
        $instance->setEPPN($email);
        $instance->setNumber($number);
        $instance->setTimeZone($timezone);
        $instance->setRoles($roles);

        return $instance->save();
    }

    /**
     * Update user profile
     * @param string $id CiLogon oid of user to update
     * @param array $userProfile the profile obtained by CiLogon
     * @return User the updated User object
     * @throws Exception if no user with supplied oid exists
     */
    public static function updateProfile(string $id, array $userProfile): User {
        if (empty($id))
            throw new Exception('You must provide an id to update a user\'s profile');
        $instance = User::withId($id);
        if (is_null($instance))
            throw new Exception("No user found with id [$id]");
        $instance->updateWithProfile($userProfile);
        return $instance->save();
    }

    /** Update a user (pre-login), no updates after login
     * @param $id string the id of the user
     * @param $email string the updated email of the user
     * @param $roles array the updated role ids of a user
     * @return User object
     * @throws Exception if no user exists or no id was provided
     */
    public static function update(string $id, string $email, string $number, string $timezone, array $roles): User {
        if (!isset($id) || !isset($email) || !isset($number) || !isset($roles))
            throw new Exception('You must provide an id, email, phone number, and user roles to update a user');
        $instance = User::withId($id);
        if (is_null($instance))
            throw new Exception("No user found with id [$id]");

        $instance->setEPPN($email);
        $instance->setNumber($number);
        $instance->setTimeZone($timezone);
        $instance->setRoles($roles);
        return $instance->save();
    }

    /**
     * Delete a user with ID
     * @param string $id ID to be deleted
     * @returns bool $status True/False if sucessfully deleted
     * @throws Exception if no user is found
     */
    public static function delete(string $id): bool {
        if (empty($id))
            throw new Exception('ID for user not found.');
        $instance = new self();
        return $instance->deleteFromId($id);
    }


    /**
     * Gets all users
     * @return array<User>
     */
    public static function all(): array {
        $users = [];
        $query = "SELECT * FROM users";
        $stmt = PostgresDB::run($query);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $users[] = User::withRow($row);
        return $users;
    }

    /**
     * Gets the total count from user database
     * @return int
     */
    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT count(id) FROM users");
        return $stmt->fetchColumn();
    }

    /**
     * Gets the total count from user database with filter
     * @param string $filter which user param to filter by
     * @return int
     */
    public static function countFilteredForDatatable(string $filter): int {
        $query = "SELECT count(id) FROM users";
        if (strlen($filter) > 0) {
            $filter = "%$filter%";
            $query .= " WHERE (full_name LIKE :filter)";
        }
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * Get users in format for datatable
     * @param int $start start of list
     * @param int $length how many entries to display
     * @param string $order_by a column to order by
     * @param string $order_dir asc or desc
     * @param string $filter string to filter from search
     * @return array<User> users for datatable
     */
    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        $query = "SELECT * FROM users";
        $prune = '';
        if ($length > 0)
            $prune .= " OFFSET $start ROWS FETCH NEXT $length ROWS ONLY";
        if ($order_by == '' || $order_by == '0')
            $order_by = 'full_name';
        
        // Sanitize order_by: allow only alphanumeric and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $order_by)) {
            $order_by = 'full_name';
        }

        if ($order_dir == '')
            $order_dir = 'desc';
        
        // Sanitize order_dir: allow only ASC or DESC
        if (!in_array(strtoupper($order_dir), ['ASC', 'DESC'])) {
            $order_dir = 'DESC';
        }

        if (strlen($filter) > 0) {
            $filter = '%' . $filter . '%';
            $query .= " WHERE (full_name LIKE :filter)";
        }
        $query .= " ORDER BY $order_by $order_dir$prune";
        $stmt = PostgresDB::prepare($query);
        if (strlen($filter) > 0) {
            $stmt->bindValue('filter', $filter);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $matches[] = User::withRow($row);
        return $matches;
    }

    /**
     * Wrapper for getting User with ID
     * @param string $id ID of the user
     * @return User|null
     *@throws PDOException if something DB related goes wrong
     */
    public static function withId( string $id ): ?User {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Wrapper for getting User with email (EPPN)
     * @param string|null $email email of the user
     * @return User|null
     *@throws PDOException if something DB related goes wrong
     */
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

    /**
     * gets all the user roles
     * @return array|null of all user roles
     */
    public static function getAllRoles(): ?array {
        $roles = [];
        $query = "SELECT * FROM user_roles WHERE role_name != 'Default' ORDER BY role_id";
        try{
            $stmt = PostgresDB::run($query);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
                $roles[] = array($row['role_id'], $row['role_name']);
        } catch (PDOException $e){
            return null;
        }
        return $roles;
    }

    /**
     * Wrapper for loading a User from row
     * @param array $row single row returned by database query
     * @return User
     */
    public static function withRow(array $row ): User {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    /**
     * Gets the role name given a role id
     * @param $role_id int the id of the role
     * @return string the name of the role
     * @throws PDOException when database is angry
     */
    public static function getRoleNameFromId(int $role_id): string {
        $stmt = PostgresDB::run("SELECT role_name FROM user_roles WHERE role_id = :id", ['id' => $role_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row <> null)
            return $row['role_name'];
        else
            throw new PDOException("Could not find role named for role id [$role_id]");
    }

    /**
     * Gets the role id given a role name
     * @param $role_name int the name of the role
     * @return int the id of the role
     * @throws PDOException when database is angry
     */
    public static function getRoleIdFromName(string $role_name): int {
        $stmt = PostgresDB::run("SELECT role_id FROM user_roles WHERE role_name = :name", ['name' => $role_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row <> null)
            return $row['role_id'];
        else
            throw new PDOException("Could not find role id for role name [$role_name]");
    }

    private static function convertPGArrayToPHP(string $pgArray){
        if (!$pgArray || $pgArray == "{}"){
            return [];
        }
        $pgArray = trim($pgArray, '{}');
        return explode(',', $pgArray);
    }

    private static function convertPHPArrayToPG(array $phpArray){
        if (is_null($phpArray) || !$phpArray){
            return '{}';
        }
        return '{' . implode(',', $phpArray) . '}';
    }


    /**
     * Updates object with new user profile content
     * @param array<string> $userProfile user profile from CiLogon
     * @return void
     */
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


    /**
     * database call to actually get the user with an ID
     * @param string $id user ID
     * @return void
     *@throws PDOException if something goes wrong with the Database
     */
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

    /**
     * database call to actually get the user with an email (EPPN)
     * @param string $email user email
     * @return void
     *@throws PDOException if something goes wrong with the Database
     */
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

    /**
     * Fills User object given a row from the database
     * @param array $row row from database
     * @return void
     */
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
        if (array_key_exists('roles', $row)){
            if (!$row["roles"] || $row["roles"] == "{}"){
                $this->roles = [self::getRoleIdFromName("Default")];
            } else {
                $this->roles = self::convertPGArrayToPHP($row['roles']);
            }
        }

    }

    /**
     * Save or Update a User object
     * @return User|null
     */
    protected function save(): ?User {
        $exists = User::withId($this->getId());
        if (is_null($exists)) {
            $exists_email = (User::withEPPN($this->getEPPN()) ?? User::withEPPN($this->getEmail()));
            if (is_null($exists_email)) {
                // create the new user
                $insert = "INSERT INTO users (id, email, phone_number, timezone, first_name, last_name, full_name, eppn, idp, idp_name, affiliation, roles) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
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
                    self::convertPHPArrayToPG($this->getRoles())
                ]);
            } else {
                $update = "UPDATE users SET id=?, email=?, phone_number=?, timezone=?, first_name=?, last_name=?, full_name=?, eppn=?, idp=?, idp_name=?, affiliation=?, roles=? WHERE id=?";
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
                    self::convertPHPArrayToPG($this->getRoles()),
                    $exists_email->getId()
                ]);
            }
        } else {
            $update = "UPDATE users SET email=?, phone_number=?, timezone=?, first_name=?, last_name=?, full_name=?, eppn=?, idp=?, idp_name=?, affiliation=?, roles=? WHERE id=?";
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
                self::convertPHPArrayToPG($this->getRoles()),
                $this->getId()
            ]);
        }
        return User::withId($this->getId());
    }

    /**
     * database function to delete a user by ID
     * @param string $id the user ID to delete
     * @return bool True if deleted, False if not
     */
    protected function deleteFromId(string $id): bool {
        $stmt = PostgresDB::prepare("DELETE FROM users WHERE id = :id");
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
    public function getId(): string {
        return $this->id;
    }
    /**
     * @param string $id
     */
    public function setId(?string $id): void {
        $this->id = $id;
    }

    /**
     * @return string|null
     */
    public function getEmail(): ?string {
        return $this->email;
    }

    /**
     * @param string $email
     * @return void
     */
    public function setEmail(?string $email): void {
        $this->email = $email;
    }

    /**
     * @return string|null
     */
    public function getFirstName(): ?string {
        return $this->firstName;
    }

    /**
     * @param string $firstName
     * @return void
     */
    public function setFirstName(?string $firstName): void {
        $this->firstName = $firstName;
    }

    /**
     * @return string|null
     */
    public function getLastName(): ?string {
        return $this->lastName;
    }

    /**
     * @param string $lastName
     * @return void
     */
    public function setLastName(?string $lastName): void {
        $this->lastName = $lastName;
    }

    /**
     * @return string|null
     */
    public function getFullName(): ?string {
        return $this->fullName;
    }

    /**
     * @param string $fullName
     * @return void
     */
    public function setFullName(?string $fullName): void {
        $this->fullName = $fullName;
    }

    /**
     * @return string
     */
    public function getEPPN(): string {
        return $this->eppn;
    }

    /**
     * @param string $eppn
     * @return void
     */
    public function setEPPN(?string $eppn): void {
        $this->eppn = $eppn;
    }

    /**
     * @return string
     */
    public function getNumber(): ?string {
        return $this->number;
    }

    /**
     * @param string $number
     * @return void
     */
    public function setNumber(string $number): void {
        $this->number = $number;
    }

    /**
     * @return string
     */
    public function getTimeZone(): ?string {
        return $this->timezone;
    }

    /**
     * @param string $timezone
     * @return void
     */
    public function setTimeZone(string $timezone): void {
        $this->timezone = $timezone;
    }

    /**
     * @return string|null
     */
    public function getIDP(): ?string {
        return $this->idp;
    }

    /**
     * @param string $idp
     * @return void
     */
    public function setIDP(?string $idp): void {
        $this->idp = $idp;
    }

    /**
     * @return string|null
     */
    public function getIDPName(): ?string {
        return $this->idpName;
    }

    /**
     * @param string $idpName
     * @return void
     */
    public function setIDPName(?string $idpName): void {
        $this->idpName = $idpName;
    }

    /**
     * @return string|null
     */
    public function getAffiliation(): ?string {
        return $this->affiliation;
    }

    /**
     * @param string $affiliation
     * @return void
     */
    public function setAffiliation(?string $affiliation): void {
        $this->affiliation = $affiliation;
    }

    /**
     * @return string
     */
    public function getRoles(): array {
        return $this->roles;
    }

    /**
     * @param array $roles
     * @return void
     */
    public function setRoles(array $roles): void {
        $this->roles = $roles;
    }

    /**
     * @param string $role
     * @return void
     */
    public function addRole(string $newRole): void {
        if (!in_array($newRole, $this->roles)) {
            // Add the value if it doesn't exist
            $this->roles[] = $newRole;
        }
    }

    /**
     * @param string $role
     * @return void
     */
    public function removeRole(string $role): void {
        $key = array_search($role, $this->roles);
        // If the value is found, remove it
        if ($key !== false) {
            unset($this->roles[$key]);
        }
    }

    /**
     * @return bool
     */
    public function hasRole(string $roleName): bool {
        foreach ($this->roles as $roleId){
            if ($roleName == self::getRoleNameFromId($roleId)){
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public function isSuperAdmin(): bool {
        return self::hasRole("Super Admin");
    }

    /**
     * @return bool
     */
    public function isStudyAdmin(): bool {
        return self::hasRole("Study Admin");
    }

    /**
     * Encodes User Object into a JSON string
     * @return array
     */
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
            'roles'             => $this->getRoles()
        ];
    }

    /**
     * @return string
     */
    public function __toString(): string {
        return json_encode($this->jsonSerialize());
    }

    /**
     * Generates a random 7-digit number to use for temp logins when a user is added notloggedin_<number>
     * @return string
     */
    public static function generateRandomId(): string {
        return str_pad(mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
    }
}