<?php
/**
 * login.php — Unified login for both teachers and students.
 *
 * Security: CSRF token validation, rate limiting (5 attempts / 5 min per IP),
 * secure session cookie flags set via config/security.php.
 */

require_once __DIR__ . '/config/app.php'; // loads security.php → configureSecureSession()
sendSecurityHeaders();

require_once __DIR__ . '/config/auth.php';

// Redirect already-authenticated users
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    $dest = $role === 'teacher' ? '/teacher/dashboard.php' : '/student/dashboard.php';
    header('Location: ' . APP_BASE . $dest);
    exit;
}

$error   = '';
$success = '';

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($submittedToken)) {
        $error = 'Invalid or expired request. Please refresh and try again.';
    } else {
        // Rate limiting: max 5 login attempts per IP per 5 minutes
        $rlKey = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!checkRateLimit($rlKey, 5, 300)) {
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
            // Trim inputs — preserve exact case for case-sensitive matching.
            $username    = trim($_POST['username'] ?? '');
            $password    = trim($_POST['password'] ?? '');
            $selectedTab = in_array($_POST['role_tab'] ?? '', ['teacher', 'student'])
                           ? $_POST['role_tab']
                           : 'student';

            $user = authenticate($username, $password);

            if ($user !== false) {
                // Clear rate limit on successful login
                clearRateLimit($rlKey);

                // Enforce: the tab selected must match the account's actual role
                if ($user['role'] !== $selectedTab) {
                    session_destroy();
                    session_start(); // restart session for CSRF token on re-render
                    $error = 'Wrong portal. Please select the correct role tab for your account.';
                } else {
                    $dest = $user['role'] === 'teacher' ? '/teacher/dashboard.php' : '/student/dashboard.php';
                    header('Location: ' . APP_BASE . $dest);
                    exit;
                }
            } else {
                $error = 'Incorrect username or password, or your account has been suspended.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();

// Success message after registration
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['registered'] ?? '') === '1') {
    $roleLabel = ($_GET['role'] ?? '') === 'teacher' ? 'Teacher' : 'Student';
    $success   = $roleLabel . ' account created successfully! You can now log in.';
}

// Success message after password reset
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['reset'] ?? '') === '1') {
    $success = 'Password reset successfully! Log in with your new password.';
}

