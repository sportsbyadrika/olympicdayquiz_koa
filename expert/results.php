<?php
/**
 * Expert · Results, Round 2 qualifier selection, and result declaration.
 * - Review each round's results per school.
 * - Mark qualifying teams (Round 1 → Round 2).
 * - Declare a round's results: until declared, schools see only "pending".
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'toggle_qualify') {
        $resultId = int_val(post('result_id'));
        if ($resultId) {
            db()->prepare('UPDATE results SET qualified_for_next = 1 - qualified_for_next WHERE id=?')->execute([$resultId]);
            audit_log('result_toggle_qualify', 'results', $resultId);
        }
        redirect('/expert/results.php#round1');
    }

    if ($action === 'declare_round') {
        $roundId = int_val(post('round_id'));
        if ($roundId) {
            db()->prepare('UPDATE results SET declared=1, declared_at=NOW() WHERE round_id=? AND declared=0')->execute([$roundId]);
            audit_log('result_declare', 'rounds', $roundId);
            flash('success', 'Results declared for the round. Schools can now view them.');
        }
        redirect('/expert/results.php');
    }
}

/** Fetch results for a round number, joined with school + session info. */
function round_results(int $roundNo): array
{
    $st = db()->prepare(
        'SELECT res.*, s.name AS school_name, s.code, r.id AS round_id,
                sl.slot_name, qs.status AS session_status,
                qs.start_time AS session_start, qs.end_time AS session_end,
                (SELECT COUNT(*) FROM responses rsp WHERE rsp.session_id = res.session_id AND rsp.selected_option IS NOT NULL) AS attended
         FROM rounds r
         JOIN results res ON res.round_id = r.id
         JOIN schools s ON s.id = res.school_id
         LEFT JOIN quiz_sessions qs ON qs.id = res.session_id
         LEFT JOIN slots sl ON sl.id = qs.slot_id
         WHERE r.round_no = ?
         ORDER BY res.score DESC, s.name'
    );
    $st->execute([$roundNo]);
    return $st->fetchAll();
}

function round_id_by_no(int $no): int
{
    $st = db()->prepare('SELECT id FROM rounds WHERE round_no=?');
    $st->execute([$no]);
    return (int) $st->fetchColumn();
}

/** Human-friendly duration between two datetimes, or '—'. */
function fmt_duration(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $secs = strtotime($end) - strtotime($start);
    if ($secs < 0) {
        return '—';
    }
    $m = intdiv($secs, 60);
    $s = $secs % 60;
    return $m . 'm ' . $s . 's';
}

$pageTitle = 'Results';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-6">Results & Declaration</h1>

<?php foreach ([1, 2] as $rno):
  $results = round_results($rno);
  $roundId = round_id_by_no($rno);
  $declaredCount = count(array_filter($results, fn($r) => (int) $r['declared'] === 1));
  $allDeclared = $results && $declaredCount === count($results);
?>
  <section id="round<?= $rno ?>" class="mb-10 scroll-mt-20">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h2 class="font-semibold text-navy text-lg">Round <?= $rno ?> Results</h2>
      <div class="flex gap-2">
        <a href="<?= e(BASE_URL) ?>/reports/round_results.php?round=<?= $rno ?>" target="_blank" class="bg-white border border-navy text-navy rounded-lg px-4 py-2 text-sm font-medium">Print Report</a>
        <?php if ($results && !$allDeclared): ?>
          <form method="post" onsubmit="return confirm('Declare Round <?= $rno ?> results to all schools?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="declare_round">
            <input type="hidden" name="round_id" value="<?= $roundId ?>">
            <button class="bg-teal text-white rounded-lg px-4 py-2 text-sm font-semibold">Declare Round <?= $rno ?></button>
          </form>
        <?php elseif ($allDeclared): ?>
          <span class="px-3 py-2 rounded-lg bg-green-100 text-green-800 text-sm font-medium">Declared</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$results): ?>
      <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400">No results recorded for this round yet.</div>
    <?php else: ?>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-lightgrey text-gray-600 text-left">
            <tr>
              <th class="px-4 py-3">#</th>
              <th class="px-4 py-3">School</th>
              <th class="px-4 py-3">Slot</th>
              <th class="px-4 py-3">Started / Completed</th>
              <th class="px-4 py-3">Duration</th>
              <th class="px-4 py-3">Attended</th>
              <th class="px-4 py-3">Score</th>
              <th class="px-4 py-3">Status</th>
              <?php if ($rno === 1): ?><th class="px-4 py-3">Qualify R2</th><?php endif; ?>
              <th class="px-4 py-3">Declared</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($results as $i => $r): ?>
              <tr>
                <td class="px-4 py-3 text-gray-400"><?= $i + 1 ?></td>
                <td class="px-4 py-3 font-medium text-navy"><?= e($r['school_name']) ?> <span class="text-xs text-gray-400">(<?= e($r['code']) ?>)</span></td>
                <td class="px-4 py-3 text-gray-600"><?= e($r['slot_name'] ?? '—') ?></td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap text-xs">
                  <div><span class="text-gray-400">Start:</span> <?= $r['session_start'] ? e(date('d M, H:i:s', strtotime((string)$r['session_start']))) : '—' ?></div>
                  <div><span class="text-gray-400">End:</span> <?= $r['session_end'] ? e(date('d M, H:i:s', strtotime((string)$r['session_end']))) : '—' ?></div>
                </td>
                <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= e(fmt_duration($r['session_start'] ?? null, $r['session_end'] ?? null)) ?></td>
                <td class="px-4 py-3 text-gray-600"><?= (int)$r['attended'] ?> / <?= (int)$r['total_questions'] ?></td>
                <td class="px-4 py-3 font-semibold"><?= (int)$r['score'] ?> / <?= (int)$r['total_questions'] ?></td>
                <td class="px-4 py-3"><?= status_badge($r['session_status'] ?? 'pending') ?></td>
                <?php if ($rno === 1): ?>
                  <td class="px-4 py-3">
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_qualify">
                      <input type="hidden" name="result_id" value="<?= (int)$r['id'] ?>">
                      <button class="text-sm <?= (int)$r['qualified_for_next']===1 ? 'text-teal font-semibold' : 'text-gray-500' ?> hover:underline">
                        <?= (int)$r['qualified_for_next']===1 ? '✓ Qualified' : 'Mark qualified' ?>
                      </button>
                    </form>
                  </td>
                <?php endif; ?>
                <td class="px-4 py-3"><?= (int)$r['declared']===1 ? status_badge('declared') : status_badge('pending') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
  <h2 class="font-semibold text-navy mb-2">Round 2 setup</h2>
  <p class="text-sm text-gray-500 mb-3">After marking Round 1 qualifiers, create Round 2 slots, assign the qualified teams, and assign Round 2 questions.</p>
  <a href="<?= e(BASE_URL) ?>/expert/slots.php" class="inline-block bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium">Manage Round 2 Slots</a>
  <a href="<?= e(BASE_URL) ?>/reports/final_result.php" target="_blank" class="inline-block bg-white border border-navy text-navy rounded-lg px-4 py-2 text-sm font-medium ml-2">Final Consolidated Report</a>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
