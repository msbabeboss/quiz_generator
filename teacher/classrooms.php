<?php
/**
 * teacher/classrooms.php — Section-Based Classroom Enrollment management.
 *
 * Teachers can:
 *   - Create named classrooms with a unique room code
 *   - Assign any of their quizzes to a classroom
 *   - See enrolled students per classroom
 *   - Delete classrooms or remove quizzes from them
 *
 * Students who enter the room code gain access to ALL quizzes assigned to
 * that classroom. Exams can also be accessed directly via exam codes
 * (existing flow) — both paths work independently.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';

requireRole('teacher');

$teacherId = (int) $_SESSION['user_id'];
$pdo       = getDB();
$csrfToken = generateCsrfToken();
$error     = '';
$success   = '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/** Generate a unique 8-char room code (uppercase, no ambiguous chars). */
function generateRoomCode(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $attempts = 0;
    do {
        $code  = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        $chk = $pdo->prepare('SELECT id FROM classrooms WHERE room_code = ?');
        $chk->execute([$code]);
        $attempts++;
    } while ($chk->fetch() && $attempts < 10);
    return $code;
}

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $action = $_POST['action'] ?? '';

    // ── Create classroom ────────────────────────────────────────────────
    if ($action === 'create_classroom') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') {
            $error = 'Classroom name is required.';
        } else {
            $code = generateRoomCode($pdo);
            $pdo->prepare(
                'INSERT INTO classrooms (teacher_id, name, description, room_code)
                 VALUES (?, ?, ?, ?)'
            )->execute([$teacherId, $name, $desc ?: null, $code]);
            $success = "Classroom <strong>" . e($name) . "</strong> created. Room code: <strong>{$code}</strong>";
        }
    }

    // ── Delete classroom ────────────────────────────────────────────────
    if ($action === 'delete_classroom') {
        $cid = (int) ($_POST['classroom_id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?');
        $own->execute([$cid, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM classrooms WHERE id = ?')->execute([$cid]);
            $success = 'Classroom deleted.';
        } else {
            $error = 'Classroom not found or access denied.';
        }
    }

    // ── Toggle classroom active ─────────────────────────────────────────
    if ($action === 'toggle_classroom') {
        $cid = (int) ($_POST['classroom_id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?');
        $own->execute([$cid, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('UPDATE classrooms SET is_active = 1 - is_active WHERE id = ?')->execute([$cid]);
            $success = 'Classroom status updated.';
        }
    }

    // ── Assign quiz to classroom ────────────────────────────────────────
    if ($action === 'assign_quiz') {
        $cid    = (int) ($_POST['classroom_id'] ?? 0);
        $quizId = (int) ($_POST['quiz_id']      ?? 0);
        $ownC   = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?');
        $ownC->execute([$cid, $teacherId]);
        $ownQ   = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND created_by = ?');
        $ownQ->execute([$quizId, $teacherId]);
        if ($ownC->fetch() && $ownQ->fetch()) {
            try {
                $pdo->prepare(
                    'INSERT IGNORE INTO classroom_quizzes (classroom_id, quiz_id) VALUES (?, ?)'
                )->execute([$cid, $quizId]);
                $success = 'Exam assigned to classroom.';
            } catch (PDOException $e) {
                $error = 'Could not assign exam.';
            }
        } else {
            $error = 'Access denied.';
        }
    }

    // ── Remove quiz from classroom ──────────────────────────────────────
    if ($action === 'remove_quiz') {
        $cid    = (int) ($_POST['classroom_id'] ?? 0);
        $quizId = (int) ($_POST['quiz_id']      ?? 0);
        $own    = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?');
        $own->execute([$cid, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM classroom_quizzes WHERE classroom_id = ? AND quiz_id = ?')
                ->execute([$cid, $quizId]);
            $success = 'Exam removed from classroom.';
        }
    }

    // ── Remove student from classroom ───────────────────────────────────
    if ($action === 'remove_student') {
        $cid = (int) ($_POST['classroom_id'] ?? 0);
        $sid = (int) ($_POST['student_id']   ?? 0);
        $own = $pdo->prepare('SELECT id FROM classrooms WHERE id = ? AND teacher_id = ?');
        $own->execute([$cid, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM classroom_enrollments WHERE classroom_id = ? AND student_id = ?')
                ->execute([$cid, $sid]);
            $success = 'Student removed from classroom.';
        }
    }

    if ($success || $error) {
        $redir = APP_BASE . '/teacher/classrooms.php';
        if (!empty($_POST['classroom_id'])) {
            $redir .= '?room=' . (int) $_POST['classroom_id'];
        }
        if ($success) $redir .= (strpos($redir, '?') !== false ? '&' : '?') . 'msg=' . urlencode($success);
        header('Location: ' . $redir);
        exit;
    }
}

if (isset($_GET['msg'])) $success = strip_tags(urldecode($_GET['msg']));

// ---------------------------------------------------------------------------
// Load teacher's classrooms
// ---------------------------------------------------------------------------
$classrooms = $pdo->prepare(
    'SELECT c.*, COUNT(DISTINCT ce.student_id) AS student_count,
            COUNT(DISTINCT cq.quiz_id) AS quiz_count
     FROM classrooms c
     LEFT JOIN classroom_enrollments ce ON ce.classroom_id = c.id
     LEFT JOIN classroom_quizzes cq     ON cq.classroom_id = c.id
     WHERE c.teacher_id = ?
     GROUP BY c.id
     ORDER BY c.created_at DESC'
);
$classrooms->execute([$teacherId]);
$classrooms = $classrooms->fetchAll();

// Load teacher's quizzes for the assign dropdown
$myQuizzes = $pdo->prepare('SELECT id, title FROM quizzes WHERE created_by = ? ORDER BY title');
$myQuizzes->execute([$teacherId]);
$myQuizzes = $myQuizzes->fetchAll();

// If a specific room is selected, load its details
$selectedRoom    = null;
$roomStudents    = [];
$roomQuizzes     = [];
$assignableQuizzes = [];
$selectedRoomId  = isset($_GET['room']) ? (int) $_GET['room'] : 0;

if ($selectedRoomId > 0) {
    $rs = $pdo->prepare('SELECT * FROM classrooms WHERE id = ? AND teacher_id = ?');
    $rs->execute([$selectedRoomId, $teacherId]);
    $selectedRoom = $rs->fetch() ?: null;

    if ($selectedRoom) {
        // Students enrolled in this room
        $ss = $pdo->prepare(
            'SELECT u.id, u.username, u.email, ce.enrolled_at
             FROM classroom_enrollments ce
             JOIN users u ON ce.student_id = u.id
             WHERE ce.classroom_id = ?
             ORDER BY ce.enrolled_at DESC'
        );
        $ss->execute([$selectedRoomId]);
        $roomStudents = $ss->fetchAll();

        // Quizzes assigned to this room
        $qs = $pdo->prepare(
            'SELECT q.id, q.title, q.is_active, q.time_limit, cq.added_at
             FROM classroom_quizzes cq
             JOIN quizzes q ON cq.quiz_id = q.id
             WHERE cq.classroom_id = ?
             ORDER BY cq.added_at DESC'
        );
        $qs->execute([$selectedRoomId]);
        $roomQuizzes = $qs->fetchAll();

        // Quizzes not yet assigned to this room
        $assignedIds = array_column($roomQuizzes, 'id');
        $assignableQuizzes = array_filter($myQuizzes, fn($q) => !in_array($q['id'], $assignedIds));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classrooms — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .room-code { font-family:'Courier New',monospace; font-size:1.4rem; letter-spacing:.18em; font-weight:700;
            background:rgba(6,182,212,.12); border:1px solid rgba(6,182,212,.35); color:#06b6d4;
            padding:.25rem .75rem; border-radius:.4rem; cursor:pointer; user-select:all; }
        .room-code:hover { background:rgba(6,182,212,.22); }
        .copy-hint { font-size:.72rem; color:#6c757d; }
        .room-card { background:#12122a; border:1px solid rgba(255,255,255,.08); border-radius:.75rem;
            transition:border-color .2s; cursor:pointer; }
        .room-card:hover, .room-card.active { border-color:rgba(6,182,212,.4); }
        .room-card.active { background:#0d1a2a; }
        .badge-active   { background:rgba(74,222,128,.15); color:#4ade80; border:1px solid rgba(74,222,128,.3); }
        .badge-inactive { background:rgba(248,113,113,.12); color:#f87171; border:1px solid rgba(248,113,113,.3); }
    </style>
</head>
<body>
<?php $activePage = 'classrooms'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container-fluid py-4" style="max-width:1200px;">
    <div class="d-flex align-items-center mb-1 gap-3">
        <div style="font-size:2rem;">🏫</div>
        <div>
            <h1 class="mb-0 fw-bold">Classrooms</h1>
            <p class="text-muted mb-0 small">Create rooms, share a room code with your class, and assign exams to them.</p>
        </div>
    </div>

    <!-- Access path explainer -->
    <div class="alert d-flex gap-3 align-items-start mt-3 mb-4"
         style="background:rgba(6,182,212,.07); border:1px solid rgba(6,182,212,.2); color:#9090b0;">
        <span style="font-size:1.4rem; flex-shrink:0;">ℹ️</span>
        <div style="font-size:.875rem;">
            <strong style="color:#e0e0f0;">Two ways students can access exams:</strong><br>
            <strong style="color:#06b6d4;">① Room Code</strong> — Student joins your classroom → sees all exams assigned to that room.<br>
            <strong style="color:#fbbf24;">② Exam Code</strong> — Student enters a specific exam code directly (via <a href="<?= APP_BASE ?>/teacher/access-codes.php" style="color:#fbbf24;">Access Codes</a> page). Both methods work independently.
        </div>
    </div>

    <?php if ($error):   ?><div class="alert alert-danger  alert-dismissible fade show"><?= e($error)   ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= $success     ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="row g-4">
        <!-- ── Left: classroom list + create form ── -->
        <div class="col-lg-4">

            <!-- Create classroom -->
            <div class="card mb-4" style="background:#12122a; border:1px solid rgba(74,222,128,.2);">
                <div class="card-header" style="background:transparent; border-bottom:1px solid rgba(74,222,128,.15);">
                    <strong>➕ Create New Classroom</strong>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action"     value="create_classroom">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Classroom Name *</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   placeholder="e.g. Grade 10 - Section A" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Description (optional)</label>
                            <input type="text" name="description" class="form-control form-control-sm"
                                   placeholder="e.g. Morning class, SY 2025-2026" maxlength="255">
                        </div>
                        <button type="submit" class="btn btn-success btn-sm w-100">Create Classroom</button>
                    </form>
                </div>
            </div>

            <!-- Classroom list -->
            <h6 class="fw-bold text-muted mb-2">MY CLASSROOMS (<?= count($classrooms) ?>)</h6>
            <?php if (empty($classrooms)): ?>
                <p class="text-muted small">No classrooms yet. Create one above.</p>
            <?php else: ?>
                <?php foreach ($classrooms as $room): ?>
                <a href="<?= APP_BASE ?>/teacher/classrooms.php?room=<?= (int)$room['id'] ?>"
                   class="d-block text-decoration-none mb-2">
                    <div class="room-card p-3 <?= $selectedRoomId === (int)$room['id'] ? 'active' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold" style="color:#e0e0f0;"><?= e($room['name']) ?></div>
                                <?php if ($room['description']): ?>
                                    <div class="text-muted small"><?= e($room['description']) ?></div>
                                <?php endif; ?>
                                <div class="mt-1 d-flex gap-2 flex-wrap">
                                    <span class="badge <?= $room['is_active'] ? 'badge-active' : 'badge-inactive' ?>" style="font-size:.65rem;">
                                        <?= $room['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <span class="text-muted" style="font-size:.75rem;">
                                        👥 <?= (int)$room['student_count'] ?> &nbsp; 📋 <?= (int)$room['quiz_count'] ?> exams
                                    </span>
                                </div>
                            </div>
                            <span class="room-code" style="font-size:.9rem; padding:.15rem .5rem;"
                                  onclick="event.preventDefault(); copyCode(this)"
                                  title="Click to copy room code">
                                <?= e($room['room_code']) ?>
                            </span>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── Right: selected room detail ── -->
        <div class="col-lg-8">
        <?php if ($selectedRoom): ?>

            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <h2 class="h4 fw-bold mb-0"><?= e($selectedRoom['name']) ?></h2>
                    <?php if ($selectedRoom['description']): ?>
                        <p class="text-muted small mb-0"><?= e($selectedRoom['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <!-- Toggle active -->
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token"    value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action"        value="toggle_classroom">
                        <input type="hidden" name="classroom_id"  value="<?= (int)$selectedRoom['id'] ?>">
                        <button class="btn btn-sm <?= $selectedRoom['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                            <?= $selectedRoom['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <!-- Delete -->
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Delete classroom <?= e($selectedRoom['name']) ?>? All enrollments will be removed.');">
                        <input type="hidden" name="csrf_token"   value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action"       value="delete_classroom">
                        <input type="hidden" name="classroom_id" value="<?= (int)$selectedRoom['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete Room</button>
                    </form>
                </div>
            </div>

            <!-- Room code share card -->
            <div class="card mb-4" style="background:#0d1a2a; border:1px solid rgba(6,182,212,.3);">
                <div class="card-body d-flex align-items-center gap-4 flex-wrap">
                    <div>
                        <div class="text-muted small mb-1">Share this room code with your students:</div>
                        <span class="room-code" onclick="copyCode(this)" title="Click to copy">
                            <?= e($selectedRoom['room_code']) ?>
                        </span>
                        <span class="copy-hint ms-2">click to copy</span>
                    </div>
                    <div class="text-muted small">
                        Students go to <strong style="color:#e0e0f0;">Dashboard → Enter Room Code</strong><br>
                        and type this code to join and see all assigned exams.
                    </div>
                </div>
            </div>

            <!-- Assigned exams -->
            <div class="card mb-4" style="background:#12122a; border:1px solid rgba(255,255,255,.08);">
                <div class="card-header d-flex justify-content-between align-items-center"
                     style="background:transparent; border-bottom:1px solid rgba(255,255,255,.08);">
                    <strong>📋 Assigned Exams (<?= count($roomQuizzes) ?>)</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($roomQuizzes)): ?>
                        <p class="text-muted small p-3 mb-0">No exams assigned yet. Use the form below to add one.</p>
                    <?php else: ?>
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead><tr><th>Exam Title</th><th>Status</th><th>Time</th><th>Added</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($roomQuizzes as $rq): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($rq['title']) ?></td>
                            <td><?= $rq['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            <td class="text-muted small"><?= (int)($rq['time_limit']/60) ?> min</td>
                            <td class="text-muted small"><?= e(date('M j, Y', strtotime($rq['added_at']))) ?></td>
                            <td>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Remove this exam from the classroom?');">
                                    <input type="hidden" name="csrf_token"   value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action"       value="remove_quiz">
                                    <input type="hidden" name="classroom_id" value="<?= (int)$selectedRoom['id'] ?>">
                                    <input type="hidden" name="quiz_id"      value="<?= (int)$rq['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php if (!empty($assignableQuizzes)): ?>
                <div class="card-footer" style="background:transparent; border-top:1px solid rgba(255,255,255,.08);">
                    <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
                        <input type="hidden" name="csrf_token"   value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action"       value="assign_quiz">
                        <input type="hidden" name="classroom_id" value="<?= (int)$selectedRoom['id'] ?>">
                        <div class="flex-grow-1">
                            <label class="form-label small fw-semibold mb-1">Assign an Exam</label>
                            <select name="quiz_id" class="form-select form-select-sm" required>
                                <option value="">— select exam —</option>
                                <?php foreach ($assignableQuizzes as $q): ?>
                                    <option value="<?= (int)$q['id'] ?>"><?= e($q['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Assign →</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Enrolled students -->
            <div class="card" style="background:#12122a; border:1px solid rgba(255,255,255,.08);">
                <div class="card-header" style="background:transparent; border-bottom:1px solid rgba(255,255,255,.08);">
                    <strong>👥 Enrolled Students (<?= count($roomStudents) ?>)</strong>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($roomStudents)): ?>
                        <p class="text-muted small p-3 mb-0">No students enrolled yet. Share the room code above.</p>
                    <?php else: ?>
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead><tr><th>Username</th><th>Email</th><th>Enrolled</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($roomStudents as $st): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($st['username']) ?></td>
                            <td class="text-muted small"><?= e($st['email']) ?></td>
                            <td class="text-muted small"><?= e(date('M j, Y', strtotime($st['enrolled_at']))) ?></td>
                            <td>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Remove <?= e($st['username']) ?> from this classroom?');">
                                    <input type="hidden" name="csrf_token"   value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action"       value="remove_student">
                                    <input type="hidden" name="classroom_id" value="<?= (int)$selectedRoom['id'] ?>">
                                    <input type="hidden" name="student_id"   value="<?= (int)$st['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="d-flex flex-column align-items-center justify-content-center text-center py-5"
                 style="background:#12122a; border:1px solid rgba(255,255,255,.06); border-radius:.75rem; min-height:300px;">
                <div style="font-size:3rem; margin-bottom:1rem;">🏫</div>
                <h3 class="h5 mb-2" style="color:#e0e0f0;">Select a classroom</h3>
                <p class="text-muted small">Choose a classroom from the list on the left to manage it,<br>or create a new one.</p>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
<script>
function copyCode(el) {
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function () {
        var hint = el.nextElementSibling;
        if (hint && hint.classList.contains('copy-hint')) {
            hint.textContent = '✅ Copied!';
            setTimeout(function () { hint.textContent = 'click to copy'; }, 2000);
        }
    }).catch(function () {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
    });
}
</script>
</body>
</html>
