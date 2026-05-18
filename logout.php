<?php
/**
 * logout.php — Securely destroy the authenticated session.
 *
 * Steps:
 *   1. Start the session so we can access and clear it.
 *   2. Wipe all session variables.
 *   3. Delete the session cookie from the browser by expiring it.
 *   4. Destroy the server-side session data.
 *   5. Redirect to login.
 */

require_once __DIR__ . '/config/app.php'; // loads security.php → configureSecureSession()

// 1. Wipe all session variables
$_SESSION = [];

// 2. Expire the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',                          // empty value
        [
            'expires'  => time() - 42000, // past timestamp → browser deletes it
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );
}

// 3. Destroy the server-side session
session_destroy();

// 4. Redirect to login
require_once __DIR__ . '/config/auth.php';
header('Location: ' . APP_BASE . '/login.php');
exit;
