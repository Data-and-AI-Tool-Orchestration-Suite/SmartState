<?php
date_default_timezone_set('UTC');

$config = include_once 'config.php';

require_once __DIR__ . '/vendor/autoload.php';

// Handle error logging for environment mode
$environment = $config['environment'] ?? null;
if ($environment && $environment == "development") {
    // Environment in DEVELOPMENT mode
    ini_set('display_startup_errors', 1);
    ini_set('display_errors', 1);
    error_reporting(E_ALL); // Log all errors to error logs
} else {
    // Environment in PRODUCTION mode
    if ($environment != "production"){
        error_log("Environment variable not specified or set to invalid value in config.php. Defaulting to \"production\"");
    }
    ini_set('display_startup_errors', 0);
    ini_set('display_errors', 0);
    error_reporting(E_ALL);// Log all errors to error logs
}

ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr');

// Set cookie lifetime to session max-age and do not make it eligible for garbage collection
// until it has reached max-age
ini_set('session.gc_maxlifetime', $config['sessions']['max-age']);
session_set_cookie_params($config['sessions']['max-age']);

session_start();

require_once __DIR__ . '/routes.php';