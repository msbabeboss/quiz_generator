<?php
/**
 * student/take-quiz.php — Quiz-taking page (student only).
 *
 * Requirements: 4.1, 4.2, 4.3
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/QuestionEngine.php';
require_once __DIR__ . '/../config/QuizSession.php';

// Guard: only students may access this page.
requireRole('student');

// ---------------------------------------------------------------------------
// Load environment variables (needed for PUSHER_KEY)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

// ---------------------------------------------------------------------------
// Validate quiz_id query parameter
// ---------------------------------------------------------------------------
if (empty($_GET['quiz_id'])) {
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

$quizId = (int) $_GET['quiz_id'];

// ---------------------------------------------------------------------------
// Load quiz — redirect if not found or not active
// ---------------------------------------------------------------------------
$quiz = getQuiz($quizId);

if ($quiz === null || (int) $quiz['is_active'] !== 1) {
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// Block access if the student has already completed this quiz
// ---------------------------------------------------------------------------
$studentId = (int) $_SESSION['user_id'];

try {
    $pdo = getDB();
    $checkStmt = $pdo->prepare(
        "SELECT id FROM quiz_sessions
         WHERE student_id = ? AND quiz_id = ? AND status IN ('submitted', 'timed_out')
         LIMIT 1"
    );
    $checkStmt->execute([$studentId, $quizId]);
    $completedSession = $checkStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('take-quiz.php PDOException (completion check): ' . $e->getMessage());
    $completedSession = false;
}

if ($completedSession !== false) {
    // Student already submitted — redirect to their results
    header('Location: ' . APP_BASE . '/student/results.php?session_id=' . (int) $completedSession . '&already_submitted=1');
    exit;
}

// ---------------------------------------------------------------------------
// Enrollment check — student must be enrolled via exam code OR classroom.
// Direct URL access without either enrollment type is blocked.
// ---------------------------------------------------------------------------
try {
    // Path 1: direct exam code enrollment
    $enrollCheck = $pdo->prepare(
        'SELECT qe.id FROM quiz_enrollments qe
         WHERE qe.student_id = ? AND qe.quiz_id = ?
         LIMIT 1'
    );
    $enrollCheck->execute([$studentId, $quizId]);
    $hasDirectEnrollment = (bool) $enrollCheck->fetch();

    // Path 2: classroom enrollment — student joined a room that has this quiz
    $hasClassroomAccess = false;
    if (!$hasDirectEnrollment) {
        $classCheck = $pdo->prepare(
            'SELECT ce.id
             FROM classroom_enrollments ce
             JOIN classrooms c         ON ce.classroom_id = c.id AND c.is_active = 1
             JOIN classroom_quizzes cq ON cq.classroom_id = c.id AND cq.quiz_id = ?
             WHERE ce.student_id = ?
             LIMIT 1'
        );
        $classCheck->execute([$quizId, $studentId]);
        $hasClassroomAccess = (bool) $classCheck->fetch();
    }

    if (!$hasDirectEnrollment && !$hasClassroomAccess) {
        header('Location: ' . APP_BASE . '/student/join.php?error=not_enrolled');
        exit;
    }
} catch (PDOException $e) {
    error_log('take-quiz.php PDOException (enrollment check): ' . $e->getMessage());
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// Start (or resume) a quiz session for this student
// ---------------------------------------------------------------------------
$sessionId = startSession($studentId, $quizId);

if ($sessionId === false) {
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load questions (shuffled if quiz is randomized)
// ---------------------------------------------------------------------------
$questions = getQuestions($quizId, (bool) $quiz['is_randomized']);

// ---------------------------------------------------------------------------
// CSRF token and other values needed in the template
// ---------------------------------------------------------------------------
$csrfToken      = $_SESSION['csrf_token'] ?? generateCsrfToken();
$pusherKey      = $_ENV['PUSHER_KEY']     ?? '';
$timeLimit      = (int) $quiz['time_limit'];
$participantCount = getParticipantCount($quizId);

// Convenience: escape helper
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($quiz['title']) ?> — Take Quiz</title>
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <style>
        /* Sticky timer bar */
        #quiz-timer-bar {
            position: sticky;
            top: 0;
            z-index: 1030;
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= APP_BASE ?>/student/dashboard.php">🧠 Quiz App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= APP_BASE ?>/student/dashboard.php">Dashboard</a></li>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><span class="nav-link text-light"><?= e($_SESSION['username'] ?? '') ?></span></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_BASE ?>/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Sticky timer bar -->
<div id="quiz-timer-bar" class="bg-dark text-white py-2">
    <div class="container-fluid d-flex justify-content-between align-items-center">
        <div>
            <strong><?= e($quiz['title']) ?></strong>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Participant count -->
            <span class="badge bg-info text-dark fs-6">
                <span id="participant-count"><?= e((string) $participantCount) ?></span>
                student(s) taking this quiz
            </span>
            <!-- Countdown timer -->
            <span class="fs-5 fw-bold">
                Time remaining: <span id="timer-display">--:--</span>
            </span>
        </div>
    </div>
