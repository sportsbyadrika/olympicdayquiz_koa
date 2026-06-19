<?php
/**
 * School dashboard: profile, participants, assigned slots with timing, quiz
 * status per slot, and result links (post-declaration).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quiz.php';
require_role('school');

$school = current_profile();
if (!$school) { redirect('/public/logout.php'); }
$schoolId = (int) $school['id'];

$slots = school_slots($schoolId);
$now = server_now();

$pageTitle = 'School Dashboard';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1"><?= e($school['name']) ?></h1>
<p class="text-gray-500 mb-6 text-sm">
  Code: <?= e($school['code']) ?>
  · Participants: <?= e($school['participant1_name'] ?: '—') ?><?= $school['participant2_name'] ? ' &amp; ' . e($school['participant2_name']) : '' ?>
</p>

<?php $announcement = trim((string) setting('school_message', '')); ?>
<?php if ($announcement !== ''): ?>
  <div class="bg-teal/10 border border-teal/30 rounded-xl p-4 mb-6">
    <div class="flex items-start gap-3">
      <span class="text-xl">📢</span>
      <div>
        <div class="font-semibold text-navy text-sm mb-1">Announcement</div>
        <div class="text-sm text-gray-700 whitespace-pre-line"><?= e($announcement) ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ((string) setting('show_round1_to_school', '0') === '1'):
  $r1 = school_round_summary($schoolId, 1);
  $statusCls = $r1['status'] === 'Qualified' ? 'bg-green-100 text-green-800'
      : ($r1['status'] === 'Absent' ? 'bg-gray-200 text-gray-600' : 'bg-amber-100 text-amber-800');
?>
  <h2 class="font-semibold text-navy mb-3">Your Round 1 Result</h2>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto mb-8">
    <table class="min-w-full text-sm">
      <thead class="bg-lightgrey text-gray-600 text-left">
        <tr>
          <th class="px-4 py-3">Started / Completed</th>
          <th class="px-4 py-3">Duration</th>
          <th class="px-4 py-3">% Marks</th>
          <th class="px-4 py-3">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="px-4 py-3 text-gray-600 text-xs">
            <?php if ($r1['attempted']): ?>
              <div><span class="text-gray-400">Start:</span> <?= e(date('d M Y, H:i:s', strtotime((string)$r1['start']))) ?></div>
              <div><span class="text-gray-400">End:</span> <?= e(date('d M Y, H:i:s', strtotime((string)$r1['end']))) ?></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-4 py-3 text-gray-600"><?= $r1['attempted'] ? e($r1['duration']) : '—' ?></td>
          <td class="px-4 py-3 font-bold text-navy"><?= $r1['attempted'] ? number_format((float)$r1['pct'], 2) . '%' : '—' ?></td>
          <td class="px-4 py-3"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $statusCls ?>"><?= e($r1['status']) ?></span></td>
        </tr>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2 class="font-semibold text-navy mb-3">Your Quiz Slots</h2>

<?php if (!$slots): ?>
  <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400">
    You have not been assigned to any slot yet. Please check back later.
  </div>
<?php endif; ?>

<div class="grid md:grid-cols-2 gap-4">
  <?php foreach ($slots as $s):
    $start = strtotime((string) $s['start_time']);
    $end = strtotime((string) $s['end_time']);
    $windowOpen = ($now >= $start && $now <= $end);
    $declared = result_declared($schoolId, (int) $s['round_id']);
    $status = $s['session_status'] ?? null;

    // Derive a friendly status.
    if ($status === 'submitted' || $status === 'force_submitted') {
        $label = $declared ? 'Result declared' : 'Submitted · result pending';
        $badge = $declared ? 'declared' : 'pending';
    } elseif ($status === 'in_progress') {
        $label = 'In progress';
        $badge = 'in_progress';
    } elseif ($now < $start) {
        $label = 'Not started';
        $badge = 'not_started';
    } elseif ($windowOpen) {
        $label = 'Ready to start';
        $badge = 'pending';
    } else {
        $label = 'Window closed';
        $badge = 'inactive';
    }
  ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="text-xs font-semibold text-teal"><?= e($s['round_name']) ?></div>
          <div class="font-semibold text-navy"><?= e($s['slot_name']) ?></div>
        </div>
        <?= status_badge($badge) ?>
      </div>
      <div class="text-sm text-gray-500 mt-2">
        <?= e(date('d M Y, H:i', $start)) ?> – <?= e(date('H:i', $end)) ?><br>
        Quiz duration: <?= (int)$s['quiz_duration_min'] ?> min · <?= (int)$s['question_total'] ?> questions
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        <?php if (($status === 'submitted' || $status === 'force_submitted')): ?>
          <?php if ($declared): ?>
            <a href="<?= e(BASE_URL) ?>/school/result.php?slot_id=<?= (int)$s['id'] ?>" class="bg-teal text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">View Result</a>
          <?php else: ?>
            <span class="text-sm text-gray-500 px-1 py-2">Result will be declared after expert review.</span>
          <?php endif; ?>
        <?php elseif ($status === 'in_progress'): ?>
          <a href="<?= e(BASE_URL) ?>/school/quiz.php?slot_id=<?= (int)$s['id'] ?>" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">Resume Quiz</a>
        <?php elseif ($windowOpen && (int)$s['question_total'] > 0): ?>
          <a href="<?= e(BASE_URL) ?>/school/quiz.php?slot_id=<?= (int)$s['id'] ?>" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">Start Quiz</a>
        <?php elseif ($now < $start): ?>
          <span class="text-sm text-gray-400 px-1 py-2">Opens <?= e(date('d M, H:i', $start)) ?></span>
        <?php else: ?>
          <span class="text-sm text-gray-400 px-1 py-2">This slot window has closed.</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
