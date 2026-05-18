<?php
/**
 * api/flashcard-leaderboard.php — Returns top-10 flashcard scores for a quiz.
 * GET: quiz_id
 */
session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$quizId = (int) ($_GET['quiz_id'] ?? 0);
if ($quizId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid quiz_id']);
    exit;
}

try {
    $pdo  = getDB();
    // Best attempt per student (highest percentage, then earliest finish)
    $stmt = $pdo->prepare(
        'SELECT u.username, fs.correct, fs.total_cards,
                ROUND(fs.percentage, 1) AS percentage, fs.score, fs.total_points
         FROM flashcard_sessions fs
         JOIN users u ON fs.student_id = u.id
         WHERE fs.quiz_id = ? AND fs.completed = 1
           AND fs.id = (
               SELECT id FROM flashcard_sessions fs2
               WHERE fs2.student_id = fs.student_id AND fs2.quiz_id = fs.quiz_id AND fs2.completed = 1
               ORDER BY fs2.percentage DESC, fs2.finished_at ASC
               LIMIT 1
           )
         ORDER BY fs.percentage DESC, fs.finished_at ASC
         LIMIT 10'
    );
    $stmt->execute([$quizId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'leaderboard' => $rows]);
} catch (PDOException $e) {
    error_log('flashcard-leaderboard PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
