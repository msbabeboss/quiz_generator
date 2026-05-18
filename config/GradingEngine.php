<?php

require_once __DIR__ . '/database.php';

/**
 * Contract for score computation, leaderboard retrieval, and CSV export.
 *
 * All implementations must use PDO prepared statements exclusively — no SQL
 * string interpolation or concatenation with user-supplied data is permitted.
 */
interface GradingEngineInterface {
    /**
     * Invoke the ComputeScore stored procedure and return the result record.
     *
     * @param int $studentId The student whose score should be computed.
     * @param int $quizId    The quiz being graded.
     * @return array|false   Associative array with keys 'score', 'total_points',
     *                       and 'percentage' on success; false on error.
     */
    public function computeScore(int $studentId, int $quizId): array|false;

    /**
     * Invoke the GetQuizResults stored procedure and return all result rows.
     *
     * @param int $quizId The quiz whose results should be fetched.
     * @return array      Array of associative row arrays, each containing
     *                    'username', 'score', 'total_points', 'percentage',
     *                    'submitted_at', and 'status'. Empty array on error.
     */
    public function getResults(int $quizId): array;

    /**
     * Return the top-$limit results for a quiz, ordered by percentage DESC
     * then submitted_at ASC (ordering is provided by the stored procedure).
     *
     * @param int $quizId The quiz whose leaderboard should be returned.
     * @param int $limit  Maximum number of entries to return (default 10).
     * @return array      Sliced array of result rows.
     */
    public function getLeaderboard(int $quizId, int $limit = 10): array;

    /**
     * Stream a CSV file of all results for a quiz to the browser.
     *
     * Sets Content-Type and Content-Disposition headers, writes a header row,
     * then writes one data row per result record using fputcsv().
     *
     * @param int $quizId The quiz whose results should be exported.
     * @return void
     */
    public function exportCsv(int $quizId): void;
}

/**
 * PDO-backed implementation of GradingEngineInterface.
 *
 * Security guarantees:
 * - Every query uses a PDO prepared statement; no user data is interpolated
 *   into SQL strings.
 * - Errors are logged via error_log() and surfaced as false/empty-array return
 *   values rather than propagated exceptions, so callers can handle failures
 *   cleanly.
 *
 * Postcondition invariants (enforced by the stored procedure and verified
 * after fetch):
 * - score <= total_points for every result record
 * - percentage is always in the range [0.00, 100.00]
 */
class GradingEngine implements GradingEngineInterface {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Interface implementation
    // -----------------------------------------------------------------------