// Pre-fill role tab from query string (e.g. login.php?role=admin)
$defaultTab = in_array($_GET['role'] ?? '', ['teacher', 'student']) ? $_GET['role'] : 'student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Smart Quiz Generator</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/landing.css">
    <style>
        /* ── Page shell ─────────────────────────────────────────────── */
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: #0d0d1a;
            transition: background 0.5s ease;
        }

        body.mode-admin {
            background:
                radial-gradient(ellipse 70% 50% at 20% 40%, rgba(247,37,133,0.14) 0%, transparent 60%),
                radial-gradient(ellipse 50% 40% at 80% 60%, rgba(58,12,163,0.18) 0%, transparent 60%),
                #0d0d1a;
        }

        body.mode-student {
            background:
                radial-gradient(ellipse 70% 50% at 20% 40%, rgba(67,97,238,0.14) 0%, transparent 60%),
                radial-gradient(ellipse 50% 40% at 80% 60%, rgba(247,37,133,0.10) 0%, transparent 60%),
                #0d0d1a;
        }

        .auth-wrapper { width: 100%; max-width: 440px; }

        /* ── Back link ───────────────────────────────────────────────── */
        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: #9090b0;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            text-decoration: none;
            transition: color 0.2s;
        }
        .back-home:hover { color: #e0e0f0; }

        /* ── Card ────────────────────────────────────────────────────── */
        .auth-card {
            background: #12122a;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 24px 60px rgba(0,0,0,0.5);
            transition: border-color 0.4s;
        }
        .auth-card.mode-admin  { border-color: rgba(247,37,133,0.25); }
        .auth-card.mode-student{ border-color: rgba(67,97,238,0.25); }

        /* ── Logo ────────────────────────────────────────────────────── */
        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            margin-bottom: 1.5rem;
        }
        .brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #4361ee, #f72585);
            border-radius: 0.6rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
        }
        .auth-logo span { font-size: 1.15rem; font-weight: 800; color: #e0e0f0; }

        /* ── Role toggle tabs ────────────────────────────────────────── */
        .role-tabs {
            display: flex;
            background: rgba(255,255,255,0.05);
            border-radius: 0.6rem;
            padding: 4px;
            margin-bottom: 1.75rem;
            gap: 4px;
        }

        .role-tab {
            flex: 1;
            padding: 0.55rem 0.5rem;
            border: none;
            border-radius: 0.45rem;
            background: transparent;
            color: #9090b0;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s, color 0.25s, box-shadow 0.25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .role-tab.active-student {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: #fff;
            box-shadow: 0 2px 12px rgba(67,97,238,0.4);
        }

        .role-tab.active-admin {
            background: linear-gradient(135deg, #f72585, #b5179e);
            color: #fff;
            box-shadow: 0 2px 12px rgba(247,37,133,0.4);
        }

        /* ── Headings ────────────────────────────────────────────────── */
        .auth-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: #e0e0f0;
            text-align: center;
            margin-bottom: 0.35rem;
        }
        .auth-subtitle {
            font-size: 0.875rem;
            color: #9090b0;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        /* ── Form fields ─────────────────────────────────────────────── */
        .form-label {
            color: #9090b0;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 0.4rem;
        }
        .form-control {
            background: #0d0d1a;
            border: 1px solid rgba(255,255,255,0.1);
            color: #e0e0f0;
            border-radius: 0.5rem;
            padding: 0.65rem 0.9rem;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            background: #0d0d1a;
            color: #e0e0f0;
            box-shadow: 0 0 0 3px rgba(67,97,238,0.2);
        }
        .form-control::placeholder { color: #555570; }

        /* Focus colour changes with role */
        body.mode-admin   .form-control:focus { border-color: #f72585; box-shadow: 0 0 0 3px rgba(247,37,133,0.2); }
        body.mode-teacher .form-control:focus { border-color: #06b6d4; box-shadow: 0 0 0 3px rgba(6,182,212,0.2); }
        body.mode-student .form-control:focus { border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,0.2); }
        body.mode-admin  .form-control:focus { border-color: #f72585; box-shadow: 0 0 0 3px rgba(247,37,133,0.2); }
        body.mode-student .form-control:focus { border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,0.2); }

        /* ── Submit button ───────────────────────────────────────────── */
        .btn-auth {
            width: 100%;
            padding: 0.75rem;
            border: none;
            border-radius: 0.5rem;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, background 0.4s;
            margin-top: 0.5rem;
        }
        .btn-auth:hover  { transform: translateY(-2px); }
        .btn-auth:active { transform: translateY(0); }

        .btn-auth.student-btn {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            box-shadow: 0 4px 20px rgba(67,97,238,0.4);
        }
        .btn-auth.student-btn:hover { box-shadow: 0 6px 28px rgba(67,97,238,0.6); }

        .btn-auth.admin-btn {
            background: linear-gradient(135deg, #f72585, #b5179e);
            box-shadow: 0 4px 20px rgba(247,37,133,0.4);
        }
        .btn-auth.admin-btn:hover { box-shadow: 0 6px 28px rgba(247,37,133,0.6); }

        .btn-auth.teacher-btn {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            box-shadow: 0 4px 20px rgba(6,182,212,0.4);
        }
        .btn-auth.teacher-btn:hover { box-shadow: 0 6px 28px rgba(6,182,212,0.6); }

        /* ── Footer links ────────────────────────────────────────────── */
        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
            color: #9090b0;
        }
        .auth-footer a { color: #4fc3f7; text-decoration: none; font-weight: 600; }
        .auth-footer a:hover { color: #81d4fa; }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0;
            color: #555570;
            font-size: 0.8rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255,255,255,0.07);
        }

        /* ── Alerts ──────────────────────────────────────────────────── */
        .alert-danger  { background: rgba(239,68,68,0.12);  border-color: rgba(239,68,68,0.3);  color: #fca5a5; border-radius: 0.5rem; font-size: 0.875rem; }
        .alert-success { background: rgba(74,222,128,0.12); border-color: rgba(74,222,128,0.3); color: #86efac; border-radius: 0.5rem; font-size: 0.875rem; }

        /* ── Role badge on card ──────────────────────────────────────── */
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin: 0 auto 1rem;
            display: flex;
            justify-content: center;
        }
        .role-badge.student { background: rgba(67,97,238,0.15); color: #a5b4fc; border: 1px solid rgba(67,97,238,0.3); }
        .role-badge.admin   { background: rgba(247,37,133,0.15); color: #f9a8d4; border: 1px solid rgba(247,37,133,0.3); }
    </style>
</head>
<body class="mode-<?= htmlspecialchars($defaultTab, ENT_QUOTES, 'UTF-8') ?>">

<div class="auth-wrapper">

    <a href="index.html" class="back-home">&#8592; Back to home</a>

    <div class="auth-card mode-<?= htmlspecialchars($defaultTab, ENT_QUOTES, 'UTF-8') ?>" id="auth-card">

        <!-- Logo -->
        <div class="auth-logo">
            <div class="brand-icon" aria-hidden="true">🧠</div>
            <span>QuizGen</span>
        </div>

        <!-- Role toggle tabs -->
        <div class="role-tabs" role="tablist" aria-label="Select account type">
            <button
                class="role-tab <?= $defaultTab === 'student' ? 'active-student' : '' ?>"
                id="tab-student"
                role="tab"
                aria-selected="<?= $defaultTab === 'student' ? 'true' : 'false' ?>"
                type="button"
            >
                🎓 Student
            </button>
            <button
                class="role-tab <?= $defaultTab === 'teacher' ? 'active-teacher' : '' ?>"
                id="tab-teacher"
                role="tab"
                aria-selected="<?= $defaultTab === 'teacher' ? 'true' : 'false' ?>"
                type="button"
            >
                📚 Teacher
            </button>
        </div>

        <!-- Dynamic heading -->
        <div class="role-badge student" id="role-badge">
            <span id="role-badge-icon">🎓</span>
            <span id="role-badge-text">Student Login</span>
        </div>

        <h1 class="auth-title" id="auth-title">Welcome back</h1>
        <p class="auth-subtitle" id="auth-subtitle">Log in to your student account</p>

        <!-- Alerts -->
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger mb-3" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success mb-3" role="alert">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Login form (same for both roles — backend routes by DB role) -->
        <form method="POST" action="login.php" novalidate id="login-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="role_tab"   value="student" id="role-tab-input">

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input
                    type="text"
                    class="form-control"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                    autocomplete="username"
                    autofocus
                    placeholder="Enter your username"
                >
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div style="position:relative;">
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        style="padding-right:3rem;"
                    >
                    <!-- Show/hide password toggle -->
                    <button
                        type="button"
                        id="toggle-pw"
                        aria-label="Show password"
                        style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#9090b0;cursor:pointer;font-size:1rem;padding:0;line-height:1;"
                    >👁️</button>
                </div>
            </div>

            <button type="submit" class="btn-auth student-btn" id="submit-btn">
                Log In as Student
            </button>
            <div style="text-align:center; margin-top:1rem;">
                <a href="<?= APP_BASE ?>/forgot-password.php" style="color:#9090b0; font-size:.85rem; text-decoration:none;">Forgot password?</a>
            </div>
        </form>

        <div class="divider">or</div>

        <!-- Footer links -->
        <div class="auth-footer" id="footer-student">
            Don't have a student account?
            <a href="register.php">Sign up free</a>
        </div>

        <div class="auth-footer" id="footer-teacher" style="display:none;">
            Need a teacher account?
            <a href="register.php?as=teacher">Register as Teacher</a>
        </div>

    </div><!-- /.auth-card -->
</div><!-- /.auth-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
        crossorigin="anonymous"></script>
<script>
(function () {
    'use strict';

    var body        = document.body;
    var card        = document.getElementById('auth-card');
    var tabStudent  = document.getElementById('tab-student');
    var tabTeacher  = document.getElementById('tab-teacher');
    var badge       = document.getElementById('role-badge');
    var badgeIcon   = document.getElementById('role-badge-icon');
    var badgeText   = document.getElementById('role-badge-text');
    var title       = document.getElementById('auth-title');
    var subtitle    = document.getElementById('auth-subtitle');
    var submitBtn   = document.getElementById('submit-btn');
    var footerStu   = document.getElementById('footer-student');
    var footerTea   = document.getElementById('footer-teacher');
    var pwInput     = document.getElementById('password');
    var togglePw    = document.getElementById('toggle-pw');
    var roleTabInput = document.getElementById('role-tab-input');

    togglePw.addEventListener('click', function () {
        var isText = pwInput.type === 'text';
        pwInput.type = isText ? 'password' : 'text';
        togglePw.textContent = isText ? '👁️' : '🙈';
        togglePw.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    });

    function setRole(role) {
        var isTeacher = role === 'teacher';

        body.className = 'mode-' + role;
        card.className = 'auth-card mode-' + role;

        tabStudent.className = 'role-tab' + (!isTeacher ? ' active-student' : '');
        tabTeacher.className = 'role-tab' + (isTeacher  ? ' active-teacher' : '');
        tabStudent.setAttribute('aria-selected', String(!isTeacher));
        tabTeacher.setAttribute('aria-selected', String(isTeacher));

        badge.className = 'role-badge ' + role;
        badgeIcon.textContent = isTeacher ? '📚' : '🎓';
        badgeText.textContent = isTeacher ? 'Teacher Login' : 'Student Login';

        title.textContent    = isTeacher ? 'Teacher Portal' : 'Welcome back';
        subtitle.textContent = isTeacher
            ? 'Log in to create quizzes and track student progress'
            : 'Log in to your student account';

        submitBtn.textContent = isTeacher ? 'Log In as Teacher' : 'Log In as Student';
        submitBtn.className   = 'btn-auth ' + (isTeacher ? 'teacher-btn' : 'student-btn');

        footerStu.style.display = isTeacher ? 'none' : '';
        footerTea.style.display = isTeacher ? ''     : 'none';

        // Keep hidden input in sync so server knows which tab was selected
        if (roleTabInput) roleTabInput.value = role;

        var url = new URL(window.location.href);
        url.searchParams.set('role', role);
        window.history.replaceState({}, '', url.toString());
    }

    tabStudent.addEventListener('click', function () { setRole('student'); });
    tabTeacher.addEventListener('click', function () { setRole('teacher'); });

    setRole('<?= htmlspecialchars($defaultTab, ENT_QUOTES, 'UTF-8') ?>');
}());
</script>
</body>
</html>
