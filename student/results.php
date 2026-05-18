<?php
/**
 * student/results.php — Post-submission results + Review Mode.
 *
 * ?session_id=N           → normal results view (after submitting)
 * ?session_id=N&mode=review → review mode (from Exam History)
 *
 * Review mode shows all questions, the student's answers, correct answers,
 * and explanations — for learning purposes after the exam is complete.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';

requireRole('student');

if (empty($_GET['session_id'])) {
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

$sessionId  = (int) $_GET['session_id'];
$studentId  = (int) $_SESSION['user_id'];
$reviewMode = isset($_GET['mode']) && $_GET['mode'] === 'review';

$pdo = getDB();

// ---------------------------------------------------------------------------
// Load result — student can only see their own
// ---------------------------------------------------------------------------
try {
    $stmt = $pdo->prepare(
        'SELECT r.*,
                qs.status, qs.submitted_at,
                q.title      AS quiz_title,
                q.id         AS quiz_id,
                q.created_by AS teacher_id,
                u.username   AS teacher_name,
                qac.code     AS exam_code,
                qac.label    AS section_label
         FROM results r
         JOIN quiz_sessions qs      ON r.session_id  = qs.id
         JOIN quizzes q             ON r.quiz_id     = q.id
         JOIN users u               ON q.created_by  = u.id
         LEFT JOIN quiz_enrollments qe  ON qe.quiz_id   = q.id AND qe.student_id = r.student_id
         LEFT JOIN quiz_access_codes qac ON qe.code_id  = qac.id
         WHERE r.session_id = ? AND r.student_id = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $studentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('results.php PDOException (result fetch): ' . $e->getMessage());
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

if (!$result) {
    header('Location: ' . APP_BASE . '/student/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// Load per-question breakdown
// ---------------------------------------------------------------------------
try {
    $stmtAnswers = $pdo->prepare(
        'SELECT q.id AS question_id,
                q.question_text, q.question_type, q.correct_answer, q.points,
                a.student_answer, a.is_correct
         FROM answers a
         JOIN questions q ON a.question_id = q.id
         WHERE a.session_id = ?
         ORDER BY q.order_index, q.id'
    );
    $stmtAnswers->execute([$sessionId]);
    $answers = $stmtAnswers->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('results.php PDOException (answers fetch): ' . $e->getMessage());
    $answers = [];
}

// ---------------------------------------------------------------------------
// Derived values
// ---------------------------------------------------------------------------
$score       = (float) $result['score'];
$totalPoints = (float) $result['total_points'];
$percentage  = $totalPoints > 0 ? round(($score / $totalPoints) * 100, 1) : 0;
$status      = $result['status'];
$passed      = $percentage >= 75;

$correctCount = 0;
$wrongCount   = 0;
foreach ($answers as $a) {
    if ($a['is_correct']) $correctCount++;
    else $wrongCount++;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Format an answer value for display based on question type.
 */
function formatAnswer(string $type, ?string $value): string {
    if ($value === null || $value === '') return '';
    if ($type === 'true_false') {
        return $value === 'T' ? 'True' : ($value === 'F' ? 'False' : $value);
    }
    if ($type === 'enumeration') {
        $items = array_map('trim', explode(',', $value));
        return implode(', ', array_map(fn($i, $v) => ($i + 1) . '. ' . $v, array_keys($items), $items));
    }
    return $value;
}