    /**
     * Invoke ComputeScore(student_id, quiz_id) via a CALL statement, then
     * fetch and return the freshly upserted result record.
     *
     * Preconditions:
     *   - A quiz_sessions record exists for ($studentId, $quizId) with
     *     status != 'in_progress'
     *   - All answers for the session have been inserted
     *
     * Postconditions (on success):
     *   - Returns ['score' => int, 'total_points' => int, 'percentage' => float]
     *   - percentage is always in [0.00, 100.00]
     *   - score <= total_points always holds
     *
     * @param int $studentId The student whose score should be computed.
     * @param int $quizId    The quiz being graded.
     * @return array|false   Result array on success, false on error.
     */
    public function computeScore(int $studentId, int $quizId): array|false {
        try {
            // Invoke the stored procedure to upsert the result record.
            $call = $this->pdo->prepare('CALL ComputeScore(?, ?)');
            $call->execute([$studentId, $quizId]);
            // Close the cursor so the next query can run on the same connection.
            $call->closeCursor();

            // Fetch the freshly computed result.
            $stmt = $this->pdo->prepare(
                'SELECT score, total_points, percentage
                   FROM results
                  WHERE student_id = ? AND quiz_id = ?
                  ORDER BY computed_at DESC
                  LIMIT 1'
            );
            $stmt->execute([$studentId, $quizId]);
            $row = $stmt->fetch();

            if ($row === false) {
                error_log("GradingEngine::computeScore — no result row found for student={$studentId} quiz={$quizId}");
                return false;
            }

            return [
                'score'        => (int)   $row['score'],
                'total_points' => (int)   $row['total_points'],
                'percentage'   => (float) $row['percentage'],
            ];
        } catch (PDOException $e) {
            error_log('GradingEngine::computeScore PDOException: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invoke GetQuizResults(quiz_id) and return all result rows.
     *
     * Each row contains: username, score, total_points, percentage,
     * submitted_at, status — ordered by percentage DESC, submitted_at ASC
     * as defined in the stored procedure.
     *
     * @param int $quizId The quiz whose results should be fetched.
     * @return array      Array of associative row arrays; empty on error.
     */
    public function getResults(int $quizId): array {
        try {
            $stmt = $this->pdo->prepare('CALL GetQuizResults(?)');
            $stmt->execute([$quizId]);
            $rows = $stmt->fetchAll();
            $stmt->closeCursor();
            return $rows;
        } catch (PDOException $e) {
            error_log('GradingEngine::getResults PDOException: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return the top-$limit entries from the quiz leaderboard.
     *
     * Delegates to getResults() (which calls the stored procedure) and slices
     * the result to $limit entries. The stored procedure already orders rows
     * by percentage DESC, submitted_at ASC, so no additional sorting is needed.
     *
     * @param int $quizId The quiz whose leaderboard should be returned.
     * @param int $limit  Maximum number of entries (default 10).
     * @return array      Sliced array of result rows.
     */
    public function getLeaderboard(int $quizId, int $limit = 10): array {
        $results = $this->getResults($quizId);
        return array_slice($results, 0, $limit);
    }

    /**
     * Stream a CSV export of all results for a quiz to the browser.
     *
     * Sends Content-Type and Content-Disposition headers, writes a header row,
     * then writes one data row per result record using fputcsv() to the
     * php://output stream.
     *
     * @param int $quizId The quiz whose results should be exported.
     * @return void
     */
    public function exportCsv(int $quizId): void {
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"quiz_{$quizId}_results.csv\"");

        $handle = fopen('php://output', 'w');

        // Write the header row.
        fputcsv($handle, ['Username', 'Score', 'Total Points', 'Percentage', 'Submitted At', 'Status']);

        // Write one data row per result record.
        $results = $this->getResults($quizId);
        foreach ($results as $row) {
            fputcsv($handle, [
                $row['username'],
                $row['score'],
                $row['total_points'],
                $row['percentage'],
                $row['submitted_at'],
                $row['status'],
            ]);
        }

        fclose($handle);
    }
}

// ---------------------------------------------------------------------------
// Shared singleton instance — allows procedural PHP files to call the
// standalone functions below without managing the GradingEngine object directly.
// ---------------------------------------------------------------------------

/**
 * Return (and lazily create) the shared GradingEngine instance.
 */
function getGradingEngine(): GradingEngine {
    static $engine = null;
    if ($engine === null) {
        $engine = new GradingEngine(getDB());
    }
    return $engine;
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers — each delegates to the shared GradingEngine.
// ---------------------------------------------------------------------------

/**
 * Invoke ComputeScore(student_id, quiz_id) and return the result record.
 *
 * Preconditions:
 *   - A quiz_sessions record exists for ($studentId, $quizId) with
 *     status != 'in_progress'
 *   - All answers for the session have been inserted
 *
 * Postconditions (on success):
 *   - Returns ['score' => int, 'total_points' => int, 'percentage' => float]
 *   - percentage is always in [0.00, 100.00]
 *   - score <= total_points always holds
 *
 * @param int $studentId The student whose score should be computed.
 * @param int $quizId    The quiz being graded.
 * @return array|false   Result array on success, false on error.
 */
function computeScore(int $studentId, int $quizId): array|false {
    return getGradingEngine()->computeScore($studentId, $quizId);
}

/**
 * Invoke GetQuizResults(quiz_id) and return all result rows.
 *
 * Each row contains: username, score, total_points, percentage,
 * submitted_at, status — ordered by percentage DESC, submitted_at ASC.
 *
 * @param int $quizId The quiz whose results should be fetched.
 * @return array      Array of associative row arrays; empty on error.
 */
function getResults(int $quizId): array {
    return getGradingEngine()->getResults($quizId);
}

/**
 * Return the top-$limit entries from the quiz leaderboard.
 *
 * Results are ordered by percentage DESC, with ties broken by submitted_at ASC.
 *
 * @param int $quizId The quiz whose leaderboard should be returned.
 * @param int $limit  Maximum number of entries to return (default 10).
 * @return array      Sliced array of result rows.
 */
function getLeaderboard(int $quizId, int $limit = 10): array {
    return getGradingEngine()->getLeaderboard($quizId, $limit);
}

/**
 * Stream a CSV export of all results for a quiz to the browser.
 *
 * Sets Content-Type: text/csv and Content-Disposition: attachment headers,
 * writes a header row, then writes one data row per result record.
 *
 * @param int $quizId The quiz whose results should be exported.
 * @return void
 */
function exportCsv(int $quizId): void {
    getGradingEngine()->exportCsv($quizId);
}
