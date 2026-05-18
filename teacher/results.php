<?php
/**
 * teacher/results.php — Results for teacher's own quizzes.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/GradingEngine.php';

requireRole('teacher');

$teacherId = (int) $_SESSION['user_id'];
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$quizId    = isset($_GET['quiz_id'])    ? (int) $_GET['quiz_id']    : null;
$sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : null;

$pdo = getDB();

if ($sessionId !== null && $sessionId > 0) {
    // ── Detail view: per-question breakdown for one student submission ──
    // Verify the session belongs to a quiz owned by this teacher
    $stmt = $pdo->prepare(
        'SELECT qs.id AS session_id, qs.student_id, qs.quiz_id, qs.status, qs.submitted_at,
                u.username, q.title AS quiz_title, q.id AS quiz_id_check
         FROM quiz_sessions qs
         JOIN quizzes q  ON qs.quiz_id    = q.id
         JOIN users   u  ON qs.student_id = u.id
         WHERE qs.id = ? AND q.created_by = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $teacherId]);
    $sessionInfo = $stmt->fetch();

    if ($sessionInfo === false) {
        header('Location: ' . APP_BASE . '/teacher/results.php'); exit;
    }

    $quizId = (int) $sessionInfo['quiz_id'];

    // Fetch per-question breakdown
    $stmtA = $pdo->prepare(
        'SELECT q.question_text, q.question_type, q.correct_answer, q.points,
                a.student_answer, a.is_correct
         FROM answers a
         JOIN questions q ON a.question_id = q.id
         WHERE a.session_id = ?
         ORDER BY q.order_index'
    );
    $stmtA->execute([$sessionId]);
    $answerBreakdown = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    // Fetch the result summary for this session
    $stmtR = $pdo->prepare(
        'SELECT score, total_points, percentage FROM results WHERE session_id = ? LIMIT 1'
    );
    $stmtR->execute([$sessionId]);
    $sessionResult = $stmtR->fetch(PDO::FETCH_ASSOC);

} elseif ($quizId !== null && $quizId > 0) {
    $quiz = getQuiz($quizId);
    // Ownership check
    if ($quiz === null || (int)$quiz['created_by'] !== $teacherId) {
        header('Location: ' . APP_BASE . '/teacher/results.php'); exit;
    }
    $results     = getResults($quizId);
    $leaderboard = getLeaderboard($quizId, 10);

    // Fetch session IDs so we can link to detail view
    $stmtSessions = $pdo->prepare(
        'SELECT qs.id AS session_id, u.username
         FROM quiz_sessions qs
         JOIN users u ON qs.student_id = u.id
         WHERE qs.quiz_id = ? AND qs.status != \'in_progress\'
         ORDER BY qs.submitted_at DESC'
    );
    $stmtSessions->execute([$quizId]);
    $sessionMap = [];
    foreach ($stmtSessions->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sessionMap[$row['username']] = (int) $row['session_id'];
    }
} else {
    $quizId = null;
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE created_by = ? ORDER BY created_at DESC');
    $stmt->execute([$teacherId]);
    $quizzes = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }
        .live-badge { font-size:.7rem; vertical-align:middle; }
        .new-row-flash { animation: rowFlash 1.5s ease-out; }
        @keyframes rowFlash {
            0%   { background-color: rgba(74,222,128,0.35); }
            100% { background-color: transparent; }
        }
    </style>
</head>
<body>
<?php $activePage = 'results'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>
<div class="container py-4">

<?php if ($sessionId !== null): ?>
    <!-- ── Detail view: per-question breakdown ── -->
    <div class="d-flex align-items-center mb-4">
        <a href="<?= APP_BASE ?>/teacher/results.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-secondary btn-sm me-3">← Back to Results</a>
        <h1 class="mb-0">Answer Breakdown — <?= e($sessionInfo['username']) ?></h1>
    </div>
    <p class="text-muted mb-1">Quiz: <strong><?= e($sessionInfo['quiz_title']) ?></strong></p>
    <p class="text-muted mb-4">
        Submitted: <?= e($sessionInfo['submitted_at'] ?? '—') ?> &nbsp;|&nbsp;
        Status:
        <?php
            $s = $sessionInfo['status'];
            if ($s === 'submitted')       echo '<span class="badge bg-primary">Submitted</span>';
            elseif ($s === 'timed_out')   echo '<span class="badge bg-warning text-dark">Timed Out</span>';
            else                          echo '<span class="badge bg-secondary">' . e($s) . '</span>';
        ?>
        <?php if ($sessionResult): ?>
            &nbsp;|&nbsp; Score: <strong><?= e((string)$sessionResult['score']) ?> / <?= e((string)$sessionResult['total_points']) ?></strong>
            (<?= number_format((float)$sessionResult['percentage'], 2) ?>%)
        <?php endif; ?>
    </p>

    <?php if (empty($answerBreakdown)): ?>
        <div class="alert alert-info">No answer data available for this submission.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-bordered align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Question</th>
                    <th>Student's Answer</th>
                    <th>Correct Answer</th>
                    <th>Result</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($answerBreakdown as $i => $a): ?>
                <?php $correct = (bool) $a['is_correct']; ?>
                <tr class="<?= $correct ? 'table-success' : 'table-danger' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= e($a['question_text']) ?></td>
                    <td>
                        <?php if ($a['student_answer'] !== null && $a['student_answer'] !== ''): ?>
                            <?= e($a['student_answer']) ?>
                        <?php else: ?>
                            <span class="text-muted fst-italic">No answer</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-bold"><?= e($a['correct_answer']) ?></td>
                    <td class="text-center fs-5">
                        <?php if ($correct): ?>
                            <span class="text-success" title="Correct">&#10003;</span>
                        <?php else: ?>
                            <span class="text-danger" title="Incorrect">&#10007;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $correct ? e(number_format((float)$a['points'], 1)) : '0.0' ?>
                        / <?= e(number_format((float)$a['points'], 1)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($quizId !== null): ?>
    <!-- ── Quiz results summary ── -->
    <div class="d-flex align-items-center mb-4">
        <a href="<?= APP_BASE ?>/teacher/results.php" class="btn btn-outline-secondary btn-sm me-3">← Back</a>
        <h1 class="mb-0">Results: <?= e($quiz['title']) ?></h1>
    </div>

    <h4 class="mb-3">
        🏆 Top-10 Leaderboard
        <span class="badge bg-success ms-2 live-badge">
            <span class="pulse-dot d-inline-block me-1" style="width:7px;height:7px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite;"></span>
            Live
        </span>
    </h4>
    <p id="leaderboard-empty" class="text-muted mb-4"<?= !empty($leaderboard) ? ' style="display:none"' : '' ?>>No submissions yet.</p>
    <div class="table-responsive mb-5" id="leaderboard-wrap"<?= empty($leaderboard) ? ' style="display:none"' : '' ?>>
        <table class="table table-dark table-hover align-middle">
            <thead><tr><th>#</th><th>Username</th><th>Score / Total</th><th>Percentage</th><th>Submitted At</th></tr></thead>
            <tbody id="leaderboard-body">
            <?php foreach ($leaderboard as $rank => $row): ?>
            <tr <?= $rank === 0 ? 'class="table-warning text-dark fw-bold"' : '' ?>>
                <td><?= $rank+1 ?></td>
                <td><?= e($row['username']) ?></td>
                <td><?= e((string)$row['score']) ?> / <?= e((string)$row['total_points']) ?></td>
                <td><?= number_format((float)$row['percentage'], 2) ?>%</td>
                <td><?= e($row['submitted_at'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h4 class="mb-3">
        All Submissions <span id="submission-count-badge" class="badge bg-secondary ms-1"><?= count($results) ?></span>
        <span class="badge bg-success ms-2 live-badge">
            <span class="pulse-dot d-inline-block me-1" style="width:7px;height:7px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite;"></span>
            Live
        </span>
    </h4>
    <?php if (empty($results)): ?>
        <p id="submissions-empty" class="text-muted">No submissions yet.</p>
    <?php endif; ?>
    <div class="table-responsive" id="submissions-wrap"<?= empty($results) ? ' style="display:none"' : '' ?>>
        <table class="table table-dark table-hover align-middle" id="live-submissions">
            <thead><tr><th>Username</th><th>Score</th><th>Total</th><th>%</th><th>Submitted At</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($results as $row): ?>
            <tr>
                <td><?= e($row['username']) ?></td>
                <td><?= e((string)$row['score']) ?></td>
                <td><?= e((string)$row['total_points']) ?></td>
                <td><?= number_format((float)$row['percentage'], 2) ?>%</td>
                <td><?= e($row['submitted_at']) ?></td>
                <td><?php
                    $s = $row['status'];
                    if ($s === 'submitted')     echo '<span class="badge bg-primary">Submitted</span>';
                    elseif ($s === 'timed_out') echo '<span class="badge bg-warning text-dark">Timed Out</span>';
                    else                        echo '<span class="badge bg-secondary">' . e($s) . '</span>';
                ?></td>
                <td>
                    <?php if (isset($sessionMap[$row['username']])): ?>
                        <a href="<?= APP_BASE ?>/teacher/results.php?session_id=<?= $sessionMap[$row['username']] ?>"
                           class="btn btn-sm btn-outline-info">View Answers</a>
                    <?php else: ?>
                        <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <!-- ── Quiz list ── -->
    <h1 class="mb-4">Quiz Results</h1>
    <?php if (empty($quizzes)): ?>
        <div class="alert alert-info">No quizzes yet. <a href="<?= APP_BASE ?>/teacher/quizzes.php">Create one →</a></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle">
            <thead><tr><th>Title</th><th>Time Limit</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($quizzes as $q): ?>
            <tr>
                <td><?= e($q['title']) ?></td>
                <td><?= (int)($q['time_limit']/60) ?> min</td>
                <td><?= $q['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                <td><a href="<?= APP_BASE ?>/teacher/results.php?quiz_id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-primary">View Results</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
<?php if ($quizId !== null && isset($quiz)): ?>
<?php
    require_once __DIR__ . '/../config/env.php';
    loadEnv(__DIR__ . '/../.env');
    $pusherKey     = $_ENV['PUSHER_KEY']     ?? '';
    $pusherCluster = $_ENV['PUSHER_CLUSTER'] ?? '';
?>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script>
var PUSHER_KEY     = <?= json_encode($pusherKey) ?>;
var PUSHER_CLUSTER = <?= json_encode($pusherCluster) ?>;
var CURRENT_QUIZ_ID = <?= json_encode($quizId) ?>;
var APP_BASE = <?= json_encode(APP_BASE) ?>;
</script>
<script>
(function () {
    'use strict';

    if (!PUSHER_KEY || !PUSHER_CLUSTER) return;

    var pusher  = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER });
    var channel = pusher.subscribe('quiz-channel');

    // ── Leaderboard live update ──────────────────────────────────────────
    channel.bind('score-updated', function (data) {
        if (parseInt(data.quiz_id) !== parseInt(CURRENT_QUIZ_ID)) return;
        renderLeaderboard(data.leaderboard);
    });

    function renderLeaderboard(leaderboard) {
        var tbody = document.getElementById('leaderboard-body');
        var wrap  = document.getElementById('leaderboard-wrap');
        var empty = document.getElementById('leaderboard-empty');
        if (!tbody) return;

        tbody.innerHTML = '';

        if (!Array.isArray(leaderboard) || leaderboard.length === 0) {
            if (wrap)  wrap.style.display  = 'none';
            if (empty) empty.style.display = '';
            return;
        }

        if (wrap)  wrap.style.display  = '';
        if (empty) empty.style.display = 'none';

        leaderboard.forEach(function (entry, index) {
            var tr = document.createElement('tr');
            if (index === 0) tr.className = 'table-warning text-dark fw-bold';

            var pct = parseFloat(entry.percentage || 0).toFixed(2);
            tr.innerHTML =
                '<td>' + (index + 1) + '</td>' +
                '<td>' + esc(entry.username) + '</td>' +
                '<td>' + esc(entry.score) + ' / ' + esc(entry.total_points) + '</td>' +
                '<td>' + pct + '%</td>' +
                '<td>' + esc(entry.submitted_at || '—') + '</td>';

            tbody.appendChild(tr);
        });
    }

    // ── New submission live row ──────────────────────────────────────────
    channel.bind('quiz-submitted', function (data) {
        if (parseInt(data.quiz_id) !== parseInt(CURRENT_QUIZ_ID)) return;
        prependSubmissionRow(data);
        playSound();
    });

    function prependSubmissionRow(data) {
        var tbody = document.querySelector('#live-submissions tbody');
        var wrap  = document.getElementById('submissions-wrap');
        var empty = document.getElementById('submissions-empty');
        if (!tbody) return;

        if (wrap)  wrap.style.display  = '';
        if (empty) empty.style.display = 'none';

        // Update submission count badge
        var badge = document.getElementById('submission-count-badge');
        if (badge) badge.textContent = parseInt(badge.textContent || '0') + 1;

        var pct = parseFloat(data.percentage || 0).toFixed(2);
        var tr  = document.createElement('tr');
        tr.className = 'new-row-flash';
        tr.innerHTML =
            '<td>' + esc(data.username) + '</td>' +
            '<td>—</td>' +
            '<td>—</td>' +
            '<td>' + pct + '%</td>' +
            '<td>' + esc(data.submitted_at) + '</td>' +
            '<td><span class="badge bg-primary">Submitted</span></td>' +
            '<td><span class="text-muted small">Loading…</span></td>';

        tbody.insertBefore(tr, tbody.firstChild);

        // After leaderboard updates, the score-updated event will fill in
        // the real score. Optionally reload the page after a short delay
        // so the "View Answers" link becomes available.
        setTimeout(function () {
            window.location.reload();
        }, 3000);
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str == null ? '' : str)));
        return d.innerHTML;
    }

    function playSound() {
        var a = new Audio(APP_BASE + '/assets/sounds/notification.mp3');
        a.play().catch(function () {});
    }
}());
</script>
<?php endif; ?>
</body>
</html>
