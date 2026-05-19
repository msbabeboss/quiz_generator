<?php
/**
 * teacher/generate-quiz.php
 * Upload a file → AI extracts/generates questions → teacher reviews → saves to quiz.
 *
 * Modes:
 *   Auto-detect  — AI reads the content and decides the best question types and counts.
 *                  If the PDF already has MCQ questions, it extracts them as MCQ.
 *                  If it has T/F questions, it extracts them as true_false.
 *   Manual       — Teacher specifies exact MCQ and True/False counts.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/GeminiService.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/QuestionEngine.php';

requireRole('teacher');

$teacherId = (int) $_SESSION['user_id'];
$step      = 'upload';
$error     = '';
$questions = [];
$quizTitle = '';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ── STEP 1: File upload + AI generation ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Forbidden'); }

    $autoDetect = isset($_POST['auto_detect']);
    $mcqCount   = max(0, min(20, (int)($_POST['mcq_count'] ?? 5)));
    $tfCount    = max(0, min(10, (int)($_POST['tf_count']  ?? 0)));
    $quizTitle  = trim($_POST['quiz_title'] ?? 'Generated Quiz');

    // Validate: in manual mode at least one type must be > 0
    if (!$autoDetect && $mcqCount === 0 && $tfCount === 0) {
        $error = 'Please set at least one question type count above 0, or use Auto-detect mode.';
    } elseif (empty($_FILES['lesson_file']['tmp_name'])) {
        $error = 'Please select a file to upload.';
    } else {
        $file    = $_FILES['lesson_file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['docx', 'pptx', 'txt', 'pdf', 'xlsx'];

        if (!in_array($ext, $allowed)) {
            $error = 'Only .docx, .pptx, .xlsx, .txt, and .pdf files are supported.';
        } elseif ($file['size'] > 100 * 1024 * 1024) {
            $error = 'File must be under 100 MB.';
        } else {
            $dest = __DIR__ . '/../uploads/lessons/' . uniqid('lesson_', true) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dest);

            $rawText = extractTextFromFile($dest, $ext);
            unlink($dest);

            if (strlen($rawText) < 30) {
                $error = 'Could not extract enough text from the file. Make sure it contains readable text (not just images).';
            } else {
                $trimmed   = mb_substr($rawText, 0, 8000);
                $gemini    = new GeminiService();
                $questions = $gemini->generateQuestions($trimmed, $mcqCount, $tfCount, $autoDetect);

                if ($questions === false || empty($questions)) {
                    $error = 'AI could not generate questions. Try again or use a different file. '
                           . 'Tip: make sure the file has readable text (not scanned images).';
                } else {
                    $step = 'review';
                    $_SESSION['gen_questions'] = $questions;
                    $_SESSION['gen_title']     = $quizTitle;
                }
            }
        }
    }
}

// ── STEP 2: Save generated questions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Forbidden'); }

    $savedQuestions = $_SESSION['gen_questions'] ?? [];
    $savedTitle     = trim($_POST['quiz_title'] ?? ($_SESSION['gen_title'] ?? 'Generated Quiz'));
    $timeLimit      = max(30, (int)($_POST['time_limit'] ?? 300));

    if (empty($savedQuestions)) {
        $error = 'No questions to save. Please generate again.';
        $step  = 'upload';
    } else {
        $quizId = createQuiz([
            'title'         => $savedTitle ?: 'Generated Quiz',
            'description'   => 'Auto-generated from uploaded lesson file.',
            'time_limit'    => $timeLimit,
            'is_randomized' => 1,
        ], $teacherId);

        if ($quizId === false) {
            $error = 'Failed to create quiz. Please try again.';
            $step  = 'upload';
        } else {
            $validTypes = ['mcq', 'true_false', 'identification', 'fill_blank', 'enumeration'];
            $saved = 0;

            foreach ($savedQuestions as $i => $q) {
                $qType = in_array($q['type'] ?? '', $validTypes, true) ? $q['type'] : 'mcq';
                $qText = trim($q['question'] ?? '');
                $qPts  = max(1, (int)($q['points'] ?? 1));

                if ($qText === '') continue;

                // Build correct_answer based on type
                if ($qType === 'mcq') {
                    $qAns = strtoupper(trim($q['correct_answer'] ?? 'A'));
                    if (!in_array($qAns, ['A','B','C','D'], true)) $qAns = 'A';

                } elseif ($qType === 'true_false') {
                    $raw  = strtoupper(trim($q['correct_answer'] ?? 'T'));
                    // Accept T/F, True/False, 1/0
                    $qAns = in_array($raw, ['T','TRUE','1'], true) ? 'T' : 'F';

                } elseif ($qType === 'enumeration') {
                    // AI may return items as array or comma-string
                    if (is_array($q['correct_answer'] ?? null)) {
                        $qAns = implode(',', array_map('trim', $q['correct_answer']));
                    } else {
                        $qAns = trim($q['correct_answer'] ?? '');
                    }
                    if ($qAns === '') continue;

                } else {
                    // identification / fill_blank
                    $qAns = trim($q['correct_answer'] ?? '');
                    if ($qAns === '') continue;
                }

                $qId = addQuestion($quizId, [
                    'question_type'  => $qType,
                    'question_text'  => $qText,
                    'correct_answer' => $qAns,
                    'points'         => $qPts,
                    'order_index'    => $i,
                ]);

                if ($qId === false) continue;
                $saved++;

                // Save options
                if ($qType === 'mcq') {
                    // Use AI-provided options if present, otherwise skip (edit-quiz can add them)
                    $opts = $q['options'] ?? [];
                    if (!empty($opts)) {
                        foreach ($opts as $opt) {
                            $label = strtoupper(trim($opt['label'] ?? ''));
                            $text  = trim($opt['text'] ?? '');
                            if ($label !== '' && $text !== '') {
                                addOption($qId, $label, $text);
                            }
                        }
                    }
                } elseif ($qType === 'true_false') {
                    // Always add T/F options — take-quiz.php requires them
                    addOption($qId, 'T', 'True');
                    addOption($qId, 'F', 'False');
                }
                // identification / fill_blank / enumeration have no options rows
            }

            unset($_SESSION['gen_questions'], $_SESSION['gen_title']);

            if ($saved === 0) {
                // Quiz was created but no questions saved — delete it and show error
                deleteQuiz($quizId);
                $error = 'No valid questions could be saved. The AI response may have been malformed. Please try again.';
                $step  = 'upload';
            } else {
                header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $quizId . '&success=question_added');
                exit;
            }
        }
    }
}

// Restore review step from session
if ($step === 'upload' && !empty($_SESSION['gen_questions'])) {
    $questions = $_SESSION['gen_questions'];
    $quizTitle = $_SESSION['gen_title'] ?? 'Generated Quiz';
    $step      = 'review';
}

$csrfToken = generateCsrfToken();

// Count types for the review summary
$typeCounts = [];
foreach ($questions as $q) {
    $t = $q['type'] ?? 'mcq';
    $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
}
$mcqGenerated = $typeCounts['mcq']        ?? 0;
$tfGenerated  = $typeCounts['true_false'] ?? 0;
$idGenerated  = $typeCounts['identification'] ?? 0;
$fbGenerated  = $typeCounts['fill_blank'] ?? 0;
$enGenerated  = $typeCounts['enumeration'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Quiz Generator — Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .upload-zone { border:2px dashed rgba(6,182,212,0.4); border-radius:1rem; padding:3rem 2rem; text-align:center; background:rgba(6,182,212,0.04); transition:border-color .2s,background .2s; cursor:pointer; }
        .upload-zone:hover,.upload-zone.drag-over { border-color:#06b6d4; background:rgba(6,182,212,0.08); }
        .upload-icon { font-size:3rem; margin-bottom:1rem; }

        /* Mode toggle */
        .mode-toggle { display:flex; gap:.5rem; margin-bottom:1.5rem; }
        .mode-btn { flex:1; padding:.65rem 1rem; border-radius:.5rem; border:1px solid rgba(255,255,255,.12);
            background:transparent; color:#9090b0; cursor:pointer; font-size:.88rem; font-weight:600;
            transition:all .2s; text-align:center; }
        .mode-btn:hover { border-color:rgba(6,182,212,.4); color:#e0e0f0; }
        .mode-btn.selected { background:rgba(6,182,212,.15); border-color:rgba(6,182,212,.5); color:#06b6d4; }
        .mode-btn .mode-icon { font-size:1.3rem; display:block; margin-bottom:.25rem; }

        /* Manual controls */
        #manual-controls { transition:opacity .2s; }
        #manual-controls.hidden { opacity:.35; pointer-events:none; }

        /* Question preview cards */
        .q-card { background:#12122a; border:1px solid rgba(255,255,255,0.08); border-radius:.75rem; padding:1.25rem; margin-bottom:1rem; }
        .q-type-badge { font-size:.7rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; padding:.2rem .6rem; border-radius:999px; }
        .q-type-badge.mcq { background:rgba(67,97,238,.2); color:#a5b4fc; }
        .q-type-badge.tf  { background:rgba(6,182,212,.2); color:#67e8f9; }
        .q-type-badge.id  { background:rgba(74,222,128,.2); color:#4ade80; }
        .q-type-badge.fb  { background:rgba(251,191,36,.2); color:#fbbf24; }
        .q-type-badge.en  { background:rgba(167,139,250,.2); color:#a78bfa; }
        .q-text { font-weight:700; margin:.5rem 0; color:#e0e0f0; }
        .q-opts { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.5rem; }
        .q-opt { font-size:.8rem; padding:.2rem .6rem; border-radius:.4rem; background:rgba(255,255,255,.05); color:#c0c0d8; }
        .q-opt.correct { background:rgba(74,222,128,.15); color:#4ade80; font-weight:700; }

        .spinner-wrap { display:none; text-align:center; padding:3rem; }
        .spinner-wrap.show { display:block; }
        .form-control,.form-select { background:#0d0d1a; border:1px solid rgba(255,255,255,.1); color:#e0e0f0; }
        .form-control:focus,.form-select:focus { background:#0d0d1a; border-color:#06b6d4; color:#e0e0f0; box-shadow:0 0 0 3px rgba(6,182,212,.2); }
        .form-control::placeholder { color:#555570; }
        .form-label { color:#9090b0; font-size:.78rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; }
        .form-select option { background:#12122a; }
    </style>
</head>
<body>
<?php $activePage = 'quiz-ai'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>

<div class="container py-4" style="max-width:820px;">

    <div class="d-flex align-items-center mb-4 gap-3">
        <div style="font-size:2rem;">🤖</div>
        <div>
            <h1 class="mb-0 fw-bold">AI Quiz Generator</h1>
            <p class="text-muted mb-0 small">Upload a file — the AI reads it and generates questions automatically</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger mb-4"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'upload'): ?>
    <!-- ── STEP 1: Upload ──────────────────────────────────────────── -->
    <div class="card mb-4" style="background:#12122a; border:1px solid rgba(6,182,212,0.2);">
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" id="upload-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action"     value="generate">
                <input type="hidden" name="auto_detect" id="auto_detect_input" value="1">

                <!-- Drop zone -->
                <div class="upload-zone mb-4" id="drop-zone"
                     onclick="document.getElementById('lesson_file').click()">
                    <div class="upload-icon">📄</div>
                    <h5 class="fw-bold mb-1">Drop your file here or click to browse</h5>
                    <p class="text-muted small mb-2">
                        Supports <strong>.pptx</strong>, <strong>.docx</strong>, <strong>.xlsx</strong>,
                        <strong>.pdf</strong>, <strong>.txt</strong> — max 100 MB
                    </p>
                    <div id="file-name" class="text-info small"></div>
                    <input type="file" id="lesson_file" name="lesson_file"
                           accept=".pptx,.docx,.xlsx,.pdf,.txt" class="d-none" required>
                </div>

                <!-- Quiz title -->
                <div class="mb-4">
                    <label class="form-label">Quiz Title</label>
                    <input type="text" name="quiz_title" class="form-control"
                           value="Generated Quiz" required placeholder="e.g. Chapter 3 Review">
                </div>

                <!-- Mode selector -->
                <div class="mb-1">
                    <label class="form-label">Question Generation Mode</label>
                </div>
                <div class="mode-toggle" id="mode-toggle">
                    <div class="mode-btn selected" id="btn-auto" onclick="setMode('auto')">
                        <span class="mode-icon">🧠</span>
                        <strong>Auto-detect</strong>
                        <div style="font-size:.75rem; color:#9090b0; font-weight:400; margin-top:.2rem;">
                            AI reads your file and decides the best question types and counts
                        </div>
                    </div>
                    <div class="mode-btn" id="btn-manual" onclick="setMode('manual')">
                        <span class="mode-icon">🎛️</span>
                        <strong>Manual</strong>
                        <div style="font-size:.75rem; color:#9090b0; font-weight:400; margin-top:.2rem;">
                            You choose exactly how many MCQ and True/False questions
                        </div>
                    </div>
                </div>

                <!-- Auto-detect info -->
                <div id="auto-info" class="mb-3 mt-2"
                     style="background:rgba(6,182,212,.07); border:1px solid rgba(6,182,212,.2); border-radius:.5rem; padding:.75rem 1rem; font-size:.85rem; color:#9090b0;">
                    💡 <strong style="color:#e0e0f0;">Smart mode:</strong>
                    If your PDF already contains multiple-choice or True/False questions, the AI will
                    <strong style="color:#06b6d4;">extract them directly</strong> and preserve the original
                    question types. If it's plain lesson content, the AI will generate the most suitable mix.
                </div>

                <!-- Manual controls (hidden in auto mode) -->
                <div id="manual-controls" class="hidden">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label">MCQ Questions</label>
                            <select name="mcq_count" class="form-select">
                                <option value="0">0 — skip MCQ</option>
                                <?php foreach ([3,5,8,10,15,20] as $n): ?>
                                    <option value="<?= $n ?>" <?= $n === 5 ? 'selected' : '' ?>><?= $n ?> questions</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">True/False Questions</label>
                            <select name="tf_count" class="form-select">
                                <option value="0" selected>0 — skip True/False</option>
                                <?php foreach ([2,3,5,8,10] as $n): ?>
                                    <option value="<?= $n ?>"><?= $n ?> questions</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:.8rem;">
                        💡 Set a type to <strong>0</strong> to skip it entirely.
                        E.g. set MCQ to 0 and True/False to 5 to get only T/F questions.
                    </div>
                </div>

                <!-- Time limit -->
                <div class="mb-4">
                    <label class="form-label">Default Time Limit</label>
                    <select name="time_limit" class="form-select" style="max-width:220px;">
                        <option value="120">2 minutes</option>
                        <option value="300" selected>5 minutes</option>
                        <option value="600">10 minutes</option>
                        <option value="900">15 minutes</option>
                        <option value="1800">30 minutes</option>
                    </select>
                </div>

                <!-- Spinner -->
                <div class="spinner-wrap" id="spinner">
                    <div class="spinner-border text-info mb-3" style="width:3rem;height:3rem;" role="status"></div>
                    <h5 class="fw-bold">AI is analyzing your file…</h5>
                    <p class="text-muted small">This usually takes 5–20 seconds</p>
                </div>

                <button type="submit" class="btn btn-info text-dark fw-bold w-100 py-3"
                        id="generate-btn" style="font-size:1.05rem;">
                    🤖 Generate Questions with AI
                </button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── STEP 2: Review & Save ───────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">✅ <?= count($questions) ?> Questions Generated</h4>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($mcqGenerated > 0): ?>
                    <span class="q-type-badge mcq"><?= $mcqGenerated ?> MCQ</span>
                <?php endif; ?>
                <?php if ($tfGenerated > 0): ?>
                    <span class="q-type-badge tf"><?= $tfGenerated ?> True/False</span>
                <?php endif; ?>
                <?php if ($idGenerated > 0): ?>
                    <span class="q-type-badge id"><?= $idGenerated ?> Identification</span>
                <?php endif; ?>
                <?php if ($fbGenerated > 0): ?>
                    <span class="q-type-badge fb"><?= $fbGenerated ?> Fill Blank</span>
                <?php endif; ?>
                <?php if ($enGenerated > 0): ?>
                    <span class="q-type-badge en"><?= $enGenerated ?> Enumeration</span>
                <?php endif; ?>
            </div>
        </div>
        <a href="<?= APP_BASE ?>/teacher/generate-quiz.php" class="btn btn-sm btn-outline-secondary">↩ Start Over</a>
    </div>

    <!-- Question preview -->
    <div class="mb-4">
        <?php foreach ($questions as $i => $q): ?>
        <?php $qtype = $q['type'] ?? 'mcq'; ?>
        <div class="q-card">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="text-muted small fw-bold"><?= $i + 1 ?>.</span>
                <?php
                $typeLabelMap = [
                    'mcq'            => ['label' => 'MCQ',            'css' => 'mcq'],
                    'true_false'     => ['label' => 'True / False',   'css' => 'tf'],
                    'identification' => ['label' => 'Identification', 'css' => 'id'],
                    'fill_blank'     => ['label' => 'Fill Blank',     'css' => 'fb'],
                    'enumeration'    => ['label' => 'Enumeration',    'css' => 'en'],
                ];
                $tl = $typeLabelMap[$qtype] ?? ['label' => strtoupper($qtype), 'css' => 'mcq'];
                ?>
                <span class="q-type-badge <?= e($tl['css']) ?>"><?= e($tl['label']) ?></span>
                <span class="text-muted small ms-auto"><?= (int)($q['points'] ?? 1) ?> pt</span>
            </div>
            <div class="q-text"><?= e($q['question'] ?? '') ?></div>
            <?php if (!empty($q['options'])): ?>
            <div class="q-opts">
                <?php foreach ($q['options'] as $opt): ?>
                    <span class="q-opt <?= ($opt['label'] === ($q['correct_answer'] ?? '')) ? 'correct' : '' ?>">
                        <?= e($opt['label']) ?>. <?= e($opt['text']) ?>
                        <?= ($opt['label'] === ($q['correct_answer'] ?? '')) ? ' ✓' : '' ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php elseif (in_array($qtype, ['identification','fill_blank','enumeration'])): ?>
            <div class="mt-2" style="font-size:.82rem; color:#4ade80;">
                ✓ Answer: <strong><?= e($q['correct_answer'] ?? '') ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Save form -->
    <div class="card" style="background:#12122a; border:1px solid rgba(74,222,128,0.2);">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">💾 Save as Quiz</h5>
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action"     value="save">
                <div class="row g-3">
                    <div class="col-sm-8">
                        <label class="form-label">Quiz Title</label>
                        <input type="text" name="quiz_title" class="form-control"
                               value="<?= e($quizTitle) ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label">Time Limit</label>
                        <select name="time_limit" class="form-select">
                            <option value="120">2 minutes</option>
                            <option value="300" selected>5 minutes</option>
                            <option value="600">10 minutes</option>
                            <option value="900">15 minutes</option>
                            <option value="1800">30 minutes</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success fw-bold px-4">✅ Save Quiz</button>
                    <a href="<?= APP_BASE ?>/teacher/generate-quiz.php"
                       class="btn btn-outline-secondary">↩ Regenerate</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
(function () {
    // ── Mode toggle ──────────────────────────────────────────────────
    var autoInput    = document.getElementById('auto_detect_input');
    var manualCtrl   = document.getElementById('manual-controls');
    var autoInfo     = document.getElementById('auto-info');
    var btnAuto      = document.getElementById('btn-auto');
    var btnManual    = document.getElementById('btn-manual');

    function setMode(mode) {
        if (mode === 'auto') {
            autoInput.value = '1';
            manualCtrl.classList.add('hidden');
            autoInfo.style.display = '';
            btnAuto.classList.add('selected');
            btnManual.classList.remove('selected');
        } else {
            autoInput.value = '';
            manualCtrl.classList.remove('hidden');
            autoInfo.style.display = 'none';
            btnManual.classList.add('selected');
            btnAuto.classList.remove('selected');
        }
    }
    window.setMode = setMode;

    // ── File input display ───────────────────────────────────────────
    var fileInput = document.getElementById('lesson_file');
    var fileName  = document.getElementById('file-name');
    var dropZone  = document.getElementById('drop-zone');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files[0]) fileName.textContent = '📎 ' + fileInput.files[0].name;
        });
    }

    if (dropZone) {
        dropZone.addEventListener('dragover',  function (e) { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', function ()  { dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            var files = e.dataTransfer.files;
            if (files[0]) {
                fileInput.files = files;
                fileName.textContent = '📎 ' + files[0].name;
            }
        });
    }

    // ── Show spinner on submit ───────────────────────────────────────
    var form    = document.getElementById('upload-form');
    var spinner = document.getElementById('spinner');
    var genBtn  = document.getElementById('generate-btn');
    if (form) {
        form.addEventListener('submit', function () {
            if (fileInput && fileInput.files[0]) {
                if (spinner) spinner.classList.add('show');
                if (genBtn)  genBtn.disabled = true;
            }
        });
    }
}());
</script>
</body>
</html>
