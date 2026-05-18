<?php
/**
 * teacher/register.php — Teacher self-registration (open, no invite code needed).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_BASE . '/teacher/dashboard.php');
    exit;
}

$errors   = [];
$username = '';
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('403 Forbidden: Invalid CSRF token.');
    }

    $username        = trim($_POST['username']        ?? '');
    $email           = trim($_POST['email']           ?? '');
    $password        = $_POST['password']             ?? '';
    $confirmPassword = $_POST['confirm_password']     ?? '';

    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $errors['username'] = 'Username must be 3–50 characters: letters, numbers, or underscores only.';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $result = registerUser($username, $email, $password, 'teacher');
        if ($result !== false) {
            header('Location: ' . APP_BASE . '/login.php?registered=1&role=teacher');
            exit;
        }
        $errors['general'] = 'Username or email is already taken. Please choose another.';
    }
}

$csrfToken = generateCsrfToken();
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Sign Up — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/landing.css">
    <style>
        body { min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1rem;
            background: radial-gradient(ellipse 70% 50% at 20% 40%, rgba(6,182,212,0.14) 0%, transparent 60%),
                        radial-gradient(ellipse 60% 50% at 80% 60%, rgba(8,145,178,0.10) 0%, transparent 60%), #0d0d1a; }
        .auth-wrapper { width:100%; max-width:460px; }
        .back-home { display:inline-flex; align-items:center; gap:.4rem; color:#9090b0; font-size:.85rem; margin-bottom:1.5rem; text-decoration:none; transition:color .2s; }
        .back-home:hover { color:#e0e0f0; }
        .auth-card { background:#12122a; border:1px solid rgba(6,182,212,0.25); border-radius:1rem; padding:2.5rem 2rem; box-shadow:0 24px 60px rgba(0,0,0,.5); }
        .auth-logo { display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:.75rem; }
        .brand-icon { width:40px; height:40px; background:linear-gradient(135deg,#06b6d4,#0891b2); border-radius:.6rem; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .auth-logo span { font-size:1.15rem; font-weight:800; color:#e0e0f0; }
        .role-pill { display:flex; align-items:center; justify-content:center; gap:.4rem; margin-bottom:1.25rem; background:rgba(6,182,212,0.12); border:1px solid rgba(6,182,212,0.3); color:#67e8f9; padding:.3rem 1rem; border-radius:999px; font-size:.78rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .auth-title { font-size:1.35rem; font-weight:800; color:#e0e0f0; text-align:center; margin-bottom:.35rem; }
        .auth-subtitle { font-size:.875rem; color:#9090b0; text-align:center; margin-bottom:1.5rem; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:.4rem; }
        .form-control { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; border-radius:.5rem; padding:.65rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; }
        .form-control:focus { background:#0d0d1a; border-color:#06b6d4; color:#e0e0f0; box-shadow:0 0 0 3px rgba(6,182,212,.2); }
        .form-control::placeholder { color:#555570; }
        .form-control.is-invalid { border-color:#ef4444 !important; }
        .form-control.is-valid   { border-color:#22c55e !important; }
        .invalid-feedback { color:#fca5a5; font-size:.8rem; margin-top:.3rem; }
        .pw-wrap { position:relative; }
        .pw-wrap .form-control { padding-right:3rem; }
        .pw-toggle { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#9090b0; cursor:pointer; font-size:1rem; padding:0; line-height:1; }
        .btn-auth { width:100%; padding:.75rem; border:none; border-radius:.5rem; color:#fff; font-size:.95rem; font-weight:700; cursor:pointer; background:linear-gradient(135deg,#06b6d4,#0891b2); box-shadow:0 4px 20px rgba(6,182,212,.4); transition:transform .2s,box-shadow .2s; margin-top:.5rem; }
        .btn-auth:hover { transform:translateY(-2px); box-shadow:0 6px 28px rgba(6,182,212,.6); }
        .auth-footer { text-align:center; margin-top:1.5rem; font-size:.875rem; color:#9090b0; }
        .auth-footer a { color:#4fc3f7; text-decoration:none; font-weight:600; }
        .auth-footer a:hover { color:#81d4fa; }
        .divider { display:flex; align-items:center; gap:.75rem; margin:1.25rem 0; color:#555570; font-size:.8rem; }
        .divider::before,.divider::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.07); }
        .alert-danger { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#fca5a5; border-radius:.5rem; font-size:.875rem; }
        .benefits { display:flex; justify-content:center; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
        .benefit-item { display:flex; align-items:center; gap:.35rem; font-size:.78rem; color:#9090b0; }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <a href="<?= APP_BASE ?>/login.php?role=teacher" class="back-home">&#8592; Back to login</a>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="brand-icon" aria-hidden="true">📚</div>
            <span>QuizGen</span>
        </div>
        <div class="role-pill">📚 Teacher Registration</div>
        <h1 class="auth-title">Create Teacher Account</h1>
        <p class="auth-subtitle">Start creating quizzes and flashcard sets for your students</p>

        <div class="benefits">
            <div class="benefit-item">✅ Free forever</div>
            <div class="benefit-item">📝 Create quizzes</div>
            <div class="benefit-item">🃏 Flashcard mode</div>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger mb-3" role="alert"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_BASE ?>/teacher/register.php" novalidate id="reg-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                    id="username" name="username" value="<?= e($username) ?>"
                    required autocomplete="username" maxlength="50" autofocus placeholder="e.g. ms_johnson">
                <?php if (isset($errors['username'])): ?>
                    <div class="invalid-feedback"><?= e($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                    id="email" name="email" value="<?= e($email) ?>"
                    required autocomplete="email" placeholder="you@school.edu">
                <?php if (isset($errors['email'])): ?>
                    <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                        id="password" name="password" required autocomplete="new-password" minlength="8" placeholder="Min. 8 characters">
                    <button type="button" class="pw-toggle" id="toggle-pw1" aria-label="Show password">👁️</button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback d-block"><?= e($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                        id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="8" placeholder="Repeat your password">
                    <button type="button" class="pw-toggle" id="toggle-pw2" aria-label="Show password">👁️</button>
                </div>
                <div class="invalid-feedback" id="confirm-error" style="display:none;">Passwords do not match.</div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <div class="invalid-feedback d-block"><?= e($errors['confirm_password']) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-auth">📚 Create Teacher Account</button>
        </form>

        <div class="divider">or</div>
        <div class="auth-footer">Already have an account? <a href="<?= APP_BASE ?>/login.php?role=teacher">Log in</a></div>
        <div class="auth-footer" style="margin-top:.75rem;">Are you a student? <a href="<?= APP_BASE ?>/register.php">Sign up as Student</a></div>
    </div>
</div>
<script>
(function(){
    function makeToggle(btnId, inputId) {
        var btn = document.getElementById(btnId), input = document.getElementById(inputId);
        if (!btn || !input) return;
        btn.addEventListener('click', function() {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁️';
        });
    }
    makeToggle('toggle-pw1', 'password');
    makeToggle('toggle-pw2', 'confirm_password');

    var pw   = document.getElementById('password');
    var conf = document.getElementById('confirm_password');
    var err  = document.getElementById('confirm-error');
    if (conf && pw) {
        conf.addEventListener('input', function() {
            var match = conf.value === pw.value;
            conf.classList.toggle('is-invalid', !match && conf.value.length > 0);
            conf.classList.toggle('is-valid',    match && conf.value.length > 0);
            if (err) err.style.display = (!match && conf.value.length > 0) ? 'block' : 'none';
        });
    }

    var form = document.getElementById('reg-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (pw && conf && pw.value !== conf.value) {
                e.preventDefault();
                conf.classList.add('is-invalid');
                if (err) err.style.display = 'block';
                conf.focus();
            }
        });
    }
}());
</script>
</body>
</html>
