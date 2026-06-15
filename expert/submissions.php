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
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $action = post('action');
    $qid = int_val(post('question_id'));

    // Load the source question (must be submitted/accepted/rejected, not draft).
    $st = db()->prepare(
        'SELECT qa.*, s.association_id FROM questions_association qa
         JOIN question_submissions s ON s.id = qa.submission_id WHERE qa.id = ?'
    );
    $st->execute([$qid]);
    $src = $st->fetch();

    $ok = false;
    $message = 'Invalid request.';
    $newStatus = null;
    $reason = null;

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
                $ok = true;
                $newStatus = 'accepted';
                $message = 'Question accepted into the master bank.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Could not accept question.';
            }
        } else { // reject
            $reason = post('reject_reason');
            db()->prepare("UPDATE questions_association SET status='rejected', reject_reason=:r WHERE id=:id")
                ->execute([':r' => $reason, ':id' => $qid]);
            audit_log('question_reject', 'questions_association', $qid, $reason);
            $ok = true;
            $newStatus = 'rejected';
            $message = 'Question rejected.';
        }
    }

    if ($isAjax) {
        json_response(['ok' => $ok, 'status' => $newStatus, 'message' => $message, 'reason' => $reason]);
    }
    flash($ok ? 'success' : 'error', $message);
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

    // Progress filter: all / pending (submitted) / accepted / rejected.
    $qstatus = get('qstatus');
    $statusMap = ['pending' => 'submitted', 'accepted' => 'accepted', 'rejected' => 'rejected'];
    $where = 'submission_id = ?';
    $params = [$submissionId];
    if (isset($statusMap[$qstatus])) {
        $where .= ' AND status = ?';
        $params[] = $statusMap[$qstatus];
    }
    $st = db()->prepare("SELECT * FROM questions_association WHERE $where ORDER BY question_no");
    $st->execute($params);
    $questions = $st->fetchAll();

    // Counts per status for the filter chips.
    $counts = ['all' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0];
    $cstmt = db()->prepare('SELECT status, COUNT(*) c FROM questions_association WHERE submission_id=? GROUP BY status');
    $cstmt->execute([$submissionId]);
    foreach ($cstmt->fetchAll() as $cr) {
        $counts['all'] += (int) $cr['c'];
        if ($cr['status'] === 'submitted') $counts['pending'] += (int) $cr['c'];
        elseif ($cr['status'] === 'accepted') $counts['accepted'] += (int) $cr['c'];
        elseif ($cr['status'] === 'rejected') $counts['rejected'] += (int) $cr['c'];
    }

    $pageTitle = 'Review Submission';
    require dirname(__DIR__) . '/includes/header.php';
    ?>
    <a href="<?= e(BASE_URL) ?>/expert/submissions.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to queue</a>
    <h1 class="text-2xl font-bold text-navy mt-2 mb-1"><?= e($sub['association_name']) ?></h1>
    <p class="text-gray-500 mb-4 text-sm">Submitted <?= e(date('d M Y', strtotime((string)$sub['submission_date']))) ?> · by <?= e($sub['submitted_by_name']) ?> · <?= (int)$counts['all'] ?> questions</p>

    <?php
    $chips = ['all' => 'All', 'pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected'];
    $activeChip = isset($statusMap[$qstatus]) ? $qstatus : 'all';
    ?>
    <div class="flex flex-wrap gap-2 mb-6">
      <?php foreach ($chips as $key => $label):
        $url = BASE_URL . '/expert/submissions.php?submission_id=' . $submissionId . ($key === 'all' ? '' : '&qstatus=' . $key);
        $active = $activeChip === $key;
      ?>
        <a href="<?= e($url) ?>" class="px-3 py-1.5 rounded-full text-sm font-medium <?= $active ? 'bg-navy text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-lightgrey' ?>">
          <?= e($label) ?> <span class="<?= $active ? 'text-white/80' : 'text-gray-400' ?>">(<?= (int)$counts[$key] ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="space-y-4">
      <?php if (!$questions): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400">No questions in this view.</div>
      <?php endif; ?>
      <?php foreach ($questions as $q): $qidInt = (int)$q['id']; ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5" id="card-<?= $qidInt ?>">
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="font-semibold text-navy">Q<?= (int)$q['question_no'] ?>. <?= e($q['question_text']) ?></div>
            <div id="badge-<?= $qidInt ?>"><?= status_badge($q['status']) ?></div>
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
          <div id="rejreason-<?= $qidInt ?>" class="text-xs text-red-600 mb-3 <?= ($q['status'] === 'rejected' && $q['reject_reason']) ? '' : 'hidden' ?>">Rejected: <span class="rej-text"><?= e($q['reject_reason'] ?? '') ?></span></div>

          <div id="actions-<?= $qidInt ?>">
            <?php if ($q['status'] === 'submitted'): ?>
              <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                <form method="post" class="reviewForm flex items-center gap-2" data-qid="<?= $qidInt ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="accept">
                  <input type="hidden" name="question_id" value="<?= $qidInt ?>">
                  <input type="hidden" name="submission_id" value="<?= $submissionId ?>">
                  <select name="suggested_round" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                    <option value="either">Either round</option>
                    <option value="round1">Round 1</option>
                    <option value="round2">Round 2</option>
                  </select>
                  <button class="bg-teal text-white rounded-lg px-3 py-1.5 text-sm font-medium">Accept</button>
                </form>
                <button type="button" onclick="document.getElementById('rej<?= $qidInt ?>').classList.toggle('hidden')" class="bg-white border border-red-300 text-red-600 rounded-lg px-3 py-1.5 text-sm font-medium">Reject</button>
              </div>
              <form method="post" id="rej<?= $qidInt ?>" class="reviewForm hidden mt-2 flex gap-2" data-qid="<?= $qidInt ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="question_id" value="<?= $qidInt ?>">
                <input type="hidden" name="submission_id" value="<?= $submissionId ?>">
                <input name="reject_reason" placeholder="Reason for rejection" required class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                <button class="bg-red-600 text-white rounded-lg px-3 py-1.5 text-sm">Confirm Reject</button>
              </form>
            <?php elseif ($q['status'] === 'accepted'): ?>
              <div class="text-sm text-teal border-t border-gray-100 pt-3">Accepted — edit it in the <a href="<?= e(BASE_URL) ?>/expert/master_questions.php" class="underline">Master Bank</a>.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="reviewToast" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-50 bg-navy text-white text-sm rounded-lg px-4 py-2 shadow-lg"></div>

    <script>
      const API = '<?= e(BASE_URL) ?>/expert/submissions.php';
      const CSRF = '<?= e(csrf_token()) ?>';

      function toast(msg, ok) {
        const t = document.getElementById('reviewToast');
        t.textContent = msg;
        t.classList.remove('hidden', 'bg-navy', 'bg-red-600');
        t.classList.add(ok ? 'bg-navy' : 'bg-red-600');
        clearTimeout(t._t);
        t._t = setTimeout(() => t.classList.add('hidden'), 2500);
      }
      function badgeHtml(status) {
        const map = { accepted: 'bg-green-100 text-green-800', rejected: 'bg-red-100 text-red-800' };
        const label = status.charAt(0).toUpperCase() + status.slice(1);
        return '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium ' + (map[status] || 'bg-gray-100 text-gray-700') + '">' + label + '</span>';
      }

      document.querySelectorAll('form.reviewForm').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          const qid = form.dataset.qid;
          const btn = form.querySelector('button[type=submit], button:not([type])');
          if (btn) { btn.disabled = true; btn.classList.add('opacity-60'); }
          fetch(API, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
          }).then(r => r.json()).then(function (d) {
            if (!d.ok) { toast(d.message || 'Action failed.', false); if (btn) { btn.disabled = false; btn.classList.remove('opacity-60'); } return; }
            document.getElementById('badge-' + qid).innerHTML = badgeHtml(d.status);
            const actions = document.getElementById('actions-' + qid);
            if (d.status === 'accepted') {
              actions.innerHTML = '<div class="text-sm text-teal border-t border-gray-100 pt-3">Accepted — edit it in the <a href="<?= e(BASE_URL) ?>/expert/master_questions.php" class="underline">Master Bank</a>.</div>';
            } else if (d.status === 'rejected') {
              actions.innerHTML = '';
              const rr = document.getElementById('rejreason-' + qid);
              rr.querySelector('.rej-text').textContent = d.reason || '';
              rr.classList.remove('hidden');
            }
            toast(d.message || 'Done.', true);
          }).catch(function () {
            toast('Network error. Please retry.', false);
            if (btn) { btn.disabled = false; btn.classList.remove('opacity-60'); }
          });
        });
      });
    </script>
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
