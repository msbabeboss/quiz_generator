<?php

/**
 * config/app.php — Application-level constants and security bootstrap.
 *
 * APP_BASE is the URL path prefix for the application root, without a
 * trailing slash.  Update this value if the app is moved to a different
 * subdirectory under htdocs.
 *
 * Examples:
 *   XAMPP default (htdocs/quiz_generator/): APP_BASE = '/quiz_generator'
 *   Virtual-host root (e.g. http://quiz.local/):  APP_BASE = ''
 */
if (!defined('APP_BASE')) {
    // Empty when served from a virtual-host root (e.g. https://casestudy/)
    // Set to '/quiz_generator' if running under localhost/quiz_generator/
    define('APP_BASE', '');
}

// Load security bootstrap (session hardening, HTTPS enforcement, helpers).
// Must be included before session_start() — all pages include app.php first.
require_once __DIR__ . '/security.php';