</div>

<div class="container mt-4 mb-5">

    <h1 class="mb-1"><?= e($quiz['title']) ?></h1>

    <?php if (!empty($quiz['description'])): ?>
        <p class="text-muted mb-4"><?= e($quiz['description']) ?></p>
    <?php endif; ?>

    <?php if (empty($questions)): ?>
        <div class="alert alert-warning">
            This quiz has no questions yet. Please check back later.
        </div>
    <?php else: ?>

    <!-- Quiz form — fallback regular submit; JS intercepts and submits via fetch -->
    <form
        id="quiz-form"
        method="post"
        action="<?= e(APP_BASE) ?>/api/submit-quiz.php"
        novalidate
    >
        <!-- Hidden fields -->
        <input type="hidden" name="session_id"  value="<?= e((string) $sessionId) ?>">
        <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">

        <?php foreach ($questions as $index => $question): ?>
            <?php
                $qId   = (int) $question['id'];
                $qNum  = $index + 1;
                $qText = $question['question_text'];
                $qType = $question['question_type'];
            ?>
            <div class="card mb-4" id="question-<?= e((string) $qId) ?>">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span>Question <?= e((string) $qNum) ?></span>
                    <span>
                        <?php
                        $typeLabels = [
                            'mcq'            => 'Multiple Choice',
                            'true_false'     => 'True / False',
                            'identification' => 'Identification',
                            'fill_blank'     => 'Fill in the Blank',
                            'enumeration'    => 'Enumeration',
                        ];
                        echo '<span class="badge bg-light text-dark me-2">'
                            . e($typeLabels[$qType] ?? $qType) . '</span>';
                        ?>
                        <?php if ((int) $question['points'] !== 1): ?>
                            <span class="badge bg-warning text-dark">
                                <?= e((string) $question['points']) ?> pts
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body">

                    <?php
                    // For fill_blank: highlight the blank visually
                    $displayText = $qType === 'fill_blank'
                        ? str_replace('___', '<span class="border-bottom border-2 border-primary px-3">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>', e($qText))
                        : e($qText);
                    ?>
                    <p class="card-text fw-semibold mb-3"><?= $displayText ?></p>

                    <?php if ($qType === 'mcq' && !empty($question['options'])): ?>
                        <!-- Multiple Choice — radio buttons -->
                        <div class="list-group">
                            <?php foreach ($question['options'] as $option): ?>
                                <?php
                                    $label      = $option['option_label'];
                                    $optionText = $option['option_text'];
                                    $inputId    = 'q' . $qId . '_' . e($label);
                                ?>
                                <label class="list-group-item list-group-item-action" for="<?= e($inputId) ?>">
                                    <input class="form-check-input me-2" type="radio"
                                           name="answer[<?= e((string) $qId) ?>]"
                                           id="<?= e($inputId) ?>"
                                           value="<?= e($label) ?>">
                                    <strong><?= e($label) ?>.</strong> <?= e($optionText) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($qType === 'true_false'): ?>
                        <!-- True / False — radio buttons -->
                        <div class="list-group" style="max-width:300px;">
                            <?php foreach ([['T','True'],['F','False']] as [$val,$label]): ?>
                                <label class="list-group-item list-group-item-action"
                                       for="q<?= $qId ?>_<?= $val ?>">
                                    <input class="form-check-input me-2" type="radio"
                                           name="answer[<?= e((string) $qId) ?>]"
                                           id="q<?= $qId ?>_<?= $val ?>"
                                           value="<?= $val ?>">
                                    <?= $label ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($qType === 'identification' || $qType === 'fill_blank'): ?>
                        <!-- Identification / Fill in the Blank — text input -->
                        <div style="max-width:480px;">
                            <input type="text"
                                   name="answer[<?= e((string) $qId) ?>]"
                                   class="form-control form-control-lg"
                                   placeholder="Type your answer here…"
                                   autocomplete="off"
                                   spellcheck="false">
                        </div>

                    <?php elseif ($qType === 'enumeration'): ?>
                        <!-- Enumeration — one text input per item -->
                        <?php
                        $enumItems = array_filter(array_map('trim', explode(',', $question['correct_answer'])));
                        $enumCount = count($enumItems);
                        ?>
                        <p class="text-muted small mb-2">
                            List <?= $enumCount ?> item<?= $enumCount !== 1 ? 's' : '' ?> in order:
                        </p>
                        <div style="max-width:480px;">
                            <?php for ($ei = 1; $ei <= $enumCount; $ei++): ?>
                            <div class="input-group mb-2">
                                <span class="input-group-text"><?= $ei ?></span>
                                <input type="text"
                                       name="enum_answer[<?= e((string) $qId) ?>][]"
                                       class="form-control"
                                       placeholder="Item <?= $ei ?>…"
                                       autocomplete="off"
                                       spellcheck="false">
                            </div>
                            <?php endfor; ?>
                        </div>

                    <?php else: ?>
                        <p class="text-muted fst-italic">Unknown question type.</p>
                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>

        <!-- Submit button -->
        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
            <button
                type="submit"
                id="submit-btn"
                class="btn btn-success btn-lg"
                onclick="return confirm('Are you sure you want to submit the quiz? You cannot change your answers after submission.')"
            >
                Submit Quiz
            </button>
        </div>

    </form>

    <?php endif; ?>

