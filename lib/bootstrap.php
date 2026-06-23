<?php
/**
 * Bootstrap — included by every entry point (index.php, api.php).
 * Loads config, sets up sessions, and pulls in the core libraries.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('CONFIG_FILE', ROOT . '/config.php');

// Not installed yet? Send the user to the installer (unless they're already there).
if (!file_exists(CONFIG_FILE)) {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($self) !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    return; // install.php handles its own bootstrapping
}

$GLOBALS['__config'] = require CONFIG_FILE;

// Errors: log, don't display (never leak stack traces to the browser)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/migrate.php';

date_default_timezone_set(cfg('app.timezone', 'Asia/Kolkata'));

// Keep the database schema up to date (no-op once current).
maybe_migrate();

// Hardened session cookie
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('IBCTRACK');
    session_start();
}