$pageTitle = $reviewMode
    ? e($result['quiz_title']) . ' — Review'
    : e($result['quiz_title']) . ' — Results';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <style>
        :root {
            --bg: #0d0d1a; --card-bg: #12122a; --border: rgba(255,255,255,0.08);
            --success: #4ade80; --danger: #f87171; --warning: #fbbf24;
            --info: #06b6d4; --text: #e0e0f0; --muted: #9090b0;
        }
        body { background: var(--bg); color: var(--text); }

        /* Score hero */
        .score-hero { background: var(--card-bg); border: 1px solid var(--border); border-radius: 1rem; padding: 2rem; margin-bottom: 1.5rem; }
        .score-big { font-size: 3rem; font-weight: 900; line-height: 1; }
        .score-big.pass { color: var(--success); }
        .score-big.fail { color: var(--danger); }
        .score-big.timeout { color: var(--warning); }

        /* Meta pills */
        .meta-pill { display: inline-flex; align-items: center; gap: .4rem; background: rgba(255,255,255,.05); border: 1px solid var(--border); border-radius: 999px; padding: .25rem .75rem; font-size: .8rem; color: var(--muted); }
        .meta-pill strong { color: var(--text); }

        /* Review mode banner */
        .review-banner { background: rgba(6,182,212,.1); border: 1px solid rgba(6,182,212,.3); border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .75rem; }

        /* Question cards */
        .q-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: .75rem; margin-bottom: 1rem; overflow: hidden; }
        .q-card-header { padding: .75rem 1.25rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); }
        .q-card-body { padding: 1.25rem; }
        .q-card.correct { border-color: rgba(74,222,128,.35); }
        .q-card.correct .q-card-header { background: rgba(74,222,128,.08); }
        .q-card.wrong   { border-color: rgba(248,113,113,.35); }
        .q-card.wrong   .q-card-header { background: rgba(248,113,113,.08); }
        .q-card.no-answer { border-color: rgba(251,191,36,.25); }
        .q-card.no-answer .q-card-header { background: rgba(251,191,36,.06); }

        .q-text { font-weight: 600; font-size: 1rem; margin-bottom: 1rem; color: var(--text); }
        .answer-row { display: flex; gap: .5rem; align-items: flex-start; margin-bottom: .5rem; font-size: .9rem; }
        .answer-label { font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: .2rem .6rem; border-radius: 999px; white-space: nowrap; flex-shrink: 0; margin-top: .1rem; }
        .label-yours   { background: rgba(255,255,255,.08); color: var(--muted); }
        .label-correct { background: rgba(74,222,128,.15); color: var(--success); }
        .answer-val { color: var(--text); }
        .answer-val.correct-val { color: var(--success); font-weight: 600; }
        .answer-val.wrong-val   { color: var(--danger); text-decoration: line-through; opacity: .8; }
        .answer-val.no-val      { color: var(--muted); font-style: italic; }

        /* Stats bar */
        .stats-bar { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .stat-box { background: var(--card-bg); border: 1px solid var(--border); border-radius: .6rem; padding: .6rem 1.2rem; text-align: center; flex: 1; min-width: 100px; }
        .stat-box .val { font-size: 1.5rem; font-weight: 900; }
        .stat-box .lbl { font-size: .75rem; color: var(--muted); }
        .stat-box.s-correct .val { color: var(--success); }
        .stat-box.s-wrong   .val { color: var(--danger); }
        .stat-box.s-score   .val { color: var(--info); }
        .stat-box.s-pct     .val { color: <?= $passed ? 'var(--success)' : 'var(--danger)' ?>; }

        /* Exam code */
        .exam-code { font-family: 'Courier New', monospace; font-size: 1rem; letter-spacing: .15em; color: var(--info); background: rgba(6,182,212,.1); border: 1px solid rgba(6,182,212,.25); padding: .2rem .6rem; border-radius: .4rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark" style="background:#0d0d1a; border-bottom:1px solid rgba(6,182,212,0.2);">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= APP_BASE ?>/student/dashboard.php">🧠 Quiz App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
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

<div class="container mt-4 mb-5" style="max-width:860px;">

    <!-- Back link -->
    <a href="<?= APP_BASE ?>/student/dashboard.php" class="text-muted small text-decoration-none d-inline-flex align-items-center gap-1 mb-3">
        ← Back to Dashboard
    </a>

    <!-- Review mode banner -->
    <?php if ($reviewMode): ?>
    <div class="review-banner">
        <span style="font-size:1.5rem;">🔍</span>
        <div>
            <strong style="color:#06b6d4;">Review Mode</strong>
            <div class="text-muted small">You are reviewing a completed exam. Your answers, correct answers, and scores are shown below for learning purposes.</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['already_submitted'])): ?>
    <div class="alert alert-warning alert-dismissible fade show mb-3">
        <strong>Already submitted.</strong> You have already completed this exam. You cannot retake it.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Score Hero ──────────────────────────────────────────────────── -->
    <div class="score-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">

            <!-- Left: title + score -->
            <div>
                <h1 class="h3 fw-bold mb-1"><?= e($result['quiz_title']) ?></h1>

                <!-- Meta pills -->
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if (!empty($result['teacher_name'])): ?>
                    <span class="meta-pill">👤 Teacher: <strong><?= e($result['teacher_name']) ?></strong></span>
                    <?php endif; ?>
                    <?php if (!empty($result['section_label'])): ?>
                    <span class="meta-pill">📋 <strong><?= e($result['section_label']) ?></strong></span>
                    <?php endif; ?>
                    <?php if (!empty($result['exam_code'])): ?>
                    <span class="meta-pill">🔑 Code: <span class="exam-code"><?= e($result['exam_code']) ?></span></span>
                    <?php endif; ?>
                    <?php if (!empty($result['submitted_at'])): ?>
                    <span class="meta-pill">📅 <strong><?= e(date('M j, Y g:i A', strtotime($result['submitted_at']))) ?></strong></span>
                    <?php endif; ?>
                </div>

                <!-- Big score -->
                <div class="d-flex align-items-baseline gap-3 flex-wrap">
                    <div class="score-big <?= $status === 'timed_out' ? 'timeout' : ($passed ? 'pass' : 'fail') ?>">
                        <?= number_format($percentage, 1) ?>%
                    </div>
                    <div>
                        <div style="font-size:1.25rem; font-weight:700; color:var(--text);">
                            <?= e(number_format($score, 1)) ?> / <?= e(number_format($totalPoints, 1)) ?> pts
                        </div>
                        <div>
                            <?php if ($status === 'timed_out'): ?>
                                <span class="badge bg-warning text-dark">⏱ Timed Out</span>
                            <?php elseif ($passed): ?>
                                <span class="badge bg-success">✓ Passed</span>
                            <?php else: ?>
                                <span class="badge bg-danger">✗ Failed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: quick stats -->
            <div class="stats-bar" style="min-width:260px;">
                <div class="stat-box s-correct">
                    <div class="val"><?= $correctCount ?></div>
                    <div class="lbl">Correct</div>
                </div>
                <div class="stat-box s-wrong">
                    <div class="val"><?= $wrongCount ?></div>
                    <div class="lbl">Wrong</div>
                </div>
                <div class="stat-box">
                    <div class="val" style="color:var(--muted);"><?= count($answers) ?></div>
                    <div class="lbl">Total Q</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Question Breakdown ──────────────────────────────────────────── -->
    <?php if (!empty($answers)): ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0 fw-bold">
            <?= $reviewMode ? '📖 Answer Review' : '📋 Question Breakdown' ?>
        </h2>
        <?php if (!$reviewMode): ?>
        <a href="?session_id=<?= $sessionId ?>&mode=review" class="btn btn-sm btn-outline-info">
            🔍 Open Review Mode
        </a>
        <?php endif; ?>
    </div>

    <?php foreach ($answers as $index => $answer): ?>
        <?php
            $isCorrect     = (bool) $answer['is_correct'];
            $hasAnswer     = $answer['student_answer'] !== null && $answer['student_answer'] !== '';
            $qType         = $answer['question_type'];
            $pointsEarned  = $isCorrect ? (float) $answer['points'] : 0;

            $studentDisplay = formatAnswer($qType, $answer['student_answer']);
            $correctDisplay = formatAnswer($qType, $answer['correct_answer']);

            $cardClass = $isCorrect ? 'correct' : ($hasAnswer ? 'wrong' : 'no-answer');

            $typeLabels = [
                'mcq'            => 'MCQ',
                'true_false'     => 'True/False',
                'identification' => 'Identification',
                'fill_blank'     => 'Fill Blank',
                'enumeration'    => 'Enumeration',
            ];
        ?>
        <div class="q-card <?= $cardClass ?>">
            <div class="q-card-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">Q<?= $index + 1 ?></span>
                    <span class="badge bg-secondary" style="font-size:.7rem;"><?= e($typeLabels[$qType] ?? $qType) ?></span>
                    <?php if ($isCorrect): ?>
                        <span style="color:var(--success); font-size:1.1rem;" title="Correct">✓</span>
                    <?php elseif (!$hasAnswer): ?>
                        <span style="color:var(--warning); font-size:.85rem;">No answer</span>
                    <?php else: ?>
                        <span style="color:var(--danger); font-size:1.1rem;" title="Incorrect">✗</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.85rem; color:var(--muted);">
                    <strong style="color:<?= $isCorrect ? 'var(--success)' : 'var(--danger)' ?>">
                        <?= number_format($pointsEarned, 1) ?>
                    </strong>
                    / <?= number_format((float) $answer['points'], 1) ?> pts
                </div>
            </div>
            <div class="q-card-body">
                <!-- Question text -->
                <div class="q-text"><?= e($answer['question_text']) ?></div>

                <!-- Your answer -->
                <div class="answer-row">
                    <span class="answer-label label-yours">Your Answer</span>
                    <?php if ($hasAnswer): ?>
                        <span class="answer-val <?= $isCorrect ? 'correct-val' : 'wrong-val' ?>">
                            <?= e($studentDisplay) ?>
                        </span>
                    <?php else: ?>
                        <span class="answer-val no-val">No answer given</span>
                    <?php endif; ?>
                </div>

                <!-- Correct answer — always shown in review mode, shown on wrong in normal mode -->
                <?php if ($reviewMode || !$isCorrect): ?>
                <div class="answer-row">
                    <span class="answer-label label-correct">Correct Answer</span>
                    <span class="answer-val correct-val"><?= e($correctDisplay) ?></span>
                </div>
                <?php endif; ?>

                <!-- MCQ options in review mode -->
                <?php if ($reviewMode && $qType === 'mcq'): ?>
                    <?php
                    try {
                        $optStmt = $pdo->prepare(
                            'SELECT option_label, option_text FROM question_options WHERE question_id = ? ORDER BY option_label'
                        );
                        $optStmt->execute([$answer['question_id']]);
                        $opts = $optStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) { $opts = []; }
                    ?>
                    <?php if (!empty($opts)): ?>
                    <div class="mt-2" style="font-size:.85rem;">
                        <?php foreach ($opts as $opt): ?>
                            <?php
                                $isCorrectOpt  = $opt['option_label'] === $answer['correct_answer'];
                                $isStudentOpt  = $opt['option_label'] === $answer['student_answer'];
                            ?>
                            <div class="d-flex align-items-center gap-2 py-1"
                                 style="<?= $isCorrectOpt ? 'color:var(--success);font-weight:600;' : ($isStudentOpt && !$isCorrectOpt ? 'color:var(--danger);' : 'color:var(--muted);') ?>">
                                <span style="width:20px; text-align:center;">
                                    <?php if ($isCorrectOpt): ?>✓
                                    <?php elseif ($isStudentOpt): ?>✗
                                    <?php else: ?>○
                                    <?php endif; ?>
                                </span>
                                <strong><?= e($opt['option_label']) ?>.</strong>
                                <?= e($opt['option_text']) ?>
                                <?php if ($isCorrectOpt): ?>
                                    <span class="badge bg-success ms-1" style="font-size:.65rem;">Correct</span>
                                <?php endif; ?>
                                <?php if ($isStudentOpt && !$isCorrectOpt): ?>
                                    <span class="badge bg-danger ms-1" style="font-size:.65rem;">Your choice</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>
    <?php endforeach; ?>

    <?php else: ?>
        <div class="alert alert-info">No answer breakdown available for this exam.</div>
    <?php endif; ?>

    <!-- ── Footer actions ─────────────────────────────────────────────── -->
    <div class="d-flex gap-2 mt-4 flex-wrap">
        <a href="<?= e(APP_BASE) ?>/student/dashboard.php" class="btn btn-primary">
            ← Back to Dashboard
        </a>
        <?php if (!$reviewMode): ?>
        <a href="?session_id=<?= $sessionId ?>&mode=review" class="btn btn-outline-info">
            🔍 Review Answers
        </a>
        <?php else: ?>
        <a href="?session_id=<?= $sessionId ?>" class="btn btn-outline-secondary">
            📊 Score Summary
        </a>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
        crossorigin="anonymous"></script>
</body>
</html>
