<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/auth.php';

/**
 * Middleware helpers for route-level access control.
 *
 * Usage:
 *   requireAuth();           // any logged-in user
 *   requireRole('teacher');  // only users with the 'teacher' role
 *
 * Both functions redirect to login.php using APP_BASE so that the redirect
 * works correctly whether the app is served from the web root or a
 * subdirectory (e.g. /quiz_generator/ on a default XAMPP installation).
 *
 * Security headers (CSP, X-Frame-Options, HSTS, etc.) are sent automatically
 * by requireAuth() and requireRole() via sendSecurityHeaders().
 */

/**
 * Redirect to login.php if no authenticated session exists.
 */
function requireAuth(): void {
    sendSecurityHeaders();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
}

/**
 * Redirect to login.php if the authenticated user does not hold $role.
 * Accepts a single role string or an array of allowed roles.
 *
 * @param string|string[] $role
 */
function requireRole(string|array $role): void {
    sendSecurityHeaders();
    $roles = is_array($role) ? $role : [$role];
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
}
