<?php
/**
 * includes/header.php — Shared page header include.
 *
 * Requirements: 8.1, 8.3, 9.1
 *
 * Expected variables from the including page (set before require/include):
 *   string $pageTitle        — Used in <title>. Required.
 *   bool   $includeRealtime  — Whether to include Pusher JS + realtime.js. Default: false.
 *   string $pusherKey        — PUSHER_KEY value for realtime pages. Default: ''.
 *   string $pusherCluster    — PUSHER_CLUSTER value for realtime pages. Default: ''.
 *
 * Usage:
 *   $pageTitle       = 'Admin Dashboard';
 *   $includeRealtime = true;
 *   $pusherKey       = $_ENV['PUSHER_KEY'] ?? '';
 *   $pusherCluster   = $_ENV['PUSHER_CLUSTER'] ?? '';
 *   require __DIR__ . '/../includes/header.php';
 */

// Ensure APP_BASE is available.
if (!defined('APP_BASE')) {
    require_once __DIR__ . '/../config/app.php';
}

// Resolve variables with safe defaults.
$pageTitle       = isset($pageTitle)       ? (string) $pageTitle       : 'Quiz App';
$includeRealtime = isset($includeRealtime) ? (bool)   $includeRealtime : false;
$pusherKey       = isset($pusherKey)       ? (string) $pusherKey       : '';
$pusherCluster   = isset($pusherCluster)   ? (string) $pusherCluster   : '';

// Convenience escape helper (define only if not already defined by the including page).
if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

$sessionRole     = $_SESSION['role']     ?? '';
$sessionUsername = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- CSRF meta tag — available to JavaScript for AJAX requests -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <title><?= e($pageTitle) ?></title>

    <!-- Bootstrap 5 CSS -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >

    <!-- Application stylesheet (dark theme + component overrides) -->
    <link rel="stylesheet" href="<?= e(APP_BASE) ?>/assets/css/style.css">

    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
</head>
<body>

<!-- =========================================================
     Navigation bar — links vary by role
     ========================================================= -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">

        <?php if ($sessionRole === 'admin'): ?>
            <a class="navbar-brand" href="<?= e(APP_BASE) ?>/admin/dashboard.php">Quiz Admin</a>
        <?php else: ?>
            <a class="navbar-brand" href="<?= e(APP_BASE) ?>/student/dashboard.php">Quiz App</a>
        <?php endif; ?>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarNav"
            aria-controls="navbarNav"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">

            <!-- Role-specific navigation links -->
            <ul class="navbar-nav me-auto">
                <?php if ($sessionRole === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(APP_BASE) ?>/admin/dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(APP_BASE) ?>/admin/quizzes.php">Manage Quizzes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(APP_BASE) ?>/admin/results.php">View Results</a>
                    </li>
                <?php elseif ($sessionRole === 'student'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(APP_BASE) ?>/student/dashboard.php">Dashboard</a>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Username, dark mode toggle, and logout (shown for any authenticated user) -->
            <?php if ($sessionUsername !== ''): ?>
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <span class="nav-link text-light">
                        <?= e($sessionUsername) ?>
                    </span>
                </li>
                <li class="nav-item">
                    <button
                        id="dark-mode-toggle"
                        class="btn btn-sm btn-outline-light ms-2"
                        type="button"
                        aria-label="Switch to dark mode"
                        title="Switch to dark mode"
                    >🌙</button>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= e(APP_BASE) ?>/logout.php">Logout</a>
                </li>
            </ul>
            <?php endif; ?>

        </div><!-- /.navbar-collapse -->
    </div><!-- /.container-fluid -->
</nav>

<?php if ($includeRealtime): ?>
<!-- Pusher JS SDK -->
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>

<!-- Inject Pusher credentials as JS globals for realtime.js -->
<script>
    var PUSHER_KEY     = <?= json_encode($pusherKey) ?>;
    var PUSHER_CLUSTER = <?= json_encode($pusherCluster) ?>;
</script>

<!-- Real-time event handlers -->
<script src="<?= e(APP_BASE) ?>/assets/js/realtime.js"></script>
<?php endif; ?>
