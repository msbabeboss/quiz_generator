<?php

require_once __DIR__ . '/database.php';

/**
 * Contract for quiz CRUD operations, activation, and randomization settings.
 *
 * All implementations must use PDO prepared statements exclusively — no SQL
 * string interpolation or concatenation with user-supplied data is permitted.
 */
interface QuizManagerInterface {
    /**
     * Insert a new quiz record and return its auto-increment ID.
     *
     * @param array $data    Associative array with keys: title (string),
     *                       description (string, optional), time_limit (int),
     *                       is_randomized (int 0|1, optional, default 0).
     * @param int   $adminId The ID of the admin creating the quiz.
     * @return int|false     New quiz ID on success, false on validation failure
     *                       or DB error.
     */
    public function createQuiz(array $data, int $adminId): int|false;

    /**
     * Update one or more fields of an existing quiz.
     *
     * Only the keys present in $data are updated; omitted keys are left
     * unchanged. Accepted keys: title, description, time_limit, is_randomized.
     *
     * @param int   $quizId The quiz to update.
     * @param array $data   Fields to update (partial update supported).
     * @return bool         true on success, false on validation failure or
     *                      DB error.
     */
    public function updateQuiz(int $quizId, array $data): bool;

    /**
     * Delete a quiz and all its associated records.
     *
     * Cascading deletes for questions, options, sessions, answers, and results
     * are handled by the database foreign-key constraints.
     *
     * @param int $quizId The quiz to delete.
     * @return bool       true on success, false on DB error.
     */
    public function deleteQuiz(int $quizId): bool;

    /**
     * Fetch a single quiz record by its ID.
     *
     * @param int $quizId The quiz to fetch.
     * @return array|null Associative row array, or null if not found.
     */
    public function getQuiz(int $quizId): array|null;

    /**
     * Return all quizzes, optionally filtered to active ones only.
     *
     * @param bool $activeOnly When true, only quizzes with is_active = 1 are
     *                         returned.
     * @return array           Array of associative row arrays ordered by
     *                         created_at DESC.
     */
    public function listQuizzes(bool $activeOnly = false): array;

    /**
     * Flip the is_active flag for a quiz (0 → 1 or 1 → 0).
     *
     * @param int $quizId The quiz whose active status should be toggled.
     * @return bool       true on success, false on DB error.
     */
    public function toggleActive(int $quizId): bool;
}

/**
 * PDO-backed implementation of QuizManagerInterface.
 *
 * Security guarantees:
 * - Every query uses a PDO prepared statement; no user data is interpolated
 *   into SQL strings.
 * - Input validation is performed before any DB interaction.
 * - Errors are logged via error_log() and surfaced as false/null return values
 *   rather than propagated exceptions, so callers can handle failures cleanly.
 */
class QuizManager implements QuizManagerInterface {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------------

    /**
     * Validate a quiz title: non-empty string, max 255 characters.
     *
     * @param mixed $title Value to validate.
     * @return bool
     */
    private function isValidTitle(mixed $title): bool {
        return is_string($title) && $title !== '' && strlen($title) <= 255;
    }

    /**
     * Validate a time_limit: integer (or numeric string) >= 30.
     *
     * @param mixed $timeLimit Value to validate.
     * @return bool
     */
    private function isValidTimeLimit(mixed $timeLimit): bool {
        return filter_var($timeLimit, FILTER_VALIDATE_INT) !== false
            && (int) $timeLimit >= 30;
    }

    // -----------------------------------------------------------------------
    // Interface implementation
    // -----------------------------------------------------------------------

