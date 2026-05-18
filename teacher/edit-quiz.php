<?php
/**
 * teacher/edit-quiz.php — Edit quiz and manage questions (teacher only, own quizzes).
 *
 * Supported question types:
 *   mcq            — Multiple Choice (A/B/C/D options, correct = A|B|C|D)
 *   true_false     — True / False (correct = T|F)
 *   identification — One-word / short answer (correct = exact text, graded case-insensitive)
 *   fill_blank     — Fill in the blank (correct = exact text, graded case-insensitive)
 *   enumeration    — List items in order (correct = comma-separated items)
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/QuestionEngine.php';

requireRole('teacher');

$teacherId = (int) $_SESSION['user_id'];
$quizId    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$quiz      = $quizId > 0 ? getQuiz($quizId) : null;

// Ownership check
if ($quiz === null || (int)$quiz['created_by'] !== $teacherId) {
    header('Location: ' . APP_BASE . '/teacher/quizzes.php'); exit;
}

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Forbidden'); }
    $action = $_POST['action'] ?? '';

    // ── Update quiz metadata ─────────────────────────────────────────────
    if ($action === 'update_quiz') {
        $ok = updateQuiz($quizId, [
            'title'         => trim($_POST['title']       ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'time_limit'    => $_POST['time_limit']       ?? '',
            'is_randomized' => isset($_POST['is_randomized']) ? 1 : 0,
        ]);
        header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId
            . ($ok ? '&success=quiz_updated' : '&error=quiz_update_failed')); exit;
    }

    // ── Add question ─────────────────────────────────────────────────────
    if ($action === 'add_question') {
        $qType = $_POST['question_type']  ?? '';
        $qText = trim($_POST['question_text']  ?? '');
        $qPts  = max(1, (int)($_POST['points'] ?? 1));

        // Build correct_answer based on type
        switch ($qType) {
            case 'mcq':
                $qAns = strtoupper(trim($_POST['correct_answer_mcq'] ?? ''));
                break;
            case 'true_false':
                $qAns = strtoupper(trim($_POST['correct_answer_tf'] ?? ''));
                break;
            case 'enumeration':
                // Store as comma-separated list, trimmed
                $items = array_filter(array_map('trim', explode("\n", $_POST['enum_items'] ?? '')));
                $qAns  = implode(',', $items);
                break;
            default: // identification, fill_blank
                $qAns = trim($_POST['correct_answer_text'] ?? '');
        }

        $qid = addQuestion($quizId, [
            'question_type'  => $qType,
            'question_text'  => $qText,
            'correct_answer' => $qAns,
            'points'         => $qPts,
        ]);

        if ($qid !== false) {
            // Add options based on type
            if ($qType === 'mcq') {
                foreach (['A','B','C','D'] as $lbl) {
                    addOption($qid, $lbl, trim($_POST['option_' . $lbl] ?? ''));
                }
            } elseif ($qType === 'true_false') {
                addOption($qid, 'T', 'True');
                addOption($qid, 'F', 'False');
            }
            // identification / fill_blank / enumeration have no options table entries
            header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId . '&success=question_added'); exit;
        }
        header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId . '&error=question_add_failed'); exit;
    }

    // ── Delete question ──────────────────────────────────────────────────
    if ($action === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid > 0) deleteQuestion($qid);
        header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId . '&success=question_deleted'); exit;
    }

    header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId); exit;
}

// ---------------------------------------------------------------------------
// Load data
// ---------------------------------------------------------------------------
$questions = getQuestions($quizId);
$csrfToken = generateCsrfToken();

$successMsgs = [
    'quiz_created'    => 'Quiz created! Now add more questions below.',
    'quiz_updated'    => 'Quiz updated.',
    'question_added'  => 'Question added.',
    'question_deleted'=> 'Question deleted.',
];
$errorMsgs = [
    'quiz_update_failed'   => 'Failed to update quiz.',
    'question_add_failed'  => 'Failed to add question. Make sure all required fields are filled.',
];
$success = $successMsgs[$_GET['success'] ?? ''] ?? '';
$error   = $errorMsgs[$_GET['error']   ?? ''] ?? '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/** Human-readable label for each question type */
function typeLabel(string $type): string {
    return match($type) {
        'mcq'            => 'Multiple Choice',
        'true_false'     => 'True / False',
        'identification' => 'Identification',
        'fill_blank'     => 'Fill in the Blank',
        'enumeration'    => 'Enumeration',
        default          => strtoupper($type),
    };
}

