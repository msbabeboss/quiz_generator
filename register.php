<?php
/**
 * register.php — Role chooser: Student or Teacher.
 * If ?as=student or ?as=teacher is set, shows the actual registration form.
 * Admin registration exists at /admin/register.php but is never linked publicly.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/auth.php';

// Redirect already-authenticated users
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    $dest = $role === 'teacher' ? '/teacher/dashboard.php' : '/student/dashboard.php';
    header('Location: ' . APP_BASE . $dest);
    exit;
}

$as = in_array($_GET['as'] ?? '', ['student', 'teacher']) ? $_GET['as'] : null;

// ── If a specific role is chosen, handle the form ────────────────────────
$errors   = [];
$username = '';
$email    = '';

if ($as && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('403 Forbidden: Invalid CSRF token.');
    }

    $username        = trim($_POST['username']       ?? '');
    $email           = trim($_POST['email']          ?? '');
    $password        = trim($_POST['password']       ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

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
        $result = registerUser($username, $email, $password, $as);
        if ($result !== false) {
            header('Location: ' . APP_BASE . '/login.php?registered=1&role=' . $as);
            exit;
        }
        $errors['general'] = 'Username or email is already taken. Please choose another.';
    }
}

$csrfToken = generateCsrfToken();
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// Colours per role
$isTeacher   = $as === 'teacher';
$accentColor = $isTeacher ? '#06b6d4' : '#4361ee';
$accentRgb   = $isTeacher ? '6,182,212' : '67,97,238';
$gradFrom    = $isTeacher ? '#06b6d4' : '#4361ee';
$gradTo      = $isTeacher ? '#0891b2' : '#3a0ca3';
$roleIcon    = $isTeacher ? '📚' : '🎓';
$roleLabel   = $isTeacher ? 'Teacher' : 'Student';
$rolePillBg  = $isTeacher ? 'rgba(6,182,212,0.12)'  : 'rgba(67,97,238,0.12)';
$rolePillBdr = $isTeacher ? 'rgba(6,182,212,0.3)'   : 'rgba(67,97,238,0.3)';
$rolePillClr = $isTeacher ? '#67e8f9' : '#a5b4fc';
$cardBdr     = $isTeacher ? 'rgba(6,182,212,0.25)'  : 'rgba(67,97,238,0.25)';
$bgGlow1     = $isTeacher ? 'rgba(6,182,212,0.14)'  : 'rgba(67,97,238,0.16)';
$bgGlow2     = $isTeacher ? 'rgba(8,145,178,0.10)'  : 'rgba(76,201,240,0.10)';
$subtitle    = $isTeacher
    ? 'Start creating quizzes and flashcard sets for your students'
    : 'Join students taking quizzes and flashcard games in real time';
$benefits    = $isTeacher
    ? ['✅ Free forever', '📝 Create quizzes', '🃏 Flashcard mode']
    : ['✅ Free forever', '⚡ Instant grading', '🏆 Live leaderboards'];
$btnLabel    = $isTeacher ? '📚 Create Teacher Account' : '🚀 Create Student Account';
$otherRole   = $isTeacher ? 'student' : 'teacher';
$otherLabel  = $isTeacher ? 'Sign up as Student' : 'Sign up as Teacher';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $as ? "$roleLabel Sign Up" : 'Sign Up' ?> — QuizGen</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/landing.css">
    <style>
        body {
            min-height: 100vh; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 2rem 1rem;
            background:
                radial-gradient(ellipse 70% 50% at 10% 30%, <?= $bgGlow1 ?> 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 70%, <?= $bgGlow2 ?> 0%, transparent 60%),
                #0d0d1a;
        }
        .auth-wrapper { width: 100%; max-width: <?= $as ? '460px' : '520px' ?>; }
        .back-home { display:inline-flex; align-items:center; gap:.4rem; color:#9090b0; font-size:.85rem; margin-bottom:1.5rem; text-decoration:none; transition:color .2s; }
        .back-home:hover { color:#e0e0f0; }
        .auth-card { background:#12122a; border:1px solid <?= $as ? $cardBdr : 'rgba(255,255,255,0.08)' ?>; border-radius:1rem; padding:2.5rem 2rem; box-shadow:0 24px 60px rgba(0,0,0,.5); }
        .auth-logo { display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:.75rem; }
        .brand-icon { width:40px; height:40px; background:linear-gradient(135deg,<?= $gradFrom ?>,<?= $gradTo ?>); border-radius:.6rem; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .auth-logo span { font-size:1.15rem; font-weight:800; color:#e0e0f0; }
        .role-pill { display:flex; align-items:center; justify-content:center; gap:.4rem; margin-bottom:1.25rem; background:<?= $rolePillBg ?>; border:1px solid <?= $rolePillBdr ?>; color:<?= $rolePillClr ?>; padding:.3rem 1rem; border-radius:999px; font-size:.78rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .auth-title { font-size:1.35rem; font-weight:800; color:#e0e0f0; text-align:center; margin-bottom:.35rem; }
        .auth-subtitle { font-size:.875rem; color:#9090b0; text-align:center; margin-bottom:1.5rem; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; margin-bottom:.4rem; }
        .form-control { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; border-radius:.5rem; padding:.65rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; }
        .form-control:focus { background:#0d0d1a; border-color:<?= $accentColor ?>; color:#e0e0f0; box-shadow:0 0 0 3px rgba(<?= $accentRgb ?>,.2); }
        .form-control::placeholder { color:#555570; }
        .form-control.is-invalid { border-color:#ef4444 !important; }
        .form-control.is-valid   { border-color:#22c55e !important; }
        .invalid-feedback { color:#fca5a5; font-size:.8rem; margin-top:.3rem; }
        .pw-wrap { position:relative; }
        .pw-wrap .form-control { padding-right:3rem; }
        .pw-toggle { position:absolute; right:.75rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#9090b0; cursor:pointer; font-size:1rem; padding:0; line-height:1; }
        .strength-wrap { margin-top:.5rem; }
        .strength-bar-bg { height:4px; background:rgba(255,255,255,.07); border-radius:999px; overflow:hidden; }
        .strength-bar { height:100%; width:0; border-radius:999px; transition:width .3s,background .3s; }
        .strength-text { font-size:.75rem; margin-top:.3rem; color:#9090b0; }
        .pw-reqs { list-style:none; padding:0; margin:.5rem 0 0; display:flex; flex-wrap:wrap; gap:.4rem; }
        .pw-reqs li { font-size:.75rem; color:#555570; display:flex; align-items:center; gap:.3rem; transition:color .2s; }
        .pw-reqs li.met { color:#86efac; }
        .pw-reqs li::before { content:'○'; font-size:.6rem; }
        .pw-reqs li.met::before { content:'✓'; }
        .btn-auth { width:100%; padding:.75rem; border:none; border-radius:.5rem; color:#fff; font-size:.95rem; font-weight:700; cursor:pointer; background:linear-gradient(135deg,<?= $gradFrom ?>,<?= $gradTo ?>); box-shadow:0 4px 20px rgba(<?= $accentRgb ?>,.4); transition:transform .2s,box-shadow .2s; margin-top:.5rem; }
        .btn-auth:hover { transform:translateY(-2px); box-shadow:0 6px 28px rgba(<?= $accentRgb ?>,.6); }
        .auth-footer { text-align:center; margin-top:1.5rem; font-size:.875rem; color:#9090b0; }
        .auth-footer a { color:#4fc3f7; text-decoration:none; font-weight:600; }
        .auth-footer a:hover { color:#81d4fa; }
        .divider { display:flex; align-items:center; gap:.75rem; margin:1.25rem 0; color:#555570; font-size:.8rem; }
        .divider::before,.divider::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.07); }
        .alert-danger { background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.3); color:#fca5a5; border-radius:.5rem; font-size:.875rem; }
        .benefits { display:flex; justify-content:center; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
        .benefit-item { display:flex; align-items:center; gap:.35rem; font-size:.78rem; color:#9090b0; }

        /* ── Role chooser cards (shown when no ?as= param) ── */
        .choose-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1.5rem; }
        .choose-card {
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:.75rem; padding:2rem 1rem; border-radius:1rem; text-decoration:none;
            border:1.5px solid rgba(255,255,255,.08); background:#12122a;
            transition:border-color .25s, transform .2s, box-shadow .25s;
            color:#e0e0f0;
        }
        .choose-card:hover { transform:translateY(-4px); color:#fff; }
        .choose-card.student:hover { border-color:rgba(67,97,238,.5); box-shadow:0 0 30px rgba(67,97,238,.2); }
        .choose-card.teacher:hover { border-color:rgba(6,182,212,.5); box-shadow:0 0 30px rgba(6,182,212,.2); }
        .choose-icon { font-size:2.5rem; }
        .choose-title { font-size:1.05rem; font-weight:800; }
        .choose-desc  { font-size:.8rem; color:#9090b0; text-align:center; line-height:1.4; }
        .choose-btn {
            margin-top:.25rem; padding:.45rem 1.25rem; border-radius:.5rem; font-size:.85rem; font-weight:700;
            border:none; cursor:pointer; color:#fff;
        }
        .choose-btn.student { background:linear-gradient(135deg,#4361ee,#3a0ca3); }
        .choose-btn.teacher { background:linear-gradient(135deg,#06b6d4,#0891b2); }
        @media(max-width:420px) { .choose-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <a href="index.html" class="back-home">&#8592; Back to home</a>

<?php if (!$as): ?>
    <!-- ── Role chooser ─────────────────────────────────────────────── -->
    <div class="auth-card" style="text-align:center;">
        <div class="auth-logo">
            <div class="brand-icon" style="background:linear-gradient(135deg,#4361ee,#f72585);" aria-hidden="true">🧠</div>
            <span>QuizGen</span>
        </div>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">Choose how you want to use QuizGen</p>

        <div class="choose-grid">
            <a href="register.php?as=student" class="choose-card student">
                <div class="choose-icon">🎓</div>
                <div class="choose-title">Student</div>
                <div class="choose-desc">Take quizzes, play flashcard games, and track your scores</div>
                <span class="choose-btn student">Sign Up as Student</span>
            </a>
            <a href="register.php?as=teacher" class="choose-card teacher">
                <div class="choose-icon">📚</div>
                <div class="choose-title">Teacher</div>
                <div class="choose-desc">Create quizzes and flashcard sets for your students</div>
                <span class="choose-btn teacher">Sign Up as Teacher</span>
            </a>
        </div>

        <div class="divider">already have an account?</div>
        <div class="auth-footer"><a href="login.php">Log in here</a></div>
    </div>

<?php else: ?>
    <!-- ── Registration form ────────────────────────────────────────── -->
    <div class="auth-card">
        <div class="auth-logo">
            <div class="brand-icon" aria-hidden="true"><?= $roleIcon ?></div>
            <span>QuizGen</span>
        </div>
        <div class="role-pill"><?= $roleIcon ?> <?= $roleLabel ?> Registration</div>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle"><?= $subtitle ?></p>

        <div class="benefits">
            <?php foreach ($benefits as $b): ?>
                <div class="benefit-item"><?= $b ?></div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger mb-3" role="alert"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" action="register.php?as=<?= e($as) ?>" novalidate id="reg-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                    id="username" name="username" value="<?= e($username) ?>"
                    required autocomplete="username" maxlength="50" autofocus
                    placeholder="e.g. <?= $isTeacher ? 'ms_johnson' : 'john_doe' ?>">
                <?php if (isset($errors['username'])): ?>
                    <div class="invalid-feedback"><?= e($errors['username']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                    id="email" name="email" value="<?= e($email) ?>"
                    required autocomplete="email"
                    placeholder="<?= $isTeacher ? 'you@school.edu' : 'you@example.com' ?>">
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
                <div class="strength-wrap">
                    <div class="strength-bar-bg"><div class="strength-bar" id="strength-bar"></div></div>
                    <div class="strength-text" id="strength-text"></div>
                </div>
                <ul class="pw-reqs" aria-label="Password requirements">
                    <li id="req-len">8+ characters</li>
                    <li id="req-upper">Uppercase letter</li>
                    <li id="req-num">Number</li>
                    <li id="req-special">Special character</li>
                </ul>
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

            <button type="submit" class="btn-auth"><?= $btnLabel ?></button>
        </form>

        <div class="divider">or</div>
        <div class="auth-footer">Already have an account? <a href="login.php?role=<?= e($as) ?>">Log in</a></div>
        <div class="auth-footer" style="margin-top:.6rem;">
            Want to sign up as <?= $otherLabel === 'Sign up as Teacher' ? 'a teacher' : 'a student' ?> instead?
            <a href="register.php?as=<?= e($otherRole) ?>"><?= $otherLabel ?></a>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.auth-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
<script>
(function () {
    'use strict';
    function makeToggle(btnId, inputId) {
        var btn = document.getElementById(btnId), input = document.getElementById(inputId);
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁️';
        });
    }
    makeToggle('toggle-pw1', 'password');
    makeToggle('toggle-pw2', 'confirm_password');

    var pw  = document.getElementById('password');
    var bar = document.getElementById('strength-bar');
    var txt = document.getElementById('strength-text');
    var levels = [
        {color:'#ef4444',text:'Too weak',  width:'20%'},
        {color:'#f97316',text:'Weak',      width:'40%'},
        {color:'#eab308',text:'Fair',      width:'65%'},
        {color:'#22c55e',text:'Strong',    width:'85%'},
        {color:'#4ade80',text:'Very strong 💪',width:'100%'}
    ];
    if (pw) {
        pw.addEventListener('input', function () {
            var v = pw.value, score = 0;
            if (v.length >= 8)          score++;
            if (/[A-Z]/.test(v))        score++;
            if (/[0-9]/.test(v))        score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            if (v.length >= 12)         score++;
            document.getElementById('req-len').classList.toggle('met',     v.length >= 8);
            document.getElementById('req-upper').classList.toggle('met',   /[A-Z]/.test(v));
            document.getElementById('req-num').classList.toggle('met',     /[0-9]/.test(v));
            document.getElementById('req-special').classList.toggle('met', /[^A-Za-z0-9]/.test(v));
            if (!v.length) { if(bar) bar.style.width='0'; if(txt) txt.textContent=''; return; }
            var l = levels[Math.min(score, levels.length) - 1] || levels[0];
            if(bar){ bar.style.width=l.width; bar.style.background=l.color; }
            if(txt){ txt.textContent=l.text; txt.style.color=l.color; }
        });
    }

    var conf = document.getElementById('confirm_password');
    var cerr = document.getElementById('confirm-error');
    if (conf && pw) {
        conf.addEventListener('input', function () {
            if (!conf.value.length) { conf.classList.remove('is-invalid','is-valid'); if(cerr) cerr.style.display='none'; return; }
            var match = conf.value === pw.value;
            conf.classList.toggle('is-invalid', !match);
            conf.classList.toggle('is-valid',    match);
            if (cerr) cerr.style.display = match ? 'none' : 'block';
        });
    }

    var form = document.getElementById('reg-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (pw && conf && pw.value !== conf.value) {
                e.preventDefault();
                conf.classList.add('is-invalid');
                if (cerr) cerr.style.display = 'block';
                conf.focus();
            }
        });
    }
}());
</script>
</body>
</html>
