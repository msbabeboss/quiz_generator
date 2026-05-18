<?php
/**
 * api/save-flashcard.php — Save a completed flashcard session.
 * POST: csrf_token, quiz_id, score, total_points, percentage, correct, wrong, total_cards
 */
session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$studentId   = (int) $_SESSION['user_id'];
$quizId      = (int) ($_POST['quiz_id']      ?? 0);
$score       = (int) ($_POST['score']        ?? 0);
$totalPoints = (int) ($_POST['total_points'] ?? 0);
$percentage  = min(100, max(0, (float) ($_POST['percentage'] ?? 0)));
$correct     = (int) ($_POST['correct']      ?? 0);
$wrong       = (int) ($_POST['wrong']        ?? 0);
$totalCards  = (int) ($_POST['total_cards']  ?? 0);

if ($quizId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid quiz_id']);
    exit;
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO flashcard_sessions
            (quiz_id, student_id, total_cards, correct, wrong, score, total_points, percentage, completed, finished_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
    );
    $stmt->execute([$quizId, $studentId, $totalCards, $correct, $wrong, $score, $totalPoints, $percentage]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('save-flashcard PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
