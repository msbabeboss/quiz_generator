<?php
/**
 * profile.php — Edit username, email, and password. Works for both roles.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/middleware.php';

requireAuth();

$userId   = (int) $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'student';
$pdo      = getDB();

// Load current user data
$stmt = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) { session_destroy(); header('Location: ' . APP_BASE . '/login.php'); exit; }

$successProfile = $successPassword = $errorProfile = $errorPassword = '';

// ── POST handler ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $action = $_POST['action'] ?? '';

    // ── Update profile (username + email) ────────────────────────────
    if ($action === 'update_profile') {
        $newUsername = trim($_POST['username'] ?? '');
        $newEmail    = trim($_POST['email']    ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $newUsername)) {
            $errorProfile = 'Username must be 3–50 characters: letters, numbers, or underscores only.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errorProfile = 'Please enter a valid email address.';
        } else {
            try {
                $upd = $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
                $upd->execute([$newUsername, $newEmail, $userId]);
                $_SESSION['username'] = $newUsername;
                $user['username']     = $newUsername;
                $user['email']        = $newEmail;
                $successProfile = 'Profile updated successfully.';
            } catch (PDOException $e) {
                $errorProfile = 'Username or email is already taken.';
            }
        }
    }

    // ── Change password ──────────────────────────────────────────────
    if ($action === 'change_password') {
        $currentPw  = $_POST['current_password']  ?? '';
        $newPw      = $_POST['new_password']       ?? '';
        $confirmPw  = $_POST['confirm_password']   ?? '';

        // Verify current password
        $chk = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $chk->execute([$userId]);
        $row = $chk->fetch();

        if (!$row || !password_verify($currentPw, $row['password_hash'])) {
            $errorPassword = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 8) {
            $errorPassword = 'New password must be at least 8 characters.';
        } elseif ($newPw !== $confirmPw) {
            $errorPassword = 'New passwords do not match.';
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            $successPassword = 'Password changed successfully.';
        }
    }
}

$csrfToken   = generateCsrfToken();
$dashUrl     = $userRole === 'teacher' ? APP_BASE . '/teacher/dashboard.php' : APP_BASE . '/student/dashboard.php';
$navBrand    = $userRole === 'teacher' ? '📚 QuizGen Teacher' : '🧠 QuizGen';
$navColor    = $userRole === 'teacher' ? 'rgba(6,182,212,0.2)' : 'rgba(67,97,238,0.2)';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .profile-avatar {
            width:80px; height:80px; border-radius:50%;
            background:linear-gradient(135deg,#4361ee,#f72585);
            display:flex; align-items:center; justify-content:center;
            font-size:2rem; font-weight:900; color:#fff;
            margin:0 auto 1rem;
        }
        .section-card { background:#12122a; border:1px solid rgba(255,255,255,0.08); border-radius:1rem; padding:1.75rem; margin-bottom:1.5rem; }
        .section-title { font-size:1rem; font-weight:800; margin-bottom:1.25rem; display:flex; align-items:center; gap:.5rem; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:.4rem; }
        .form-control { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; border-radius:.5rem; padding:.65rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; }
        .form-control:focus { background:#0d0d1a; border-color:#4361ee; color:#e0e0f0; box-shadow:0 0 0 3px rgba(67,97,238,.2); }
        .form-control::placeholder { color:#555570; }
        .form-control.is-invalid { border-color:#ef4444 !important; }
        .pw-wrap { position:relative; }
        .pw-wrap .form-control { padding-right:3rem; }
        .pw-toggle { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#9090b0; cursor:pointer; font-size:1rem; padding:0; line-height:1; }
        .alert-danger  { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#fca5a5; border-radius:.5rem; font-size:.875rem; }
        .alert-success { background:rgba(74,222,128,.12); border-color:rgba(74,222,128,.3); color:#86efac; border-radius:.5rem; font-size:.875rem; }
        .role-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .75rem; border-radius:999px; font-size:.75rem; font-weight:700; }
        .role-badge.teacher { background:rgba(6,182,212,.15); color:#67e8f9; border:1px solid rgba(6,182,212,.3); }
        .role-badge.student { background:rgba(67,97,238,.15); color:#a5b4fc; border:1px solid rgba(67,97,238,.3); }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark" style="background:#0d0d1a; border-bottom:1px solid <?= $navColor ?>;">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= $dashUrl ?>"><?= $navBrand ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navP"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navP">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= $dashUrl ?>">Dashboard</a></li>
            </ul>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item me-3"><span class="text-muted small">👤 <?= e($user['username']) ?></span></li>
                <li class="nav-item"><a class="btn btn-sm btn-outline-danger" href="<?= APP_BASE ?>/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width:640px;">

    <!-- Avatar + name -->
    <div class="text-center mb-4">
        <div class="profile-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
        <h2 class="fw-bold mb-1"><?= e($user['username']) ?></h2>
        <span class="role-badge <?= e($userRole) ?>"><?= $userRole === 'teacher' ? '📚 Teacher' : '🎓 Student' ?></span>
    </div>

    <!-- Profile info -->
    <div class="section-card">
        <div class="section-title">👤 Profile Information</div>

        <?php if ($successProfile): ?><div class="alert alert-success mb-3"><?= e($successProfile) ?></div><?php endif; ?>
        <?php if ($errorProfile):   ?><div class="alert alert-danger  mb-3"><?= e($errorProfile)   ?></div><?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action"     value="update_profile">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username"
                    value="<?= e($user['username']) ?>" required
                    minlength="3" maxlength="50"
                    pattern="[a-zA-Z0-9_]+"
                    title="3–50 characters: letters, numbers, or underscores only"
                    autocomplete="username"
                    oninput="updateUsernameHint(this)">
                <div class="d-flex justify-content-between mt-1">
                    <small id="username-hint" class="text-muted" style="font-size:.75rem;">
                        Letters, numbers, and underscores only.
                    </small>
                    <small id="username-count" class="text-muted" style="font-size:.75rem;">
                        <?= strlen($user['username']) ?>/50
                    </small>
                </div>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="email" name="email"
                    value="<?= e($user['email']) ?>" required autocomplete="email">
            </div>
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </form>
    </div>

    <!-- Change password -->
    <div class="section-card">
        <div class="section-title">🔒 Change Password</div>

        <?php if ($successPassword): ?><div class="alert alert-success mb-3"><?= e($successPassword) ?></div><?php endif; ?>
        <?php if ($errorPassword):   ?><div class="alert alert-danger  mb-3"><?= e($errorPassword)   ?></div><?php endif; ?>

        <form method="POST" novalidate id="pw-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action"     value="change_password">
            <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="current_password" name="current_password"
                        required autocomplete="current-password" placeholder="Enter current password">
                    <button type="button" class="pw-toggle" id="toggle-cur" aria-label="Show">👁️</button>
                </div>
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="new_password" name="new_password"
                        required minlength="8" autocomplete="new-password" placeholder="Min. 8 characters">
                    <button type="button" class="pw-toggle" id="toggle-new" aria-label="Show">👁️</button>
                </div>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                        required minlength="8" autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="pw-toggle" id="toggle-conf" aria-label="Show">👁️</button>
                </div>
                <div class="text-danger small mt-1" id="pw-match-err" style="display:none;">Passwords do not match.</div>
            </div>
            <button type="submit" class="btn btn-warning text-dark fw-bold">🔑 Change Password</button>
        </form>
    </div>

    <!-- Forgot password link -->
    <div class="text-center text-muted small">
        Forgot your current password? <a href="<?= APP_BASE ?>/forgot-password.php" style="color:#4fc3f7;">Reset via email →</a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
<script>
(function(){
    function makeToggle(btnId, inputId) {
        var btn = document.getElementById(btnId), inp = document.getElementById(inputId);
        if (!btn || !inp) return;
        btn.addEventListener('click', function() {
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁️';
        });
    }
    makeToggle('toggle-cur',  'current_password');
    makeToggle('toggle-new',  'new_password');
    makeToggle('toggle-conf', 'confirm_password');

    var newPw = document.getElementById('new_password');
    var conf  = document.getElementById('confirm_password');
    var err   = document.getElementById('pw-match-err');
    if (conf && newPw) {
        conf.addEventListener('input', function() {
            var match = conf.value === newPw.value;
            conf.classList.toggle('is-invalid', !match && conf.value.length > 0);
            if (err) err.style.display = (!match && conf.value.length > 0) ? 'block' : 'none';
        });
    }

    var form = document.getElementById('pw-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (newPw && conf && newPw.value !== conf.value) {
                e.preventDefault();
                conf.classList.add('is-invalid');
                if (err) err.style.display = 'block';
                conf.focus();
            }
        });
    }
}());

function updateUsernameHint(input) {
    var count = document.getElementById('username-count');
    var hint  = document.getElementById('username-hint');
    if (count) count.textContent = input.value.length + '/50';
    if (!hint) return;
    var val = input.value;
    if (val.length < 3) {
        hint.textContent = 'Too short — minimum 3 characters.';
        hint.style.color = '#f87171';
    } else if (!/^[a-zA-Z0-9_]+$/.test(val)) {
        hint.textContent = 'Only letters, numbers, and underscores allowed.';
        hint.style.color = '#f87171';
    } else {
        hint.textContent = 'Looks good.';
        hint.style.color = '#4ade80';
    }
}
</script>
</body>
</html>