</div><!-- /.container -->

<!-- Bootstrap JS -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
    crossorigin="anonymous"
></script>

<!-- Server-injected configuration for JavaScript -->
<script>
    const PUSHER_KEY  = <?= json_encode($pusherKey) ?>;
    const TIME_LIMIT  = <?= json_encode($timeLimit) ?>;
    const SESSION_ID  = <?= json_encode($sessionId) ?>;
    const CSRF_TOKEN  = <?= json_encode($csrfToken) ?>;
    const APP_BASE    = <?= json_encode(APP_BASE) ?>;
</script>

<!-- Timer (auto-submit on timeout) -->
<script src="<?= e(APP_BASE) ?>/assets/js/timer.js"></script>

<!-- Quiz form: intercept submit and send via fetch; fall back to regular POST -->
<script>
(function () {
    'use strict';

    const form      = document.getElementById('quiz-form');
    const submitBtn = document.getElementById('submit-btn');

    if (!form) return;

    /**
     * Collect all selected answers from the form.
     * Returns a FormData-compatible object.
     */
    function buildFormData() {
        const fd = new FormData();
        fd.append('session_id', SESSION_ID);
        fd.append('csrf_token', CSRF_TOKEN);

        // Collect checked radio buttons: answer[question_id] = option_label
        const radios = form.querySelectorAll('input[type="radio"]:checked');
        radios.forEach(function (radio) {
            fd.append(radio.name, radio.value);
        });

        return fd;
    }

    /**
     * Submit the quiz via fetch (AJAX).
     * On success, redirect to the results page.
     * On network/server error, fall back to a regular form POST.
     */
    async function submitViaFetch(event) {
        if (event) event.preventDefault();

        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting…';

        try {
            const response = await fetch(APP_BASE + '/api/submit-quiz.php', {
                method: 'POST',
                body: buildFormData(),
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    // Redirect to results page
                    window.location.href = APP_BASE + '/student/results.php?session_id=' + encodeURIComponent(SESSION_ID);
                    return;
                }
            }

            // Server returned an error — fall back to regular form submit
            form.removeEventListener('submit', submitViaFetch);
            form.submit();
        } catch (err) {
            // Network error — fall back to regular form submit
            form.removeEventListener('submit', submitViaFetch);
            form.submit();
        }
    }

    form.addEventListener('submit', submitViaFetch);

    // Start the countdown timer once the page is ready
    if (typeof startQuizTimer === 'function') {
        startQuizTimer(TIME_LIMIT, SESSION_ID, CSRF_TOKEN);
    }
})();
</script>

</body>
</html>

