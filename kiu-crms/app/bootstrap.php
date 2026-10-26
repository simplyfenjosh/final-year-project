<?php
/*
 * Loaded at the top of every page. It reads the settings, starts the session,
 * loads the helper functions and services, and gives overdue cases a chance to escalate.
 */
define('APP_ROOT', dirname(__DIR__));

$GLOBALS['CONFIG'] = require APP_ROOT . '/config/config.php';
require_once __DIR__ . '/helpers.php';

date_default_timezone_set(config('app.timezone', 'Africa/Kampala'));
if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (PHP_SAPI !== 'cli') {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('kiucrms');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

require_once __DIR__ . '/Classifier.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/CaseService.php';
require_once __DIR__ . '/Escalation.php';
require_once __DIR__ . '/reports.php';

if (!defined('SKIP_ESCALATION')) {
    // Gives overdue cases a chance to move up. Rate limited, so it costs almost nothing.
    Escalation::maybeRun(db());
}
