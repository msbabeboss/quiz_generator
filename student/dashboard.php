<?php
/**
 * student/dashboard.php — Student dashboard.
 *
 * ALL quizzes require an access code. Students only see quizzes they have
 * enrolled in by entering a valid code on the join page.
 * No quiz is ever shown or accessible without prior code-based enrollment.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';

// Guard: only students may access this page.
requireRole('student');

$studentId = (int) $_SESSION['user_id'];
$pdo       = getDB();

// ---------------------------------------------------------------------------
// Load ONLY quizzes the student has enrolled in via a valid access code
// OR via classroom enrollment (room code path).
// Quizzes without an enrollment record are never shown — period.
// ---------------------------------------------------------------------------
$enrolledQuizzes = [];

try {
    // Path 1: direct exam code enrollment
    $estmt = $pdo->prepare(
        'SELECT DISTINCT q.*, qac.label AS section_label,
                u.username AS teacher_name, \'exam_code\' AS access_type
         FROM quiz_enrollments qe
         JOIN quizzes q             ON qe.quiz_id  = q.id
         JOIN quiz_access_codes qac ON qe.code_id  = qac.id
         JOIN users u               ON q.created_by = u.id
         WHERE qe.student_id = ? AND q.is_active = 1'
    );
    $estmt->execute([$studentId]);
    $directEnrolled = $estmt->fetchAll();

    // Path 2: classroom enrollment — all active quizzes assigned to rooms the student joined
    $cstmt = $pdo->prepare(
        'SELECT DISTINCT q.*, c.name AS section_label,
                u.username AS teacher_name, \'room\' AS access_type
         FROM classroom_enrollments ce
         JOIN classrooms c          ON ce.classroom_id = c.id AND c.is_active = 1
         JOIN classroom_quizzes cq  ON cq.classroom_id = c.id
         JOIN quizzes q             ON cq.quiz_id = q.id AND q.is_active = 1
         JOIN users u               ON q.created_by = u.id
         WHERE ce.student_id = ?'
    );
    $cstmt->execute([$studentId]);
    $roomEnrolled = $cstmt->fetchAll();

    // Merge, deduplicate by quiz id (prefer exam_code entry if both exist)
    $seen = [];
    foreach (array_merge($directEnrolled, $roomEnrolled) as $quiz) {
        $qid = (int) $quiz['id'];
        if (!isset($seen[$qid]) || $quiz['access_type'] === 'exam_code') {
            $seen[$qid] = $quiz;
        }
    }
    // Sort by most recently enrolled
    $enrolledQuizzes = array_values($seen);
} catch (PDOException $e) {
    error_log('student/dashboard.php PDOException (enrolled quizzes): ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Build completed-quiz lookup for "Already Submitted" state.
// ---------------------------------------------------------------------------
$completedQuizIds    = [];
$completedSessionMap = [];
$pastAttempts        = [];

try {
    // Full exam history — includes teacher name, exam code, score, status
    $stmt = $pdo->prepare(
        'SELECT
            r.score,
            r.total_points,
            r.percentage,
            r.computed_at,
            q.title        AS quiz_title,
            q.id           AS quiz_id,
            qs.status,
            qs.id          AS session_id,
            qs.submitted_at,
            qac.code       AS exam_code,
            qac.label      AS section_label,
            u.username     AS teacher_name
         FROM results r
         JOIN quiz_sessions qs      ON r.session_id  = qs.id
         JOIN quizzes q             ON r.quiz_id     = q.id
         JOIN quiz_enrollments qe   ON qe.quiz_id    = q.id AND qe.student_id = r.student_id
         JOIN quiz_access_codes qac ON qe.code_id    = qac.id
         JOIN users u               ON q.created_by  = u.id
         WHERE r.student_id = ?
         ORDER BY r.computed_at DESC'
    );
    $stmt->execute([$studentId]);
    $pastAttempts = $stmt->fetchAll();

    foreach ($pastAttempts as $attempt) {
        if (in_array($attempt['status'], ['submitted', 'timed_out'], true)) {
            $qid = (int) $attempt['quiz_id'];
            $completedQuizIds[$qid] = true;
            if (!isset($completedSessionMap[$qid])) {
                $completedSessionMap[$qid] = (int) $attempt['session_id'];
            }
        }
    }
} catch (PDOException $e) {
    error_log('student/dashboard.php PDOException (past attempts): ' . $e->getMessage());
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — Quiz App</title>
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
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
                <li class="nav-item me-2"><a class="btn btn-sm btn-outline-secondary" href="<?= APP_BASE ?>/profile.php">My Profile</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_BASE ?>/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">

    <!-- Welcome message -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h1 class="mb-0">Welcome, <?= e($_SESSION['username'] ?? '') ?>!</h1>
        <a href="<?= e(APP_BASE) ?>/student/join.php" class="btn btn-primary btn-lg">
            🔑 Enter Code
        </a>
    </div>

    <!-- Joined room flash -->
    <?php if (!empty($_GET['joined_room'])): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
        <span style="font-size:1.3rem;">🏫</span>
        <div>
            <strong>Joined classroom!</strong>
            You are now enrolled in <strong><?= e($_GET['joined_room']) ?></strong>.
            All exams assigned to this classroom are now visible below.
        </div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Info banner -->
    <div class="alert d-flex align-items-start gap-3 mb-4"
         style="background:rgba(6,182,212,0.08); border:1px solid rgba(6,182,212,0.25); color:#9090b0;">
        <span style="font-size:1.4rem; flex-shrink:0;">🔒</span>
        <div style="font-size:.875rem;">
            <strong style="color:#e0e0f0;">Two ways to access exams:</strong><br>
            <strong style="color:#06b6d4;">🏫 Room Code</strong> — Join your teacher's classroom to see all assigned exams at once.<br>
            <strong style="color:#fbbf24;">📝 Exam Code</strong> — Enter a specific exam code to access one exam directly.<br>
            <span class="mt-1 d-inline-block">Click <strong style="color:#e0e0f0;">Enter Code</strong> to use either type.</span>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Enrolled (Code-Unlocked) Exams                                       -->
    <!-- ------------------------------------------------------------------ -->
    <section class="mb-5" aria-labelledby="enrolled-quizzes-heading">
        <h2 id="enrolled-quizzes-heading" class="h4 mb-3">
            📋 My Exams
            <?php if (!empty($enrolledQuizzes)): ?>
                <span class="badge bg-secondary ms-2" style="font-size:.7rem;"><?= count($enrolledQuizzes) ?></span>
            <?php endif; ?>
        </h2>

        <?php if (empty($enrolledQuizzes)): ?>
            <div class="card text-center py-5"
                 style="background:#12122a; border:1px solid rgba(255,255,255,0.08);">
                <div style="font-size:3rem; margin-bottom:1rem;">🔑</div>
                <h3 class="h5 mb-2" style="color:#e0e0f0;">No exams yet</h3>
                <p class="text-muted mb-3">Enter the access code your teacher gave you to unlock your first exam.</p>
                <div>
                    <a href="<?= e(APP_BASE) ?>/student/join.php" class="btn btn-primary">
                        Enter Exam Code →
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($enrolledQuizzes as $quiz): ?>
                    <?php $qid = (int) $quiz['id']; ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="card h-100" style="background:#12122a; border:1px solid rgba(6,182,212,0.25);">
                            <div class="card-body d-flex flex-column">

                                <!-- Access type + section badge -->
                                <div class="d-flex gap-1 flex-wrap mb-2">
                                    <?php if (($quiz['access_type'] ?? '') === 'room'): ?>
                                        <span class="badge" style="background:rgba(6,182,212,.15); color:#06b6d4; border:1px solid rgba(6,182,212,.3); font-size:.65rem;">🏫 Classroom</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); font-size:.65rem;">📝 Exam Code</span>
                                    <?php endif; ?>
                                    <?php if (!empty($quiz['section_label'])): ?>
                                        <span class="badge bg-secondary" style="font-size:.65rem;"><?= e($quiz['section_label']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <h3 class="card-title h5" style="color:#e0e0f0;"><?= e($quiz['title']) ?></h3>
                                <?php if (!empty($quiz['teacher_name'])): ?>
                                    <div class="text-muted small mb-1">👤 <?= e($quiz['teacher_name']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($quiz['description'])): ?>
                                    <p class="card-text text-muted small flex-grow-1"><?= e($quiz['description']) ?></p>
                                <?php else: ?>
                                    <p class="card-text text-muted small flex-grow-1 fst-italic">No description.</p>
                                <?php endif; ?>

                                <p class="card-text small mb-3 text-muted">
                                    ⏱ Time limit: <strong><?= e((string) $quiz['time_limit']) ?>s</strong>
                                </p>

                                <?php if (!empty($completedQuizIds[$qid])): ?>
                                    <a href="<?= e(APP_BASE) ?>/student/results.php?session_id=<?= e((string) $completedSessionMap[$qid]) ?>"
                                       class="btn btn-secondary mt-auto">
                                        ✅ View Results
                                    </a>
                                <?php else: ?>
                                    <div class="d-flex gap-2 mt-auto">
                                        <a href="<?= e(APP_BASE) ?>/student/take-quiz.php?quiz_id=<?= e((string) $qid) ?>"
                                           class="btn btn-primary flex-grow-1">Take Exam</a>
                                        <a href="<?= e(APP_BASE) ?>/student/flashcards.php?quiz_id=<?= e((string) $qid) ?>"
                                           class="btn btn-outline-warning" title="Practice with flashcards">🃏</a>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ------------------------------------------------------------------ -->
    <!-- Exam History                                                         -->
    <!-- ------------------------------------------------------------------ -->
    <section aria-labelledby="exam-history-heading">
        <h2 id="exam-history-heading" class="h4 mb-3">
            📜 Exam History
            <?php if (!empty($pastAttempts)): ?>
                <span class="badge bg-secondary ms-2" style="font-size:.7rem;"><?= count($pastAttempts) ?></span>
            <?php endif; ?>
        </h2>

        <?php if (empty($pastAttempts)): ?>
            <div class="alert alert-secondary">You have not completed any exams yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle" style="font-size:.9rem;">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Exam Title</th>
                            <th scope="col">Teacher</th>
                            <th scope="col">Section / Code</th>
                            <th scope="col">Date Taken</th>
                            <th scope="col">Score</th>
                            <th scope="col">%</th>
                            <th scope="col">Status</th>
                            <th scope="col">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pastAttempts as $i => $attempt): ?>
                            <?php
                                $pct    = (float) $attempt['percentage'];
                                $passed = $pct >= 75;
                            ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td class="fw-semibold"><?= e($attempt['quiz_title']) ?></td>
                                <td class="text-muted">
                                    <span style="color:#06b6d4;">👤</span>
                                    <?= e($attempt['teacher_name']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($attempt['section_label'])): ?>
                                        <span class="badge bg-info text-dark d-block mb-1" style="width:fit-content;">
                                            <?= e($attempt['section_label']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <code style="font-size:.8rem; color:#06b6d4; letter-spacing:.1em;">
                                        <?= e($attempt['exam_code']) ?>
                                    </code>
                                </td>
                                <td class="text-muted small">
                                    <?= e(date('M j, Y', strtotime($attempt['submitted_at'] ?? $attempt['computed_at']))) ?>
                                    <br>
                                    <span style="font-size:.75rem;">
                                        <?= e(date('g:i A', strtotime($attempt['submitted_at'] ?? $attempt['computed_at']))) ?>
                                    </span>
                                </td>
                                <td class="fw-bold">
                                    <?= e(number_format((float) $attempt['score'], 1)) ?>
                                    <span class="text-muted fw-normal">/ <?= e(number_format((float) $attempt['total_points'], 1)) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold" style="color:<?= $pct >= 75 ? '#4ade80' : ($pct >= 50 ? '#fbbf24' : '#f87171') ?>">
                                        <?= number_format($pct, 1) ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php if ($attempt['status'] === 'timed_out'): ?>
                                        <span class="badge bg-warning text-dark">Timed Out</span>
                                    <?php elseif ($attempt['status'] === 'submitted'): ?>
                                        <?php if ($passed): ?>
                                            <span class="badge bg-success">Passed ✓</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Failed ✗</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= e($attempt['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= e(APP_BASE) ?>/student/results.php?session_id=<?= e((string) $attempt['session_id']) ?>&mode=review"
                                       class="btn btn-sm btn-outline-info">
                                        🔍 Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div><!-- /.container -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
    crossorigin="anonymous"
></script>

</body>
</html>

