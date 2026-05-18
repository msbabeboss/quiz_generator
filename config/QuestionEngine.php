<?php

require_once __DIR__ . '/database.php';

/**
 * Contract for managing quiz questions and their answer options.
 *
 * Supports two question types:
 *   - 'mcq'        — multiple-choice with four options labelled A, B, C, D
 *   - 'true_false' — two options labelled T and F
 *
 * All implementations must use PDO prepared statements exclusively — no SQL
 * string interpolation or concatenation with user-supplied data is permitted.
 */
interface QuestionEngineInterface {
    /**
     * Insert a new question for the given quiz and return its auto-increment ID.
     *
     * @param int   $quizId The quiz this question belongs to.
     * @param array $data   Associative array with keys:
     *                        - question_text   (string, non-empty)
     *                        - question_type   ('mcq', 'true_false', 'identification', 'fill_blank', or 'enumeration')
     *                        - correct_answer  (string, non-empty)
     *                        - points          (int, optional, default 1)
     *                        - order_index     (int, optional, default 0)
     * @return int|false    New question ID on success, false on validation
     *                      failure or DB error.
     */
    public function addQuestion(int $quizId, array $data): int|false;

    /**
     * Update one or more fields of an existing question.
     *
     * Only the keys present in $data are updated; omitted keys are left
     * unchanged. Accepted keys: question_text, question_type, correct_answer,
     * points, order_index.
     *
     * @param int   $questionId The question to update.
     * @param array $data       Fields to update (partial update supported).
     * @return bool             true on success, false on validation failure or
     *                          DB error.
     */
    public function updateQuestion(int $questionId, array $data): bool;

    /**
     * Delete a question by ID.
     *
     * Cascading deletes for question_options are handled by the database
     * foreign-key constraints.
     *
     * @param int $questionId The question to delete.
     * @return bool           true on success, false on DB error.
     */
    public function deleteQuestion(int $questionId): bool;

    /**
     * Return all questions for a quiz, each with a nested 'options' array.
     *
     * Questions are ordered by order_index ASC. If $shuffle is true the
     * returned array is shuffled before being returned (used when the quiz
     * has is_randomized = 1).
     *
     * @param int  $quizId  The quiz whose questions to fetch.
     * @param bool $shuffle When true, shuffle the questions array.
     * @return array        Array of associative question rows, each containing
     *                      an 'options' key with the question's option rows
     *                      ordered by option_label ASC.
     */
    public function getQuestions(int $quizId, bool $shuffle = false): array;

    /**
     * Insert an answer option for a question and return its auto-increment ID.
     *
     * @param int    $questionId  The question this option belongs to.
     * @param string $label       Single-character label (e.g. 'A', 'B', 'T').
     * @param string $text        The display text for this option.
     * @return int|false          New option ID on success, false on DB error.
     */
    public function addOption(int $questionId, string $label, string $text): int|false;
}

/**
 * PDO-backed implementation of QuestionEngineInterface.
 *
 * Security guarantees:
 * - Every query uses a PDO prepared statement; no user data is interpolated
 *   into SQL strings.
 * - Input validation is performed before any DB interaction.
 * - Errors are logged via error_log() and surfaced as false/empty-array return
 *   values rather than propagated exceptions, so callers can handle failures
 *   cleanly.
 */
class QuestionEngine implements QuestionEngineInterface {

    /** @var string[] Valid question type values. */
    private const VALID_TYPES = ['mcq', 'true_false', 'identification', 'fill_blank', 'enumeration'];

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------------

    /**
     * Return true when $type is one of the accepted question type strings.
     *
     * @param mixed $type Value to validate.
     * @return bool
     */
    private function isValidType(mixed $type): bool {
        return is_string($type) && in_array($type, self::VALID_TYPES, true);
    }

    /**
     * Return true when $value is a non-empty string.
     *
     * @param mixed $value Value to validate.
     * @return bool
     */
    private function isNonEmptyString(mixed $value): bool {
        return is_string($value) && $value !== '';
    }

    // -----------------------------------------------------------------------
    // Interface implementation
    // -----------------------------------------------------------------------

