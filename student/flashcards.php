<?php
/**
 * student/flashcards.php — Flashcard game mode.
 *
 * Cards flip one at a time. Student picks an answer, gets instant ✓/✗ feedback,
 * then moves to the next card. At the end, shows score + live leaderboard of
 * who answered the most correctly on this quiz.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/QuestionEngine.php';

requireRole('student');

if (empty($_GET['quiz_id'])) {
    header('Location: ' . APP_BASE . '/student/dashboard.php'); exit;
}

$quizId    = (int) $_GET['quiz_id'];
$quiz      = getQuiz($quizId);
$studentId = (int) $_SESSION['user_id'];

if ($quiz === null || !(int)$quiz['is_active']) {
    header('Location: ' . APP_BASE . '/student/dashboard.php'); exit;
}

// Enrollment check — flashcards also require prior code-based or classroom enrollment.
try {
    $pdo = getDB();
    // Path 1: direct exam code
    $enrollCheck = $pdo->prepare(
        'SELECT qe.id FROM quiz_enrollments qe WHERE qe.student_id = ? AND qe.quiz_id = ? LIMIT 1'
    );
    $enrollCheck->execute([$studentId, $quizId]);
    $hasAccess = (bool) $enrollCheck->fetch();

    // Path 2: classroom enrollment
    if (!$hasAccess) {
        $classCheck = $pdo->prepare(
            'SELECT ce.id FROM classroom_enrollments ce
             JOIN classrooms c         ON ce.classroom_id = c.id AND c.is_active = 1
             JOIN classroom_quizzes cq ON cq.classroom_id = c.id AND cq.quiz_id = ?
             WHERE ce.student_id = ? LIMIT 1'
        );
        $classCheck->execute([$quizId, $studentId]);
        $hasAccess = (bool) $classCheck->fetch();
    }

    if (!$hasAccess) {
        header('Location: ' . APP_BASE . '/student/join.php?error=not_enrolled'); exit;
    }
} catch (PDOException $e) {
    error_log('flashcards.php PDOException (enrollment check): ' . $e->getMessage());
    header('Location: ' . APP_BASE . '/student/dashboard.php'); exit;
}

$questions = getQuestions($quizId, (bool)$quiz['is_randomized']);

if (empty($questions)) {
    header('Location: ' . APP_BASE . '/student/dashboard.php'); exit;
}

$csrfToken = generateCsrfToken();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// Build a JSON-safe questions array for JS
$jsQuestions = [];
foreach ($questions as $q) {
    $opts = [];
    foreach ($q['options'] as $o) {
        $opts[] = ['label' => $o['option_label'], 'text' => $o['option_text']];
    }
    $jsQuestions[] = [
        'id'             => (int)$q['id'],
        'text'           => $q['question_text'],
        'type'           => $q['question_type'],
        'correct_answer' => $q['correct_answer'],
        'points'         => (int)$q['points'],
        'options'        => $opts,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($quiz['title']) ?> — Flashcards</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        :root {
            --bg: #0d0d1a; --card-bg: #12122a; --border: rgba(255,255,255,0.08);
            --primary: #4361ee; --accent: #f72585; --success: #4ade80; --danger: #ef4444;
            --text: #e0e0f0; --muted: #9090b0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; }

        /* Navbar */
        .fc-nav { background: #0d0d1a; border-bottom: 1px solid var(--border); padding: .75rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
        .fc-nav .brand { font-weight: 800; font-size: 1.1rem; display: flex; align-items: center; gap: .5rem; }
        .fc-nav .brand-icon { width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary), var(--accent)); border-radius: .4rem; display: flex; align-items: center; justify-content: center; font-size: .9rem; }

        /* Layout */
        .fc-wrap { max-width: 680px; margin: 0 auto; padding: 2rem 1rem 4rem; }

        /* Progress bar */
        .fc-progress-wrap { margin-bottom: 1.5rem; }
        .fc-progress-label { display: flex; justify-content: space-between; font-size: .8rem; color: var(--muted); margin-bottom: .4rem; }
        .fc-progress-bar-bg { height: 6px; background: rgba(255,255,255,.07); border-radius: 999px; overflow: hidden; }
        .fc-progress-bar { height: 100%; background: linear-gradient(90deg, var(--primary), var(--accent)); border-radius: 999px; transition: width .4s ease; }

        /* Score strip */
        .fc-score-strip { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .fc-score-item { background: var(--card-bg); border: 1px solid var(--border); border-radius: .6rem; padding: .5rem 1rem; font-size: .85rem; display: flex; align-items: center; gap: .4rem; }
        .fc-score-item .val { font-weight: 800; font-size: 1.1rem; }
        .fc-score-item.correct .val { color: var(--success); }
        .fc-score-item.wrong   .val { color: var(--danger); }
        .fc-score-item.pts     .val { color: #fbbf24; }

        /* Flashcard */
        .fc-card-wrap { perspective: 1000px; margin-bottom: 1.5rem; }
        .fc-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            padding: 2.5rem 2rem;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            box-shadow: 0 8px 40px rgba(0,0,0,.4);
            transition: border-color .3s, box-shadow .3s;
            position: relative;
        }
        .fc-card.correct-card { border-color: var(--success); box-shadow: 0 0 0 2px rgba(74,222,128,.25), 0 8px 40px rgba(0,0,0,.4); }
        .fc-card.wrong-card   { border-color: var(--danger);  box-shadow: 0 0 0 2px rgba(239,68,68,.25),  0 8px 40px rgba(0,0,0,.4); }

        .fc-card-num { font-size: .75rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 1rem; }
        .fc-card-pts { position: absolute; top: 1rem; right: 1.25rem; font-size: .75rem; color: var(--muted); background: rgba(255,255,255,.05); padding: .2rem .6rem; border-radius: 999px; }
        .fc-card-q   { font-size: 1.2rem; font-weight: 700; line-height: 1.4; color: var(--text); }

        /* Feedback overlay */
        .fc-feedback {
            position: absolute; inset: 0; border-radius: 1.25rem;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            font-size: 1.1rem; font-weight: 700; gap: .5rem;
            opacity: 0; pointer-events: none; transition: opacity .25s;
        }
        .fc-feedback.show { opacity: 1; }
        .fc-feedback.correct-fb { background: rgba(74,222,128,.12); color: var(--success); }
        .fc-feedback.wrong-fb   { background: rgba(239,68,68,.12);  color: var(--danger); }
        .fc-feedback .fb-icon { font-size: 2.5rem; }
        .fc-feedback .fb-answer { font-size: .9rem; color: var(--muted); }

        /* Options */
        .fc-options { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1.5rem; }
        .fc-option {
            background: var(--card-bg); border: 1.5px solid var(--border);
            border-radius: .75rem; padding: .85rem 1rem;
            font-size: .95rem; font-weight: 600; color: var(--text);
            cursor: pointer; text-align: left; display: flex; align-items: center; gap: .6rem;
            transition: border-color .2s, background .2s, transform .15s;
        }
        .fc-option:hover:not(:disabled) { border-color: rgba(67,97,238,.5); background: rgba(67,97,238,.08); transform: translateY(-1px); }
        .fc-option:disabled { cursor: default; }
        .fc-option.opt-correct { border-color: var(--success); background: rgba(74,222,128,.1); color: var(--success); }
        .fc-option.opt-wrong   { border-color: var(--danger);  background: rgba(239,68,68,.1);  color: var(--danger); }
        .fc-option .opt-label { width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,.07); display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; flex-shrink: 0; }

        /* True/False options */
        .fc-options.tf { grid-template-columns: 1fr 1fr; }

        /* Next button */
        .fc-next-btn {
            width: 100%; padding: .85rem; border: none; border-radius: .75rem;
            background: linear-gradient(135deg, var(--primary), #3a0ca3);
            color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer;
            box-shadow: 0 4px 20px rgba(67,97,238,.4);
            transition: transform .2s, box-shadow .2s, opacity .2s;
            display: none;
        }
        .fc-next-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 28px rgba(67,97,238,.6); }
        .fc-next-btn.visible { display: block; }

        /* Results screen */
        #results-screen { display: none; }
        .results-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 1.25rem; padding: 2.5rem 2rem; text-align: center; margin-bottom: 2rem; }
        .results-score { font-size: 3.5rem; font-weight: 900; background: linear-gradient(135deg, var(--primary), var(--accent)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; margin-bottom: .5rem; }
        .results-label { font-size: 1rem; color: var(--muted); margin-bottom: 1.5rem; }
        .results-stats { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .results-stat .val { font-size: 1.5rem; font-weight: 800; }
        .results-stat .lbl { font-size: .8rem; color: var(--muted); }
        .results-stat.c .val { color: var(--success); }
        .results-stat.w .val { color: var(--danger); }

        /* Leaderboard */
        .lb-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 1.25rem; padding: 1.5rem; }
        .lb-title { font-size: 1rem; font-weight: 800; margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem; }
        .lb-row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid var(--border); }
        .lb-row:last-child { border-bottom: none; }
        .lb-rank { width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,.07); display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; flex-shrink: 0; }
        .lb-rank.gold   { background: rgba(251,191,36,.2); color: #fbbf24; }
        .lb-rank.silver { background: rgba(148,163,184,.2); color: #94a3b8; }
        .lb-rank.bronze { background: rgba(180,83,9,.2);   color: #b45309; }
        .lb-name { flex: 1; font-weight: 600; font-size: .9rem; }
        .lb-score { font-size: .85rem; color: var(--muted); }
        .lb-pct { font-weight: 800; font-size: .9rem; color: var(--success); }
        .lb-you { font-size: .7rem; background: rgba(67,97,238,.2); color: #a5b4fc; padding: .1rem .4rem; border-radius: 999px; margin-left: .3rem; }

        @media (max-width: 480px) {
            .fc-options { grid-template-columns: 1fr; }
            .fc-card { padding: 1.75rem 1.25rem; }
        }
    </style>
</head>
<body>

<nav class="fc-nav">
    <div class="brand">
        <div class="brand-icon" aria-hidden="true">🧠</div>
        <span>Flashcards</span>
    </div>
    <a href="<?= APP_BASE ?>/student/dashboard.php" style="color:var(--muted); font-size:.85rem; text-decoration:none;">✕ Exit</a>
</nav>

<div class="fc-wrap">

    <!-- Quiz title -->
    <h2 style="font-size:1.3rem; font-weight:800; margin-bottom:1.5rem;"><?= e($quiz['title']) ?></h2>

    <!-- Game screen -->
    <div id="game-screen">

        <!-- Progress -->
        <div class="fc-progress-wrap">
            <div class="fc-progress-label">
                <span id="prog-label">Card 1 of <?= count($questions) ?></span>
                <span id="prog-pct">0%</span>
            </div>
            <div class="fc-progress-bar-bg">
                <div class="fc-progress-bar" id="prog-bar" style="width:0%"></div>
            </div>
        </div>

        <!-- Score strip -->
        <div class="fc-score-strip">
            <div class="fc-score-item correct">✅ <span class="val" id="score-correct">0</span> Correct</div>
            <div class="fc-score-item wrong">❌ <span class="val" id="score-wrong">0</span> Wrong</div>
            <div class="fc-score-item pts">⭐ <span class="val" id="score-pts">0</span> pts</div>
        </div>

        <!-- Flashcard -->
        <div class="fc-card-wrap">
            <div class="fc-card" id="fc-card">
                <div class="fc-card-pts" id="card-pts"></div>
                <div class="fc-card-num" id="card-num"></div>
                <div class="fc-card-q"  id="card-q"></div>
                <!-- Feedback overlay -->
                <div class="fc-feedback" id="fc-feedback">
                    <div class="fb-icon" id="fb-icon"></div>
                    <div id="fb-msg"></div>
                    <div class="fb-answer" id="fb-answer"></div>
                </div>
            </div>
        </div>

        <!-- Options -->
        <div class="fc-options" id="fc-options"></div>

        <!-- Next button -->
        <button class="fc-next-btn" id="fc-next-btn">Next Card →</button>

    </div><!-- /#game-screen -->

    <!-- Results screen -->
    <div id="results-screen">
        <div class="results-card">
            <div style="font-size:2rem; margin-bottom:.5rem;">🎉</div>
            <div class="results-score" id="res-pct">0%</div>
            <div class="results-label" id="res-label">You scored 0 / 0 points</div>
            <div class="results-stats">
                <div class="results-stat c"><div class="val" id="res-correct">0</div><div class="lbl">Correct</div></div>
                <div class="results-stat w"><div class="val" id="res-wrong">0</div><div class="lbl">Wrong</div></div>
                <div class="results-stat"><div class="val" id="res-total">0</div><div class="lbl">Total Cards</div></div>
            </div>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <button class="btn btn-primary" id="btn-retry">🔄 Try Again</button>
                <a href="<?= APP_BASE ?>/student/dashboard.php" class="btn btn-outline-secondary">← Dashboard</a>
            </div>
        </div>

        <!-- Leaderboard -->
        <div class="lb-card">
            <div class="lb-title">🏆 Flashcard Leaderboard — <?= e($quiz['title']) ?></div>
            <div id="lb-body">
                <div class="text-muted small text-center py-3">Loading leaderboard…</div>
            </div>
        </div>
    </div><!-- /#results-screen -->

</div><!-- /.fc-wrap -->

<script>
(function () {
    'use strict';

    var APP_BASE   = <?= json_encode(APP_BASE) ?>;
    var QUIZ_ID    = <?= (int)$quizId ?>;
    var STUDENT_ID = <?= $studentId ?>;
    var USERNAME   = <?= json_encode($_SESSION['username'] ?? '') ?>;
    var CSRF       = <?= json_encode($csrfToken) ?>;
    var QUESTIONS  = <?= json_encode($jsQuestions, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    // State
    var idx       = 0;
    var correct   = 0;
    var wrong     = 0;
    var pts       = 0;
    var totalPts  = 0;
    var answered  = false;

    // DOM refs
    var gameScreen    = document.getElementById('game-screen');
    var resultsScreen = document.getElementById('results-screen');
    var progLabel     = document.getElementById('prog-label');
    var progPct       = document.getElementById('prog-pct');
    var progBar       = document.getElementById('prog-bar');
    var scoreCorrect  = document.getElementById('score-correct');
    var scoreWrong    = document.getElementById('score-wrong');
    var scorePts      = document.getElementById('score-pts');
    var fcCard        = document.getElementById('fc-card');
    var cardPts       = document.getElementById('card-pts');
    var cardNum       = document.getElementById('card-num');
    var cardQ         = document.getElementById('card-q');
    var fcFeedback    = document.getElementById('fc-feedback');
    var fbIcon        = document.getElementById('fb-icon');
    var fbMsg         = document.getElementById('fb-msg');
    var fbAnswer      = document.getElementById('fb-answer');
    var fcOptions     = document.getElementById('fc-options');
    var nextBtn       = document.getElementById('fc-next-btn');
    var btnRetry      = document.getElementById('btn-retry');

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function renderCard() {
        if (idx >= QUESTIONS.length) { showResults(); return; }

        var q = QUESTIONS[idx];
        answered = false;

        // Reset card state
        fcCard.className = 'fc-card';
        fcFeedback.className = 'fc-feedback';
        nextBtn.classList.remove('visible');

        // Progress
        var pct = Math.round((idx / QUESTIONS.length) * 100);
        progLabel.textContent = 'Card ' + (idx + 1) + ' of ' + QUESTIONS.length;
        progPct.textContent   = pct + '%';
        progBar.style.width   = pct + '%';

        // Card content
        cardNum.textContent = 'Question ' + (idx + 1);
        cardQ.textContent   = q.text;
        cardPts.textContent = q.points + ' pt' + (q.points !== 1 ? 's' : '');

        // Build options
        fcOptions.innerHTML = '';
        fcOptions.className = 'fc-options' + (q.type === 'true_false' ? ' tf' : '');

        var opts = q.options.length > 0 ? q.options : [
            { label: 'T', text: 'True' },
            { label: 'F', text: 'False' }
        ];

        opts.forEach(function (opt) {
            var btn = document.createElement('button');
            btn.className = 'fc-option';
            btn.dataset.label = opt.label;
            btn.innerHTML = '<span class="opt-label">' + escHtml(opt.label) + '</span>' + escHtml(opt.text);
            btn.addEventListener('click', function () { handleAnswer(opt.label, q); });
            fcOptions.appendChild(btn);
        });

        totalPts += q.points;
    }

    function handleAnswer(chosen, q) {
        if (answered) return;
        answered = true;

        var isCorrect = chosen === q.correct_answer;

        // Find correct option text for feedback
        var correctText = q.correct_answer;
        q.options.forEach(function (o) {
            if (o.label === q.correct_answer) correctText = o.label + '. ' + o.text;
        });

        // Update score
        if (isCorrect) {
            correct++;
            pts += q.points;
        } else {
            wrong++;
        }
        scoreCorrect.textContent = correct;
        scoreWrong.textContent   = wrong;
        scorePts.textContent     = pts;

        // Style options
        var buttons = fcOptions.querySelectorAll('.fc-option');
        buttons.forEach(function (btn) {
            btn.disabled = true;
            if (btn.dataset.label === q.correct_answer) {
                btn.classList.add('opt-correct');
            } else if (btn.dataset.label === chosen && !isCorrect) {
                btn.classList.add('opt-wrong');
            }
        });

        // Card border
        fcCard.classList.add(isCorrect ? 'correct-card' : 'wrong-card');

        // Feedback overlay
        fbIcon.textContent   = isCorrect ? '✅' : '❌';
        fbMsg.textContent    = isCorrect ? 'Correct!' : 'Not quite…';
        fbAnswer.textContent = isCorrect ? '' : 'Answer: ' + correctText;
        fcFeedback.className = 'fc-feedback show ' + (isCorrect ? 'correct-fb' : 'wrong-fb');

        // Show next button
        nextBtn.textContent = (idx + 1 < QUESTIONS.length) ? 'Next Card →' : 'See Results →';
        nextBtn.classList.add('visible');
    }

    nextBtn.addEventListener('click', function () {
        idx++;
        renderCard();
    });

    function showResults() {
        gameScreen.style.display    = 'none';
        resultsScreen.style.display = 'block';

        var pct = totalPts > 0 ? Math.round((pts / totalPts) * 100) : 0;

        document.getElementById('res-pct').textContent     = pct + '%';
        document.getElementById('res-label').textContent   = 'You scored ' + pts + ' / ' + totalPts + ' points';
        document.getElementById('res-correct').textContent = correct;
        document.getElementById('res-wrong').textContent   = wrong;
        document.getElementById('res-total').textContent   = QUESTIONS.length;

        // Save session to server
        saveSession(pts, totalPts, pct, correct, wrong);
    }

    function saveSession(score, total, pct, corr, wrng) {
        fetch(APP_BASE + '/api/save-flashcard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token:   CSRF,
                quiz_id:      QUIZ_ID,
                score:        score,
                total_points: total,
                percentage:   pct,
                correct:      corr,
                wrong:        wrng,
                total_cards:  QUESTIONS.length
            })
        })
        .then(function (r) { return r.json(); })
        .then(function () { loadLeaderboard(); })
        .catch(function () { loadLeaderboard(); });
    }

    function loadLeaderboard() {
        fetch(APP_BASE + '/api/flashcard-leaderboard.php?quiz_id=' + QUIZ_ID)
            .then(function (r) { return r.json(); })
            .then(function (data) { renderLeaderboard(data.leaderboard || []); })
            .catch(function () {
                document.getElementById('lb-body').innerHTML = '<p class="text-muted small text-center py-2">Could not load leaderboard.</p>';
            });
    }

    function renderLeaderboard(rows) {
        var lbBody = document.getElementById('lb-body');
        if (!rows.length) {
            lbBody.innerHTML = '<p class="text-muted small text-center py-2">No scores yet.</p>';
            return;
        }
        var html = '';
        rows.forEach(function (row, i) {
            var rankClass = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
            var isYou = row.username === USERNAME;
            html += '<div class="lb-row">'
                + '<div class="lb-rank ' + rankClass + '">' + (i + 1) + '</div>'
                + '<div class="lb-name">' + escHtml(row.username) + (isYou ? '<span class="lb-you">you</span>' : '') + '</div>'
                + '<div class="lb-score">' + row.correct + '/' + row.total_cards + ' correct</div>'
                + '<div class="lb-pct">' + row.percentage + '%</div>'
                + '</div>';
        });
        lbBody.innerHTML = html;
    }

    btnRetry.addEventListener('click', function () {
        // Reset state
        idx = correct = wrong = pts = totalPts = 0;
        answered = false;
        scoreCorrect.textContent = scoreWrong.textContent = scorePts.textContent = '0';
        resultsScreen.style.display = 'none';
        gameScreen.style.display    = 'block';
        renderCard();
    });

    // Start
    renderCard();
}());
</script>
</body>
</html>
