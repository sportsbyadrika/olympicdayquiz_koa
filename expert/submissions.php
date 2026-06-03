<?php
/**
 * Expert · Submission queue + review.
 * Filter by association/date/status. Open a submission to accept (copy into
 * master with traceability), reject (with reason), per question.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

$expert = current_profile();
$expertId = $expert['id'] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');
    $qid = int_val(post('question_id'));

    // Load the source question (must be submitted/accepted/rejected, not draft).
    $st = db()->prepare(
        'SELECT qa.*, s.association_id FROM questions_association qa
         JOIN question_submissions s ON s.id = qa.submission_id WHERE qa.id = ?'
    );
    $st->execute([$qid]);
    $src = $st->fetch();

    if ($src && in_array($action, ['accept', 'reject'], true)) {
        if ($action === 'accept') {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Avoid double-copy: only insert if not already linked.
                $chk = $pdo->prepare('SELECT id FROM questions_master WHERE source_question_id = ? LIMIT 1');
                $chk->execute([$qid]);
                if (!$chk->fetch()) {
                    $sround = post('suggested_round', 'either');
                    if (!in_array($sround, ['round1','round2','either'], true)) $sround = 'either';
                    $ins = $pdo->prepare(
                        'INSERT INTO questions_master
                         (question_text, option_a, option_b, option_c, option_d, correct_option, sport, category,
                          difficulty, reference_source, explanation, suggested_round, source_association_id, source_question_id, created_by_expert_id)
                         VALUES (:qt,:a,:b,:c,:d,:co,:sport,:cat,:diff,:ref,:exp,:sr,:said,:sqid,:eid)'
                    );
                    $ins->execute([
                        ':qt' => $src['question_text'], ':a' => $src['option_a'], ':b' => $src['option_b'],
                        ':c' => $src['option_c'], ':d' => $src['option_d'], ':co' => $src['correct_option'],
                        ':sport' => $src['sport'], ':cat' => $src['category'], ':diff' => $src['difficulty'],
                        ':ref' => $src['reference_source'], ':exp' => $src['explanation'], ':sr' => $sround,
                        ':said' => $src['association_id'], ':sqid' => $qid, ':eid' => $expertId,
                    ]);
                }
                $pdo->prepare("UPDATE questions_association SET status='accepted', reject_reason=NULL WHERE id=?")->execute([$qid]);
                $pdo->commit();
                audit_log('question_accept', 'questions_association', $qid);
                flash('success', 'Question accepted into the master bank.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Could not accept question.');
            }
        } else { // reject
            $reason = post('reject_reason');
            db()->prepare("UPDATE questions_association SET status='rejected', reject_reason=:r WHERE id=:id")
                ->execute([':r' => $reason, ':id' => $qid]);
            audit_log('question_reject', 'questions_association', $qid, $reason);
            flash('success', 'Question rejected.');
        }
    }
    redirect('/expert/submissions.php?submission_id=' . int_val(post('submission_id')));
}

$submissionId = int_val(get('submission_id'));

// ---- Detail view -------------------------------------------------------
if ($submissionId) {
    $st = db()->prepare(
        'SELECT s.*, a.name AS association_name FROM question_submissions s
         JOIN associations a ON a.id = s.association_id WHERE s.id = ?'
    );
    $st->execute([$submissionId]);
    $sub = $st->fetch();
    if (!$sub) { flash('error', 'Submission not found.'); redirect('/expert/submissions.php'); }

    $st = db()->prepare('SELECT * FROM questions_association WHERE submission_id = ? ORDER BY question_no');
    $st->execute([$submissionId]);
    $questions = $st->fetchAll();

    $pageTitle = 'Review Submission';
    require dirname(__DIR__) . '/includes/header.php';
    ?>
    <a href="<?= e(BASE_URL) ?>/expert/submissions.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to queue</a>
    <h1 class="text-2xl font-bold text-navy mt-2 mb-1"><?= e($sub['association_name']) ?></h1>
    <p class="text-gray-500 mb-6 text-sm">Submitted <?= e(date('d M Y', strtotime((string)$sub['submission_date']))) ?> · by <?= e($sub['submitted_by_name']) ?> · <?= count($questions) ?> questions</p>

    <div class="space-y-4">
      <?php foreach ($questions as $q): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="font-semibold text-navy">Q<?= (int)$q['question_no'] ?>. <?= e($q['question_text']) ?></div>
            <div><?= status_badge($q['status']) ?></div>
          </div>
          <div class="grid sm:grid-cols-2 gap-2 text-sm mb-3">
            <?php foreach (['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $L=>$col): ?>
              <div class="px-3 py-2 rounded-lg border <?= $q['correct_option']===$L ? 'border-teal bg-teal/5 text-teal font-medium' : 'border-gray-200' ?>">
                <span class="font-semibold mr-1"><?= $L ?>.</span><?= e($q[$col]) ?><?= $q['correct_option']===$L ? ' ✓' : '' ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="text-xs text-gray-500 mb-3">
            <?= $q['sport'] ? 'Sport: ' . e($q['sport']) . ' · ' : '' ?>
            <?= $q['category'] ? 'Category: ' . e($q['category']) . ' · ' : '' ?>
            Difficulty: <?= e($q['difficulty']) ?>
            <?= $q['reference_source'] ? ' · Ref: ' . e($q['reference_source']) : '' ?>
          </div>
          <?php if ($q['explanation']): ?><div class="text-xs text-gray-500 mb-3 italic">Explanation: <?= e($q['explanation']) ?></div><?php endif; ?>
          <?php if ($q['status'] === 'rejected' && $q['reject_reason']): ?>
            <div class="text-xs text-red-600 mb-3">Rejected: <?= e($q['reject_reason']) ?></div>
          <?php endif; ?>

          <?php if ($q['status'] === 'submitted'): ?>
            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
              <form method="post" class="flex items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <input type="hidden" name="submission_id" value="<?= $submissionId ?>">
                <select name="suggested_round" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                  <option value="either">Either round</option>
                  <option value="round1">Round 1</option>
                  <option value="round2">Round 2</option>
                </select>
                <button class="bg-teal text-white rounded-lg px-3 py-1.5 text-sm font-medium">Accept</button>
              </form>
              <button onclick="document.getElementById('rej<?= (int)$q['id'] ?>').classList.toggle('hidden')" class="bg-white border border-red-300 text-red-600 rounded-lg px-3 py-1.5 text-sm font-medium">Reject</button>
            </div>
            <form method="post" id="rej<?= (int)$q['id'] ?>" class="hidden mt-2 flex gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="submission_id" value="<?= $submissionId ?>">
              <input name="reject_reason" placeholder="Reason for rejection" required class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
              <button class="bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">Confirm Reject</button>
            </form>
          <?php elseif ($q['status'] === 'accepted'): ?>
            <div class="text-sm text-teal border-t border-gray-100 pt-3">Accepted — edit it in the <a href="<?= e(BASE_URL) ?>/expert/master_questions.php" class="underline">Master Bank</a>.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    require dirname(__DIR__) . '/includes/footer.php';
    exit;
}

// ---- Queue list --------------------------------------------------------
$fAssoc = int_val(get('association_id'));
$fStatus = get('status');
$fDate = get('date');

$sql = 'SELECT s.*, a.name AS association_name,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id) AS total_q,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id AND q.status=\'accepted\') AS accepted_q,
        (SELECT COUNT(*) FROM questions_association q WHERE q.submission_id=s.id AND q.status=\'submitted\') AS pending_q
        FROM question_submissions s JOIN associations a ON a.id=s.association_id WHERE 1=1';
$params = [];
if ($fAssoc)  { $sql .= ' AND s.association_id = :aid'; $params[':aid'] = $fAssoc; }
if ($fDate !== '') { $sql .= ' AND s.submission_date = :d'; $params[':d'] = $fDate; }
if ($fStatus === 'submitted' || $fStatus === 'draft') { $sql .= ' AND s.status = :st'; $params[':st'] = $fStatus; }
else { $sql .= " AND s.status = 'submitted'"; }
$sql .= ' ORDER BY s.submitted_at DESC, s.id DESC';
$st = db()->prepare($sql);
$st->execute($params);
$subs = $st->fetchAll();

$assocs = db()->query('SELECT id, name FROM associations ORDER BY name')->fetchAll();

$pageTitle = 'Submissions';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-4">Submission Queue</h1>

<form method="get" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6 grid sm:grid-cols-4 gap-3">
  <select name="association_id" class="border border-gray-300 rounded-lg px-3 py-2.5">
    <option value="">All associations</option>
    <?php foreach ($assocs as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $fAssoc===(int)$a['id']?'selected':'' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
  </select>
  <input type="date" name="date" value="<?= e($fDate) ?>" class="border border-gray-300 rounded-lg px-3 py-2.5">
  <select name="status" class="border border-gray-300 rounded-lg px-3 py-2.5">
    <option value="submitted" <?= $fStatus==='submitted'?'selected':'' ?>>Submitted</option>
  </select>
  <button class="bg-navy text-white rounded-lg px-4 py-2.5 text-sm font-medium">Filter</button>
</form>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr><th class="px-4 py-3">Association</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Submitted by</th><th class="px-4 py-3">Progress</th><th class="px-4 py-3 text-right">Action</th></tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$subs): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No submissions.</td></tr><?php endif; ?>
      <?php foreach ($subs as $s): ?>
        <tr>
          <td class="px-4 py-3 font-medium text-navy"><?= e($s['association_name']) ?></td>
          <td class="px-4 py-3"><?= e(date('d M Y', strtotime((string)$s['submission_date']))) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($s['submitted_by_name']) ?></td>
          <td class="px-4 py-3 text-gray-600 text-xs"><?= (int)$s['accepted_q'] ?> accepted · <?= (int)$s['pending_q'] ?> pending / <?= (int)$s['total_q'] ?></td>
          <td class="px-4 py-3 text-right"><a href="<?= e(BASE_URL) ?>/expert/submissions.php?submission_id=<?= (int)$s['id'] ?>" class="text-teal font-medium hover:underline">Review &rarr;</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
