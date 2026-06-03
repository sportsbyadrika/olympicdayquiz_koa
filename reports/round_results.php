<?php
/** Printable report: Round 1 or Round 2 results. */
declare(strict_types=1);

require_once __DIR__ . '/report_layout.php';

$roundNo = int_val(get('round')) === 2 ? 2 : 1;

$st = db()->prepare(
    'SELECT res.*, s.name AS school_name, s.code, sl.slot_name
     FROM rounds r
     JOIN results res ON res.round_id = r.id
     JOIN schools s ON s.id = res.school_id
     LEFT JOIN quiz_sessions qs ON qs.id = res.session_id
     LEFT JOIN slots sl ON sl.id = qs.slot_id
     WHERE r.round_no = ?
     ORDER BY res.score DESC, s.name'
);
$st->execute([$roundNo]);
$rows = $st->fetchAll();

report_header("Round {$roundNo} Results", 'Ranked by score');
?>
<table>
  <thead>
    <tr>
      <th style="width:48px">Rank</th>
      <th>School</th>
      <th>Code</th>
      <th>Slot</th>
      <th style="width:90px">Score</th>
      <th style="width:90px">Total</th>
      <?php if ($roundNo === 1): ?><th style="width:90px">Qualified</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= $roundNo === 1 ? 7 : 6 ?>" style="text-align:center;color:#9ca3af">No results recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['school_name']) ?></td>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['slot_name'] ?? '—') ?></td>
        <td><?= (int)$r['score'] ?></td>
        <td><?= (int)$r['total_questions'] ?></td>
        <?php if ($roundNo === 1): ?><td><?= (int)$r['qualified_for_next'] === 1 ? 'Yes' : '' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
report_footer();