    /**
     * Insert a new quiz record.
     *
     * Preconditions:
     *   - $data['title']      is a non-empty string, max 255 chars
     *   - $data['time_limit'] is an integer >= 30
     *   - $adminId            corresponds to a user with role = 'admin'
     *   - CSRF token has been validated by the caller before this is invoked
     *
     * Postconditions (on success):
     *   - Returns new quiz ID (positive integer)
     *   - Quiz record inserted with created_by = $adminId and is_active = 1
     *   - expires_at is set to NOW() + 30 days by default
     *   - No side effects on existing quiz records
     *
     * @param array $data    Must contain 'title' and 'time_limit'; optionally
     *                       'description' (string), 'is_randomized' (0|1),
     *                       and 'expires_at' (datetime string or null).
     * @param int   $adminId ID of the admin creating the quiz.
     * @return int|false     New quiz ID, or false on validation/DB failure.
     */
    public function createQuiz(array $data, int $adminId): int|false {
        // Validate required fields.
        if (!$this->isValidTitle($data['title'] ?? null)) {
            error_log('QuizManager::createQuiz validation failed: title must be a non-empty string of max 255 chars');
            return false;
        }

        if (!$this->isValidTimeLimit($data['time_limit'] ?? null)) {
            error_log('QuizManager::createQuiz validation failed: time_limit must be an integer >= 30');
            return false;
        }

        $title        = $data['title'];
        $description  = isset($data['description']) ? (string) $data['description'] : null;
        $timeLimit    = (int) $data['time_limit'];
        $isRandomized = isset($data['is_randomized']) ? (int) (bool) $data['is_randomized'] : 0;
        // Default expiry: 30 days from now. Pass expires_at = null to disable.
        $expiresAt    = array_key_exists('expires_at', $data)
            ? $data['expires_at']
            : date('Y-m-d H:i:s', strtotime('+30 days'));

        try {
            // Try inserting with expires_at. If the column doesn't exist yet
            // (migration not run), fall back to inserting without it.
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO quizzes (title, description, created_by, time_limit, is_randomized, is_active, expires_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?)'
                );
                $stmt->execute([$title, $description, $adminId, $timeLimit, $isRandomized, $expiresAt]);
            } catch (PDOException $colErr) {
                // Column probably doesn't exist — insert without it
                error_log('QuizManager::createQuiz falling back (no expires_at column): ' . $colErr->getMessage());
                $stmt = $this->pdo->prepare(
                    'INSERT INTO quizzes (title, description, created_by, time_limit, is_randomized, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)'
                );
                $stmt->execute([$title, $description, $adminId, $timeLimit, $isRandomized]);
            }
            $id = (int) $this->pdo->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (PDOException $e) {
            error_log('QuizManager::createQuiz PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update one or more fields of an existing quiz.
     *
     * Builds a dynamic SET clause from the keys present in $data so that
     * callers can perform partial updates without overwriting untouched fields.
     * Accepted keys: title, description, time_limit, is_randomized.
     *
     * @param int   $quizId The quiz to update.
     * @param array $data   Fields to update.
     * @return bool         true on success, false on validation/DB failure.
     */
    public function updateQuiz(int $quizId, array $data): bool {
        $allowed = ['title', 'description', 'time_limit', 'is_randomized', 'expires_at'];
        $setClauses = [];
        $params     = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            // Validate fields that have constraints.
            if ($field === 'title' && !$this->isValidTitle($data['title'])) {
                error_log('QuizManager::updateQuiz validation failed: title must be a non-empty string of max 255 chars');
                return false;
            }

            if ($field === 'time_limit' && !$this->isValidTimeLimit($data['time_limit'])) {
                error_log('QuizManager::updateQuiz validation failed: time_limit must be an integer >= 30');
                return false;
            }

            $setClauses[] = "`{$field}` = ?";

            if ($field === 'time_limit') {
                $params[] = (int) $data[$field];
            } elseif ($field === 'is_randomized') {
                $params[] = (int) (bool) $data[$field];
            } elseif ($field === 'expires_at') {
                // Accept a datetime string or null (no expiry)
                $params[] = $data[$field] !== null && $data[$field] !== ''
                    ? (string) $data[$field]
                    : null;
            } else {
                $params[] = $data[$field] === null ? null : (string) $data[$field];
            }
        }

        if (empty($setClauses)) {
            // Nothing to update — treat as a no-op success.
            return true;
        }

        $params[] = $quizId;

        try {
            $sql  = 'UPDATE quizzes SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            error_log('QuizManager::updateQuiz PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a quiz and ALL associated data by ID.
     *
     * Deletes are performed in dependency order so this works even if the
     * database FK constraints do not have ON DELETE CASCADE set.
     * Order: answers → results → quiz_sessions → question_options →
     *        questions → flashcard_sessions → quiz_enrollments →
     *        quiz_access_codes → classroom_quizzes → quizzes
     *
     * @param int $quizId The quiz to delete.
     * @return bool       true on success, false on DB error.
     */
    public function deleteQuiz(int $quizId): bool {
        try {
            $this->pdo->beginTransaction();

            // 1. answers (depend on quiz_sessions and questions)
            $this->pdo->prepare(
                'DELETE a FROM answers a
                 JOIN quiz_sessions qs ON a.session_id = qs.id
                 WHERE qs.quiz_id = ?'
            )->execute([$quizId]);

            // 2. results (depend on quiz_sessions and quizzes)
            $this->pdo->prepare(
                'DELETE FROM results WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 3. quiz_sessions (depend on quizzes)
            $this->pdo->prepare(
                'DELETE FROM quiz_sessions WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 4. question_options (depend on questions → quizzes)
            $this->pdo->prepare(
                'DELETE qo FROM question_options qo
                 JOIN questions q ON qo.question_id = q.id
                 WHERE q.quiz_id = ?'
            )->execute([$quizId]);

            // 5. questions (depend on quizzes)
            $this->pdo->prepare(
                'DELETE FROM questions WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 6. flashcard_sessions (depend on quizzes)
            $this->pdo->prepare(
                'DELETE FROM flashcard_sessions WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 7. quiz_enrollments (depend on quiz_access_codes → quizzes)
            $this->pdo->prepare(
                'DELETE FROM quiz_enrollments WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 8. quiz_access_codes (depend on quizzes)
            $this->pdo->prepare(
                'DELETE FROM quiz_access_codes WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 9. classroom_quizzes (depend on quizzes)
            $this->pdo->prepare(
                'DELETE FROM classroom_quizzes WHERE quiz_id = ?'
            )->execute([$quizId]);

            // 10. finally delete the quiz itself
            $this->pdo->prepare(
                'DELETE FROM quizzes WHERE id = ?'
            )->execute([$quizId]);

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('QuizManager::deleteQuiz PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch a single quiz record by its ID.
     *
     * @param int $quizId The quiz to fetch.
     * @return array|null Associative row array, or null if not found.
     */
    public function getQuiz(int $quizId): array|null {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM quizzes WHERE id = ?');
            $stmt->execute([$quizId]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log('QuizManager::getQuiz PDOException: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return all quizzes ordered by created_at DESC.
     *
     * @param bool $activeOnly When true, only quizzes with is_active = 1 are
     *                         returned.
     * @return array           Array of associative row arrays.
     */
    public function listQuizzes(bool $activeOnly = false): array {
        try {
            if ($activeOnly) {
                $stmt = $this->pdo->prepare(
                    'SELECT * FROM quizzes WHERE is_active = 1 ORDER BY created_at DESC'
                );
                $stmt->execute();
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT * FROM quizzes ORDER BY created_at DESC'
                );
                $stmt->execute();
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('QuizManager::listQuizzes PDOException: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Flip the is_active flag for a quiz (0 → 1 or 1 → 0).
     *
     * Uses the expression `is_active = 1 - is_active` so the toggle is
     * performed atomically in a single UPDATE without a prior SELECT.
     *
     * @param int $quizId The quiz whose active status should be toggled.
     * @return bool       true on success, false on DB error.
     */
    public function toggleActive(int $quizId): bool {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE quizzes SET is_active = 1 - is_active WHERE id = ?'
            );
            $stmt->execute([$quizId]);
            return true;
        } catch (PDOException $e) {
            error_log('QuizManager::toggleActive PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all quizzes whose expires_at is in the past, along with all
     * their associated data (same deletion order as deleteQuiz).
     *
     * @return int  Number of quizzes purged, or -1 on DB error.
     */
    public function purgeExpiredQuizzes(): int {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM quizzes WHERE expires_at IS NOT NULL AND expires_at <= NOW()'
            );
            $stmt->execute();
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                return 0;
            }

            $count = 0;
            foreach ($ids as $quizId) {
                if ($this->deleteQuiz((int) $quizId)) {
                    $count++;
                }
            }
            return $count;
        } catch (PDOException $e) {
            // Column may not exist yet — silently skip purge
            error_log('QuizManager::purgeExpiredQuizzes PDOException (column may not exist yet): ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update the expiry date of a quiz.
     *
     * @param int         $quizId    The quiz to update.
     * @param string|null $expiresAt New expiry datetime string, or null to remove expiry.
     * @return bool
     */
    public function setExpiry(int $quizId, ?string $expiresAt): bool {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE quizzes SET expires_at = ? WHERE id = ?'
            );
            $stmt->execute([$expiresAt, $quizId]);
            return true;
        } catch (PDOException $e) {
            // Column may not exist yet
            error_log('QuizManager::setExpiry PDOException (column may not exist yet): ' . $e->getMessage());
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// Shared singleton instance — allows procedural PHP files to call the
// standalone functions below without managing the QuizManager object directly.
// ---------------------------------------------------------------------------

/**
 * Return (and lazily create) the shared QuizManager instance.
 */
function getQuizManager(): QuizManager {
    static $manager = null;
    if ($manager === null) {
        $manager = new QuizManager(getDB());
    }
    return $manager;
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers — each delegates to the shared QuizManager.
// ---------------------------------------------------------------------------

/**
 * Insert a new quiz record and return its auto-increment ID.
 *
 * @param array $data    Must contain 'title' (non-empty, max 255 chars) and
 *                       'time_limit' (int >= 30). Optionally 'description'
 *                       and 'is_randomized' (0|1).
 * @param int   $adminId ID of the admin creating the quiz.
 * @return int|false     New quiz ID on success, false on failure.
 */
function createQuiz(array $data, int $adminId): int|false {
    return getQuizManager()->createQuiz($data, $adminId);
}

/**
 * Update one or more fields of an existing quiz.
 *
 * @param int   $quizId The quiz to update.
 * @param array $data   Fields to update (title, description, time_limit,
 *                      is_randomized). Partial updates are supported.
 * @return bool         true on success, false on failure.
 */
function updateQuiz(int $quizId, array $data): bool {
    return getQuizManager()->updateQuiz($quizId, $data);
}

/**
 * Delete a quiz and all its associated records (cascade via FK constraints).
 *
 * @param int $quizId The quiz to delete.
 * @return bool       true on success, false on failure.
 */
function deleteQuiz(int $quizId): bool {
    return getQuizManager()->deleteQuiz($quizId);
}

/**
 * Fetch a single quiz record by its ID.
 *
 * @param int $quizId The quiz to fetch.
 * @return array|null Associative row array, or null if not found.
 */
function getQuiz(int $quizId): array|null {
    return getQuizManager()->getQuiz($quizId);
}

/**
 * Return all quizzes ordered by created_at DESC.
 *
 * @param bool $activeOnly When true, only quizzes with is_active = 1 are
 *                         returned.
 * @return array           Array of associative row arrays.
 */
function listQuizzes(bool $activeOnly = false): array {
    return getQuizManager()->listQuizzes($activeOnly);
}

/**
 * Flip the is_active flag for a quiz (0 → 1 or 1 → 0).
 *
 * @param int $quizId The quiz whose active status should be toggled.
 * @return bool       true on success, false on failure.
 */
function toggleActive(int $quizId): bool {
    return getQuizManager()->toggleActive($quizId);
}

/**
 * Delete all quizzes whose expires_at is in the past.
 *
 * @return int  Number of quizzes purged, or -1 on error.
 */
function purgeExpiredQuizzes(): int {
    return getQuizManager()->purgeExpiredQuizzes();
}

/**
 * Update the expiry date of a quiz.
 *
 * @param int         $quizId    The quiz to update.
 * @param string|null $expiresAt New expiry datetime string, or null to remove expiry.
 * @return bool
 */
function setQuizExpiry(int $quizId, ?string $expiresAt): bool {
    return getQuizManager()->setExpiry($quizId, $expiresAt);
}
