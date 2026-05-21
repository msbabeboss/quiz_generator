<?php

/**
 * config/app.php — Application-level constants and security bootstrap.
 *
 * APP_BASE is auto-detected from the request URI so the app works
 * regardless of what folder name it is placed in under htdocs.
 *
 * Examples:
 *   http://localhost/quiz_generator/      → APP_BASE = '/quiz_generator'
 *   http://localhost/quiz_generator-main/ → APP_BASE = '/quiz_generator-main'
 *   http://casestudy/                     → APP_BASE = ''
 *
 * You can override this by setting APP_BASE_PATH in your .env file.
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

if (!defined('APP_BASE')) {
    $envBase = $_ENV['APP_BASE_PATH'] ?? null;

    if ($envBase !== null && $envBase !== '') {
        // Explicit override in .env — use it as-is
        define('APP_BASE', rtrim($envBase, '/'));
    } elseif ($envBase === '') {
        // Explicitly blank in .env — virtual host root (e.g. http://casestudy/)
        define('APP_BASE', '');
    } else {
        // Auto-detect from the script path — works with any folder name
        // e.g. /quiz_generator/config/app.php → /quiz_generator
        $scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $parts      = explode('/', trim($scriptDir, '/'));
        $base       = !empty($parts[0]) ? '/' . $parts[0] : '';
        define('APP_BASE', $base);
    }
}

// Load security bootstrap (session hardening, HTTPS enforcement, helpers).
require_once __DIR__ . '/security.php';
