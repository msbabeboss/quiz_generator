<?php

/**
 * api/submit-answer.php — Per-answer AJAX endpoint.
 *
 * Accepts a POST request with session_id, question_id, answer, and csrf_token.
 * Validates the authenticated student session, verifies CSRF protection,
 * confirms the quiz session belongs to the student and is in_progress, then
 * records the answer via submitAnswer().
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
$sessionId  = isset($_POST['session_id'])  ? (int) $_POST['session_id']  : 0;
$questionId = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
$answer     = isset($_POST['answer'])      ? trim($_POST['answer'])       : '';

if ($sessionId <= 0 || $questionId <= 0 || $answer === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

// ---------------------------------------------------------------------------
// Verify the session belongs to the current student and is in_progress.
// ---------------------------------------------------------------------------
$studentId = (int) $_SESSION['user_id'];

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT id FROM quiz_sessions
         WHERE id = ? AND student_id = ? AND status = \'in_progress\'
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $studentId]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('submit-answer.php PDOException (session check): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if ($row === false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Quiz session not found or not active']);
    exit;
}

// ---------------------------------------------------------------------------
// Record the answer.
// ---------------------------------------------------------------------------
$result = submitAnswer($sessionId, $questionId, $answer);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to record answer']);
}
