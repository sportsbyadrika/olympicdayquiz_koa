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
    "SELECT s.name AS school_name, s.code, s.participant1_name, s.participant2_name, s.email, s.contact,
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

// ----- Send result emails (one per school, to its registered email) -----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('action') === 'send_email') {
    csrf_check();
    $sendResults = [];
    foreach ($rows as $i => $r) {
        $rank = $i + 1;
        $status = $rank <= $topN ? 'Qualified' : 'Not Qualified';
        $to = trim((string) ($r['email'] ?? ''));
        $name = (string) $r['school_name'];

        if ($to === '' || !is_email($to)) {
            $sendResults[] = ['school' => $name, 'email' => $to, 'state' => 'No valid email'];
            continue;
        }
        $html = '<div style="font-family:Arial,sans-serif;color:#333;font-size:14px;line-height:1.6">'
            . 'Hi Team, 👋<br><br>'
            . 'The results for the Preliminary Round Quiz Competition (part of Olympic Day Events - 2026) have been published in the WhatsApp group! 🎉<br>'
            . 'Here are the details for your school:<br><br>'
            . '<b>School Name:</b> ' . e($name) . '<br>'
            . '<b>School Code:</b> ' . e((string) $r['code']) . '<br>'
            . '<b>Participant 1 :</b> ' . e((string) $r['participant1_name']) . '<br>'
            . '<b>Participant 2 :</b> ' . e((string) $r['participant2_name']) . '<br>'
            . '<b>Rank Obtained:</b> ' . $rank . '<br>'
            . '<b>Final Round Status:</b> ' . e($status) . '<br><br>'
            . 'Regards,<br>Admin</div>';
        $sent = send_email($to, $name, 'Preliminary Round Results — Olympic Day Events 2026', $html);
        $sendResults[] = ['school' => $name, 'email' => $to, 'state' => $sent ? 'Sent' : 'Failed'];
        audit_log($sent ? 'final_result_email_sent' : 'final_result_email_failed', 'schools', null, $to);
    }

    $okCount = count(array_filter($sendResults, fn($x) => $x['state'] === 'Sent'));
    report_header("Email Send Results — Round {$roundNo}", "{$okCount} of " . count($sendResults) . ' email(s) sent successfully');
    echo '<p style="margin-bottom:14px"><a href="' . e(app_url('/reports/final_round1.php?round=' . $roundNo . '&top=' . $topN)) . '" style="color:#00897B">&larr; Back to report</a></p>';
    echo '<table><thead><tr><th>School</th><th>Email</th><th style="width:120px">Result</th></tr></thead><tbody>';
    foreach ($sendResults as $sr) {
        $color = $sr['state'] === 'Sent' ? '#067647' : ($sr['state'] === 'Failed' ? '#b42318' : '#888');
        echo '<tr><td>' . e($sr['school']) . '</td><td>' . e($sr['email'] ?: '—') . '</td>'
            . '<td style="color:' . $color . ';font-weight:600">' . e($sr['state']) . '</td></tr>';
    }
    echo '</tbody></table>';
    report_footer();
    exit;
}

// CSV export (same ranking) — must run before any HTML output.
if (get('format') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="final_report_round' . $roundNo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['School Code', 'School Name', 'Rank', 'Status', 'Participant 1', 'Participant 2', 'Registered Email', 'Registered Mobile']);
    foreach ($rows as $i => $r) {
        $rank = $i + 1;
        fputcsv($out, [
            $r['code'], $r['school_name'], $rank, ($rank <= $topN ? 'Qualified' : 'Not Qualified'),
            $r['participant1_name'], $r['participant2_name'], $r['email'], $r['contact'],
        ]);
    }
    fclose($out);
    exit;
}

$csvBtn = '<a href="' . e(app_url('/reports/final_round1.php?round=' . $roundNo . '&top=' . $topN . '&format=csv'))
    . '" class="bg-white text-[#1A2B49] rounded px-4 py-2 text-sm font-semibold">Download CSV</a>';

$sendBtn = '<form method="post" style="display:inline" onsubmit="return confirm(\'Send the result email to every school\\\'s registered email address?\');">'
    . csrf_field()
    . '<input type="hidden" name="action" value="send_email">'
    . '<button type="submit" class="bg-white text-[#1A2B49] rounded px-4 py-2 text-sm font-semibold">Send email</button>'
    . '</form>';

report_header("Final Report — Round {$roundNo}", "Ranked by percentage, then time taken · top {$topN} qualify", $csvBtn . $sendBtn);
?>
<table>
  <thead>
    <tr>
      <th style="width:80px">Code</th>
      <th>School Name</th>
      <th style="width:170px">Started / Completed</th>
      <th style="width:56px">Rank</th>
      <th style="width:110px">Status</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="5" style="text-align:center;color:#9ca3af">No results recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): $rank = $i + 1; $qualified = $rank <= $topN; ?>
      <tr<?= $qualified ? ' style="background:#E8F5F3"' : '' ?>>
        <td><?= e($r['code']) ?></td>
        <td><?= e($r['school_name']) ?></td>
        <td>
          <?= $r['session_start'] ? e(date('d M Y, H:i:s', strtotime((string)$r['session_start']))) : '—' ?><br>
          <?= $r['session_end'] ? e(date('d M Y, H:i:s', strtotime((string)$r['session_end']))) : '—' ?>
        </td>
        <td><?= $rank ?></td>
        <td><?= $qualified ? '<strong style="color:#00897B">Qualified</strong>' : 'Not Qualified' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
report_footer();
