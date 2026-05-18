<?php
/**
 * forgot-password.php — Request a password reset code via email.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mailer.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_BASE . '/index.php'); exit;
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Forbidden');
    }

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ? AND status = ? LIMIT 1');
        $stmt->execute([$email, 'active']);
        $user = $stmt->fetch();

        // Always show success to prevent email enumeration
        if ($user) {
            // Generate 6-digit code
            $code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Invalidate old tokens for this email
            $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

            // Store new token
            $ins = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
            $ins->execute([$email, $code, $expiresAt]);

            // Send email
            sendPasswordResetEmail($email, $user['username'], $code);
        }

        $success = 'If that email exists, a reset code has been sent. Check your inbox.';
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
    <title>Forgot Password — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/landing.css">
    <style>
        body { min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1rem;
            background: radial-gradient(ellipse 70% 50% at 20% 40%, rgba(67,97,238,0.14) 0%, transparent 60%), #0d0d1a; }
        .auth-wrapper { width:100%; max-width:420px; }
        .back-home { display:inline-flex; align-items:center; gap:.4rem; color:#9090b0; font-size:.85rem; margin-bottom:1.5rem; text-decoration:none; transition:color .2s; }
        .back-home:hover { color:#e0e0f0; }
        .auth-card { background:#12122a; border:1px solid rgba(67,97,238,0.25); border-radius:1rem; padding:2.5rem 2rem; box-shadow:0 24px 60px rgba(0,0,0,.5); }
        .auth-logo { display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:1.25rem; }
        .brand-icon { width:40px; height:40px; background:linear-gradient(135deg,#4361ee,#f72585); border-radius:.6rem; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .auth-logo span { font-size:1.15rem; font-weight:800; color:#e0e0f0; }
        .auth-title { font-size:1.35rem; font-weight:800; color:#e0e0f0; text-align:center; margin-bottom:.35rem; }
        .auth-subtitle { font-size:.875rem; color:#9090b0; text-align:center; margin-bottom:1.5rem; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:.4rem; }
        .form-control { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; border-radius:.5rem; padding:.65rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; }
        .form-control:focus { background:#0d0d1a; border-color:#4361ee; color:#e0e0f0; box-shadow:0 0 0 3px rgba(67,97,238,.2); }
        .form-control::placeholder { color:#555570; }
        .btn-auth { width:100%; padding:.75rem; border:none; border-radius:.5rem; color:#fff; font-size:.95rem; font-weight:700; cursor:pointer; background:linear-gradient(135deg,#4361ee,#3a0ca3); box-shadow:0 4px 20px rgba(67,97,238,.4); transition:transform .2s,box-shadow .2s; margin-top:.5rem; }
        .btn-auth:hover { transform:translateY(-2px); box-shadow:0 6px 28px rgba(67,97,238,.6); }
        .auth-footer { text-align:center; margin-top:1.5rem; font-size:.875rem; color:#9090b0; }
        .auth-footer a { color:#4fc3f7; text-decoration:none; font-weight:600; }
        .alert-danger  { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#fca5a5; border-radius:.5rem; font-size:.875rem; }
        .alert-success { background:rgba(74,222,128,.12); border-color:rgba(74,222,128,.3); color:#86efac; border-radius:.5rem; font-size:.875rem; }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <a href="<?= APP_BASE ?>/login.php" class="back-home">&#8592; Back to login</a>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="brand-icon" aria-hidden="true">🔑</div>
            <span>QuizGen</span>
        </div>
        <h1 class="auth-title">Forgot Password</h1>
        <p class="auth-subtitle">Enter your email and we'll send you a reset code</p>

        <?php if ($error):   ?><div class="alert alert-danger  mb-3"><?= e($error)   ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success mb-3"><?= e($success) ?>
            <div class="mt-2"><a href="<?= APP_BASE ?>/reset-password.php" style="color:#4ade80;font-weight:700;">Enter reset code →</a></div>
        </div><?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="email" name="email" required autofocus
                    placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn-auth">📧 Send Reset Code</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">Remember your password? <a href="<?= APP_BASE ?>/login.php">Log in</a></div>
    </div>
</div>
</body>
</html>
