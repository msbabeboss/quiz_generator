<?php
/**
 * admin/users.php — User management: view, suspend, activate, delete users.
 * Admin can manage all students and teachers.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';

requireRole('teacher');

$pdo     = getDB();
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Forbidden'); }

    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    // Never allow admin to modify another admin or themselves
    if ($uid > 0 && $uid !== (int)$_SESSION['user_id']) {
        $chk = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $chk->execute([$uid]);
        $target = $chk->fetch();

        if ($target && $target['role'] === 'student') {
            // Teachers can only manage students — not other teachers
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
            $error = 'You can only manage student accounts.';
        }
    }
}

$csrfToken = generateCsrfToken();

// Filters
$roleFilter   = in_array($_GET['role']   ?? '', ['teacher','student','']) ? ($_GET['role']   ?? '') : '';
$statusFilter = in_array($_GET['status'] ?? '', ['active','suspended','']) ? ($_GET['status'] ?? '') : '';
$search       = trim($_GET['search'] ?? '');

$sql    = 'SELECT id, username, email, role, status, created_at FROM users WHERE role = \'student\'';
$params = [];
if ($roleFilter)   { $sql .= ' AND role = ?';   $params[] = $roleFilter; }
if ($statusFilter) { $sql .= ' AND status = ?'; $params[] = $statusFilter; }
if ($search)       { $sql .= ' AND (username LIKE ? OR email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= ' ORDER BY created_at DESC';

$stmt  = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Counts
$countStmt = $pdo->query("SELECT role, status, COUNT(*) as cnt FROM users WHERE role != 'admin' GROUP BY role, status");
$counts = ['teacher' => ['active' => 0, 'suspended' => 0], 'student' => ['active' => 0, 'suspended' => 0]];
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['role']][$row['status']] = (int)$row['cnt'];
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Admin</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
</head>
<body>
<?php $activePage = 'students'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>
<div class="container py-4">
    <h1 class="mb-4">User Management</h1>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= e($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger  alert-dismissible fade show"><?= e($error)   ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#06b6d4;"><?= $counts['teacher']['active'] ?></div>
                    <div class="text-muted small">Active Teachers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(239,68,68,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#ef4444;"><?= $counts['teacher']['suspended'] ?></div>
                    <div class="text-muted small">Suspended Teachers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(74,222,128,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#4ade80;"><?= $counts['student']['active'] ?></div>
                    <div class="text-muted small">Active Students</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center" style="background:#12122a; border:1px solid rgba(251,191,36,0.2);">
                <div class="card-body py-3">
                    <div style="font-size:1.75rem; font-weight:900; color:#fbbf24;"><?= $counts['student']['suspended'] ?></div>
                    <div class="text-muted small">Suspended Students</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="row g-2 mb-4 align-items-end">
        <div class="col-sm-4">
            <label class="form-label small text-muted">Search</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Username or email" value="<?= e($search) ?>">
        </div>
        <div class="col-sm-3">
            <label class="form-label small text-muted">Role</label>
            <select name="role" class="form-select form-select-sm">
                <option value="">All Roles</option>
                <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
            </select>
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
    </form>

    <!-- Users table -->
    <?php if (empty($users)): ?>
        <div class="alert alert-info">No users found matching your filters.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td class="fw-semibold"><?= e($u['username']) ?></td>
                <td class="text-muted small"><?= e($u['email']) ?></td>
                <td>
                    <?php if ($u['role'] === 'teacher'): ?>
                        <span class="badge" style="background:rgba(6,182,212,0.2); color:#67e8f9; border:1px solid rgba(6,182,212,0.3);">📚 Teacher</span>
                    <?php else: ?>
                        <span class="badge" style="background:rgba(67,97,238,0.2); color:#a5b4fc; border:1px solid rgba(67,97,238,0.3);">🎓 Student</span>
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
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete user <?= e($u['username']) ?>? This cannot be undone.');">
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
    <div class="text-muted small mt-2">Showing <?= count($users) ?> user(s)</div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
</body>
</html>
