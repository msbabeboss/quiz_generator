<?php
/**
 * config/security.php — Centralised security bootstrap.
 *
 * Include this file BEFORE session_start() on every page.
 * It configures:
 *   - Secure session cookie parameters (HttpOnly, SameSite=Strict, Secure on HTTPS)
 *   - HTTPS enforcement (redirect HTTP → HTTPS in production)
 *   - Security response headers (CSP, X-Frame-Options, HSTS, etc.)
 *   - Input sanitisation helpers
 *   - Output escaping helpers
 */

// ---------------------------------------------------------------------------
// 1. HTTPS enforcement
//    Redirect to HTTPS if the request arrived over plain HTTP.
//    Disabled on localhost/127.0.0.1 so local dev still works.
// ---------------------------------------------------------------------------
function enforceHttps(): void {
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    // Skip HTTPS enforcement for:
    // - localhost / 127.0.0.1 (local dev)
    // - LAN IP addresses (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
    //   so other devices on the network can access via plain HTTP
    $isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1'], true)
        || $serverName === 'localhost';

    $isLanIp = (bool) preg_match(
        '/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/',
        $serverName
    );

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    if (!$isLocalhost && !$isLanIp && !$isHttps) {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $url, true, 301);
        exit;
    }
}

// ---------------------------------------------------------------------------
// 2. Secure session cookie parameters
//    Must be called BEFORE session_start().
//
//    - HttpOnly   : JS cannot read the cookie → mitigates XSS session theft
//    - SameSite   : Strict → cookie not sent on cross-site requests → CSRF defence
//    - Secure     : Only sent over HTTPS (set to true when on HTTPS)
//    - Path       : Scoped to the application root
// ---------------------------------------------------------------------------
function configureSecureSession(): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    session_set_cookie_params([
        'lifetime' => 0,           // Session cookie (expires when browser closes)
        'path'     => '/',
        'domain'   => '',          // Current domain only
        'secure'   => $isHttps,    // HTTPS-only when on HTTPS
        'httponly' => true,        // Not accessible via JavaScript
        'samesite' => 'Strict',    // No cross-site sending → CSRF mitigation
    ]);

    // Harden session settings
    ini_set('session.use_strict_mode',   '1'); // Reject unrecognised session IDs
    ini_set('session.use_only_cookies',  '1'); // No session ID in URL
    ini_set('session.cookie_httponly',   '1');
    ini_set('session.cookie_samesite',   'Strict');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
}

// ---------------------------------------------------------------------------
// 3. Security response headers
//    Call after session_start() and before any output.
// ---------------------------------------------------------------------------
function sendSecurityHeaders(): void {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');

    // XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy — don't leak URL to third parties
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions policy — disable unused browser features
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // Content Security Policy
    // - default-src 'self'          : only load resources from same origin
    // - script-src 'self' + CDNs    : allow Bootstrap JS and Pusher from CDN
    // - style-src  'self' + CDN     : allow Bootstrap CSS from CDN
    // - connect-src 'self' + Pusher : allow Pusher WebSocket connections
    // - img-src 'self' data:        : allow inline SVG favicons
    // - font-src 'self'             : local fonts only
    // - frame-ancestors 'none'      : no embedding in iframes
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' https://cdn.jsdelivr.net https://js.pusher.com 'unsafe-inline'; " .
        "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; " .
        "connect-src 'self' wss://*.pusher.com https://*.pusher.com; " .
        "img-src 'self' data:; " .
        "font-src 'self' https://cdn.jsdelivr.net; " .
        "frame-ancestors 'none';"
    );

    // HSTS — only on HTTPS; tells browsers to always use HTTPS for 1 year
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------------------------------------------------------------------------
// 4. Input sanitisation helpers
// ---------------------------------------------------------------------------

/**
 * Sanitise a string input: strip tags, trim whitespace.
 * Use for text fields that should never contain HTML.
 *
 * @param mixed $value Raw input value
 * @param int   $maxLen Maximum allowed length (0 = no limit)
 * @return string
 */
function sanitizeString(mixed $value, int $maxLen = 0): string {
    $str = strip_tags(trim((string) $value));
    if ($maxLen > 0 && mb_strlen($str) > $maxLen) {
        $str = mb_substr($str, 0, $maxLen);
    }
    return $str;
}

/**
 * Sanitise an integer input.
 *
 * @param mixed $value Raw input value
 * @return int
 */
function sanitizeInt(mixed $value): int {
    return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * Sanitise an email address.
 *
 * @param mixed $value Raw input value
 * @return string Sanitised email, or empty string if invalid
 */
function sanitizeEmail(mixed $value): string {
    $email = filter_var(trim((string) $value), FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

// ---------------------------------------------------------------------------
// 5. Output escaping helpers
//    Always use these when rendering user-supplied or DB-sourced data in HTML.
// ---------------------------------------------------------------------------

/**
 * HTML-escape a value for safe output in HTML context.
 * Equivalent to htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').
 *
 * @param mixed $value
 * @return string
 */
function esc(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape a value for safe output inside a JavaScript string literal.
 * Use when injecting PHP values into <script> blocks.
 *
 * @param mixed $value
 * @return string JSON-encoded value (safe for JS context)
 */
function escJs(mixed $value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// 6. Rate limiting helper (simple file-based, for login brute-force protection)
// ---------------------------------------------------------------------------

/**
 * Check and increment a rate limit counter for a given key (e.g. IP address).
 * Returns true if the action is allowed, false if the limit is exceeded.
 *
 * @param string $key      Unique identifier (e.g. 'login_' . $_SERVER['REMOTE_ADDR'])
 * @param int    $maxTries Maximum attempts allowed within $windowSeconds
 * @param int    $windowSeconds Time window in seconds
 * @return bool  true = allowed, false = rate limited
 */
function checkRateLimit(string $key, int $maxTries = 5, int $windowSeconds = 300): bool {
    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    $cacheDir = sys_get_temp_dir() . '/quiz_rl/';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0700, true);
    }
    $file = $cacheDir . $safeKey . '.json';
    $now  = time();

    $data = ['count' => 0, 'window_start' => $now];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }
    }

    // Reset window if expired
    if ($now - $data['window_start'] > $windowSeconds) {
        $data = ['count' => 0, 'window_start' => $now];
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxTries;
}

/**
 * Clear the rate limit counter for a given key (call on successful login).
 *
 * @param string $key
 */
function clearRateLimit(string $key): void {
    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    $cacheDir = sys_get_temp_dir() . '/quiz_rl/';
    $file     = $cacheDir . $safeKey . '.json';
    if (file_exists($file)) @unlink($file);
}

// ---------------------------------------------------------------------------
// Run on include
// ---------------------------------------------------------------------------
enforceHttps();

// Configure session cookie params and start the session if not already active.
// This ensures params are always set before session_start(), regardless of
// include order in individual page files.
if (session_status() === PHP_SESSION_NONE) {
    configureSecureSession();
    session_start();
}
