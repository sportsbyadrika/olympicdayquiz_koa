<?php
/**
 * Printable FINAL report (portrait A4): ranked standings for a round.
 * Rank · School · Started/Completed · Duration · % of marks (after cancellation)
 * · Qualified status (top N qualify; default 8). Default round = 1.
 */
declare(strict_types=1);

require_once __DIR__ . '/report_layout.php';

$roundNo = int_val(get('round')) === 2 ? 2 : 1;
$topN = int_val(get('top')) ?: 8;

$hasCancel = db_column_exists('slot_questions', 'cancelled');
$c1 = $hasCancel ? 'AND sq.cancelled = 0' : '';
$c3 = $hasCancel ? 'AND sq3.cancelled = 0' : '';

$st = db()->prepare(
    "SELECT s.name AS school_name, s.code,
            qs.start_time AS session_start, qs.end_time AS session_end,
            (SELECT COUNT(*) FROM slot_questions sq WHERE sq.slot_id = qs.slot_id $c1) AS effective_total,
            (SELECT COUNT(*) FROM responses rp
                JOIN questions_master qmm ON qmm.id = rp.question_id
                JOIN slot_questions sq3 ON sq3.slot_id = qs.slot_id AND sq3.question_id = rp.question_id
                WHERE rp.session_id = qs.id AND rp.selected_option = qmm.correct_option $c3) AS score_after
     FROM rounds r
     JOIN results res ON res.round_id = r.id
     JOIN schools s ON s.id = res.school_id
     LEFT JOIN quiz_sessions qs ON qs.id = res.session_id
     WHERE r.round_no = ?"
);
$st->execute([$roundNo]);
$rows = $st->fetchAll();

function dur_str(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '—';
    }
    $s = max(0, strtotime($end) - strtotime($start));
    return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
}

foreach ($rows as &$row) {
    $eff = (int) $row['effective_total'];
    $row['pct'] = $eff > 0 ? round((int) $row['score_after'] / $eff * 100, 2) : 0.0;
    $row['duration_secs'] = ($row['session_start'] && $row['session_end'])
        ? max(0, strtotime((string) $row['session_end']) - strtotime((string) $row['session_start']))
        : PHP_INT_MAX;
}
unset($row);
usort($rows, fn($a, $b) => ($b['pct'] <=> $a['pct']) ?: ($a['duration_secs'] <=> $b['duration_secs']));

report_header("Final Report — Round {$roundNo}", "Ranked by percentage, then time taken · top {$topN} qualify");
?>
<table>
  <thead>
    <tr>
      <th style="width:48px">Rank</th>
      <th>School</th>
      <th style="width:70px">Code</th>
      <th style="width:160px">Started / Completed</th>
      <th style="width:90px">Duration</th>
      <th style="width:100px">Status</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6" style="text-align:center;color:#9ca3af">No results recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): $rank = $i + 1; $qualified = $rank <= $topN; ?>
      <tr<?= $qualified ? ' style="background:#E8F5F3"' : '' ?>>
        <td><?= $rank ?></td>
        <td><?= e($r['school_name']) ?></td>
        <td><?= e($r['code']) ?></td>
        <td>
          <?= $r['session_start'] ? e(date('d M Y, H:i:s', strtotime((string)$r['session_start']))) : '—' ?><br>
          <?= $r['session_end'] ? e(date('d M Y, H:i:s', strtotime((string)$r['session_end']))) : '—' ?>
        </td>
        <td><?= e(dur_str($r['session_start'] ?? null, $r['session_end'] ?? null)) ?></td>
        <td><?= $qualified ? '<strong style="color:#00897B">Qualified</strong>' : 'Not Qualified' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
report_footer();
