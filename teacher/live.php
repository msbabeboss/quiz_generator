<?php
/**
 * teacher/live.php — Live submissions feed and leaderboard (teacher only).
 * Mirrors what was the admin dashboard real-time view.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

requireRole('teacher');

$pdo         = getDB();
$teacherId   = (int) $_SESSION['user_id'];

// Stats — scoped to this teacher's quizzes
$stmt = $pdo->prepare('SELECT COUNT(*) FROM quizzes WHERE created_by = ?');
$stmt->execute([$teacherId]);
$totalQuizzes = (int) $stmt->fetchColumn();

$stmt2 = $pdo->prepare(
    'SELECT COUNT(DISTINCT qs.student_id) FROM quiz_sessions qs
     JOIN quizzes q ON qs.quiz_id = q.id WHERE q.created_by = ?'
);
$stmt2->execute([$teacherId]);
$totalStudents = (int) $stmt2->fetchColumn();

$stmt3 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
$stmt3->execute();
$totalAllStudents = (int) $stmt3->fetchColumn();

$pusherKey     = $_ENV['PUSHER_KEY']     ?? '';
$pusherCluster = $_ENV['PUSHER_CLUSTER'] ?? '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Dashboard — QuizGen Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
</head>
<body>
<?php $activePage = 'live'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4">
    <h1 class="mb-4">⚡ Live Dashboard</h1>

    <!-- Stats -->
    <div class="row g-3 mb-5">
        <div class="col-sm-4">
            <div class="card text-center h-100" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
                <div class="card-body">
                    <div style="font-size:2rem; font-weight:900; color:#06b6d4;"><?= $totalQuizzes ?></div>
                    <div class="text-muted small">My Quizzes</div>
                </div>
                <div class="card-footer border-0 bg-transparent">
                    <a href="<?= APP_BASE ?>/teacher/quizzes.php" class="text-info text-decoration-none small">Manage →</a>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center h-100" style="background:#12122a; border:1px solid rgba(74,222,128,0.2);">
                <div class="card-body">
                    <div style="font-size:2rem; font-weight:900; color:#4ade80;"><?= $totalStudents ?></div>
                    <div class="text-muted small">Students Reached</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card text-center h-100" style="background:#12122a; border:1px solid rgba(251,191,36,0.2);">
                <div class="card-body">
                    <div style="font-size:2rem; font-weight:900; color:#fbbf24;"><?= $totalAllStudents ?></div>
                    <div class="text-muted small">Total Active Students</div>
                </div>
                <div class="card-footer border-0 bg-transparent">
                    <a href="<?= APP_BASE ?>/teacher/users.php" class="text-warning text-decoration-none small">Manage →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Submissions -->
    <h4 class="mb-2">📡 Live Submissions
        <span class="badge bg-success ms-2" style="font-size:.7rem; vertical-align:middle;">
            <span class="pulse-dot d-inline-block me-1" style="width:7px;height:7px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite;"></span>
            Live
        </span>
    </h4>
    <p class="text-muted small mb-3">New submissions appear here in real time via Pusher.</p>
    <div class="table-responsive mb-5">
        <table id="live-submissions" class="table table-dark table-hover align-middle">
            <thead><tr><th>Username</th><th>Score %</th><th>Submitted At</th></tr></thead>
            <tbody>
                <tr id="live-submissions-placeholder">
                    <td colspan="3" class="text-center text-muted">Waiting for submissions…</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Live Leaderboard -->
    <h4 class="mb-2">🏆 Live Leaderboard</h4>
    <p class="text-muted small mb-3">Updates automatically as scores are graded.</p>
    <div class="table-responsive mb-5">
        <table class="table table-dark table-hover align-middle">
            <thead><tr><th>#</th><th>Username</th><th>Score / Total</th><th>Percentage</th><th>Submitted At</th></tr></thead>
            <tbody id="leaderboard-body">
                <tr><td colspan="5" class="text-center text-muted">No results yet.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<style>
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.4)} }
</style>

<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script>
    var PUSHER_KEY     = <?= json_encode($pusherKey) ?>;
    var PUSHER_CLUSTER = <?= json_encode($pusherCluster) ?>;
</script>
<script src="<?= APP_BASE ?>/assets/js/realtime.js"></script>
<script>
(function () {
    var orig = window.appendSubmissionRow;
    if (typeof orig === 'function') {
        window.appendSubmissionRow = function (data) {
            var ph = document.getElementById('live-submissions-placeholder');
            if (ph) ph.parentNode.removeChild(ph);
            orig(data);
        };
    }
}());
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
</body>
</html>
