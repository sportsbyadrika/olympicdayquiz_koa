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

    // Reset a school's attempt for a round so they can take it again from scratch.
    if ($action === 'reset_attempt') {
        $resultId = int_val(post('result_id'));
        $st = db()->prepare('SELECT school_id, round_id FROM results WHERE id=?');
        $st->execute([$resultId]);
        $row = $st->fetch();
        if ($row) {
            $schoolId = (int) $row['school_id'];
            $roundId = (int) $row['round_id'];
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Clear answers, the session(s) and the result for this school+round.
                $pdo->prepare(
                    'DELETE rsp FROM responses rsp JOIN quiz_sessions qs ON qs.id = rsp.session_id
                     WHERE qs.school_id = ? AND qs.round_id = ?'
                )->execute([$schoolId, $roundId]);
                $pdo->prepare('DELETE FROM quiz_sessions WHERE school_id = ? AND round_id = ?')->execute([$schoolId, $roundId]);
                $pdo->prepare('DELETE FROM results WHERE school_id = ? AND round_id = ?')->execute([$schoolId, $roundId]);
                $pdo->commit();
                audit_log('exam_reset', 'results', $resultId, "school={$schoolId} round={$roundId}");
                flash('success', 'Attempt reset. The team can start the quiz again during their slot window.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Could not reset the attempt.');
            }
        }
        redirect('/expert/results.php');
    }
}

/** Fetch results for a round number, joined with school + session info. */
function round_results(int $roundNo): array
{
    // Degrade gracefully if the slot_questions.cancelled column isn't there yet.
    $hasCancel = db_column_exists('slot_questions', 'cancelled');
    $c1 = $hasCancel ? 'AND sq.cancelled = 0' : '';
    $c2 = $hasCancel ? 'AND sq2.cancelled = 0' : '';
    $c3 = $hasCancel ? 'AND sq3.cancelled = 0' : '';

    $st = db()->prepare(
        "SELECT res.*, s.name AS school_name, s.code, r.id AS round_id,
                sl.slot_name, qs.status AS session_status,
                qs.start_time AS session_start, qs.end_time AS session_end,
                (SELECT COUNT(*) FROM responses rsp WHERE rsp.session_id = res.session_id AND rsp.selected_option IS NOT NULL) AS attended,
                -- counts after cancellation (cancelled questions excluded):
                (SELECT COUNT(*) FROM slot_questions sq WHERE sq.slot_id = qs.slot_id $c1) AS effective_total,
                (SELECT COUNT(*) FROM responses rp
                    JOIN slot_questions sq2 ON sq2.slot_id = qs.slot_id AND sq2.question_id = rp.question_id
                    WHERE rp.session_id = qs.id AND rp.selected_option IS NOT NULL $c2) AS attended_after,
                (SELECT COUNT(*) FROM responses rp
                    JOIN questions_master qmm ON qmm.id = rp.question_id
                    JOIN slot_questions sq3 ON sq3.slot_id = qs.slot_id AND sq3.question_id = rp.question_id
                    WHERE rp.session_id = qs.id AND rp.selected_option = qmm.correct_option $c3) AS score_after
         FROM rounds r
         JOIN results res ON res.round_id = r.id
         JOIN schools s ON s.id = res.school_id
         LEFT JOIN quiz_sessions qs ON qs.id = res.session_id
         LEFT JOIN slots sl ON sl.id = qs.slot_id
         WHERE r.round_no = ?"
    );
    $st->execute([$roundNo]);
    $rows = $st->fetchAll();

    // Derive percentage and duration, then rank by % desc, faster duration first.
    foreach ($rows as &$row) {
        $eff = (int) $row['effective_total'];
        $row['pct'] = $eff > 0 ? round((int) $row['score_after'] / $eff * 100, 2) : 0.0;
        $row['duration_secs'] = ($row['session_start'] && $row['session_end'])
            ? max(0, strtotime((string) $row['session_end']) - strtotime((string) $row['session_start']))
            : PHP_INT_MAX; // no/!complete attempt sorts last on the tie-break
    }
    unset($row);

    usort($rows, function ($a, $b) {
        if ($b['pct'] <=> $a['pct']) {
            return $b['pct'] <=> $a['pct']; // higher percentage first
        }
        return $a['duration_secs'] <=> $b['duration_secs']; // faster first
    });
    return $rows;
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
              <th class="px-4 py-3">Att. (after cancel)</th>
              <th class="px-4 py-3">Score (after cancel)</th>
              <th class="px-4 py-3">%</th>
              <th class="px-4 py-3">Status</th>
              <?php if ($rno === 1): ?><th class="px-4 py-3">Qualify R2</th><?php endif; ?>
              <th class="px-4 py-3">Declared</th>
              <th class="px-4 py-3 text-right">Actions</th>
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
                <td class="px-4 py-3 text-gray-600"><?= (int)$r['attended_after'] ?> / <?= (int)$r['effective_total'] ?></td>
                <td class="px-4 py-3 font-semibold text-teal"><?= (int)$r['score_after'] ?> / <?= (int)$r['effective_total'] ?></td>
                <td class="px-4 py-3 font-bold text-navy"><?= number_format((float)$r['pct'], 2) ?>%</td>
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
                <td class="px-4 py-3 text-right">
                  <form method="post" onsubmit="return confirm('Reset this team\'s attempt? Their answers and score for this round will be permanently cleared so they can start fresh.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_attempt">
                    <input type="hidden" name="result_id" value="<?= (int)$r['id'] ?>">
                    <button class="text-red-600 hover:underline text-sm">Reset attempt</button>
                  </form>
                </td>
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
