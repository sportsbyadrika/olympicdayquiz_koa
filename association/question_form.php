<?php
/**
 * Association · Add / Edit a question (full page).
 * GET ?id= edits an existing draft question; otherwise it's a new question.
 * "Save" returns to the question bank; "Save & Enter New Question" saves and
 * reopens a blank form for fast sequential entry.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assoc_questions.php';
require_role('association');

$assoc = current_profile();
if (!$assoc) { redirect('/public/logout.php'); }
$aid = (int) $assoc['id'];

/** Load an editable (draft) question owned by this association, or null. */
function load_draft_question(int $qid, int $aid): ?array
{
    $st = db()->prepare(
        'SELECT qa.* FROM questions_association qa
         JOIN question_submissions s ON s.id = qa.submission_id
         WHERE qa.id=? AND s.association_id=? AND qa.status=\'draft\' AND s.status=\'draft\' LIMIT 1'
    );
    $st->execute([$qid, $aid]);
    return $st->fetch() ?: null;
}

$editId = int_val(get('id'));
$errors = [];
// Values used to (re)populate the form.
$v = [
    'question_text' => '', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
    'correct_option' => '', 'sport' => '', 'category' => '', 'difficulty' => 'Medium',
    'reference_source' => '', 'explanation' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $editId = int_val(post('question_id'));
    $action = post('action'); // 'save' | 'save_new'
    [$d, $errors] = read_question_input();
    $v = $d; // keep entered values for re-render on error

    if (!$errors) {
        if ($editId) {
            // Edit existing draft question.
            $existing = load_draft_question($editId, $aid);
            if (!$existing) {
                flash('error', 'Cannot edit that question.');
                redirect('/association/question_bank.php');
            }
            db()->prepare(
                'UPDATE questions_association SET question_text=:qt, option_a=:a, option_b=:b, option_c=:c, option_d=:d,
                 correct_option=:co, sport=:sport, category=:cat, difficulty=:diff, reference_source=:ref, explanation=:exp WHERE id=:id'
            )->execute([
                ':qt' => $d['question_text'], ':a' => $d['option_a'], ':b' => $d['option_b'], ':c' => $d['option_c'], ':d' => $d['option_d'],
                ':co' => $d['correct_option'], ':sport' => $d['sport'], ':cat' => $d['category'], ':diff' => $d['difficulty'],
                ':ref' => $d['reference_source'], ':exp' => $d['explanation'], ':id' => $editId,
            ]);
            flash('success', 'Question updated.');
            redirect('/association/question_bank.php');
        } else {
            // Add a new draft question.
            $draft = ensure_draft($aid, $assoc);
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
            // Stay on a fresh form, or go back to the bank.
            redirect($action === 'save_new' ? '/association/question_form.php' : '/association/question_bank.php');
        }
    }
    // fall through to re-render with $errors and $v
} elseif ($editId) {
    // GET edit: load existing values.
    $existing = load_draft_question($editId, $aid);
    if (!$existing) {
        flash('error', 'Cannot edit that question.');
        redirect('/association/question_bank.php');
    }
    foreach ($v as $k => $_) {
        $v[$k] = $existing[$k] ?? $v[$k];
    }
}

$isEdit = $editId > 0;
$pageTitle = $isEdit ? 'Edit Question' : 'Add Question';
require dirname(__DIR__) . '/includes/header.php';
?>
<a href="<?= e(BASE_URL) ?>/association/question_bank.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to question bank</a>
<h1 class="text-2xl font-bold text-navy mt-2 mb-1"><?= $isEdit ? 'Edit Question' : 'Add Question' ?></h1>
<p class="text-gray-500 mb-6 text-sm">Enter the question exactly as on the official capture form.</p>

<?php if ($errors): ?>
  <div class="bg-red-50 text-red-800 border border-red-200 rounded-lg px-4 py-3 mb-5 text-sm">
    <?= e(implode(' ', $errors)) ?>
  </div>
<?php endif; ?>

<form method="post" class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 sm:p-6 max-w-3xl">
  <?= csrf_field() ?>
  <input type="hidden" name="question_id" value="<?= (int) $editId ?>">

  <label class="block text-sm font-medium mb-1">Question text *</label>
  <textarea name="question_text" required rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4"><?= e($v['question_text']) ?></textarea>

  <div class="grid sm:grid-cols-2 gap-4">
    <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $key => $L): ?>
      <div>
        <label class="block text-sm font-medium mb-1">Option <?= $L ?> *</label>
        <textarea name="option_<?= $key ?>" required rows="2" maxlength="500" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"><?= e($v['option_' . $key]) ?></textarea>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="grid sm:grid-cols-2 gap-4 mt-4">
    <div>
      <label class="block text-sm font-medium mb-1">Correct option *</label>
      <div class="flex gap-4 mt-2">
        <?php foreach (['A', 'B', 'C', 'D'] as $c): ?>
          <label class="flex items-center gap-1 text-sm"><input type="radio" name="correct_option" value="<?= $c ?>" <?= $v['correct_option'] === $c ? 'checked' : '' ?>> <?= $c ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Difficulty</label>
      <select name="difficulty" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
        <?php foreach (['Easy', 'Medium', 'Hard'] as $lvl): ?>
          <option <?= $v['difficulty'] === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-sm font-medium mb-1">Sport</label><input name="sport" value="<?= e($v['sport']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
    <div><label class="block text-sm font-medium mb-1">Category</label><input name="category" value="<?= e($v['category']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
    <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Reference / Source</label><input name="reference_source" value="<?= e($v['reference_source']) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
    <div class="sm:col-span-2">
      <label class="block text-sm font-medium mb-1">Explanation</label>
      <textarea name="explanation" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"><?= e($v['explanation']) ?></textarea>
      <p class="text-xs text-gray-500 mt-1">Shown to participants on the result screen after submission (not during the quiz).</p>
    </div>
  </div>

  <div class="flex flex-wrap gap-2 mt-6">
    <button type="submit" name="action" value="save" class="bg-navy text-white rounded-lg px-5 py-2.5 font-medium min-h-[44px]"><?= $isEdit ? 'Save Changes' : 'Save' ?></button>
    <?php if (!$isEdit): ?>
      <button type="submit" name="action" value="save_new" class="bg-teal text-white rounded-lg px-5 py-2.5 font-medium min-h-[44px]">Save &amp; Enter New Question</button>
    <?php endif; ?>
    <a href="<?= e(BASE_URL) ?>/association/question_bank.php" class="px-5 py-2.5 rounded-lg border border-gray-300 self-center">Cancel</a>
  </div>
</form>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
