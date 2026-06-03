<?php
/**
 * Association · My Submissions.
 * Lists past (submitted) batches. Clicking "view" opens that submission's
 * questions and answers as a card-based table, with a question search filter
 * and the expert's accept/reject status per question.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('association');

$assoc = current_profile();
if (!$assoc) { redirect('/public/logout.php'); }
$aid = (int) $assoc['id'];

$submissionId = int_val(get('id'));

// -------------------- Detail view --------------------
if ($submissionId) {
    $st = db()->prepare('SELECT * FROM question_submissions WHERE id=? AND association_id=? LIMIT 1');
    $st->execute([$submissionId, $aid]);
    $sub = $st->fetch();
    if (!$sub) {
        flash('error', 'Submission not found.');
        redirect('/association/submissions.php');
    }

    $fq = get('q');
    $where = 'submission_id = ?';
    $params = [$submissionId];
    if ($fq !== '') {
        $where .= ' AND (question_text LIKE ? OR sport LIKE ? OR category LIKE ?)';
        $like = '%' . $fq . '%';
        array_push($params, $like, $like, $like);
    }
    $qs = db()->prepare("SELECT * FROM questions_association WHERE $where ORDER BY question_no");
    $qs->execute($params);
    $questions = $qs->fetchAll();

    $pageTitle = 'Submission details';
    require dirname(__DIR__) . '/includes/header.php';
    ?>
    <a href="<?= e(BASE_URL) ?>/association/submissions.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to my submissions</a>
    <h1 class="text-2xl font-bold text-navy mt-2 mb-1">Submission · <?= e(date('d M Y', strtotime((string)$sub['submission_date']))) ?></h1>
    <p class="text-gray-500 mb-5 text-sm">
      Submitted by <?= e($sub['submitted_by_name'] ?: '—') ?>
      <?= $sub['submitted_at'] ? ' · ' . e(date('d M Y, H:i', strtotime((string)$sub['submitted_at']))) : '' ?>
    </p>

    <form method="get" class="mb-5 flex gap-2">
      <input type="hidden" name="id" value="<?= $submissionId ?>">
      <input name="q" value="<?= e($fq) ?>" placeholder="Search question / sport / category" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm w-full sm:w-96">
      <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Search</button>
      <?php if ($fq !== ''): ?><a href="<?= e(BASE_URL) ?>/association/submissions.php?id=<?= $submissionId ?>" class="px-4 py-2 rounded-lg border border-gray-300 text-sm self-center">Clear</a><?php endif; ?>
    </form>

    <div class="grid gap-4">
      <?php if (!$questions): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400">No questions match.</div>
      <?php endif; ?>
      <?php foreach ($questions as $q): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="font-semibold text-navy">Q<?= (int)$q['question_no'] ?>. <?= e($q['question_text']) ?></div>
            <div class="shrink-0"><?= status_badge($q['status']) ?></div>
          </div>
          <div class="grid sm:grid-cols-2 gap-2 text-sm">
            <?php foreach (['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $L => $col):
              $isCorrect = ($q['correct_option'] === $L); ?>
              <div class="px-3 py-2 rounded-lg border <?= $isCorrect ? 'border-teal bg-teal/5 text-teal font-medium' : 'border-gray-200' ?>">
                <span class="font-semibold mr-1"><?= $L ?>.</span><?= e($q[$col]) ?><?= $isCorrect ? ' &#10003;' : '' ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="text-xs text-gray-500 mt-3">
            <?= $q['sport'] ? 'Sport: ' . e($q['sport']) . ' · ' : '' ?>
            <?= $q['category'] ? 'Category: ' . e($q['category']) . ' · ' : '' ?>
            Difficulty: <?= e($q['difficulty']) ?>
            <?= $q['reference_source'] ? ' · Ref: ' . e($q['reference_source']) : '' ?>
          </div>
          <?php if (!empty($q['explanation'])): ?>
            <div class="text-xs text-gray-500 mt-2 italic">Explanation: <?= e($q['explanation']) ?></div>
          <?php endif; ?>
          <?php if ($q['status'] === 'rejected' && !empty($q['reject_reason'])): ?>
            <div class="text-xs text-red-600 mt-2">Rejected: <?= e($q['reject_reason']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php
    require dirname(__DIR__) . '/includes/footer.php';
    exit;
}

// -------------------- List view (with pagination) --------------------
$perPage = 15;
$page = max(1, int_val(get('page')));
$cnt = db()->prepare('SELECT COUNT(*) FROM question_submissions WHERE association_id=? AND status=\'submitted\'');
$cnt->execute([$aid]);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$st = db()->prepare(
    "SELECT s.*,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id) AS total_q,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id AND q.status='accepted') AS accepted_q,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id AND q.status='rejected') AS rejected_q
     FROM question_submissions s
     WHERE s.association_id=? AND s.status='submitted'
     ORDER BY s.submitted_at DESC, s.id DESC
     LIMIT $perPage OFFSET $offset"
);
$st->execute([$aid]);
$subs = $st->fetchAll();

$pageTitle = 'My Submissions';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-1">
  <h1 class="text-2xl font-bold text-navy">My Submissions</h1>
  <a href="<?= e(BASE_URL) ?>/association/question_bank.php" class="text-sm text-teal hover:underline">&larr; Back to question bank</a>
</div>
<p class="text-gray-500 mb-6 text-sm"><?= $total ?> submitted batch<?= $total === 1 ? '' : 'es' ?>.</p>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr>
        <th class="px-4 py-3">Date</th>
        <th class="px-4 py-3">Submitted by</th>
        <th class="px-4 py-3">Questions</th>
        <th class="px-4 py-3">Review status</th>
        <th class="px-4 py-3 text-right">View</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$subs): ?>
        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">You have not submitted any questions yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($subs as $s): ?>
        <tr>
          <td class="px-4 py-3 text-navy font-medium"><?= e(date('d M Y', strtotime((string)$s['submission_date']))) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($s['submitted_by_name'] ?: '—') ?></td>
          <td class="px-4 py-3 text-gray-600"><?= (int)$s['total_q'] ?></td>
          <td class="px-4 py-3 text-xs text-gray-600">
            <span class="text-green-700"><?= (int)$s['accepted_q'] ?> accepted</span> ·
            <span class="text-red-600"><?= (int)$s['rejected_q'] ?> rejected</span> ·
            <span class="text-gray-500"><?= max(0, (int)$s['total_q'] - (int)$s['accepted_q'] - (int)$s['rejected_q']) ?> pending</span>
          </td>
          <td class="px-4 py-3 text-right">
            <a href="<?= e(BASE_URL) ?>/association/submissions.php?id=<?= (int)$s['id'] ?>" title="View questions" class="inline-flex text-teal hover:opacity-70">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?= paginate_links($page, $pages) ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
