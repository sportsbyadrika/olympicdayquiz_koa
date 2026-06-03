<?php
/**
 * Association · My Question Bank.
 * Mirrors the official capture template: a submission header plus a repeating
 * per-question form. Manual entry only (no CSV for questions). Edit/delete are
 * allowed only while a submission is in draft. "Submit to Experts" locks it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('association');

$assoc = current_profile();
if (!$assoc) { redirect('/public/logout.php'); }
$aid = (int) $assoc['id'];

/** Get the association's active draft submission, or null. */
function active_draft(int $aid): ?array
{
    $st = db()->prepare("SELECT * FROM question_submissions WHERE association_id=? AND status='draft' ORDER BY id DESC LIMIT 1");
    $st->execute([$aid]);
    return $st->fetch() ?: null;
}

/** Validate a question's posted fields. Returns [data, errors]. */
function read_question_input(): array
{
    $data = [
        'question_text'  => post('question_text'),
        'option_a'       => post('option_a'),
        'option_b'       => post('option_b'),
        'option_c'       => post('option_c'),
        'option_d'       => post('option_d'),
        'correct_option' => strtoupper(post('correct_option')),
        'sport'          => post('sport'),
        'category'       => post('category'),
        'difficulty'     => post('difficulty', 'Medium'),
        'reference_source' => post('reference_source'),
        'explanation'    => post('explanation'),
    ];
    $errors = [];
    if ($data['question_text'] === '') $errors[] = 'Question text is required.';
    foreach (['a','b','c','d'] as $o) {
        if ($data["option_{$o}"] === '') $errors[] = 'Option ' . strtoupper($o) . ' is required.';
        if (mb_strlen($data["option_{$o}"]) > 500) $errors[] = 'Option ' . strtoupper($o) . ' exceeds 500 characters.';
    }
    if (!in_array($data['correct_option'], ['A','B','C','D'], true)) $errors[] = 'Select exactly one correct option.';
    if (!in_array($data['difficulty'], ['Easy','Medium','Hard'], true)) $data['difficulty'] = 'Medium';
    return [$data, $errors];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    // ---- Save / create submission header --------------------------------
    if ($action === 'save_header') {
        $date = post('submission_date') ?: date('Y-m-d');
        $by   = post('submitted_by_name');
        $email = post('contact_email') ?: ($assoc['contact_email'] ?? '');
        $draft = active_draft($aid);
        if ($draft) {
            db()->prepare('UPDATE question_submissions SET submission_date=:d, submitted_by_name=:b, contact_email=:e WHERE id=:id AND association_id=:aid')
                ->execute([':d' => $date, ':b' => $by, ':e' => $email, ':id' => $draft['id'], ':aid' => $aid]);
        } else {
            db()->prepare('INSERT INTO question_submissions (association_id, submission_date, submitted_by_name, contact_email, status) VALUES (:aid,:d,:b,:e,\'draft\')')
                ->execute([':aid' => $aid, ':d' => $date, ':b' => $by, ':e' => $email]);
        }
        flash('success', 'Submission header saved.');
        redirect('/association/question_bank.php');
    }

    // ---- Add question ----------------------------------------------------
    if ($action === 'add_question') {
        $draft = active_draft($aid);
        if (!$draft) {
            // Auto-create a header if missing.
            db()->prepare('INSERT INTO question_submissions (association_id, submission_date, contact_email, status) VALUES (:aid,:d,:e,\'draft\')')
                ->execute([':aid' => $aid, ':d' => date('Y-m-d'), ':e' => $assoc['contact_email'] ?? '']);
            $draft = active_draft($aid);
        }
        [$d, $errors] = read_question_input();
        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $st = db()->prepare('SELECT COALESCE(MAX(question_no),0)+1 FROM questions_association WHERE submission_id=?');
            $st->execute([$draft['id']]);
            $no = (int) $st->fetchColumn();
            db()->prepare(
                'INSERT INTO questions_association
                 (submission_id, question_no, question_text, option_a, option_b, option_c, option_d, correct_option, sport, category, difficulty, reference_source, explanation, status)
                 VALUES (:sid,:no,:qt,:a,:b,:c,:d,:co,:sport,:cat,:diff,:ref,:exp,\'draft\')'
            )->execute([
                ':sid' => $draft['id'], ':no' => $no, ':qt' => $d['question_text'],
                ':a' => $d['option_a'], ':b' => $d['option_b'], ':c' => $d['option_c'], ':d' => $d['option_d'],
                ':co' => $d['correct_option'], ':sport' => $d['sport'], ':cat' => $d['category'],
                ':diff' => $d['difficulty'], ':ref' => $d['reference_source'], ':exp' => $d['explanation'],
            ]);
            flash('success', "Question {$no} added.");
        }
        redirect('/association/question_bank.php');
    }

    // ---- Edit question (draft only) -------------------------------------
    if ($action === 'edit_question') {
        $qid = int_val(post('question_id'));
        // Ensure the question belongs to this association and is a draft.
        $st = db()->prepare(
            'SELECT qa.* FROM questions_association qa JOIN question_submissions s ON s.id=qa.submission_id
             WHERE qa.id=? AND s.association_id=? AND qa.status=\'draft\' AND s.status=\'draft\''
        );
        $st->execute([$qid, $aid]);
        if ($st->fetch()) {
            [$d, $errors] = read_question_input();
            if ($errors) {
                flash('error', implode(' ', $errors));
            } else {
                db()->prepare(
                    'UPDATE questions_association SET question_text=:qt, option_a=:a, option_b=:b, option_c=:c, option_d=:d,
                     correct_option=:co, sport=:sport, category=:cat, difficulty=:diff, reference_source=:ref, explanation=:exp WHERE id=:id'
                )->execute([
                    ':qt' => $d['question_text'], ':a' => $d['option_a'], ':b' => $d['option_b'], ':c' => $d['option_c'], ':d' => $d['option_d'],
                    ':co' => $d['correct_option'], ':sport' => $d['sport'], ':cat' => $d['category'], ':diff' => $d['difficulty'],
                    ':ref' => $d['reference_source'], ':exp' => $d['explanation'], ':id' => $qid,
                ]);
                flash('success', 'Question updated.');
            }
        } else {
            flash('error', 'Cannot edit that question.');
        }
        redirect('/association/question_bank.php');
    }

    // ---- Delete question (draft only) -----------------------------------
    if ($action === 'delete_question') {
        $qid = int_val(post('question_id'));
        $st = db()->prepare(
            'DELETE qa FROM questions_association qa JOIN question_submissions s ON s.id=qa.submission_id
             WHERE qa.id=? AND s.association_id=? AND qa.status=\'draft\' AND s.status=\'draft\''
        );
        $st->execute([$qid, $aid]);
        flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Question deleted.' : 'Cannot delete that question.');
        redirect('/association/question_bank.php');
    }

    // ---- Submit to experts ----------------------------------------------
    if ($action === 'submit_to_experts') {
        $draft = active_draft($aid);
        if ($draft) {
            $cnt = db()->prepare('SELECT COUNT(*) FROM questions_association WHERE submission_id=? AND status=\'draft\'');
            $cnt->execute([$draft['id']]);
            if ((int) $cnt->fetchColumn() === 0) {
                flash('warning', 'Add at least one question before submitting.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE questions_association SET status='submitted' WHERE submission_id=? AND status='draft'")->execute([$draft['id']]);
                    $pdo->prepare("UPDATE question_submissions SET status='submitted', submitted_at=NOW() WHERE id=?")->execute([$draft['id']]);
                    $pdo->commit();
                    audit_log('submission_submit', 'question_submissions', $draft['id']);
                    flash('success', 'Submission sent to experts and locked.');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    flash('error', 'Could not submit.');
                }
            }
        }
        redirect('/association/question_bank.php');
    }
}

