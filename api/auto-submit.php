<?php

/**
 * api/auto-submit.php — Timeout auto-submit endpoint.
 *
 * Accepts a POST request with session_id and csrf_token.
 * Validates the authenticated student session, verifies CSRF protection,
 * confirms the quiz session belongs to the student and is in_progress, then
 * calls autoSubmit() which fills NULL answers for unanswered questions,
 * marks the session as timed_out, and triggers after_session_submit →
 * ComputeScore().
 * Fetches the computed result, fires Pusher real-time events,
 * and returns JSON with the result.
 *
 * Always responds with JSON.
 */

session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/QuizSession.php';

header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// Authentication check — must be a logged-in student.
// ---------------------------------------------------------------------------
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF validation.
// ---------------------------------------------------------------------------
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ---------------------------------------------------------------------------
// Input retrieval.
// ---------------------------------------------------------------------------
$sessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
$studentId = (int) $_SESSION['user_id'];

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid session_id']);
    exit;
}

// ---------------------------------------------------------------------------
// Verify the session belongs to the current student and is in_progress.
// ---------------------------------------------------------------------------
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT qs.id, qs.quiz_id
         FROM quiz_sessions qs
         WHERE qs.id = ? AND qs.student_id = ? AND qs.status = \'in_progress\'
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $studentId]);
    $session = $stmt->fetch();
} catch (PDOException $e) {
    error_log('auto-submit.php PDOException (session check): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if ($session === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found or already submitted']);
    exit;
}

$quizId = (int) $session['quiz_id'];

// ---------------------------------------------------------------------------
// Auto-submit the quiz — fills NULL answers for unanswered questions,
// marks session as timed_out, and triggers after_session_submit →
// ComputeScore().
// ---------------------------------------------------------------------------
$submitted = autoSubmit($sessionId, $studentId, $quizId);

if (!$submitted) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to auto-submit quiz']);
    exit;
}

// ---------------------------------------------------------------------------
// Fetch the computed result from the results table.
// ---------------------------------------------------------------------------
try {
    $res = $pdo->prepare(
        'SELECT score, total_points, percentage FROM results WHERE session_id = ?'
    );
    $res->execute([$sessionId]);
    $result = $res->fetch();
} catch (PDOException $e) {
    error_log('auto-submit.php PDOException (result fetch): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch result']);
    exit;
}

if ($result === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Result not found after auto-submission']);
    exit;
}

// ---------------------------------------------------------------------------
// Trigger Pusher real-time events.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../config/pusher.php';
require_once __DIR__ . '/../config/GradingEngine.php';

triggerQuizSubmitted(
    $studentId,
    $_SESSION['username'],
    $quizId,
    (float) $result['percentage']
);

triggerScoreUpdated($quizId, getLeaderboard($quizId));

triggerParticipantUpdate($quizId, getParticipantCount($quizId));

// ---------------------------------------------------------------------------
// Return the result to the client.
// ---------------------------------------------------------------------------
echo json_encode([
    'success' => true,
    'result'  => [
        'score'        => (int)   $result['score'],
        'total_points' => (int)   $result['total_points'],
        'percentage'   => (float) $result['percentage'],
    ],
]);
