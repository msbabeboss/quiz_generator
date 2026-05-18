<?php

require_once __DIR__ . '/env.php';

/**
 * Returns a singleton PDO connection to the MySQL database.
 *
 * On the first call, loads environment variables from the project-root .env
 * file via loadEnv(), builds a DSN from DB_HOST, DB_PORT, and DB_NAME, and
 * creates a PDO instance with the following options:
 *   - ERRMODE_EXCEPTION  — all DB errors throw PDOException
 *   - FETCH_ASSOC        — fetch rows as associative arrays by default
 *   - EMULATE_PREPARES   — disabled so the driver uses true prepared statements
 *
 * Subsequent calls return the same PDO instance without reconnecting.
 *
 * @return PDO The shared database connection.
 * @throws PDOException If the connection cannot be established.
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        loadEnv(__DIR__ . '/../.env');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],   // casestudy
            $_ENV['DB_PORT'],   // 3307
            $_ENV['DB_NAME']    // quiz_db
        );
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