$draft = active_draft($aid);
$questions = [];
if ($draft) {
    $st = db()->prepare('SELECT * FROM questions_association WHERE submission_id=? ORDER BY question_no');
    $st->execute([$draft['id']]);
    $questions = $st->fetchAll();
}

// Past submitted batches (read-only)
$st = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id) AS qcount
    FROM question_submissions s WHERE s.association_id=? AND s.status='submitted' ORDER BY s.submitted_at DESC");
$st->execute([$aid]);
$pastSubs = $st->fetchAll();

$pageTitle = 'My Question Bank';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">My Question Bank</h1>
<p class="text-gray-500 mb-6 text-sm">Enter questions exactly as on the official capture form. Manual entry only — no CSV import.</p>

<!-- Submission header -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-6">
  <h2 class="font-semibold text-navy mb-4">Submission Header</h2>
  <form method="post" class="grid sm:grid-cols-2 gap-4">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_header">
    <div>
      <label class="block text-sm font-medium mb-1">Association name</label>
      <input value="<?= e($assoc['name']) ?>" readonly class="w-full border border-gray-200 bg-lightgrey rounded-lg px-3 py-2.5 text-gray-500">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Date</label>
      <input type="date" name="submission_date" value="<?= e($draft['submission_date'] ?? date('Y-m-d')) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Submitted by</label>
      <input name="submitted_by_name" value="<?= e($draft['submitted_by_name'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Contact / email</label>
      <input name="contact_email" value="<?= e($draft['contact_email'] ?? $assoc['contact_email'] ?? '') ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
    </div>
    <div class="sm:col-span-2">
      <button class="bg-navy text-white rounded-lg px-4 py-2.5 text-sm font-medium min-h-[44px]">Save Header</button>
    </div>
  </form>
</div>

