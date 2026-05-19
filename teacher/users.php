<?php
/**
 * teacher/users.php — Student management scoped to THIS teacher.
 *
 * A teacher can only see students who have enrolled in:
 *   1. A quiz they created via an exam access code, OR
 *   2. A classroom they own (room-code enrollment).
 *
 * Actions: suspend, activate, delete — students only.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';

requireRole('teacher');

$pdo       = getDB();
$teacherId = (int) $_SESSION['user_id'];
$success   = $error = '';

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $action = $_POST['action'] ?? '';
    $uid    = (int) ($_POST['user_id'] ?? 0);

    if ($uid > 0 && $uid !== $teacherId) {
        // Confirm the target is a student enrolled with THIS teacher
        $chk = $pdo->prepare(
            'SELECT u.id FROM users u
             WHERE u.id = ? AND u.role = \'student\'
               AND (
                   EXISTS (
                       SELECT 1 FROM quiz_enrollments qe
                       JOIN quiz_access_codes qac ON qe.code_id = qac.id
                       JOIN quizzes q             ON qac.quiz_id = q.id
                       WHERE qe.student_id = u.id AND q.created_by = ?
                   )
                   OR EXISTS (
                       SELECT 1 FROM classroom_enrollments ce
                       JOIN classrooms c ON ce.classroom_id = c.id
                       WHERE ce.student_id = u.id AND c.teacher_id = ?
                   )
               )'
        );
        $chk->execute([$uid, $teacherId, $teacherId]);
        $target = $chk->fetch();

        if ($target) {
            if ($action === 'suspend') {
                $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
                $success = 'Student suspended.';
            } elseif ($action === 'activate') {
                $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$uid]);
                $success = 'Student activated.';
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                $success = 'Student deleted.';
            } else {
                $error = 'Unknown action.';
            }
        } else {
            $error = 'You can only manage students enrolled in your quizzes or classrooms.';
        }
    }
}

$csrfToken = generateCsrfToken();

// ── Filters ─────────────────────────────────────────────────────────────────
$statusFilter = in_array($_GET['status'] ?? '', ['active', 'suspended', ''])
    ? ($_GET['status'] ?? '') : '';
$search = trim($_GET['search'] ?? '');

// ── Main query — students scoped to this teacher ─────────────────────────────
// Pulls in how the student is connected: exam code, classroom, or both.
$sql = '
    SELECT
        u.id, u.username, u.email, u.status, u.created_at,
        GROUP_CONCAT(DISTINCT qac.label   ORDER BY qac.label   SEPARATOR \', \') AS exam_labels,
        GROUP_CONCAT(DISTINCT c.name      ORDER BY c.name      SEPARATOR \', \') AS room_names,
        GROUP_CONCAT(DISTINCT q.title     ORDER BY q.title     SEPARATOR \', \') AS quiz_titles
    FROM users u
    LEFT JOIN quiz_enrollments qe  ON qe.student_id = u.id
    LEFT JOIN quiz_access_codes qac ON qe.code_id   = qac.id
    LEFT JOIN quizzes q            ON qac.quiz_id   = q.id AND q.created_by = :tid1
    LEFT JOIN classroom_enrollments ce ON ce.student_id = u.id
    LEFT JOIN classrooms c         ON ce.classroom_id = c.id AND c.teacher_id = :tid2
    WHERE u.role = \'student\'
      AND (q.created_by = :tid3 OR c.teacher_id = :tid4)
';
$params = [
    ':tid1' => $teacherId,
    ':tid2' => $teacherId,
    ':tid3' => $teacherId,
    ':tid4' => $teacherId,
];

if ($statusFilter) {
    $sql .= ' AND u.status = :status';
    $params[':status'] = $statusFilter;
}
if ($search) {
    $sql .= ' AND (u.username LIKE :s1 OR u.email LIKE :s2)';
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
}
$sql .= ' GROUP BY u.id ORDER BY u.created_at DESC';

$stmt  = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// ── Counts scoped to this teacher ────────────────────────────────────────────
$cntStmt = $pdo->prepare('
    SELECT u.status, COUNT(DISTINCT u.id) AS cnt
    FROM users u
    WHERE u.role = \'student\'
      AND (
          EXISTS (
              SELECT 1 FROM quiz_enrollments qe
              JOIN quiz_access_codes qac ON qe.code_id = qac.id
              JOIN quizzes q             ON qac.quiz_id = q.id
              WHERE qe.student_id = u.id AND q.created_by = ?
          )
          OR EXISTS (
              SELECT 1 FROM classroom_enrollments ce
              JOIN classrooms c ON ce.classroom_id = c.id
              WHERE ce.student_id = u.id AND c.teacher_id = ?
          )
      )
    GROUP BY u.status
');
$cntStmt->execute([$teacherId, $teacherId]);
$counts = ['active' => 0, 'suspended' => 0];
foreach ($cntStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['cnt'];
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Students — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
</head>
<body>
<?php $activePage = 'students'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
        <h1 class="mb-0">My Students</h1>
        <span class="text-muted small">Only students enrolled in your quizzes or classrooms are shown.</span>
    </div>
    <hr class="mb-4">

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(74,222,128,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#4ade80;"><?= $counts['active'] ?></div>
                    <div class="text-muted small">Active Students</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(251,191,36,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#fbbf24;"><?= $counts['suspended'] ?></div>
                    <div class="text-muted small">Suspended Students</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(67,97,238,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#a5b4fc;"><?= $counts['active'] + $counts['suspended'] ?></div>
                    <div class="text-muted small">Total Students</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="row g-2 mb-4 align-items-end">
        <div class="col-sm-5">
            <label class="form-label small text-muted">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Username or email" value="<?= e($search) ?>">
        </div>
        <div class="col-sm-3">
            <label class="form-label small text-muted">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="active"    <?= $statusFilter === 'active'    ? 'selected' : '' ?>>Active</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>
        <div class="col-sm-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
        </div>
        <?php if ($search || $statusFilter): ?>
        <div class="col-sm-2">
            <a href="users.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <?php if (empty($users)): ?>
        <div class="card text-center py-5" style="background:#12122a; border:1px solid rgba(255,255,255,0.08);">
            <div style="font-size:3rem; margin-bottom:1rem;">🎓</div>
            <h3 class="h5 mb-2" style="color:#e0e0f0;">No students yet</h3>
            <p class="text-muted mb-0">Students will appear here once they join one of your quizzes or classrooms.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle" style="font-size:.875rem;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Enrolled Via</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $i => $u): ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td class="fw-semibold"><?= e($u['username']) ?></td>
                    <td class="text-muted small"><?= e($u['email']) ?></td>
                    <td style="max-width:220px;">
                        <?php if (!empty($u['room_names'])): ?>
                            <?php foreach (explode(', ', $u['room_names']) as $room): ?>
                                <span class="badge me-1 mb-1"
                                      style="background:rgba(6,182,212,.15); color:#67e8f9; border:1px solid rgba(6,182,212,.3); font-size:.65rem;">
                                    🏫 <?= e(trim($room)) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($u['exam_labels'])): ?>
                            <?php foreach (explode(', ', $u['exam_labels']) as $label): ?>
                                <span class="badge me-1 mb-1"
                                      style="background:rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); font-size:.65rem;">
                                    📝 <?= e(trim($label)) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (empty($u['room_names']) && empty($u['exam_labels'])): ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Suspended</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <form method="post" class="d-inline me-1">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action"    value="suspend">
                                <input type="hidden" name="user_id"   value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-warning text-dark">Suspend</button>
                            </form>
                        <?php else: ?>
                            <form method="post" class="d-inline me-1">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action"    value="activate">
                                <input type="hidden" name="user_id"   value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-success">Activate</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete <?= e($u['username']) ?>? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action"    value="delete">
                            <input type="hidden" name="user_id"   value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-muted small mt-2">Showing <?= count($users) ?> student(s)</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
</body>
</html>
