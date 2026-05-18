<?php
/**
 * reset-password.php — Enter reset code + new password.
 * On success: destroys session and redirects to login.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_BASE . '/index.php'); exit;
}

$success = $error = '';
$step    = 'code'; // 'code' or 'password'
$email   = $_SESSION['reset_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $action = $_POST['action'] ?? 'verify_code';

    // ── Step 1: verify the code ──────────────────────────────────────
    if ($action === 'verify_code') {
        $code      = trim($_POST['code'] ?? '');
        $emailPost = trim($_POST['email'] ?? '');

        $pdo  = getDB();
        $stmt = $pdo->prepare(
            'SELECT * FROM password_resets
             WHERE token = ? AND email = ? AND used = 0 AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$code, $emailPost]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = 'Invalid or expired code. Please check and try again.';
        } else {
            // Code valid — move to password step
            $_SESSION['reset_email'] = $emailPost;
            $_SESSION['reset_token'] = $code;
            $email = $emailPost;
            $step  = 'password';
        }
    }

    // ── Step 2: set new password ─────────────────────────────────────
    if ($action === 'set_password') {
        $password        = $_POST['password']         ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $storedEmail     = $_SESSION['reset_email']   ?? '';
        $storedToken     = $_SESSION['reset_token']   ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
            $step  = 'password';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $step  = 'password';
        } elseif (!$storedEmail || !$storedToken) {
            $error = 'Session expired. Please start over.';
            $step  = 'code';
        } else {
            $pdo = getDB();

            // Verify token still valid
            $stmt = $pdo->prepare(
                'SELECT id FROM password_resets
                 WHERE token = ? AND email = ? AND used = 0 AND expires_at > NOW() LIMIT 1'
            );
            $stmt->execute([$storedToken, $storedEmail]);
            if (!$stmt->fetch()) {
                $error = 'Reset code expired. Please request a new one.';
                $step  = 'code';
            } else {
                // Update password
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                    ->execute([$hash, $storedEmail]);

                // Mark token used
                $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')
                    ->execute([$storedToken]);

                // Clear session reset data
                unset($_SESSION['reset_email'], $_SESSION['reset_token']);

                // Destroy session and redirect to login
                session_destroy();
                header('Location: ' . APP_BASE . '/login.php?reset=1');
                exit;
            }
        }
    }
} else {
    // GET — if we have a stored email from step 1, show password form
    if ($email && isset($_SESSION['reset_token'])) {
        $step = 'password';
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
    <title>Reset Password — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/landing.css">
    <style>
        body { min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1rem;
            background: radial-gradient(ellipse 70% 50% at 20% 40%, rgba(247,37,133,0.14) 0%, transparent 60%), #0d0d1a; }
        .auth-wrapper { width:100%; max-width:420px; }
        .back-home { display:inline-flex; align-items:center; gap:.4rem; color:#9090b0; font-size:.85rem; margin-bottom:1.5rem; text-decoration:none; transition:color .2s; }
        .back-home:hover { color:#e0e0f0; }
        .auth-card { background:#12122a; border:1px solid rgba(247,37,133,0.25); border-radius:1rem; padding:2.5rem 2rem; box-shadow:0 24px 60px rgba(0,0,0,.5); }
        .auth-logo { display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:1.25rem; }
        .brand-icon { width:40px; height:40px; background:linear-gradient(135deg,#f72585,#b5179e); border-radius:.6rem; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .auth-logo span { font-size:1.15rem; font-weight:800; color:#e0e0f0; }
        .auth-title { font-size:1.35rem; font-weight:800; color:#e0e0f0; text-align:center; margin-bottom:.35rem; }
        .auth-subtitle { font-size:.875rem; color:#9090b0; text-align:center; margin-bottom:1.5rem; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:.4rem; }
        .form-control { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; border-radius:.5rem; padding:.65rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; }
        .form-control:focus { background:#0d0d1a; border-color:#f72585; color:#e0e0f0; box-shadow:0 0 0 3px rgba(247,37,133,.2); }
        .form-control::placeholder { color:#555570; }
        .form-control.is-invalid { border-color:#ef4444 !important; }
        .invalid-feedback { color:#fca5a5; font-size:.8rem; margin-top:.3rem; }
        .code-input { font-size:1.75rem; font-weight:900; letter-spacing:.4em; text-align:center; font-family:monospace; }
        .pw-wrap { position:relative; }
        .pw-wrap .form-control { padding-right:3rem; }
        .pw-toggle { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#9090b0; cursor:pointer; font-size:1rem; padding:0; line-height:1; }
        .btn-auth { width:100%; padding:.75rem; border:none; border-radius:.5rem; color:#fff; font-size:.95rem; font-weight:700; cursor:pointer; background:linear-gradient(135deg,#f72585,#b5179e); box-shadow:0 4px 20px rgba(247,37,133,.4); transition:transform .2s,box-shadow .2s; margin-top:.5rem; }
        .btn-auth:hover { transform:translateY(-2px); box-shadow:0 6px 28px rgba(247,37,133,.6); }
        .auth-footer { text-align:center; margin-top:1.5rem; font-size:.875rem; color:#9090b0; }
        .auth-footer a { color:#4fc3f7; text-decoration:none; font-weight:600; }
        .alert-danger { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#fca5a5; border-radius:.5rem; font-size:.875rem; }
        .step-indicator { display:flex; gap:.5rem; justify-content:center; margin-bottom:1.5rem; }
        .step-dot { width:8px; height:8px; border-radius:50%; background:rgba(255,255,255,.15); }
        .step-dot.active { background:#f72585; }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <a href="<?= APP_BASE ?>/forgot-password.php" class="back-home">&#8592; Back</a>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="brand-icon" aria-hidden="true">🔐</div>
            <span>QuizGen</span>
        </div>

        <!-- Step indicator -->
        <div class="step-indicator">
            <div class="step-dot <?= $step === 'code' ? 'active' : '' ?>"></div>
            <div class="step-dot <?= $step === 'password' ? 'active' : '' ?>"></div>
        </div>

        <?php if ($step === 'code'): ?>
        <h1 class="auth-title">Enter Reset Code</h1>
        <p class="auth-subtitle">Enter your email and the 6-digit code we sent you</p>

        <?php if ($error): ?><div class="alert alert-danger mb-3"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action"     value="verify_code">
            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="email" name="email" required autofocus
                    placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label for="code" class="form-label">Reset Code</label>
                <input type="text" class="form-control code-input" id="code" name="code"
                    required maxlength="6" placeholder="000000"
                    inputmode="numeric" autocomplete="one-time-code">
            </div>
            <button type="submit" class="btn-auth">✅ Verify Code</button>
        </form>

        <?php else: ?>
        <h1 class="auth-title">Set New Password</h1>
        <p class="auth-subtitle">Choose a strong new password for <strong><?= e($email) ?></strong></p>

        <?php if ($error): ?><div class="alert alert-danger mb-3"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" novalidate id="pw-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action"     value="set_password">
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="password" name="password"
                        required minlength="8" placeholder="Min. 8 characters" autofocus>
                    <button type="button" class="pw-toggle" id="toggle-pw1" aria-label="Show password">👁️</button>
                </div>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                        required minlength="8" placeholder="Repeat your password">
                    <button type="button" class="pw-toggle" id="toggle-pw2" aria-label="Show password">👁️</button>
                </div>
                <div class="invalid-feedback" id="confirm-error" style="display:none;">Passwords do not match.</div>
            </div>
            <button type="submit" class="btn-auth">🔐 Reset Password</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer"><a href="<?= APP_BASE ?>/login.php">Back to login</a></div>
    </div>
</div>
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
    makeToggle('toggle-pw1','password');
    makeToggle('toggle-pw2','confirm_password');

    var pw = document.getElementById('password'), conf = document.getElementById('confirm_password'), err = document.getElementById('confirm-error');
    if (conf && pw) {
        conf.addEventListener('input', function() {
            var match = conf.value === pw.value;
            conf.classList.toggle('is-invalid', !match && conf.value.length > 0);
            if (err) err.style.display = (!match && conf.value.length > 0) ? 'block' : 'none';
        });
    }

    // Auto-format code input: digits only
    var codeInput = document.getElementById('code');
    if (codeInput) {
        codeInput.addEventListener('input', function() {
            codeInput.value = codeInput.value.replace(/\D/g,'').slice(0,6);
        });
    }
}());
</script>
</body>
</html>
