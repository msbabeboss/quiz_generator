<?php
/**
 * includes/teacher-nav.php — Shared navigation bar for all teacher pages.
 *
 * Usage: set $activePage before including, then require this file.
 *
 * $activePage values:
 *   'dashboard'     → Dashboard
 *   'quiz-create'   → Quizzes dropdown → Create Quiz
 *   'quiz-ai'       → Quizzes dropdown → AI Generate
 *   'quiz-list'     → Quizzes dropdown → My Quizzes
 *   'access-codes'  → Access Codes
 *   'classrooms'    → Classrooms
 *   'results'       → Results
 *   'students'      → Students
 *   'live'          → Live Submissions
 */

if (!defined('APP_BASE')) {
    require_once __DIR__ . '/../config/app.php';
}

$activePage = $activePage ?? '';
$quizDropdownActive = in_array($activePage, ['quiz-create', 'quiz-ai', 'quiz-list'], true);

if (!function_exists('_nav_link')) {
    function _nav_link(string $href, string $label, bool $active): string {
        $cls = 'nav-link' . ($active ? ' active' : '');
        return '<a class="' . $cls . '" href="' . $href . '">' . $label . '</a>';
    }
}
?>
<style>
/* ── Teacher nav dropdown — works without JS ─────────────────────── */
.tnav-dropdown { position: relative; }

.tnav-dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 9999;
    min-width: 210px;
    background: #12122a;
    border: 1px solid rgba(6,182,212,0.3);
    border-radius: .45rem;
    padding: .4rem 0;
    box-shadow: 0 8px 24px rgba(0,0,0,.5);
    list-style: none;
    margin: 0;
}

/* Show on hover (desktop) */
.tnav-dropdown:hover > .tnav-dropdown-menu {
    display: block;
}

/* Show when toggle checkbox is checked (mobile / click) */
.tnav-toggle { display: none; }
.tnav-toggle:checked ~ .tnav-dropdown-menu {
    display: block;
}

.tnav-dropdown-menu li a {
    display: block;
    padding: .5rem 1.1rem;
    color: #c0c0d8;
    text-decoration: none;
    font-size: .9rem;
    white-space: nowrap;
    transition: background .15s, color .15s;
}
.tnav-dropdown-menu li a:hover,
.tnav-dropdown-menu li a:focus {
    background: rgba(6,182,212,0.12);
    color: #fff;
}
.tnav-dropdown-menu li a.active {
    color: #06b6d4;
    font-weight: 600;
}
.tnav-dropdown-menu .tnav-divider {
    border-top: 1px solid rgba(255,255,255,0.1);
    margin: .3rem 0;
}

/* The toggle label acts as the dropdown trigger */
.tnav-trigger {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .3rem;
    padding: .5rem .75rem;
    color: rgba(255,255,255,.75);
    font-size: .9rem;
    border-radius: .35rem;
    transition: color .15s;
    user-select: none;
    white-space: nowrap;
}
.tnav-trigger:hover { color: #fff; }
.tnav-trigger.active { color: #fff; font-weight: 600; }
.tnav-caret {
    font-size: .65rem;
    opacity: .7;
    transition: transform .2s;
}
.tnav-toggle:checked ~ .tnav-trigger .tnav-caret,
.tnav-dropdown:hover .tnav-caret { transform: rotate(180deg); }
</style>

<nav class="navbar navbar-expand-lg navbar-dark"
     style="background:#0d0d1a; border-bottom:1px solid rgba(6,182,212,0.2);">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold"
           href="<?= APP_BASE ?>/teacher/dashboard.php">📚 QuizGen Teacher</a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navTeacherMain"
                aria-controls="navTeacherMain" aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navTeacherMain">
            <ul class="navbar-nav me-auto align-items-lg-center">

                <!-- Dashboard -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/dashboard.php', 'Dashboard', $activePage === 'dashboard') ?>
                </li>

                <!-- Quizzes — CSS-only dropdown, no Bootstrap JS needed -->
                <li class="nav-item tnav-dropdown">
                    <input type="checkbox" id="tnav-quiz-toggle" class="tnav-toggle">
                    <label for="tnav-quiz-toggle"
                           class="tnav-trigger<?= $quizDropdownActive ? ' active' : '' ?>">
                        📋 Quizzes <span class="tnav-caret">▼</span>
                    </label>
                    <ul class="tnav-dropdown-menu">
                        <li>
                            <a href="<?= APP_BASE ?>/teacher/quizzes.php?view=create"
                               class="<?= $activePage === 'quiz-create' ? 'active' : '' ?>">
                                ✏️ Create Quiz
                            </a>
                        </li>
                        <li>
                            <a href="<?= APP_BASE ?>/teacher/generate-quiz.php"
                               class="<?= $activePage === 'quiz-ai' ? 'active' : '' ?>">
                                🤖 AI Generate
                            </a>
                        </li>
                        <li class="tnav-divider"></li>
                        <li>
                            <a href="<?= APP_BASE ?>/teacher/quizzes.php"
                               class="<?= $activePage === 'quiz-list' ? 'active' : '' ?>">
                                📂 My Quizzes
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Access Codes -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/access-codes.php', '🔑 Access Codes', $activePage === 'access-codes') ?>
                </li>

                <!-- Classrooms -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/classrooms.php', '🏫 Classrooms', $activePage === 'classrooms') ?>
                </li>

                <!-- Results -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/results.php', 'Results', $activePage === 'results') ?>
                </li>

                <!-- Students -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/users.php', '👥 Students', $activePage === 'students') ?>
                </li>

                <!-- Live -->
                <li class="nav-item">
                    <?= _nav_link(APP_BASE . '/teacher/live.php', '⚡ Live', $activePage === 'live') ?>
                </li>

            </ul>

            <!-- Right: username · My Profile · Logout -->
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item me-2">
                    <span class="text-muted small">👤 <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <li class="nav-item me-2">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= APP_BASE ?>/profile.php">My Profile</a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-sm btn-outline-danger"
                       href="<?= APP_BASE ?>/logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
