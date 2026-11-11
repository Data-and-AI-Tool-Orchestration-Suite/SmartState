<?php

class PostgresDB {
    protected static $instance = null;

    protected function __construct() {}
    protected function __clone() {}

    public static function instance() {
        global $config;
        if (self::$instance === null) {
            $dsn = "pgsql:host={$config['postgres']['host']};port={$config['postgres']['port']};dbname={$config['postgres']['name']}";
            try {
                self::$instance = new PDO($dsn, $config['postgres']['user'], $config['postgres']['pass']);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                throw new PDOException("{$e->getMessage()} (on line {$e->getLine()})", (int)$e->getCode());
            }
        }
        return self::$instance;
    }

    public static function __callStatic($method, $args) {
        return call_user_func_array(array(self::instance(), $method), $args);
    }

    public static function run($sql, $args = []) {
        if (!$args)
            return self::instance()->query($sql);
        $stmt = self::instance()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public static function prepare($sql) {
        return self::instance()->prepare($sql);
    }

    public static function setSessionContext(string $userId, bool $isSuperAdmin): void {
        self::run(
            "SELECT set_config('app.user_id', ?, false), set_config('app.is_super_admin', ?, false)",
            [$userId, $isSuperAdmin ? 'true' : 'false']
        );
    }

    public static function setSurveyToken(string $token): void {
        self::run("SELECT set_config('app.survey_token', ?, false)", [$token]);
    }

    public static function close() {
        self::$instance = null;
    }
}