    /**
     * Insert a new question for the given quiz.
     *
     * Preconditions:
     *   - $data['question_type']  is 'mcq' or 'true_false'
     *   - $data['question_text']  is a non-empty string
     *   - $data['correct_answer'] is a non-empty string
     *   - CSRF token has been validated by the caller before this is invoked
     *
     * Postconditions (on success):
     *   - Returns new question ID (positive integer)
     *   - Question record inserted with quiz_id = $quizId
     *   - No side effects on existing question records
     *
     * @param int   $quizId The quiz this question belongs to.
     * @param array $data   Must contain 'question_text', 'question_type', and
     *                      'correct_answer'. Optionally 'points' (int, default
     *                      1) and 'order_index' (int, default 0).
     * @return int|false    New question ID, or false on validation/DB failure.
     */
    public function addQuestion(int $quizId, array $data): int|false {
        // Validate question_type.
        if (!$this->isValidType($data['question_type'] ?? null)) {
            error_log(
                'QuestionEngine::addQuestion validation failed: question_type must be '
                . implode(' or ', array_map(fn($t) => "'$t'", self::VALID_TYPES))
            );
            return false;
        }

        // Validate question_text.
        if (!$this->isNonEmptyString($data['question_text'] ?? null)) {
            error_log('QuestionEngine::addQuestion validation failed: question_text must be a non-empty string');
            return false;
        }

        // Validate correct_answer.
        if (!$this->isNonEmptyString($data['correct_answer'] ?? null)) {
            error_log('QuestionEngine::addQuestion validation failed: correct_answer must be a non-empty string');
            return false;
        }

        $questionText  = $data['question_text'];
        $questionType  = $data['question_type'];
        $correctAnswer = $data['correct_answer'];
        $points        = isset($data['points']) ? (int) $data['points'] : 1;
        $orderIndex    = isset($data['order_index']) ? (int) $data['order_index'] : 0;

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO questions (quiz_id, question_text, question_type, correct_answer, points, order_index)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$quizId, $questionText, $questionType, $correctAnswer, $points, $orderIndex]);
            $id = (int) $this->pdo->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (PDOException $e) {
            error_log('QuestionEngine::addQuestion PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert an answer option for a question.
     *
     * @param int    $questionId The question this option belongs to.
     * @param string $label      Single-character label (e.g. 'A', 'B', 'T').
     * @param string $text       The display text for this option.
     * @return int|false         New option ID on success, false on DB error.
     */
    public function addOption(int $questionId, string $label, string $text): int|false {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO question_options (question_id, option_label, option_text)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$questionId, $label, $text]);
            $id = (int) $this->pdo->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (PDOException $e) {
            error_log('QuestionEngine::addOption PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update one or more fields of an existing question.
     *
     * Builds a dynamic SET clause from the keys present in $data so that
     * callers can perform partial updates without overwriting untouched fields.
     * Accepted keys: question_text, question_type, correct_answer, points,
     * order_index.
     *
     * @param int   $questionId The question to update.
     * @param array $data       Fields to update.
     * @return bool             true on success, false on validation/DB failure.
     */
    public function updateQuestion(int $questionId, array $data): bool {
        $allowed    = ['question_text', 'question_type', 'correct_answer', 'points', 'order_index'];
        $setClauses = [];
        $params     = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            // Validate fields that have constraints.
            if ($field === 'question_type' && !$this->isValidType($data['question_type'])) {
                error_log(
                    'QuestionEngine::updateQuestion validation failed: question_type must be '
                    . implode(' or ', array_map(fn($t) => "'$t'", self::VALID_TYPES))
                );
                return false;
            }

            if ($field === 'question_text' && !$this->isNonEmptyString($data['question_text'])) {
                error_log('QuestionEngine::updateQuestion validation failed: question_text must be a non-empty string');
                return false;
            }

            if ($field === 'correct_answer' && !$this->isNonEmptyString($data['correct_answer'])) {
                error_log('QuestionEngine::updateQuestion validation failed: correct_answer must be a non-empty string');
                return false;
            }

            $setClauses[] = "`{$field}` = ?";

            if ($field === 'points' || $field === 'order_index') {
                $params[] = (int) $data[$field];
            } else {
                $params[] = (string) $data[$field];
            }
        }

        if (empty($setClauses)) {
            // Nothing to update — treat as a no-op success.
            return true;
        }

        $params[] = $questionId;

        try {
            $sql  = 'UPDATE questions SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            error_log('QuestionEngine::updateQuestion PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a question by ID.
     *
     * Cascading deletes for question_options are handled automatically by the
     * FK constraint defined in the database schema.
     *
     * @param int $questionId The question to delete.
     * @return bool           true on success, false on DB error.
     */
    public function deleteQuestion(int $questionId): bool {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM questions WHERE id = ?');
            $stmt->execute([$questionId]);
            return true;
        } catch (PDOException $e) {
            error_log('QuestionEngine::deleteQuestion PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Return all questions for a quiz, each with a nested 'options' array.
     *
     * Questions are fetched ordered by order_index ASC. For each question its
     * options are fetched in a separate query ordered by option_label ASC and
     * attached under the 'options' key. If $shuffle is true the questions
     * array is shuffled with PHP's shuffle() before being returned.
     *
     * @param int  $quizId  The quiz whose questions to fetch.
     * @param bool $shuffle When true, shuffle the questions array.
     * @return array        Array of associative question rows with 'options'.
     */
    public function getQuestions(int $quizId, bool $shuffle = false): array {
        try {
            // Fetch all questions for the quiz ordered by order_index.
            $qStmt = $this->pdo->prepare(
                'SELECT * FROM questions WHERE quiz_id = ? ORDER BY order_index ASC'
            );
            $qStmt->execute([$quizId]);
            $questions = $qStmt->fetchAll();

            // Fetch options for each question and attach them.
            $oStmt = $this->pdo->prepare(
                'SELECT * FROM question_options WHERE question_id = ? ORDER BY option_label ASC'
            );

            foreach ($questions as &$question) {
                $oStmt->execute([$question['id']]);
                $question['options'] = $oStmt->fetchAll();
            }
            unset($question); // Break the reference to the last element.

            // Optionally randomise question order.
            if ($shuffle) {
                shuffle($questions);
            }

            return $questions;
        } catch (PDOException $e) {
            error_log('QuestionEngine::getQuestions PDOException: ' . $e->getMessage());
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// Shared singleton instance — allows procedural PHP files to call the
// standalone functions below without managing the QuestionEngine object
// directly.
// ---------------------------------------------------------------------------

/**
 * Return (and lazily create) the shared QuestionEngine instance.
 */
function getQuestionEngine(): QuestionEngine {
    static $engine = null;
    if ($engine === null) {
        $engine = new QuestionEngine(getDB());
    }
    return $engine;
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers — each delegates to the shared QuestionEngine.
// ---------------------------------------------------------------------------

/**
 * Insert a new question for the given quiz and return its auto-increment ID.
 *
 * @param int   $quizId The quiz this question belongs to.
 * @param array $data   Must contain 'question_text' (non-empty string),
 *                      'question_type' ('mcq' or 'true_false'), and
 *                      'correct_answer' (non-empty string). Optionally
 *                      'points' (int, default 1) and 'order_index' (int,
 *                      default 0).
 * @return int|false    New question ID on success, false on failure.
 */
function addQuestion(int $quizId, array $data): int|false {
    return getQuestionEngine()->addQuestion($quizId, $data);
}

/**
 * Insert an answer option for a question and return its auto-increment ID.
 *
 * @param int    $questionId The question this option belongs to.
 * @param string $label      Single-character label (e.g. 'A', 'B', 'T', 'F').
 * @param string $text       The display text for this option.
 * @return int|false         New option ID on success, false on failure.
 */
function addOption(int $questionId, string $label, string $text): int|false {
    return getQuestionEngine()->addOption($questionId, $label, $text);
}

/**
 * Update one or more fields of an existing question.
 *
 * @param int   $questionId The question to update.
 * @param array $data       Fields to update (question_text, question_type,
 *                          correct_answer, points, order_index). Partial
 *                          updates are supported.
 * @return bool             true on success, false on failure.
 */
function updateQuestion(int $questionId, array $data): bool {
    return getQuestionEngine()->updateQuestion($questionId, $data);
}

/**
 * Delete a question and all its associated options (cascade via FK constraints).
 *
 * @param int $questionId The question to delete.
 * @return bool           true on success, false on failure.
 */
function deleteQuestion(int $questionId): bool {
    return getQuestionEngine()->deleteQuestion($questionId);
}

/**
 * Return all questions for a quiz, each with a nested 'options' array.
 *
 * @param int  $quizId  The quiz whose questions to fetch.
 * @param bool $shuffle When true, shuffle the questions array before returning.
 * @return array        Array of associative question rows with 'options'.
 */
function getQuestions(int $quizId, bool $shuffle = false): array {
    return getQuestionEngine()->getQuestions($quizId, $shuffle);
}
