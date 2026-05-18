<?php

/**
 * api/submit-quiz.php — Final quiz submission endpoint.
 *
 * Accepts a POST request with session_id and csrf_token.
 * Validates the authenticated student session, verifies CSRF protection,
 * confirms the quiz session belongs to the student and is in_progress, then
 * calls submitQuiz() which triggers after_session_submit → ComputeScore().
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
    error_log('submit-quiz.php PDOException (session check): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if ($session === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Session not found or already submitted']);
    exit;
}

// ---------------------------------------------------------------------------
// Save all submitted answers before marking the session as complete.
// ---------------------------------------------------------------------------
// Standard answers: answer[question_id] = value
// Enumeration:      enum_answer[question_id][] = [item1, item2, ...]
$postedAnswers = [];

// Collect standard answers (mcq, true_false, identification, fill_blank)
$rawAnswers = $_POST['answer'] ?? [];
if (is_array($rawAnswers)) {
    foreach ($rawAnswers as $qId => $value) {
        $qId = (int) $qId;
        if ($qId > 0 && is_string($value) && trim($value) !== '') {
            $postedAnswers[$qId] = trim($value);
        }
    }
}

// Collect enumeration answers — join items with comma to match stored format
$rawEnum = $_POST['enum_answer'] ?? [];
if (is_array($rawEnum)) {
    foreach ($rawEnum as $qId => $items) {
        $qId = (int) $qId;
        if ($qId > 0 && is_array($items)) {
            $cleaned = array_map('trim', $items);
            // Store as comma-separated, same format as correct_answer
            $postedAnswers[$qId] = implode(',', $cleaned);
        }
    }
}

try {
    // Load all questions for this quiz with their correct answers and types.
    $allQStmt = $pdo->prepare(
        'SELECT id, question_type, correct_answer, points FROM questions WHERE quiz_id = ?'
    );
    $allQStmt->execute([$session['quiz_id']]);
    $allQuestions = $allQStmt->fetchAll(PDO::FETCH_ASSOC);

    // Delete any previously saved answers for this session (clean slate).
    $pdo->prepare('DELETE FROM answers WHERE session_id = ?')->execute([$sessionId]);

    // Insert one row per question — answered or not.
    $insertAnswer = $pdo->prepare(
        'INSERT INTO answers (session_id, question_id, student_answer, is_correct)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($allQuestions as $q) {
        $qId    = (int) $q['id'];
        $qType  = $q['question_type'];
        $given  = $postedAnswers[$qId] ?? null;   // null = skipped

        // Determine correctness based on question type
        $isCorrect = 0;
        if ($given !== null && $given !== '') {
            if ($qType === 'mcq' || $qType === 'true_false') {
                // Exact match (case-sensitive: A/B/C/D or T/F)
                $isCorrect = ($given === $q['correct_answer']) ? 1 : 0;
            } elseif ($qType === 'identification' || $qType === 'fill_blank') {
                // Case-insensitive exact match
                $isCorrect = (strcasecmp(trim($given), trim($q['correct_answer'])) === 0) ? 1 : 0;
            } elseif ($qType === 'enumeration') {
                // Partial credit: count how many items match (case-insensitive, order matters)
                $correctItems = array_map('trim', explode(',', $q['correct_answer']));
                $givenItems   = array_map('trim', explode(',', $given));
                $matched = 0;
                foreach ($correctItems as $idx => $expected) {
                    if (isset($givenItems[$idx]) && strcasecmp($givenItems[$idx], $expected) === 0) {
                        $matched++;
                    }
                }
                // Store as correct if ALL items match; partial credit handled by score override below
                $isCorrect = ($matched === count($correctItems)) ? 1 : 0;
                // Store the match ratio in student_answer for display purposes
                $given = $given; // keep original
            }
        }

        $insertAnswer->execute([$sessionId, $qId, $given, $isCorrect]);
    }
} catch (PDOException $e) {
    error_log('submit-quiz.php PDOException (save answers): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save answers', 'detail' => $e->getMessage()]);
    exit;
}

// ---------------------------------------------------------------------------
// Submit the quiz — triggers after_session_submit → ComputeScore().
// ---------------------------------------------------------------------------
$submitted = submitQuiz($sessionId, $studentId);

if (!$submitted) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to submit quiz']);
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
    error_log('submit-quiz.php PDOException (result fetch): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to fetch result']);
    exit;
}

if ($result === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Result not found after submission']);
    exit;
}

// ---------------------------------------------------------------------------
// Trigger Pusher real-time events.
// ---------------------------------------------------------------------------
$quizId = (int) $session['quiz_id'];

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
