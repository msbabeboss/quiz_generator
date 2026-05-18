<?php

require_once __DIR__ . '/database.php';

/**
 * Contract for managing the lifecycle of a student's quiz attempt.
 *
 * Covers session creation, per-answer submission, manual and automatic quiz
 * submission, time-remaining calculation, and live participant counting.
 *
 * All implementations must use PDO prepared statements exclusively — no SQL
 * string interpolation or concatenation with user-supplied data is permitted.
 */
interface QuizSessionInterface {
    /**
     * Start a new quiz session for a student, or return the existing one.
     *
     * If an in_progress session already exists for the given student/quiz pair
     * the existing session ID is returned without inserting a duplicate row.
     *
     * @param int $studentId The student starting the quiz.
     * @param int $quizId    The quiz being started.
     * @return int|false     Session ID (new or existing) on success, false on
     *                       DB error.
     */
    public function startSession(int $studentId, int $quizId): int|false;

    /**
     * Record a student's answer for a single question within a session.
     *
     * The after_answer_insert trigger fires automatically after the INSERT and
     * sets is_correct = 1 when student_answer matches the question's
     * correct_answer.
     *
     * @param int    $sessionId  The active quiz session.
     * @param int    $questionId The question being answered.
     * @param string $answer     The student's chosen answer (e.g. 'A', 'True').
     * @return bool              true on success, false on DB error.
     */
    public function submitAnswer(int $sessionId, int $questionId, string $answer): bool;

    /**
     * Mark a quiz session as submitted by the student.
     *
     * Verifies that the session belongs to the given student and is still
     * in_progress before updating. The after_session_submit trigger fires
     * automatically and calls ComputeScore().
     *
     * @param int $sessionId The session to submit.
     * @param int $studentId The student who owns the session.
     * @return bool          true when the session was successfully updated
     *                       (rowCount > 0), false otherwise.
     */
    public function submitQuiz(int $sessionId, int $studentId): bool;

    /**
     * Auto-submit a timed-out session, filling in NULL answers for any
     * unanswered questions and marking the session as timed_out.
     *
     * Steps:
     *   1. Fetch all question IDs for the quiz.
     *   2. Fetch already-answered question IDs for the session.
     *   3. Insert NULL/is_correct=0 answer rows for every unanswered question.
     *   4. UPDATE quiz_sessions SET status='timed_out', submitted_at=NOW().
     *
     * The after_session_submit trigger fires on step 4 and calls ComputeScore()
     * automatically.
     *
     * @param int $sessionId The session to auto-submit.
     * @param int $studentId The student who owns the session.
     * @param int $quizId    The quiz associated with the session.
     * @return bool          true on success, false on DB error.
     */
    public function autoSubmit(int $sessionId, int $studentId, int $quizId): bool;

    /**
     * Calculate the number of seconds remaining in a quiz session.
     *
     * Computed as: time_limit - TIMESTAMPDIFF(SECOND, started_at, NOW()).
     * The value may be negative if the time limit has already elapsed.
     *
     * @param int $sessionId The session to check.
     * @return int           Seconds remaining (can be negative), or 0 if the
     *                       session is not found.
     */
    public function getTimeRemaining(int $sessionId): int;

    /**
     * Return the number of students currently taking a quiz.
     *
     * Counts quiz_sessions rows with status = 'in_progress' for the given quiz.
     *
     * @param int $quizId The quiz to count active participants for.
     * @return int        Number of in-progress sessions.
     */
    public function getParticipantCount(int $quizId): int;
}

/**
 * PDO-backed implementation of QuizSessionInterface.
 *
 * Security guarantees:
 * - Every query uses a PDO prepared statement; no user data is interpolated
 *   into SQL strings.
 * - Errors are logged via error_log() and surfaced as false/0 return values
 *   rather than propagated exceptions, so callers can handle failures cleanly.
 */
class QuizSession implements QuizSessionInterface {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Interface implementation
    // -----------------------------------------------------------------------