<!-- Questions -->
<div class="flex items-center justify-between mb-3">
  <h2 class="font-semibold text-navy">Questions <span class="text-gray-400 font-normal">(<?= count($questions) ?>)</span></h2>
  <button onclick="openQuestionForm()" class="bg-teal text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ Add Question</button>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto mb-6">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr><th class="px-4 py-3">#</th><th class="px-4 py-3">Question</th><th class="px-4 py-3">Correct</th><th class="px-4 py-3">Difficulty</th><th class="px-4 py-3 text-right">Actions</th></tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$questions): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No questions yet. Click "Add Question".</td></tr><?php endif; ?>
      <?php foreach ($questions as $q): ?>
        <tr>
          <td class="px-4 py-3 text-gray-500"><?= (int) $q['question_no'] ?></td>
          <td class="px-4 py-3"><div class="font-medium text-navy line-clamp-2 max-w-md"><?= e(mb_strimwidth($q['question_text'], 0, 120, '…')) ?></div><span class="text-xs text-gray-400"><?= e($q['sport']) ?><?= $q['category'] ? ' · ' . e($q['category']) : '' ?></span></td>
          <td class="px-4 py-3 font-semibold text-teal"><?= e($q['correct_option']) ?></td>
          <td class="px-4 py-3"><?= status_badge($q['difficulty']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <button class="text-navy hover:underline mr-3" onclick='editQuestion(<?= json_encode($q, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete this question?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <button class="text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($questions): ?>
<form method="post" onsubmit="return confirm('Submit all questions to experts? You will not be able to edit them afterwards.');" class="mb-10">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="submit_to_experts">
  <button class="bg-navy text-white rounded-lg px-6 py-3 font-semibold min-h-[44px]">Submit to Experts</button>
  <span class="text-sm text-gray-500 ml-2">Locks this submission.</span>
</form>
<?php endif; ?>

<?php if ($pastSubs): ?>
<h2 class="font-semibold text-navy mb-3">Past Submissions</h2>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Submitted by</th><th class="px-4 py-3">Questions</th><th class="px-4 py-3">Status</th></tr></thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($pastSubs as $s): ?>
        <tr><td class="px-4 py-3"><?= e(date('d M Y', strtotime((string)$s['submission_date']))) ?></td><td class="px-4 py-3"><?= e($s['submitted_by_name']) ?></td><td class="px-4 py-3"><?= (int)$s['qcount'] ?></td><td class="px-4 py-3"><?= status_badge('submitted') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Add/Edit question modal -->
<div id="qModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-2xl p-6 my-6">
    <h2 id="qModalTitle" class="text-lg font-bold text-navy mb-4">Add Question</h2>
    <form method="post" id="qForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="q_action" value="add_question">
      <input type="hidden" name="question_id" id="q_id" value="">
      <label class="block text-sm font-medium mb-1">Question text *</label>
      <textarea name="question_text" id="q_text" required rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3"></textarea>
      <div class="grid sm:grid-cols-2 gap-3">
        <?php foreach (['a','b','c','d'] as $o): ?>
          <div>
            <label class="block text-sm font-medium mb-1">Option <?= strtoupper($o) ?> *</label>
            <input name="option_<?= $o ?>" id="q_opt_<?= $o ?>" maxlength="500" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <div>
          <label class="block text-sm font-medium mb-1">Correct option *</label>
          <div class="flex gap-4 mt-1" id="q_correct_group">
            <?php foreach (['A','B','C','D'] as $c): ?>
              <label class="flex items-center gap-1 text-sm"><input type="radio" name="correct_option" value="<?= $c ?>" class="q_correct"> <?= $c ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Difficulty</label>
          <select name="difficulty" id="q_diff" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
            <option>Easy</option><option selected>Medium</option><option>Hard</option>
          </select>
        </div>
        <div><label class="block text-sm font-medium mb-1">Sport</label><input name="sport" id="q_sport" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Category</label><input name="category" id="q_cat" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Reference / Source</label><input name="reference_source" id="q_ref" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium mb-1">Explanation</label>
          <textarea name="explanation" id="q_exp" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></textarea>
          <p class="text-xs text-gray-500 mt-1">Shown to participants on the result screen after submission (not during the quiz).</p>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('qModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-teal text-white font-medium">Save Question</button>
      </div>
    </form>
  </div>
</div>

<script>
  function resetQForm(){
    document.getElementById('q_action').value='add_question';
    document.getElementById('q_id').value='';
    document.getElementById('qForm').reset();
    document.getElementById('qModalTitle').textContent='Add Question';
  }
  function openQuestionForm(){ resetQForm(); document.getElementById('qModal').classList.remove('hidden'); }
  function editQuestion(q){
    resetQForm();
    document.getElementById('q_action').value='edit_question';
    document.getElementById('q_id').value=q.id;
    document.getElementById('qModalTitle').textContent='Edit Question #'+q.question_no;
    document.getElementById('q_text').value=q.question_text||'';
    document.getElementById('q_opt_a').value=q.option_a||'';
    document.getElementById('q_opt_b').value=q.option_b||'';
    document.getElementById('q_opt_c').value=q.option_c||'';
    document.getElementById('q_opt_d').value=q.option_d||'';
    document.querySelectorAll('.q_correct').forEach(r=>{ r.checked = (r.value===q.correct_option); });
    document.getElementById('q_diff').value=q.difficulty||'Medium';
    document.getElementById('q_sport').value=q.sport||'';
    document.getElementById('q_cat').value=q.category||'';
    document.getElementById('q_ref').value=q.reference_source||'';
    document.getElementById('q_exp').value=q.explanation||'';
    document.getElementById('qModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
