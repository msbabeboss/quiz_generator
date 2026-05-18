<?php
/**
 * student/join.php — Enter a Room Code or Exam Code.
 *
 * Room Code  → enrolls student in the classroom → they see all assigned exams
 * Exam Code  → enrolls student directly in that specific exam
 *
 * Both paths are independent; students can use either or both.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';

requireRole('student');

$studentId = (int) $_SESSION['user_id'];
$pdo       = getDB();
$error     = '';
$successMsg = '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$prefill = strtoupper(trim($_GET['code'] ?? ''));

$redirectError = '';
if (isset($_GET['error']) && $_GET['error'] === 'not_enrolled') {
    $redirectError = 'Access denied. You must enter a valid exam code or room code before you can take this exam.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $code = strtoupper(trim($_POST['code'] ?? ''));

    if (strlen($code) !== 8 || !ctype_alnum($code)) {
        $error = 'Please enter a valid 8-character code.';
    } else {
        // ── Try Room Code first ─────────────────────────────────────────
        $roomStmt = $pdo->prepare(
            'SELECT id, name, is_active FROM classrooms WHERE room_code = ? LIMIT 1'
        );
        $roomStmt->execute([$code]);
        $room = $roomStmt->fetch();

        if ($room) {
            if (!(int)$room['is_active']) {
                $error = 'This classroom is currently inactive. Please check with your teacher.';
            } else {
                $roomId = (int) $room['id'];
                // Check if already enrolled in this room
                $chk = $pdo->prepare(
                    'SELECT id FROM classroom_enrollments WHERE classroom_id = ? AND student_id = ?'
                );
                $chk->execute([$roomId, $studentId]);
                if (!$chk->fetch()) {
                    $pdo->prepare(
                        'INSERT INTO classroom_enrollments (classroom_id, student_id) VALUES (?, ?)'
                    )->execute([$roomId, $studentId]);
                }
                // Also enroll in all quizzes currently assigned to this room
                // (via quiz_access_codes — we need a code per quiz for the enrollment system)
                // We use classroom_quizzes to grant access; take-quiz checks both paths
                header('Location: ' . APP_BASE . '/student/dashboard.php?joined_room=' . urlencode($room['name']));
                exit;
            }
        } else {
            // ── Try Exam Code ───────────────────────────────────────────
            $examStmt = $pdo->prepare(
                'SELECT qac.id AS code_id, qac.quiz_id, qac.label,
                        q.title, q.is_active
                 FROM quiz_access_codes qac
                 JOIN quizzes q ON qac.quiz_id = q.id
                 WHERE qac.code = ?
                 LIMIT 1'
            );
            $examStmt->execute([$code]);
            $found = $examStmt->fetch();

            if (!$found) {
                $error = 'Invalid code. Please check with your teacher — this is neither a valid room code nor an exam code.';
            } elseif (!(int)$found['is_active']) {
                $error = 'This exam is currently inactive. Please check with your teacher.';
            } else {
                $quizId = (int) $found['quiz_id'];
                $codeId = (int) $found['code_id'];

                $chk = $pdo->prepare(
                    'SELECT id FROM quiz_enrollments WHERE student_id = ? AND quiz_id = ?'
                );
                $chk->execute([$studentId, $quizId]);
                if (!$chk->fetch()) {
                    $pdo->prepare(
                        'INSERT INTO quiz_enrollments (code_id, quiz_id, student_id) VALUES (?, ?, ?)'
                    )->execute([$codeId, $quizId, $studentId]);
                }
                header('Location: ' . APP_BASE . '/student/take-quiz.php?quiz_id=' . $quizId);
                exit;
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Code — Quiz App</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <style>
        .code-input { font-family:'Courier New',monospace; font-size:2rem; letter-spacing:.3em;
            text-align:center; text-transform:uppercase; max-width:320px; }
        .path-card { background:#12122a; border:1px solid rgba(255,255,255,.08); border-radius:.75rem; padding:1rem; }
        .path-card.room { border-color:rgba(6,182,212,.25); }
        .path-card.exam { border-color:rgba(251,191,36,.2); }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= APP_BASE ?>/student/dashboard.php">🧠 Quiz App</a>
        <div class="collapse navbar-collapse">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><span class="nav-link text-light"><?= e($_SESSION['username'] ?? '') ?></span></li>
                <li class="nav-item"><a class="nav-link" href="<?= APP_BASE ?>/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="text-center mb-4">
                <div style="font-size:3.5rem;">🔑</div>
                <h1 class="h3 fw-bold mt-2">Enter Your Code</h1>
                <p class="text-muted">Enter a <strong>Room Code</strong> to join a classroom, or an <strong>Exam Code</strong> to access a specific exam.</p>
            </div>

            <!-- Code type explainer -->
            <div class="row g-2 mb-4">
                <div class="col-6">
                    <div class="path-card room text-center">
                        <div style="font-size:1.5rem;">🏫</div>
                        <div class="fw-semibold small" style="color:#06b6d4;">Room Code</div>
                        <div class="text-muted" style="font-size:.75rem;">Join a classroom &amp; see all its exams</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="path-card exam text-center">
                        <div style="font-size:1.5rem;">📝</div>
                        <div class="fw-semibold small" style="color:#fbbf24;">Exam Code</div>
                        <div class="text-muted" style="font-size:.75rem;">Access one specific exam directly</div>
                    </div>
                </div>
            </div>

            <?php if ($redirectError): ?>
                <div class="alert alert-warning"><?= e($redirectError) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="card" style="background:#12122a; border:1px solid rgba(6,182,212,0.25);">
                <div class="card-body p-4">
                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <div class="mb-4 d-flex flex-column align-items-center">
                            <label for="code" class="form-label fw-semibold mb-2">8-Character Code</label>
                            <input type="text" id="code" name="code"
                                   class="form-control code-input"
                                   maxlength="8" minlength="8" required
                                   autocomplete="off" spellcheck="false"
                                   value="<?= e($prefill) ?>"
                                   placeholder="XXXXXXXX"
                                   oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">🔓 Submit Code →</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="text-center mt-3">
                <a href="<?= APP_BASE ?>/student/dashboard.php" class="text-muted small">← Back to Dashboard</a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
</body>
</html>