/** Badge colour per type */
function typeBadge(string $type): string {
    return match($type) {
        'mcq'            => 'bg-primary',
        'true_false'     => 'bg-warning text-dark',
        'identification' => 'bg-success',
        'fill_blank'     => 'bg-info text-dark',
        'enumeration'    => 'bg-purple text-white',
        default          => 'bg-secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quiz — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .bg-purple { background-color: #7c3aed !important; }
        .type-section { display: none; }
        .type-section.active { display: block; }
        .q-card { background:#12122a; border:1px solid rgba(255,255,255,0.08); }
        .q-card:hover { border-color: rgba(6,182,212,0.3); }
        .enum-preview { font-family: monospace; font-size:.85rem; }
    </style>
</head>
<body>
<?php $activePage = 'quiz-list'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4">
    <div class="d-flex align-items-center mb-4">
        <a href="<?= APP_BASE ?>/teacher/quizzes.php" class="btn btn-outline-secondary btn-sm me-3">← Back</a>
        <h1 class="mb-0">Edit: <?= e($quiz['title']) ?></h1>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= e($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Quiz metadata ──────────────────────────────────────────────── -->
    <div class="card mb-4" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
        <div class="card-header"><h5 class="mb-0">Quiz Details</h5></div>
        <div class="card-body">
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action"     value="update_quiz">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Title *</label>
                        <input type="text" name="title" class="form-control"
                               value="<?= e($quiz['title']) ?>" required maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Time Limit (seconds) *</label>
                        <input type="number" name="time_limit" class="form-control"
                               min="30" value="<?= (int)$quiz['time_limit'] ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= e($quiz['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="is_randomized" id="is_rand" class="form-check-input"
                                   <?= $quiz['is_randomized'] ? 'checked' : '' ?>>
                            <label for="is_rand" class="form-check-label">Randomize question order</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- ── Questions list ─────────────────────────────────────────────── -->
    <h4 class="mb-3">Questions (<?= count($questions) ?>)</h4>
    <?php if (empty($questions)): ?>
        <p class="text-muted mb-4">No questions yet. Add one below.</p>
    <?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($questions as $i => $q): ?>
        <div class="list-group-item q-card mb-2 rounded">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="mb-1">
                        <span class="badge <?= typeBadge($q['question_type']) ?> me-2">
                            <?= typeLabel($q['question_type']) ?>
                        </span>
                        <span class="badge bg-secondary me-2"><?= (int)$q['points'] ?> pt<?= $q['points'] != 1 ? 's' : '' ?></span>
                        <strong><?= $i+1 ?>. <?= e($q['question_text']) ?></strong>
                    </div>

                    <?php if ($q['question_type'] === 'mcq' && !empty($q['options'])): ?>
                        <ul class="mt-2 mb-0 small list-unstyled ps-3">
                            <?php foreach ($q['options'] as $opt): ?>
                            <li class="<?= $opt['option_label'] === $q['correct_answer'] ? 'text-success fw-bold' : 'text-muted' ?>">
                                <strong><?= e($opt['option_label']) ?>.</strong> <?= e($opt['option_text']) ?>
                                <?php if ($opt['option_label'] === $q['correct_answer']): ?>
                                    <span class="badge bg-success ms-1">✓ Correct</span>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>

                    <?php elseif ($q['question_type'] === 'true_false'): ?>
                        <div class="small text-muted mt-1">
                            Answer: <strong class="text-success">
                                <?= $q['correct_answer'] === 'T' ? 'True' : 'False' ?>
                            </strong>
                        </div>

                    <?php elseif ($q['question_type'] === 'enumeration'): ?>
                        <div class="small text-muted mt-1">Items (in order):</div>
                        <ol class="small mb-0 mt-1 ps-4">
                            <?php foreach (explode(',', $q['correct_answer']) as $item): ?>
                                <li class="text-success"><?= e(trim($item)) ?></li>
                            <?php endforeach; ?>
                        </ol>

                    <?php else: ?>
                        <div class="small text-muted mt-1">
                            Answer: <strong class="text-success"><?= e($q['correct_answer']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" onsubmit="return confirm('Delete this question?');" class="flex-shrink-0">
                    <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action"      value="delete_question">
                    <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Add question form ──────────────────────────────────────────── -->
    <div class="card mb-5" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
        <div class="card-header">
            <h5 class="mb-0">➕ Add New Question</h5>
        </div>
        <div class="card-body">
            <form method="post" novalidate id="add-q-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action"     value="add_question">

                <!-- Type selector + points (always visible) -->
                <div class="row g-3 mb-4">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Question Type *</label>
                        <select name="question_type" id="q_type" class="form-select" required>
                            <option value="mcq">Multiple Choice (A / B / C / D)</option>
                            <option value="true_false">True / False</option>
                            <option value="identification">Identification</option>
                            <option value="fill_blank">Fill in the Blank</option>
                            <option value="enumeration">Enumeration</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Points</label>
                        <input type="number" name="points" class="form-control" min="1" value="1">
                    </div>
                </div>

                <!-- Question text (always visible) -->
                <div class="mb-4">
                    <label class="form-label fw-semibold" id="q-text-label">Question *</label>
                    <textarea name="question_text" id="question_text" class="form-control"
                              rows="3" required placeholder="Enter your question here…"></textarea>
                </div>

                <!-- ── MCQ fields ──────────────────────────────────────── -->
                <div id="section-mcq" class="type-section active">
                    <p class="fw-semibold mb-2">Answer Options *</p>
                    <div class="row g-2 mb-3">
                        <?php foreach (['A','B','C','D'] as $lbl): ?>
                        <div class="col-md-6">
                            <label class="form-label small">Option <?= $lbl ?></label>
                            <input type="text" name="option_<?= $lbl ?>" class="form-control"
                                   placeholder="Text for option <?= $lbl ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Correct Answer *</label>
                        <select name="correct_answer_mcq" class="form-select" style="max-width:200px;">
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                        <div class="form-text text-muted">Select which option is correct.</div>
                    </div>
                </div>

                <!-- ── True / False fields ────────────────────────────── -->
                <div id="section-true_false" class="type-section">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Correct Answer *</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="correct_answer_tf" id="tf_true" value="T" checked>
                                <label class="form-check-label" for="tf_true">True</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="correct_answer_tf" id="tf_false" value="F">
                                <label class="form-check-label" for="tf_false">False</label>
                            </div>
                        </div>
                        <div class="form-text text-muted mt-1">
                            Options "True" and "False" are added automatically.
                        </div>
                    </div>
                </div>

                <!-- ── Identification fields ──────────────────────────── -->
                <div id="section-identification" class="type-section">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Correct Answer *</label>
                        <input type="text" name="correct_answer_text" class="form-control"
                               placeholder="e.g. Photosynthesis" style="max-width:400px;">
                        <div class="form-text text-muted">
                            Student must type this exactly (graded case-insensitive).
                        </div>
                    </div>
                </div>

                <!-- ── Fill in the Blank fields ───────────────────────── -->
                <div id="section-fill_blank" class="type-section">
                    <div class="alert alert-info py-2 mb-3" style="font-size:.9rem;">
                        💡 Use <code>___</code> in your question text to mark the blank,
                        e.g. <em>"The capital of France is ___."</em>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Correct Answer *</label>
                        <input type="text" name="correct_answer_text" class="form-control"
                               placeholder="e.g. Paris" style="max-width:400px;">
                        <div class="form-text text-muted">
                            Student must type this exactly (graded case-insensitive).
                        </div>
                    </div>
                </div>

                <!-- ── Enumeration fields ─────────────────────────────── -->
                <div id="section-enumeration" class="type-section">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Items (one per line, in order) *</label>
                        <textarea name="enum_items" id="enum_items" class="form-control enum-preview"
                                  rows="5" placeholder="Item 1&#10;Item 2&#10;Item 3"></textarea>
                        <div class="form-text text-muted">
                            Students must list all items. Graded by how many they get correct
                            (each item = 1 point of the total).
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success px-4">Add Question</button>
            </form>
        </div>
    </div>
</div><!-- /.container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp"
        crossorigin="anonymous"></script>
<script>
(function () {
    'use strict';

    var sel      = document.getElementById('q_type');
    var sections = document.querySelectorAll('.type-section');
    var qLabel   = document.getElementById('q-text-label');
    var qText    = document.getElementById('question_text');

    var placeholders = {
        mcq:            'e.g. Which of the following is a mammal?',
        true_false:     'e.g. The Earth revolves around the Sun.',
        identification: 'e.g. What is the process by which plants make food?',
        fill_blank:     'e.g. The capital of France is ___.',
        enumeration:    'e.g. List the 5 major oceans of the world.'
    };

    function showSection(type) {
        sections.forEach(function (s) {
            s.classList.remove('active');
        });
        var target = document.getElementById('section-' + type);
        if (target) target.classList.add('active');

        // Update placeholder
        qText.placeholder = placeholders[type] || 'Enter your question here…';
    }

    // Init
    showSection(sel.value);

    sel.addEventListener('change', function () {
        showSection(this.value);
    });

    // Client-side validation before submit
    document.getElementById('add-q-form').addEventListener('submit', function (e) {
        var type = sel.value;
        var text = document.getElementById('question_text').value.trim();

        if (!text) {
            alert('Please enter the question text.');
            e.preventDefault(); return;
        }

        if (type === 'mcq') {
            var opts = ['A','B','C','D'];
            for (var i = 0; i < opts.length; i++) {
                var inp = document.querySelector('[name="option_' + opts[i] + '"]');
                if (!inp || !inp.value.trim()) {
                    alert('Please fill in all four MCQ options (A, B, C, D).');
                    e.preventDefault(); return;
                }
            }
        }

        if (type === 'identification' || type === 'fill_blank') {
            var ans = document.querySelector('[name="correct_answer_text"]');
            if (!ans || !ans.value.trim()) {
                alert('Please enter the correct answer.');
                e.preventDefault(); return;
            }
        }

        if (type === 'enumeration') {
            var items = document.getElementById('enum_items').value.trim();
            if (!items) {
                alert('Please enter at least one enumeration item.');
                e.preventDefault(); return;
            }
        }
    });
}());
</script>
</body>
</html>
