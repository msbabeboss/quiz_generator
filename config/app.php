<?php

/**
 * config/app.php — Application-level constants and security bootstrap.
 *
 * APP_BASE is the URL path prefix for the application root, without a
 * trailing slash.
 *
 * How to set this:
 *   - Running at http://localhost/quiz_generator/  → APP_BASE = '/quiz_generator'
 *   - Running at a virtual host root (http://casestudy/ or http://quiz.local/) → APP_BASE = ''
 *
 * The value is auto-detected from the .env file if APP_BASE_PATH is set,
 * otherwise it falls back to '/quiz_generator' for standard XAMPP installs.
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

if (!defined('APP_BASE')) {
    // Read from .env if set, otherwise default to /quiz_generator for XAMPP
    $appBasePath = $_ENV['APP_BASE_PATH'] ?? '/quiz_generator';
    define('APP_BASE', rtrim($appBasePath, '/'));
}

// Load security bootstrap (session hardening, HTTPS enforcement, helpers).
require_once __DIR__ . '/security.php';