    /**
     * Start a new quiz session for a student, or return the existing one.
     *
     * Preconditions:
     *   - $studentId corresponds to a user with role = 'student'
     *   - $quizId    corresponds to an active quiz
     *
     * Postconditions (on success):
     *   - Returns an existing in_progress session ID if one already exists
     *     (no duplicate row is inserted)
     *   - Otherwise inserts a new quiz_sessions row with status = 'in_progress'
     *     and returns the new session ID
     *
     * @param int $studentId The student starting the quiz.
     * @param int $quizId    The quiz being started.
     * @return int|false     Session ID on success, false on DB error.
     */
    public function startSession(int $studentId, int $quizId): int|false {
        try {
            // Check for any existing session (in_progress OR already completed).
            $stmt = $this->pdo->prepare(
                "SELECT id, status FROM quiz_sessions
                 WHERE student_id = ? AND quiz_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute([$studentId, $quizId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing !== false) {
                // Resume an in-progress session.
                if ($existing['status'] === 'in_progress') {
                    return (int) $existing['id'];
                }
                // Already submitted or timed out — do not allow a new attempt.
                return false;
            }

            // No existing session — create a new one.
            $insert = $this->pdo->prepare(
                "INSERT INTO quiz_sessions (student_id, quiz_id, status)
                 VALUES (?, ?, 'in_progress')"
            );
            $insert->execute([$studentId, $quizId]);
            $id = (int) $this->pdo->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (PDOException $e) {
            error_log('QuizSession::startSession PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a student's answer for a single question within a session.
     *
     * The after_answer_insert trigger fires automatically after the INSERT and
     * sets is_correct = 1 when student_answer matches the question's
     * correct_answer.
     *
     * Preconditions:
     *   - $sessionId  corresponds to an in_progress quiz_sessions row
     *   - $questionId corresponds to a question in the quiz for that session
     *
     * Postconditions (on success):
     *   - A new answers row is inserted with the provided answer
     *   - The trigger sets is_correct automatically
     *
     * @param int    $sessionId  The active quiz session.
     * @param int    $questionId The question being answered.
     * @param string $answer     The student's chosen answer.
     * @return bool              true on success, false on DB error.
     */
    public function submitAnswer(int $sessionId, int $questionId, string $answer): bool {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO answers (session_id, question_id, student_answer)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$sessionId, $questionId, $answer]);
            return true;
        } catch (PDOException $e) {
            error_log('QuizSession::submitAnswer PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a quiz session as submitted by the student.
     *
     * Verifies ownership and in_progress status in the WHERE clause so that
     * the UPDATE is a no-op (rowCount = 0) if the session does not belong to
     * the student or has already been submitted/timed_out.
     *
     * The after_session_submit trigger fires automatically on a successful
     * UPDATE and calls ComputeScore(student_id, quiz_id).
     *
     * Preconditions:
     *   - Session with $sessionId exists, belongs to $studentId, and has
     *     status = 'in_progress'
     *
     * Postconditions (on success):
     *   - Session status updated to 'submitted'; submitted_at set to NOW()
     *   - after_session_submit trigger fires → ComputeScore() called
     *   - Returns true (rowCount > 0)
     *
     * @param int $sessionId The session to submit.
     * @param int $studentId The student who owns the session.
     * @return bool          true on success, false otherwise.
     */
    public function submitQuiz(int $sessionId, int $studentId): bool {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = \'submitted\', submitted_at = NOW()
                 WHERE id = ? AND student_id = ? AND status = \'in_progress\''
            );
            $stmt->execute([$sessionId, $studentId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('QuizSession::submitQuiz PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-submit a timed-out session, filling in NULL answers for any
     * unanswered questions and marking the session as timed_out.
     *
     * Preconditions:
     *   - Session with $sessionId exists, belongs to $studentId, and has
     *     status = 'in_progress'
     *   - Time remaining for the session is <= 0
     *
     * Postconditions (on success):
     *   - NULL/is_correct=0 answer rows inserted for every unanswered question
     *   - Session status updated to 'timed_out'; submitted_at set to NOW()
     *   - after_session_submit trigger fires → ComputeScore() called
     *   - Returns true
     *
     * @param int $sessionId The session to auto-submit.
     * @param int $studentId The student who owns the session.
     * @param int $quizId    The quiz associated with the session.
     * @return bool          true on success, false on DB error.
     */
    public function autoSubmit(int $sessionId, int $studentId, int $quizId): bool {
        try {
            // Fetch all question IDs for the quiz.
            $allStmt = $this->pdo->prepare(
                'SELECT id FROM questions WHERE quiz_id = ?'
            );
            $allStmt->execute([$quizId]);
            $allIds = $allStmt->fetchAll(PDO::FETCH_COLUMN);

            // Fetch already-answered question IDs for this session.
            $answeredStmt = $this->pdo->prepare(
                'SELECT question_id FROM answers WHERE session_id = ?'
            );
            $answeredStmt->execute([$sessionId]);
            $answeredIds = $answeredStmt->fetchAll(PDO::FETCH_COLUMN);

            // Determine which questions still need a NULL answer.
            $unansweredIds = array_diff($allIds, $answeredIds);

            // Insert NULL/is_correct=0 rows for each unanswered question.
            if (!empty($unansweredIds)) {
                $nullInsert = $this->pdo->prepare(
                    'INSERT INTO answers (session_id, question_id, student_answer, is_correct)
                     VALUES (?, ?, NULL, 0)'
                );
                foreach ($unansweredIds as $questionId) {
                    $nullInsert->execute([$sessionId, (int) $questionId]);
                }
            }

            // Mark the session as timed_out; triggers after_session_submit.
            $updateStmt = $this->pdo->prepare(
                'UPDATE quiz_sessions
                 SET status = \'timed_out\', submitted_at = NOW()
                 WHERE id = ? AND student_id = ? AND status = \'in_progress\''
            );
            $updateStmt->execute([$sessionId, $studentId]);

            return true;
        } catch (PDOException $e) {
            error_log('QuizSession::autoSubmit PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate the number of seconds remaining in a quiz session.
     *
     * Uses TIMESTAMPDIFF(SECOND, started_at, NOW()) so the calculation is
     * performed entirely in MySQL, avoiding clock-skew issues between the
     * application server and the database server.
     *
     * @param int $sessionId The session to check.
     * @return int           Seconds remaining (may be negative if elapsed),
     *                       or 0 if the session is not found.
     */
    public function getTimeRemaining(int $sessionId): int {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT q.time_limit - TIMESTAMPDIFF(SECOND, qs.started_at, NOW()) AS remaining
                 FROM quiz_sessions qs
                 JOIN quizzes q ON qs.quiz_id = q.id
                 WHERE qs.id = ?'
            );
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();

            if ($row === false) {
                return 0;
            }

            return (int) $row['remaining'];
        } catch (PDOException $e) {
            error_log('QuizSession::getTimeRemaining PDOException: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Return the number of students currently taking a quiz.
     *
     * Counts quiz_sessions rows with status = 'in_progress' for the given quiz.
     *
     * @param int $quizId The quiz to count active participants for.
     * @return int        Number of in-progress sessions.
     */
    public function getParticipantCount(int $quizId): int {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM quiz_sessions
                 WHERE quiz_id = ? AND status = \'in_progress\''
            );
            $stmt->execute([$quizId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('QuizSession::getParticipantCount PDOException: ' . $e->getMessage());
            return 0;
        }
    }
}

// ---------------------------------------------------------------------------
// Shared singleton instance — allows procedural PHP files to call the
// standalone functions below without managing the QuizSession object directly.
// ---------------------------------------------------------------------------

/**
 * Return (and lazily create) the shared QuizSession instance.
 */
function getQuizSession(): QuizSession {
    static $session = null;
    if ($session === null) {
        $session = new QuizSession(getDB());
    }
    return $session;
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers — each delegates to the shared QuizSession.
// ---------------------------------------------------------------------------

/**
 * Start a new quiz session for a student, or return the existing one.
 *
 * If an in_progress session already exists for the given student/quiz pair
 * the existing session ID is returned without inserting a duplicate row.
 *
 * @param int $studentId The student starting the quiz.
 * @param int $quizId    The quiz being started.
 * @return int|false     Session ID (new or existing) on success, false on
 *                       DB error.
 */
function startSession(int $studentId, int $quizId): int|false {
    return getQuizSession()->startSession($studentId, $quizId);
}

/**
 * Record a student's answer for a single question within a session.
 *
 * The after_answer_insert trigger fires automatically and grades the answer.
 *
 * @param int    $sessionId  The active quiz session.
 * @param int    $questionId The question being answered.
 * @param string $answer     The student's chosen answer (e.g. 'A', 'True').
 * @return bool              true on success, false on DB error.
 */
function submitAnswer(int $sessionId, int $questionId, string $answer): bool {
    return getQuizSession()->submitAnswer($sessionId, $questionId, $answer);
}

/**
 * Mark a quiz session as submitted by the student.
 *
 * The after_session_submit trigger fires automatically and calls ComputeScore().
 *
 * @param int $sessionId The session to submit.
 * @param int $studentId The student who owns the session.
 * @return bool          true when the session was successfully updated, false
 *                       otherwise.
 */
function submitQuiz(int $sessionId, int $studentId): bool {
    return getQuizSession()->submitQuiz($sessionId, $studentId);
}

/**
 * Auto-submit a timed-out session, filling in NULL answers for any unanswered
 * questions and marking the session as timed_out.
 *
 * @param int $sessionId The session to auto-submit.
 * @param int $studentId The student who owns the session.
 * @param int $quizId    The quiz associated with the session.
 * @return bool          true on success, false on DB error.
 */
function autoSubmit(int $sessionId, int $studentId, int $quizId): bool {
    return getQuizSession()->autoSubmit($sessionId, $studentId, $quizId);
}

/**
 * Calculate the number of seconds remaining in a quiz session.
 *
 * The value may be negative if the time limit has already elapsed.
 *
 * @param int $sessionId The session to check.
 * @return int           Seconds remaining (can be negative), or 0 if the
 *                       session is not found.
 */
function getTimeRemaining(int $sessionId): int {
    return getQuizSession()->getTimeRemaining($sessionId);
}

/**
 * Return the number of students currently taking a quiz.
 *
 * @param int $quizId The quiz to count active participants for.
 * @return int        Number of in-progress sessions.
 */
function getParticipantCount(int $quizId): int {
    return getQuizSession()->getParticipantCount($quizId);
}
