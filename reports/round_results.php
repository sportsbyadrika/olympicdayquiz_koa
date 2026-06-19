<?php
/** Printable report: Round 1 or Round 2 results (A4 landscape). */
declare(strict_types=1);

require_once __DIR__ . '/report_layout.php';

$roundNo = int_val(get('round')) === 2 ? 2 : 1;

$st = db()->prepare(
    "SELECT res.*, s.name AS school_name, s.code, sl.slot_name,
            qs.start_time AS session_start, qs.end_time AS session_end,
            (SELECT COUNT(*) FROM slot_questions sq WHERE sq.slot_id = qs.slot_id AND sq.cancelled = 0) AS effective_total,
            (SELECT COUNT(*) FROM responses rp
                JOIN slot_questions sq2 ON sq2.slot_id = qs.slot_id AND sq2.question_id = rp.question_id
                WHERE rp.session_id = qs.id AND rp.selected_option IS NOT NULL AND sq2.cancelled = 0) AS attended_after,
            (SELECT COUNT(*) FROM responses rp
                JOIN questions_master qmm ON qmm.id = rp.question_id
                JOIN slot_questions sq3 ON sq3.slot_id = qs.slot_id AND sq3.question_id = rp.question_id
                WHERE rp.session_id = qs.id AND rp.selected_option = qmm.correct_option AND sq3.cancelled = 0) AS score_after
     FROM rounds r
     JOIN results res ON res.round_id = r.id
     JOIN schools s ON s.id = res.school_id
     LEFT JOIN quiz_sessions qs ON qs.id = res.session_id
     LEFT JOIN slots sl ON sl.id = qs.slot_id
     WHERE r.round_no = ?"
);
$st->execute([$roundNo]);
$rows = $st->fetchAll();

/** Format seconds as Xm Ys. */
function dur_str(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $s = max(0, strtotime($end) - strtotime($start));
    return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
}

// Derive percentage + duration, then rank by % desc, faster duration first.
foreach ($rows as &$row) {
    $eff = (int) $row['effective_total'];
    $row['pct'] = $eff > 0 ? round((int) $row['score_after'] / $eff * 100, 2) : 0.0;
    $row['duration_secs'] = ($row['session_start'] && $row['session_end'])
        ? max(0, strtotime((string) $row['session_end']) - strtotime((string) $row['session_start']))
        : PHP_INT_MAX;
}
unset($row);
usort($rows, fn($a, $b) => ($b['pct'] <=> $a['pct']) ?: ($a['duration_secs'] <=> $b['duration_secs']));

report_header("Round {$roundNo} Results", 'Ranked by percentage (after cancellation), then time taken');
?>
<style>@page { size: A4 landscape; }</style>
<table>
  <thead>
    <tr>
      <th style="width:42px">Rank</th>
      <th>School</th>
      <th style="width:70px">Code</th>
      <th>Slot</th>
      <th style="width:70px">Score</th>
      <th style="width:90px">Attended<br>(after cancel)</th>
      <th style="width:90px">Score<br>(after cancel)</th>
      <th style="width:64px">%</th>
      <th style="width:80px">Duration</th>
      <?php if ($roundNo === 1): ?><th style="width:70px">Qualified</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= $roundNo === 1 ? 10 : 9 ?>" style="text-align:center;color:#9ca3af">No results recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['school_name']) ?></td>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['slot_name'] ?? '—') ?></td>
        <td><?= (int)$r['score'] ?> / <?= (int)$r['total_questions'] ?></td>
        <td><?= (int)$r['attended_after'] ?> / <?= (int)$r['effective_total'] ?></td>
        <td><?= (int)$r['score_after'] ?> / <?= (int)$r['effective_total'] ?></td>
        <td><?= number_format((float)$r['pct'], 2) ?>%</td>
        <td><?= e(dur_str($r['session_start'] ?? null, $r['session_end'] ?? null)) ?></td>
        <?php if ($roundNo === 1): ?><td><?= (int)$r['qualified_for_next'] === 1 ? 'Yes' : '' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
report_footer();
