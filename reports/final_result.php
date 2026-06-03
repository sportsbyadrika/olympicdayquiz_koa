<?php
/** Printable report: Final consolidated result (Round 1 + Round 2). */
declare(strict_types=1);

require_once __DIR__ . '/report_layout.php';

// Consolidate per school: round 1 score, qualified, round 2 score.
$rows = db()->query(
    "SELECT s.id, s.name, s.code,
        MAX(CASE WHEN r.round_no=1 THEN res.score END) AS r1_score,
        MAX(CASE WHEN r.round_no=1 THEN res.total_questions END) AS r1_total,
        MAX(CASE WHEN r.round_no=1 THEN res.qualified_for_next END) AS r1_qualified,
        MAX(CASE WHEN r.round_no=2 THEN res.score END) AS r2_score,
        MAX(CASE WHEN r.round_no=2 THEN res.total_questions END) AS r2_total
     FROM schools s
     JOIN results res ON res.school_id = s.id
     JOIN rounds r ON r.id = res.round_id
     GROUP BY s.id, s.name, s.code
     ORDER BY (MAX(CASE WHEN r.round_no=2 THEN res.score END)) DESC,
              (MAX(CASE WHEN r.round_no=1 THEN res.score END)) DESC,
              s.name"
)->fetchAll();

report_header('Final Consolidated Result', 'Round 1 and Round 2 combined');
?>
<table>
  <thead>
    <tr>
      <th style="width:48px">Rank</th>
      <th>School</th>
      <th>Code</th>
      <th style="width:110px">Round 1</th>
      <th style="width:90px">Qualified</th>
      <th style="width:110px">Round 2</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6" style="text-align:center;color:#9ca3af">No results recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['code']) ?></td>
        <td><?= $r['r1_score'] !== null ? (int)$r['r1_score'] . ' / ' . (int)$r['r1_total'] : '—' ?></td>
        <td><?= (int)$r['r1_qualified'] === 1 ? 'Yes' : '' ?></td>
        <td><?= $r['r2_score'] !== null ? (int)$r['r2_score'] . ' / ' . (int)$r['r2_total'] : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
report_footer();
