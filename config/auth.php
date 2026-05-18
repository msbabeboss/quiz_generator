<?php

require_once __DIR__ . '/database.php';

/**
 * Contract for authentication, session management, and CSRF protection.
 */
interface AuthInterface {
    public function authenticate(string $username, string $password): array|false;
    public function registerUser(string $username, string $email, string $password, string $role): int|false;
    public function logout(): void;
    public function getCurrentUser(): array|null;
    public function hasRole(string $role): bool;
    public function generateCsrfToken(): string;
    public function validateCsrfToken(string $token): bool;
}

/**
 * Concrete implementation of AuthInterface.
 *
 * Security guarantees:
 * - Passwords are never stored, logged, or returned in plaintext.
 * - All DB queries use PDO prepared statements (no string interpolation).
 * - session_regenerate_id(true) is called on every successful login to
 *   prevent session fixation attacks.
 * - CSRF tokens are compared with hash_equals() to prevent timing attacks.
 */
class Auth implements AuthInterface {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verify credentials and start an authenticated session.
     *
     * - Username is trimmed and matched case-sensitively (BINARY comparison).
     * - Password is trimmed then verified against the bcrypt hash.
     * - Suspended accounts are rejected after hash verification.
     *
     * @return array{id:int,username:string,role:string}|false
     */
    public function authenticate(string $username, string $password): array|false {
        // Trim whitespace from both fields before any comparison.
        $username = trim($username);
        $password = trim($password);

        if ($username === '' || $password === '') {
            return false;
        }

        // BINARY forces a byte-for-byte (case-sensitive) username match.
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, status FROM users WHERE BINARY username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // password_verify is always case-sensitive — no change needed there.
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Block suspended accounts.
        if (($user['status'] ?? 'active') === 'suspended') {
            return false;
        }

        // Prevent session fixation by regenerating the session ID on login.
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['csrf_token'] = $this->generateCsrfToken();

        // Return only safe fields — never expose the password hash.
        return [
            'id'       => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ];
    }

    /**
     * Hash the password with bcrypt (cost 12) and insert a new user record.
     *
     * - Username and email are trimmed before storage.
     * - Password is trimmed before hashing.
     * - Username is stored exactly as provided (case preserved).
     *
     * @return int|false New user ID, or false on failure.
     */
    public function registerUser(string $username, string $email, string $password, string $role): int|false {
        // Trim all inputs before storing.
        $username = trim($username);
        $email    = trim($email);
        $password = trim($password);

        if ($username === '' || $email === '' || $password === '') {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$username, $email, $hash, $role]);
            $id = (int) $this->pdo->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (PDOException $e) {
            // Duplicate key (username or email already exists) — return false
            // without leaking internal details.
            error_log('Auth::registerUser PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Destroy the current session completely.
     */
    public function logout(): void {
        session_destroy();
    }

    /**
     * Return the currently authenticated user's session data, or null.
     *
     * @return array{user_id:int,username:string,role:string,csrf_token:string}|null
     */
    public function getCurrentUser(): array|null {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        return [
            'user_id'    => $_SESSION['user_id'],
            'username'   => $_SESSION['username'],
            'role'       => $_SESSION['role'],
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ];
    }

    /**
     * Check whether the authenticated user holds the given role.
     */
    public function hasRole(string $role): bool {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    /**
     * Return the current session CSRF token, generating a new one only if
     * none exists yet.
     *
     * Calling this multiple times on the same page now returns the same token
     * instead of overwriting it, which previously caused every POST to fail
     * CSRF validation with a 403 Forbidden.
     *
     * Uses bin2hex(random_bytes(32)) — 256 bits of entropy.
     */
    public function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token against the session token using a
     * constant-time comparison to prevent timing-based attacks.
     */
    public function validateCsrfToken(string $token): bool {
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}

// ---------------------------------------------------------------------------
// Shared singleton instance — allows procedural PHP files to call the
// standalone functions below without managing the Auth object themselves.
// ---------------------------------------------------------------------------

/**
 * Return (and lazily create) the shared Auth instance.
 */
function getAuth(): Auth {
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth(getDB());
    }
    return $auth;
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers — each delegates to the shared Auth object.
// ---------------------------------------------------------------------------

/**
 * Verify credentials and start an authenticated session.
 *
 * @return array{id:int,username:string,role:string}|false
 */
function authenticate(string $username, string $password): array|false {
    return getAuth()->authenticate($username, $password);
}

/**
 * Hash the password with bcrypt (cost 12) and insert a new user record.
 *
 * @return int|false New user ID, or false on failure.
 */
function registerUser(string $username, string $email, string $password, string $role = 'student'): int|false {
    // Only allow teacher and student roles publicly
    if (!in_array($role, ['teacher', 'student'], true)) {
        $role = 'student';
    }
    return getAuth()->registerUser($username, $email, $password, $role);
}

/**
 * Destroy the current session completely.
 */
function logout(): void {
    getAuth()->logout();
}

/**
 * Return the currently authenticated user's session data, or null.
 *
 * @return array{user_id:int,username:string,role:string,csrf_token:string}|null
 */
function getCurrentUser(): array|null {
    return getAuth()->getCurrentUser();
}

/**
 * Check whether the authenticated user holds the given role.
 */
function hasRole(string $role): bool {
    return getAuth()->hasRole($role);
}

/**
 * Return the current session CSRF token, generating a new one only if none
 * exists yet. Safe to call multiple times on the same page.
 */
function generateCsrfToken(): string {
    return getAuth()->generateCsrfToken();
}

/**
 * Validate a submitted CSRF token against the session token using a
 * constant-time comparison.
 */
function validateCsrfToken(string $token): bool {
    return getAuth()->validateCsrfToken($token);
}
