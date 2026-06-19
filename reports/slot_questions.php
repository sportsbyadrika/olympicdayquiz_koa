<?php
/**
 * Printable question sheet for a slot — objective (MCQ) format with the
 * correct option ticked. Opens in a new tab; gated to expert + admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/report_layout.php';

$slotId = int_val(get('slot_id'));
$st = db()->prepare('SELECT sl.*, r.round_no FROM slots sl JOIN rounds r ON r.id = sl.round_id WHERE sl.id = ?');
$st->execute([$slotId]);
$slot = $st->fetch();
if (!$slot) {
    http_response_code(404);
    exit('Slot not found.');
}

$qs = db()->prepare(
    'SELECT qm.*, sq.sequence_no
     FROM slot_questions sq JOIN questions_master qm ON qm.id = sq.question_id
     WHERE sq.slot_id = ? ORDER BY sq.sequence_no, sq.id'
);
$qs->execute([$slotId]);
$questions = $qs->fetchAll();

report_header(
    'Question Sheet — ' . $slot['slot_name'],
    'Round ' . (int) $slot['round_no'] . ' · ' . count($questions) . ' questions · correct answer ticked'
);
?>
<style>
  .q-block { page-break-inside: avoid; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #eee; }
  .q-text { font-weight: 600; color: #1A2B49; margin-bottom: 8px; }
  .opt { display: flex; align-items: flex-start; gap: 8px; margin: 4px 0; font-size: 13px; }
  .box { flex: 0 0 auto; width: 15px; height: 15px; border: 1.5px solid #555; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; line-height: 1; }
  .box.correct { border-color: #00897B; background: #00897B; color: #fff; }
  .opt.correct { color: #00897B; font-weight: 600; }
  .q-meta { font-size: 11px; color: #888; margin-top: 6px; }
</style>

<?php if (!$questions): ?>
  <p style="color:#888">No questions have been assigned to this slot yet.</p>
<?php else: ?>
  <?php foreach ($questions as $i => $q): ?>
    <div class="q-block">
      <div class="q-text"><?= ($i + 1) ?>. <?= e($q['question_text']) ?></div>
      <?php foreach (['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'] as $L => $col):
        $isCorrect = ($q['correct_option'] === $L); ?>
        <div class="opt <?= $isCorrect ? 'correct' : '' ?>">
          <span class="box <?= $isCorrect ? 'correct' : '' ?>"><?= $isCorrect ? '&#10003;' : '' ?></span>
          <span><strong><?= $L ?>.</strong> <?= e($q[$col]) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="q-meta">
        Answer: <strong><?= e($q['correct_option']) ?></strong>
        <?= $q['sport'] ? ' · ' . e($q['sport']) : '' ?>
        <?= $q['category'] ? ' · ' . e($q['category']) : '' ?>
        · <?= e($q['difficulty']) ?>
        <?= $q['explanation'] ? '<br>Explanation: ' . e($q['explanation']) : '' ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
report_footer();
