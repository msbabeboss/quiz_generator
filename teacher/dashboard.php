<?php
/**
 * teacher/dashboard.php — Teacher home: overview of their quizzes + quick stats.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/GradingEngine.php';

requireRole('teacher');

$pdo         = getDB();
$teacherId   = (int) $_SESSION['user_id'];
$teacherName = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');

// My quizzes
$stmt = $pdo->prepare('SELECT * FROM quizzes WHERE created_by = ? ORDER BY created_at DESC');
$stmt->execute([$teacherId]);
$myQuizzes = $stmt->fetchAll();

// Total students who attempted my quizzes
$stmt2 = $pdo->prepare(
    'SELECT COUNT(DISTINCT qs.student_id) FROM quiz_sessions qs
     JOIN quizzes q ON qs.quiz_id = q.id
     WHERE q.created_by = ?'
);
$stmt2->execute([$teacherId]);
$totalStudents = (int) $stmt2->fetchColumn();

// Total submissions on my quizzes
$stmt3 = $pdo->prepare(
    'SELECT COUNT(*) FROM quiz_sessions qs
     JOIN quizzes q ON qs.quiz_id = q.id
     WHERE q.created_by = ? AND qs.status != \'in_progress\''
);
$stmt3->execute([$teacherId]);
$totalSubmissions = (int) $stmt3->fetchColumn();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
</head>
<body>
<?php $activePage = 'dashboard'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4">
    <div class="mb-4">
        <h2 class="fw-bold mb-1">Welcome back, <?= $teacherName ?>! 👋</h2>
        <p class="text-muted">Here's an overview of your quizzes and student activity.</p>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card text-center h-100" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
                <div class="card-body">
                    <div style="font-size:2rem; font-weight:900; color:#06b6d4;"><?= count($myQuizzes) ?></div>
                    <div class="text-muted small">My Quizzes</div>
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
                    <div style="font-size:2rem; font-weight:900; color:#fbbf24;"><?= $totalSubmissions ?></div>
                    <div class="text-muted small">Total Submissions</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
        <a href="<?= APP_BASE ?>/teacher/quizzes.php" class="btn btn-primary">➕ Create New Quiz</a>
        <a href="<?= APP_BASE ?>/teacher/access-codes.php" class="btn btn-outline-info">🔑 Access Codes</a>
        <a href="<?= APP_BASE ?>/teacher/results.php" class="btn btn-outline-secondary">📊 View All Results</a>
    </div>

    <!-- Recent quizzes -->
    <h5 class="fw-bold mb-3">My Recent Quizzes</h5>
    <?php if (empty($myQuizzes)): ?>
        <div class="alert" style="background:rgba(6,182,212,0.08); border:1px solid rgba(6,182,212,0.2); color:#9090b0;">
            You haven't created any quizzes yet. <a href="<?= APP_BASE ?>/teacher/quizzes.php" style="color:#06b6d4;">Create your first quiz →</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Time Limit</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($myQuizzes, 0, 5) as $quiz): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($quiz['title']) ?></td>
                        <td><?= (int)($quiz['time_limit'] / 60) ?> min</td>
                        <td>
                            <?php if ($quiz['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= e(date('M j, Y', strtotime($quiz['created_at']))) ?></td>
                        <td>
                            <a href="<?= APP_BASE ?>/teacher/edit-quiz.php?id=<?= (int)$quiz['id'] ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                            <a href="<?= APP_BASE ?>/teacher/access-codes.php?quiz_id=<?= (int)$quiz['id'] ?>" class="btn btn-sm btn-outline-info me-1">🔑 Codes</a>
                            <a href="<?= APP_BASE ?>/teacher/results.php?quiz_id=<?= (int)$quiz['id'] ?>" class="btn btn-sm btn-outline-secondary">Results</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($myQuizzes) > 5): ?>
            <a href="<?= APP_BASE ?>/teacher/quizzes.php" class="btn btn-sm btn-outline-secondary">View all <?= count($myQuizzes) ?> quizzes →</a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
</body>
</html>
