<?php
/**
 * School · Result review screen.
 * Visible only after the expert declares the round result. Shows each question
 * with all four options, the participant's choice highlighted, the correct
 * option marked, and the explanation (if any). Before declaration, the school
 * sees a "pending" message.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quiz.php';
require_role('school');

$school = current_profile();
if (!$school) { redirect('/public/logout.php'); }
$schoolId = (int) $school['id'];
$slotId = int_val(get('slot_id'));

$slot = get_slot($slotId);
if (!$slot || !school_in_slot($schoolId, $slotId)) {
    flash('error', 'Result not available.');
    redirect('/school/index.php');
}
$roundId = (int) $slot['round_id'];

$session = get_session($schoolId, $slotId);
$declared = result_declared($schoolId, $roundId);

$pageTitle = 'Quiz Result';
require dirname(__DIR__) . '/includes/header.php';
?>
<a href="<?= e(BASE_URL) ?>/school/index.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to dashboard</a>
<h1 class="text-2xl font-bold text-navy mt-2 mb-1">Result — <?= e($slot['slot_name']) ?></h1>
<p class="text-gray-500 mb-6 text-sm">Round <?= (int)$slot['round_no'] ?></p>

<?php if (!$declared || !$session): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-8 text-center">
    <div class="text-2xl mb-2">⏳</div>
    <div class="font-semibold text-amber-800">Result will be declared after expert review.</div>
    <p class="text-sm text-amber-700 mt-1">Please check back later.</p>
  </div>
<?php else:
  // Load result row + per-question review.
  $rstmt = db()->prepare('SELECT * FROM results WHERE school_id=? AND round_id=?');
  $rstmt->execute([$schoolId, $roundId]);
  $result = $rstmt->fetch();

  $qstmt = db()->prepare(
    'SELECT qm.*, sq.sequence_no, rsp.selected_option
     FROM slot_questions sq
     JOIN questions_master qm ON qm.id = sq.question_id
     LEFT JOIN responses rsp ON rsp.question_id = qm.id AND rsp.session_id = ?
     WHERE sq.slot_id = ? ORDER BY sq.sequence_no, sq.id'
  );
  $qstmt->execute([(int) $session['id'], $slotId]);
  $rows = $qstmt->fetchAll();

  $score = (int) ($result['score'] ?? 0);
  $totalQ = (int) ($result['total_questions'] ?? count($rows));
?>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
      <div class="text-sm text-gray-500">Your score</div>
      <div class="text-3xl font-extrabold text-navy"><?= $score ?> <span class="text-lg text-gray-400">/ <?= $totalQ ?></span></div>
    </div>
    <?php if ((int) ($result['qualified_for_next'] ?? 0) === 1): ?>
      <span class="px-3 py-1.5 rounded-full bg-teal/10 text-teal font-medium">Qualified for next round 🎉</span>
    <?php endif; ?>
  </div>

  <div class="space-y-4">
    <?php foreach ($rows as $i => $q):
      $sel = $q['selected_option'];
      $correct = $q['correct_option'];
    ?>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-start justify-between gap-2 mb-3">
          <div class="font-semibold text-navy">Q<?= $i + 1 ?>. <?= e($q['question_text']) ?></div>
          <?php if ($sel === $correct): ?>
            <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800 shrink-0">Correct</span>
          <?php elseif ($sel === null): ?>
            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 shrink-0">Not answered</span>
          <?php else: ?>
            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800 shrink-0">Incorrect</span>
          <?php endif; ?>
        </div>
        <div class="grid sm:grid-cols-2 gap-2 text-sm">
          <?php foreach (['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $L => $col):
            $isCorrect = ($L === $correct);
            $isChosen = ($L === $sel);
            $cls = 'border-gray-200';
            if ($isCorrect) $cls = 'border-green-400 bg-green-50';
            elseif ($isChosen) $cls = 'border-red-400 bg-red-50';
          ?>
            <div class="px-3 py-2 rounded-lg border <?= $cls ?>">
              <span class="font-semibold mr-1"><?= $L ?>.</span><?= e($q[$col]) ?>
              <?php if ($isCorrect): ?><span class="text-green-700 text-xs font-medium ml-1">✓ correct</span><?php endif; ?>
              <?php if ($isChosen && !$isCorrect): ?><span class="text-red-700 text-xs font-medium ml-1">your choice</span><?php endif; ?>
              <?php if ($isChosen && $isCorrect): ?><span class="text-green-700 text-xs font-medium ml-1">your choice</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($q['explanation'])): ?>
          <div class="mt-3 text-sm text-gray-600 bg-lightgrey rounded-lg px-3 py-2">
            <span class="font-medium text-navy">Explanation:</span> <?= e($q['explanation']) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
