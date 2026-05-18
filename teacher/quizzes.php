<?php
/**
 * teacher/quizzes.php - Quiz list, create with inline question builder (teacher only).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/middleware.php';
require_once __DIR__ . '/../config/QuizManager.php';
require_once __DIR__ . '/../config/QuestionEngine.php';

requireRole('teacher');

$teacherId = (int) $_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Forbidden'); }
    $action = $_POST['action'] ?? 'create';

    if ($action === 'toggle') {
        $qid = (int)($_POST['quiz_id'] ?? 0);
        $pdo = getDB();
        $s = $pdo->prepare('SELECT created_by FROM quizzes WHERE id = ?');
        $s->execute([$qid]);
        $row = $s->fetch();
        if ($row && (int)$row['created_by'] === $teacherId) toggleActive($qid);
        header('Location: ' . APP_BASE . '/teacher/quizzes.php?success=toggled'); exit;
    }

    if ($action === 'delete') {
        $qid = (int)($_POST['quiz_id'] ?? 0);
        $pdo = getDB();
        $s = $pdo->prepare('SELECT created_by FROM quizzes WHERE id = ?');
        $s->execute([$qid]);
        $row = $s->fetch();
        if ($row && (int)$row['created_by'] === $teacherId) deleteQuiz($qid);
        header('Location: ' . APP_BASE . '/teacher/quizzes.php?success=deleted'); exit;
    }

    if ($action === 'create') {
        $data = [
            'title'         => trim($_POST['title']       ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'time_limit'    => $_POST['time_limit']       ?? '',
            'is_randomized' => isset($_POST['is_randomized']) ? 1 : 0,
        ];
        $newId = createQuiz($data, $teacherId);
        if ($newId === false) {
            $error = 'Failed to create quiz. Check that the title is not empty and time limit is at least 30 seconds.';
        } else {
            $qtypes  = $_POST['q_type']   ?? [];
            $qtexts  = $_POST['q_text']   ?? [];
            $qpoints = $_POST['q_points'] ?? [];
            foreach ($qtexts as $i => $qtext) {
                $qtext = trim($qtext);
                if ($qtext === '') continue;
                $qtype = $qtypes[$i] ?? 'mcq';
                $qpts  = max(1, (int)($qpoints[$i] ?? 1));
                switch ($qtype) {
                    case 'mcq':
                        $qans = strtoupper(trim($_POST['q_ans_mcq'][$i] ?? 'A')); break;
                    case 'true_false':
                        $qans = strtoupper(trim($_POST['q_ans_tf'][$i] ?? 'T')); break;
                    case 'enumeration':
                        $rawItems = array_filter(array_map('trim', explode("\n", $_POST['q_enum'][$i] ?? '')));
                        $qans = implode(',', $rawItems); break;
                    default:
                        $qans = trim($_POST['q_ans_text'][$i] ?? '');
                }
                if ($qans === '') continue;
                $qid = addQuestion($newId, [
                    'question_type'  => $qtype,
                    'question_text'  => $qtext,
                    'correct_answer' => $qans,
                    'points'         => $qpts,
                    'order_index'    => $i,
                ]);
                if ($qid !== false) {
                    if ($qtype === 'mcq') {
                        $opts = $_POST['q_opts'][$i] ?? [];
                        foreach (['A','B','C','D'] as $lbl) addOption($qid, $lbl, trim($opts[$lbl] ?? ''));
                    } elseif ($qtype === 'true_false') {
                        addOption($qid, 'T', 'True');
                        addOption($qid, 'F', 'False');
                    }
                }
            }
            header('Location: ' . APP_BASE . '/teacher/edit-quiz.php?id=' . $newId . '&success=quiz_created'); exit;
        }
    }
}

if (isset($_GET['success'])) {
    $msgs = ['toggled' => 'Quiz status updated.', 'deleted' => 'Quiz deleted.'];
    $success = $msgs[$_GET['success']] ?? '';
}

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT * FROM quizzes WHERE created_by = ? ORDER BY created_at DESC');
$stmt->execute([$teacherId]);
$quizzes = $stmt->fetchAll();

$qCounts = [];
if (!empty($quizzes)) {
    $ids = implode(',', array_map('intval', array_column($quizzes, 'id')));
    $qcStmt = $pdo->query("SELECT quiz_id, COUNT(*) AS cnt FROM questions WHERE quiz_id IN ($ids) GROUP BY quiz_id");
    $qCounts = array_column($qcStmt->fetchAll(), 'cnt', 'quiz_id');
}

$csrfToken = generateCsrfToken();
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quizzes - Teacher</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#x1F9E0;</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="<?= APP_BASE ?>/assets/css/style.css">
    <style>
        .q-block{background:#0d0d1a;border:1px solid rgba(255,255,255,.1);border-radius:.5rem;}
        .q-block:hover{border-color:rgba(6,182,212,.35);}
        .type-fields{display:none;}
        .type-fields.active{display:block;}
        .q-number{font-size:1.05rem;font-weight:700;color:#06b6d4;min-width:2rem;}
        .enum-ta{font-family:monospace;font-size:.88rem;}
    </style>
</head>
<body>
<?php $activePage = 'quiz-list'; require_once __DIR__ . '/../includes/teacher-nav.php'; ?>
<div class="container py-4">
    <h1 class="mb-4">My Quizzes</h1>

    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= e($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger  alert-dismissible fade show"><?= e($error)   ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <?php if (empty($quizzes)): ?>
        <p class="text-muted">No quizzes yet. Create one below.</p>
    <?php else: ?>
    <div class="table-responsive mb-5">
        <table class="table table-dark table-hover align-middle">
            <thead><tr><th>Title</th><th>Questions</th><th>Time Limit</th><th>Randomized</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($quizzes as $q): ?>
            <tr>
                <td class="fw-semibold"><?= e($q['title']) ?></td>
                <td><span class="badge bg-secondary"><?= (int)($qCounts[$q['id']] ?? 0) ?> Q</span></td>
                <td><?= e((string)$q['time_limit']) ?>s</td>
                <td><?= $q['is_randomized'] ? '<span class="badge bg-info text-dark">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                <td><?= $q['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-warning text-dark">Inactive</span>' ?></td>
                <td class="d-flex gap-1 flex-wrap">
                    <a href="<?= APP_BASE ?>/teacher/edit-quiz.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="quiz_id" value="<?= (int)$q['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary"><?= $q['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this quiz and all its data?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="quiz_id" value="<?= (int)$q['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- CREATE FORM -->
    <h2 class="h4 mb-3">&#x2795; Create New Quiz</h2>
    <form method="post" novalidate id="create-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="create">

        <!-- Quiz details -->
        <div class="card mb-4" style="background:#12122a;border:1px solid rgba(6,182,212,.2);">
            <div class="card-header" style="background:transparent;border-bottom:1px solid rgba(6,182,212,.15);">
                <h5 class="mb-0">&#x1F4CB; Quiz Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" maxlength="255" required placeholder="e.g. Chapter 5 Review">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Time Limit (seconds) <span class="text-danger">*</span></label>
                        <input type="number" name="time_limit" class="form-control" min="30" value="300" required>
                        <div class="form-text text-muted">Min 30s. 300 = 5 min.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="is_randomized" id="is_randomized" class="form-check-input">
                            <label for="is_randomized" class="form-check-label">Randomize question order</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Questions -->
        <div class="card mb-4" style="background:#12122a;border:1px solid rgba(74,222,128,.2);">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:transparent;border-bottom:1px solid rgba(74,222,128,.15);">
                <h5 class="mb-0">&#x2753; Questions <span class="badge bg-secondary ms-2" id="q-count-badge">0</span></h5>
                <span class="text-muted small">Optional - you can also add questions after creating.</span>
            </div>
            <div class="card-body">
                <div id="questions-container"></div>
                <button type="button" id="add-q-btn" class="btn btn-outline-success w-100 mt-2">+ Add Question</button>
            </div>
        </div>

        <div class="d-flex gap-3 mb-5">
            <button type="submit" class="btn btn-success btn-lg px-5">&#x1F680; Create Quiz</button>
            <span class="text-muted small align-self-center">You will be taken to the editor to continue adding questions.</span>
        </div>
    </form>
</div>

<!-- Question block template -->
<template id="q-template">
<div class="q-block p-3 mb-3" data-qindex="">
    <div class="d-flex align-items-start gap-2 mb-2">
        <span class="q-number mt-1">Q#</span>
        <div class="flex-grow-1">
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold mb-1">Question Type</label>
                    <select name="q_type[]" class="form-select form-select-sm q-type-sel">
                        <option value="mcq">Multiple Choice (A/B/C/D)</option>
                        <option value="true_false">True / False</option>
                        <option value="identification">Identification</option>
                        <option value="fill_blank">Fill in the Blank</option>
                        <option value="enumeration">Enumeration</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Points</label>
                    <input type="number" name="q_points[]" class="form-control form-control-sm" min="1" value="1">
                </div>
                <div class="col-md-4 d-flex align-items-end justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-q-btn">Remove</button>
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label small fw-semibold mb-1">Question *</label>
                <textarea name="q_text[]" class="form-control form-control-sm q-text-area" rows="2" required placeholder="Enter your question..."></textarea>
            </div>
            <!-- MCQ -->
            <div class="type-fields tf-mcq active">
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small mb-1">Option A</label><input type="text" name="q_opts[][A]" class="form-control form-control-sm" placeholder="Option A"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Option B</label><input type="text" name="q_opts[][B]" class="form-control form-control-sm" placeholder="Option B"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Option C</label><input type="text" name="q_opts[][C]" class="form-control form-control-sm" placeholder="Option C"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Option D</label><input type="text" name="q_opts[][D]" class="form-control form-control-sm" placeholder="Option D"></div>
                </div>
                <div><label class="form-label small fw-semibold mb-1">Correct Answer</label>
                    <select name="q_ans_mcq[]" class="form-select form-select-sm" style="max-width:120px;">
                        <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                    </select>
                </div>
            </div>
            <!-- True/False -->
            <div class="type-fields tf-true_false">
                <label class="form-label small fw-semibold mb-1">Correct Answer</label>
                <div class="d-flex gap-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q_ans_tf[]" value="T" checked><label class="form-check-label">True</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q_ans_tf[]" value="F"><label class="form-check-label">False</label></div>
                </div>
                <div class="form-text text-muted">Options True/False are added automatically.</div>
            </div>
            <!-- Identification -->
            <div class="type-fields tf-identification">
                <label class="form-label small fw-semibold mb-1">Correct Answer *</label>
                <input type="text" name="q_ans_text[]" class="form-control form-control-sm" placeholder="e.g. Photosynthesis" style="max-width:360px;">
                <div class="form-text text-muted">Graded case-insensitive.</div>
            </div>
            <!-- Fill in the Blank -->
            <div class="type-fields tf-fill_blank">
                <div class="alert alert-info py-1 px-2 mb-2" style="font-size:.8rem;">Use <code>___</code> in your question to mark the blank.</div>
                <label class="form-label small fw-semibold mb-1">Correct Answer *</label>
                <input type="text" name="q_ans_text[]" class="form-control form-control-sm" placeholder="e.g. Paris" style="max-width:360px;">
                <div class="form-text text-muted">Graded case-insensitive.</div>
            </div>
            <!-- Enumeration -->
            <div class="type-fields tf-enumeration">
                <label class="form-label small fw-semibold mb-1">Items (one per line, in order) *</label>
                <textarea name="q_enum[]" class="form-control form-control-sm enum-ta" rows="4" placeholder="Item 1&#10;Item 2&#10;Item 3"></textarea>
                <div class="form-text text-muted">Each item = 1 point of the total.</div>
            </div>
        </div>
    </div>
</div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmFXFMrWCU3FA0e3dbKJx/A45Bqp" crossorigin="anonymous"></script>
<script>
(function(){
    'use strict';
    var container  = document.getElementById('questions-container');
    var addBtn     = document.getElementById('add-q-btn');
    var countBadge = document.getElementById('q-count-badge');
    var template   = document.getElementById('q-template');
    var qIndex     = 0;

    var placeholders = {
        mcq:'e.g. Which of the following is a mammal?',
        true_false:'e.g. The Earth revolves around the Sun.',
        identification:'e.g. What is the process by which plants make food?',
        fill_blank:'e.g. The capital of France is ___.',
        enumeration:'e.g. List the 5 major oceans of the world.'
    };

    function updateNumbers(){
        var blocks = container.querySelectorAll('.q-block');
        blocks.forEach(function(b,i){ var n=b.querySelector('.q-number'); if(n) n.textContent='Q'+(i+1); });
        countBadge.textContent = blocks.length;
    }

    function showTypeFields(block, type){
        block.querySelectorAll('.type-fields').forEach(function(f){ f.classList.remove('active'); });
        var t = block.querySelector('.tf-'+type);
        if(t) t.classList.add('active');
        var ta = block.querySelector('.q-text-area');
        if(ta) ta.placeholder = placeholders[type] || 'Enter your question...';
    }

    function fixNames(block, idx){
        ['q_type','q_text','q_points','q_ans_mcq','q_ans_text','q_enum'].forEach(function(n){
            block.querySelectorAll('[name="'+n+'[]"]').forEach(function(el){ el.name=n+'['+idx+']'; });
        });
        block.querySelectorAll('[name="q_ans_tf[]"]').forEach(function(el){ el.name='q_ans_tf['+idx+']'; });
        ['A','B','C','D'].forEach(function(lbl){
            block.querySelectorAll('[name="q_opts[]['+lbl+']"]').forEach(function(el){ el.name='q_opts['+idx+']['+lbl+']'; });
        });
    }

    function addQuestion(){
        var clone = template.content.cloneNode(true);
        var block = clone.querySelector('.q-block');
        block.dataset.qindex = qIndex;
        fixNames(block, qIndex);
        var sel = block.querySelector('.q-type-sel');
        showTypeFields(block, sel.value);
        sel.addEventListener('change', function(){ showTypeFields(block, this.value); });
        block.querySelector('.remove-q-btn').addEventListener('click', function(){ block.remove(); updateNumbers(); });
        container.appendChild(block);
        qIndex++;
        updateNumbers();
        block.scrollIntoView({behavior:'smooth',block:'nearest'});
    }

    addBtn.addEventListener('click', addQuestion);

    document.getElementById('create-form').addEventListener('submit', function(e){
        var blocks = container.querySelectorAll('.q-block');
        for(var i=0;i<blocks.length;i++){
            var block=blocks[i];
            var type=block.querySelector('.q-type-sel').value;
            var text=block.querySelector('.q-text-area').value.trim();
            if(!text){ alert('Question '+(i+1)+': please enter the question text.'); e.preventDefault(); return; }
            if(type==='mcq'){
                var opts=['A','B','C','D'];
                for(var j=0;j<opts.length;j++){
                    var inp=block.querySelector('[name*="q_opts"][name*="['+opts[j]+']"]');
                    if(!inp||!inp.value.trim()){ alert('Question '+(i+1)+': fill in all four MCQ options.'); e.preventDefault(); return; }
                }
            }
            if(type==='identification'||type==='fill_blank'){
                var ans=block.querySelector('[name*="q_ans_text"]');
                if(!ans||!ans.value.trim()){ alert('Question '+(i+1)+': enter the correct answer.'); e.preventDefault(); return; }
            }
            if(type==='enumeration'){
                var en=block.querySelector('[name*="q_enum"]');
                if(!en||!en.value.trim()){ alert('Question '+(i+1)+': enter at least one enumeration item.'); e.preventDefault(); return; }
            }
        }
    });
}());
</script>
</body>
</html>
