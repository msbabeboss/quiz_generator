<?php
/**
 * teacher/access-codes.php — Unified Access Codes management.
 *
 * Teachers can:
 *   - Generate unique access codes for any of their quizzes
 *   - Label each code with a section name (e.g. "Section A", "Grade 10-B")
 *   - See which students enrolled via each code
 *   - Delete a code (removes enrollments for that code)
 *   - Delete all quiz history (sessions, answers, results) for a quiz
 *
 * Students use the same 8-char code to access quizzes directly.
 * Room codes (classrooms) are managed separately in classrooms.php.
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

/**
 * Generate a cryptographically random, human-friendly 8-char uppercase code.
 * Excludes ambiguous characters (0, O, I, 1).
 */
function generateCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    $bytes = random_bytes(8);
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[ord($bytes[$i]) % strlen($chars)];
    }
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

    // ── Generate a new access code ──────────────────────────────────────
    if ($action === 'generate_code') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $label  = trim($_POST['label'] ?? '');

        // Ownership check
        $own = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND created_by = ?');
        $own->execute([$quizId, $teacherId]);
        if (!$own->fetch()) {
            $error = 'Quiz not found or access denied.';
        } else {
            // Generate a unique code (retry on collision)
            $attempts = 0;
            do {
                $code = generateCode();
                $chk  = $pdo->prepare('SELECT id FROM quiz_access_codes WHERE code = ?');
                $chk->execute([$code]);
                $attempts++;
            } while ($chk->fetch() && $attempts < 10);

            $ins = $pdo->prepare(
                'INSERT INTO quiz_access_codes (quiz_id, code, label, teacher_id)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$quizId, $code, $label, $teacherId]);
            $success = "Code <strong>{$code}</strong> created for section \"" . e($label ?: 'Unnamed') . "\".";
        }
    }

    // ── Delete an access code ────────────────────────────────────────────
    if ($action === 'delete_code') {
        $codeId = (int) ($_POST['code_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT qac.id FROM quiz_access_codes qac
             JOIN quizzes q ON qac.quiz_id = q.id
             WHERE qac.id = ? AND q.created_by = ?'
        );
        $own->execute([$codeId, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM quiz_access_codes WHERE id = ?')->execute([$codeId]);
            $success = 'Access code deleted.';
        } else {
            $error = 'Code not found or access denied.';
        }
    }

    // ── Delete quiz history (sessions/answers/results) ───────────────────
    if ($action === 'delete_history') {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND created_by = ?');
        $own->execute([$quizId, $teacherId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM quiz_sessions WHERE quiz_id = ?')->execute([$quizId]);
            $success = 'Quiz history (sessions, answers, results) cleared.';
        } else {
            $error = 'Quiz not found or access denied.';
        }
    }

    // Redirect to avoid re-POST on refresh
    if ($success) {
        $quizId = (int) ($_POST['quiz_id'] ?? 0);
        header('Location: ' . APP_BASE . '/teacher/access-codes.php?quiz_id=' . $quizId . '&msg=' . urlencode($success));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $success = strip_tags(urldecode($_GET['msg']));
}

// ---------------------------------------------------------------------------
// Load teacher's quizzes for the dropdown
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT id, title FROM quizzes WHERE created_by = ? ORDER BY created_at DESC');
$stmt->execute([$teacherId]);
$myQuizzes = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// If a quiz_id is selected, load its codes and enrolled students
// ---------------------------------------------------------------------------
$selectedQuizId = isset($_GET['quiz_id']) ? (int) $_GET['quiz_id'] : 0;
$selectedQuiz   = null;
$codes          = [];
$enrolledByCode = [];

if ($selectedQuizId > 0) {
    $qstmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ? AND created_by = ?');
    $qstmt->execute([$selectedQuizId, $teacherId]);
    $selectedQuiz = $qstmt->fetch() ?: null;

    if ($selectedQuiz) {
        $cstmt = $pdo->prepare(
            'SELECT * FROM quiz_access_codes WHERE quiz_id = ? ORDER BY created_at DESC'
        );
        $cstmt->execute([$selectedQuizId]);
        $codes = $cstmt->fetchAll();

        foreach ($codes as $c) {
            $estmt = $pdo->prepare(
                'SELECT u.username, u.email, qe.enrolled_at,
                        qs.status, r.score, r.total_points, r.percentage, qs.id AS session_id
                 FROM quiz_enrollments qe
                 JOIN users u ON qe.student_id = u.id
                 LEFT JOIN quiz_sessions qs ON qs.student_id = qe.student_id
                     AND qs.quiz_id = qe.quiz_id
                     AND qs.status != \'in_progress\'
                 LEFT JOIN results r ON r.session_id = qs.id
                 WHERE qe.code_id = ?
                 ORDER BY qe.enrolled_at DESC'
            );
            $estmt->execute([$c['id']]);
            $enrolledByCode[$c['id']] = $estmt->fetchAll();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Codes — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .code-badge {
            font-family: 'Courier New', monospace;
            font-size: 1.4rem;
            letter-spacing: .18em;
            font-weight: 700;
            background: rgba(6,182,212,.15);
            border: 1px solid rgba(6,182,212,.4);
            color: #06b6d4;
            padding: .25rem .75rem;
            border-radius: .4rem;
            cursor: pointer;
            user-select: all;
        }
        .code-badge:hover { background: rgba(6,182,212,.28); }
        .copy-hint { font-size:.75rem; color:#6c757d; }
    </style>
</head>
<body>
<?php $activePage = 'access-codes'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4">
    <h1 class="mb-1">🔑 Access Codes</h1>
    <p class="text-muted mb-4">Generate unique codes per section. Share a code with students so only they can access that quiz.</p>

    <?php if ($error):   ?><div class="alert alert-danger  alert-dismissible fade show"><?= e($error)   ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= $success     ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <!-- ── Quiz selector ─────────────────────────────────────────────── -->
    <div class="card mb-4" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-sm-8">
                    <label class="form-label fw-semibold">Select Quiz</label>
                    <select name="quiz_id" class="form-select" onchange="this.form.submit()">
                        <option value="">— choose a quiz —</option>
                        <?php foreach ($myQuizzes as $q): ?>
                            <option value="<?= (int)$q['id'] ?>"
                                <?= $selectedQuizId === (int)$q['id'] ? 'selected' : '' ?>>
                                <?= e($q['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedQuiz): ?>

    <!-- ── Generate new code ─────────────────────────────────────────── -->
    <div class="card mb-4" style="background:#12122a; border:1px solid rgba(74,222,128,0.2);">
        <div class="card-header" style="background:transparent; border-bottom:1px solid rgba(74,222,128,0.2);">
            <strong>Generate New Access Code for: <?= e($selectedQuiz['title']) ?></strong>
        </div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action"    value="generate_code">
                <input type="hidden" name="quiz_id"   value="<?= $selectedQuizId ?>">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Section / Label</label>
                    <input type="text" name="label" class="form-control"
                           placeholder="e.g. Section A, Grade 10-B, Morning Class"
                           maxlength="100">
                    <div class="form-text text-muted">Optional label to identify this group.</div>
                </div>
                <div class="col-sm-3">
                    <button type="submit" class="btn btn-success w-100">➕ Generate Code</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Delete quiz history ───────────────────────────────────────── -->
    <div class="d-flex justify-content-end mb-4">
        <form method="post"
              onsubmit="return confirm('This will permanently delete ALL sessions, answers, and results for this quiz. Students will be able to retake it. Continue?');">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action"    value="delete_history">
            <input type="hidden" name="quiz_id"   value="<?= $selectedQuizId ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                🗑 Delete All Quiz History
            </button>
        </form>
    </div>

    <!-- ── Existing codes ────────────────────────────────────────────── -->
    <?php if (empty($codes)): ?>
        <div class="alert alert-secondary">No access codes yet for this quiz. Generate one above.</div>
    <?php else: ?>
        <?php foreach ($codes as $c): ?>
        <div class="card mb-4" style="background:#0d0d1a; border:1px solid rgba(255,255,255,0.1);">
            <div class="card-header d-flex justify-content-between align-items-center"
                 style="background:transparent; border-bottom:1px solid rgba(255,255,255,0.08);">
                <div>
                    <span class="code-badge" title="Click to copy" onclick="copyCode(this)">
                        <?= e($c['code']) ?>
                    </span>
                    <span class="copy-hint ms-2">click to copy</span>
                    <?php if ($c['label'] !== ''): ?>
                        <span class="badge bg-info text-dark ms-2"><?= e($c['label']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted small ms-3">
                        Created <?= e(date('M j, Y g:i a', strtotime($c['created_at']))) ?>
                    </span>
                </div>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Delete this code? Enrolled students will lose access.');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action"    value="delete_code">
                    <input type="hidden" name="code_id"   value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="quiz_id"   value="<?= $selectedQuizId ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete Code</button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php $enrolled = $enrolledByCode[$c['id']] ?? []; ?>
                <?php if (empty($enrolled)): ?>
                    <p class="text-muted small p-3 mb-0">No students have joined with this code yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Enrolled At</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>%</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($enrolled as $row): ?>
                                <tr>
                                    <td><?= e($row['username']) ?></td>
                                    <td class="text-muted small"><?= e($row['email']) ?></td>
                                    <td class="text-muted small"><?= e($row['enrolled_at']) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'submitted'): ?>
                                            <span class="badge bg-success">Submitted</span>
                                        <?php elseif ($row['status'] === 'timed_out'): ?>
                                            <span class="badge bg-warning text-dark">Timed Out</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Not taken</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['score'] !== null): ?>
                                            <?= e((string)$row['score']) ?> / <?= e((string)$row['total_points']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['percentage'] !== null): ?>
                                            <?= number_format((float)$row['percentage'], 1) ?>%
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['session_id']): ?>
                                            <a href="<?= APP_BASE ?>/teacher/results.php?session_id=<?= (int)$row['session_id'] ?>"
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
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; /* selectedQuiz */ ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
        crossorigin="anonymous"></script>
<script>
function copyCode(el) {
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function () {
        var hint = el.nextElementSibling;
        if (hint) { hint.textContent = '✅ Copied!'; setTimeout(function(){ hint.textContent = 'click to copy'; }, 2000); }
    }).catch(function () {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}
</script>
</body>
</html>
