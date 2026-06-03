<?php
/**
 * Association · My Question Bank.
 * Working area for draft questions: add/edit/delete, filter, paginate, and
 * submit a selected set to the experts. The submission header (date, submitted
 * by, contact) is collected only at submission time. Past submissions live on
 * the separate "My Submissions" page.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assoc_questions.php';
require_role('association');

$assoc = current_profile();
if (!$assoc) { redirect('/public/logout.php'); }
$aid = (int) $assoc['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

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

    // ---- Submit selected questions to experts ---------------------------
    if ($action === 'submit_selected') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['question_ids'] ?? [])), fn($i) => $i > 0));
        $date = post('submission_date') ?: date('Y-m-d');
        $by = post('submitted_by_name');
        $email = post('contact_email') ?: ($assoc['contact_email'] ?? '');

        if (!$ids) {
            flash('warning', 'Select at least one question to submit.');
            redirect('/association/question_bank.php');
        }
        // Keep only the caller's own draft questions.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $chk = db()->prepare(
            "SELECT qa.id FROM questions_association qa JOIN question_submissions s ON s.id=qa.submission_id
             WHERE qa.id IN ($in) AND s.association_id=? AND qa.status='draft' AND s.status='draft'"
        );
        $chk->execute(array_merge($ids, [$aid]));
        $validIds = array_map('intval', array_column($chk->fetchAll(), 'id'));

        if (!$validIds) {
            flash('error', 'No valid questions were selected.');
            redirect('/association/question_bank.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO question_submissions (association_id, submission_date, submitted_by_name, contact_email, status, submitted_at)
                 VALUES (?,?,?,?,\'submitted\',NOW())'
            )->execute([$aid, $date, $by, $email]);
            $newSid = (int) $pdo->lastInsertId();
            $no = 1;
            $upd = $pdo->prepare('UPDATE questions_association SET submission_id=?, question_no=?, status=\'submitted\' WHERE id=?');
            foreach ($validIds as $qid) {
                $upd->execute([$newSid, $no++, $qid]);
            }
            $pdo->commit();
            audit_log('submission_submit', 'question_submissions', $newSid, count($validIds) . ' questions');
            flash('success', count($validIds) . ' question(s) submitted to experts.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('error', 'Could not submit the selected questions.');
        }
        redirect('/association/question_bank.php');
    }
}

// -------------------- Draft questions: filter + pagination ----------------
$draft = active_draft($aid);
$fq = get('q');
$questions = [];
$total = 0;
$pages = 1;
$page = 1;
$perPage = 10;
$totalDraft = 0;

if ($draft) {
    $totalDraft = (int) db()->query(
        "SELECT COUNT(*) FROM questions_association WHERE submission_id=" . (int) $draft['id'] . " AND status='draft'"
    )->fetchColumn();

    $where = "submission_id=? AND status='draft'";
    $params = [(int) $draft['id']];
    if ($fq !== '') {
        $where .= ' AND (question_text LIKE ? OR sport LIKE ? OR category LIKE ?)';
        $like = '%' . $fq . '%';
        array_push($params, $like, $like, $like);
    }
    $countStmt = db()->prepare("SELECT COUNT(*) FROM questions_association WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $page = max(1, int_val(get('page')));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $st = db()->prepare("SELECT * FROM questions_association WHERE $where ORDER BY question_no LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    $questions = $st->fetchAll();
}

$defaultBy = $assoc['contact_person'] ?? '';
$defaultEmail = $assoc['contact_email'] ?? '';

$pageTitle = 'My Question Bank';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-1">
  <h1 class="text-2xl font-bold text-navy">My Question Bank</h1>
  <a href="<?= e(BASE_URL) ?>/association/submissions.php" class="text-sm text-teal hover:underline">View my submissions &rarr;</a>
</div>
<p class="text-gray-500 mb-6 text-sm">Add questions to your draft bank, then select the ones to submit to the experts. Manual entry only.</p>

<!-- Toolbar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
  <form method="get" class="flex gap-2 w-full sm:w-auto">
    <input name="q" value="<?= e($fq) ?>" placeholder="Search question / sport / category" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm w-full sm:w-80">
    <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Search</button>
    <?php if ($fq !== ''): ?><a href="<?= e(BASE_URL) ?>/association/question_bank.php" class="px-4 py-2 rounded-lg border border-gray-300 text-sm self-center">Clear</a><?php endif; ?>
  </form>
  <a href="<?= e(BASE_URL) ?>/association/question_form.php" class="bg-teal text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px] whitespace-nowrap inline-flex items-center">+ Add Question</a>
</div>

<div class="text-sm text-gray-500 mb-3">
  <?= $total ?> draft question<?= $total === 1 ? '' : 's' ?><?= $fq !== '' ? ' matching “' . e($fq) . '”' : '' ?>
  · <span id="selCount">0</span> selected
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto mb-4">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr>
        <th class="px-4 py-3 w-10"><input type="checkbox" id="selectAllPage" class="rounded" title="Select all on this page"></th>
        <th class="px-4 py-3">#</th>
        <th class="px-4 py-3">Question</th>
        <th class="px-4 py-3">Correct</th>
        <th class="px-4 py-3">Difficulty</th>
        <th class="px-4 py-3 text-right">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$questions): ?>
        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400"><?= $totalDraft === 0 ? 'No draft questions yet. Click "Add Question".' : 'No questions match your search.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($questions as $q): ?>
        <tr>
          <td class="px-4 py-3"><input type="checkbox" class="rowCheck rounded" value="<?= (int)$q['id'] ?>"></td>
          <td class="px-4 py-3 text-gray-500"><?= (int) $q['question_no'] ?></td>
          <td class="px-4 py-3"><div class="font-medium text-navy line-clamp-2 max-w-md"><?= e(mb_strimwidth($q['question_text'], 0, 120, '…')) ?></div><span class="text-xs text-gray-400"><?= e($q['sport']) ?><?= $q['category'] ? ' · ' . e($q['category']) : '' ?></span></td>
          <td class="px-4 py-3 font-semibold text-teal"><?= e($q['correct_option']) ?></td>
          <td class="px-4 py-3"><?= status_badge($q['difficulty']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <a href="<?= e(BASE_URL) ?>/association/question_form.php?id=<?= (int)$q['id'] ?>" class="text-navy hover:underline mr-3">Edit</a>
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

<?= paginate_links($page, $pages) ?>

<?php if ($totalDraft > 0): ?>
<div class="mt-4 mb-10">
  <button onclick="openSubmit()" class="bg-navy text-white rounded-lg px-6 py-3 font-semibold min-h-[44px]">Submit Selected to Experts</button>
  <span class="text-sm text-gray-500 ml-2">Select questions above, then submit. Submitted questions can no longer be edited.</span>
</div>
<?php endif; ?>

<!-- Submission header modal (shown only at submit time) -->
<div id="submitModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-lg p-6 my-6">
    <h2 class="text-lg font-bold text-navy mb-1">Submit to Experts</h2>
    <p id="submitCount" class="text-sm text-gray-500 mb-4"></p>
    <form method="post" id="submitForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_selected">
      <div id="submitIds"></div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1">Association name</label>
          <input value="<?= e($assoc['name']) ?>" readonly class="w-full border border-gray-200 bg-lightgrey rounded-lg px-3 py-2.5 text-gray-500">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Date</label>
          <input type="date" name="submission_date" value="<?= e(date('Y-m-d')) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Submitted by</label>
          <input name="submitted_by_name" value="<?= e($defaultBy) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Contact / email</label>
          <input name="contact_email" value="<?= e($defaultEmail) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
        </div>
      </div>
      <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-4">Submitted questions are locked and cannot be edited afterwards.</p>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('submitModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Confirm Submit</button>
      </div>
    </form>
  </div>
</div>

<script>
  // ---- selection ----
  function selectedIds(){ return [...document.querySelectorAll('.rowCheck:checked')].map(c => c.value); }
  function refreshCount(){ document.getElementById('selCount').textContent = selectedIds().length; }
  document.querySelectorAll('.rowCheck').forEach(c => c.addEventListener('change', refreshCount));
  const selAll = document.getElementById('selectAllPage');
  if (selAll) selAll.addEventListener('change', function(){
    document.querySelectorAll('.rowCheck').forEach(c => c.checked = this.checked);
    refreshCount();
  });

  function openSubmit(){
    const ids = selectedIds();
    if (ids.length === 0){ alert('Please select at least one question to submit.'); return; }
    const box = document.getElementById('submitIds');
    box.innerHTML = '';
    ids.forEach(id => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = 'question_ids[]'; i.value = id;
      box.appendChild(i);
    });
    document.getElementById('submitCount').textContent = ids.length + ' question(s) will be submitted to the experts.';
    document.getElementById('submitModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
