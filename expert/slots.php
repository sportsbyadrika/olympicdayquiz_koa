<?php
/**
 * Expert · Slot management + school assignment.
 * Create/edit/delete slots for Round 1 & Round 2 (durations inherit settings
 * defaults but are editable per slot). Assign schools to slots; a school may
 * belong to only one slot per round.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

/** Map round_no -> round id. */
function round_id_for(int $no): int
{
    $st = db()->prepare('SELECT id FROM rounds WHERE round_no = ?');
    $st->execute([$no]);
    return (int) $st->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $roundNo = (int) post('round_no', '1');
        $roundNo = in_array($roundNo, [1, 2], true) ? $roundNo : 1;
        $name = post('slot_name');
        $start = post('start_time');
        $end = post('end_time');
        $slotDur = int_val(post('slot_duration_min')) ?: (int) setting('slot_duration', '30');
        $quizDur = int_val(post('quiz_duration_min')) ?: (int) setting('quiz_duration', '15');
        $qCount = int_val(post('question_count')) ?: (int) setting('question_count', '30');

        $errors = [];
        if ($name === '') $errors[] = 'Slot name is required.';
        if (!strtotime($start) || !strtotime($end)) $errors[] = 'Valid start and end times are required.';
        elseif (strtotime($end) <= strtotime($start)) $errors[] = 'End time must be after start time.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } elseif ($action === 'create') {
            db()->prepare(
                'INSERT INTO slots (round_id, slot_name, start_time, end_time, slot_duration_min, quiz_duration_min, question_count)
                 VALUES (:rid,:name,:start,:end,:sd,:qd,:qc)'
            )->execute([
                ':rid' => round_id_for($roundNo), ':name' => $name, ':start' => date('Y-m-d H:i:s', strtotime($start)),
                ':end' => date('Y-m-d H:i:s', strtotime($end)), ':sd' => $slotDur, ':qd' => $quizDur, ':qc' => $qCount,
            ]);
            audit_log('slot_create', 'slots', (int) db()->lastInsertId());
            flash('success', 'Slot created.');
        } else {
            $id = int_val(post('id'));
            db()->prepare(
                'UPDATE slots SET round_id=:rid, slot_name=:name, start_time=:start, end_time=:end,
                 slot_duration_min=:sd, quiz_duration_min=:qd, question_count=:qc WHERE id=:id'
            )->execute([
                ':rid' => round_id_for($roundNo), ':name' => $name, ':start' => date('Y-m-d H:i:s', strtotime($start)),
                ':end' => date('Y-m-d H:i:s', strtotime($end)), ':sd' => $slotDur, ':qd' => $quizDur, ':qc' => $qCount, ':id' => $id,
            ]);
            audit_log('slot_update', 'slots', $id);
            flash('success', 'Slot updated.');
        }
        redirect('/expert/slots.php');
    }

    if ($action === 'delete') {
        $id = int_val(post('id'));
        if ($id) {
            db()->prepare('DELETE FROM slots WHERE id=?')->execute([$id]);
            audit_log('slot_delete', 'slots', $id);
            flash('success', 'Slot deleted.');
        }
        redirect('/expert/slots.php');
    }

    if ($action === 'assign_school') {
        $slotId = int_val(post('slot_id'));
        $schoolId = int_val(post('school_id'));
        // Determine the slot's round.
        $st = db()->prepare('SELECT round_id FROM slots WHERE id=?');
        $st->execute([$slotId]);
        $roundId = (int) $st->fetchColumn();
        if ($slotId && $schoolId && $roundId) {
            // Reject if school already assigned to another slot in this round.
            $chk = db()->prepare(
                'SELECT COUNT(*) FROM slot_schools ss JOIN slots s ON s.id=ss.slot_id
                 WHERE ss.school_id=? AND s.round_id=? AND ss.slot_id<>?'
            );
            $chk->execute([$schoolId, $roundId, $slotId]);
            if ((int) $chk->fetchColumn() > 0) {
                flash('error', 'That school is already assigned to another slot in this round.');
            } else {
                db()->prepare('INSERT IGNORE INTO slot_schools (slot_id, school_id) VALUES (?,?)')->execute([$slotId, $schoolId]);
                audit_log('slot_assign_school', 'slot_schools', $slotId, 'school=' . $schoolId);
                flash('success', 'School assigned to slot.');
            }
        }
        redirect('/expert/slots.php?slot_id=' . $slotId);
    }

    if ($action === 'unassign_school') {
        $slotId = int_val(post('slot_id'));
        $schoolId = int_val(post('school_id'));
        db()->prepare('DELETE FROM slot_schools WHERE slot_id=? AND school_id=?')->execute([$slotId, $schoolId]);
        audit_log('slot_unassign_school', 'slot_schools', $slotId, 'school=' . $schoolId);
        flash('success', 'School removed from slot.');
        redirect('/expert/slots.php?slot_id=' . $slotId);
    }
}

// Load slots with counts.
$slots = db()->query(
    'SELECT sl.*, r.round_no,
       (SELECT COUNT(*) FROM slot_schools ss WHERE ss.slot_id=sl.id) AS school_count,
       (SELECT COUNT(*) FROM slot_questions sq WHERE sq.slot_id=sl.id) AS question_count_assigned
     FROM slots sl JOIN rounds r ON r.id=sl.round_id ORDER BY r.round_no, sl.start_time'
)->fetchAll();

$openSlotId = int_val(get('slot_id'));

$defaults = [
    'slot' => (int) setting('slot_duration', '30'),
    'quiz' => (int) setting('quiz_duration', '15'),
    'count' => (int) setting('question_count', '30'),
];

$pageTitle = 'Slots';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-bold text-navy">Time Slots</h1>
  <button onclick="openCreate()" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ New Slot</button>
</div>

<?php foreach ([1, 2] as $rno):
  $roundSlots = array_filter($slots, fn($s) => (int) $s['round_no'] === $rno); ?>
  <h2 class="font-semibold text-navy mt-4 mb-3">Round <?= $rno ?></h2>
  <div class="grid md:grid-cols-2 gap-4 mb-4">
    <?php if (!$roundSlots): ?><div class="text-gray-400 text-sm">No slots for this round yet.</div><?php endif; ?>
    <?php foreach ($roundSlots as $s): ?>
      <div class="bg-white rounded-xl border <?= $openSlotId===(int)$s['id'] ? 'border-teal' : 'border-gray-100' ?> shadow-sm p-5">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="font-semibold text-navy"><?= e($s['slot_name']) ?></div>
            <div class="text-xs text-gray-500 mt-0.5"><?= e(date('d M Y, H:i', strtotime((string)$s['start_time']))) ?> – <?= e(date('H:i', strtotime((string)$s['end_time']))) ?></div>
          </div>
          <div class="flex gap-2 text-sm shrink-0">
            <button class="text-navy hover:underline" onclick='openEdit(<?= json_encode($s, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete this slot?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="text-red-600 hover:underline">Delete</button></form>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-3 text-xs">
          <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Quiz <?= (int)$s['quiz_duration_min'] ?> min</span>
          <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Target <?= (int)$s['question_count'] ?> Qs</span>
          <span class="px-2 py-0.5 rounded-full bg-teal/10 text-teal"><?= (int)$s['question_count_assigned'] ?> assigned</span>
          <span class="px-2 py-0.5 rounded-full bg-navy/10 text-navy"><?= (int)$s['school_count'] ?> school(s)</span>
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
          <a href="<?= e(BASE_URL) ?>/expert/slots.php?slot_id=<?= (int)$s['id'] ?>#assign" class="text-sm bg-white border border-navy text-navy rounded-lg px-3 py-1.5">Assign Schools</a>
          <a href="<?= e(BASE_URL) ?>/expert/assign_questions.php?slot_id=<?= (int)$s['id'] ?>" class="text-sm bg-teal text-white rounded-lg px-3 py-1.5">Assign Questions</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php
// School assignment panel for the opened slot.
if ($openSlotId):
    $st = db()->prepare('SELECT sl.*, r.round_no FROM slots sl JOIN rounds r ON r.id=sl.round_id WHERE sl.id=?');
    $st->execute([$openSlotId]);
    $slot = $st->fetch();
    if ($slot):
        // Assigned schools
        $a = db()->prepare('SELECT s.* FROM slot_schools ss JOIN schools s ON s.id=ss.school_id WHERE ss.slot_id=? ORDER BY s.name');
        $a->execute([$openSlotId]);
        $assigned = $a->fetchAll();
        // Schools available (not already in another slot of this round)
        $av = db()->prepare(
            'SELECT s.* FROM schools s WHERE s.id NOT IN (
                SELECT ss.school_id FROM slot_schools ss JOIN slots sl ON sl.id=ss.slot_id WHERE sl.round_id=?
             ) ORDER BY s.name'
        );
        $av->execute([(int) $slot['round_id']]);
        $available = $av->fetchAll();
?>
<div id="assign" class="bg-white rounded-xl border border-teal shadow-sm p-5 mt-2 scroll-mt-20">
  <h2 class="font-semibold text-navy mb-1">Assign Schools — <?= e($slot['slot_name']) ?> (Round <?= (int)$slot['round_no'] ?>)</h2>
  <p class="text-xs text-gray-500 mb-4">A school may be assigned to only one slot per round.</p>
  <div class="grid md:grid-cols-2 gap-6">
    <div>
      <div class="text-sm font-medium text-gray-700 mb-2">Assigned (<?= count($assigned) ?>)</div>
      <?php if (!$assigned): ?><div class="text-gray-400 text-sm">None yet.</div><?php endif; ?>
      <ul class="space-y-2">
        <?php foreach ($assigned as $sc): ?>
          <li class="flex items-center justify-between bg-lightgrey rounded-lg px-3 py-2 text-sm">
            <span><?= e($sc['name']) ?> <span class="text-xs text-gray-400">(<?= e($sc['code']) ?>)</span></span>
            <form method="post" onsubmit="return confirm('Remove this school?');">
              <?= csrf_field() ?><input type="hidden" name="action" value="unassign_school"><input type="hidden" name="slot_id" value="<?= $openSlotId ?>"><input type="hidden" name="school_id" value="<?= (int)$sc['id'] ?>">
              <button class="text-red-600 hover:underline text-xs">Remove</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div>
      <div class="text-sm font-medium text-gray-700 mb-2">Add a school</div>
      <form method="post" class="flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_school"><input type="hidden" name="slot_id" value="<?= $openSlotId ?>">
        <select name="school_id" required class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
          <option value="">Select a school…</option>
          <?php foreach ($available as $sc): ?><option value="<?= (int)$sc['id'] ?>"><?= e($sc['name']) ?> (<?= e($sc['code']) ?>)</option><?php endforeach; ?>
        </select>
        <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Add</button>
      </form>
    </div>
  </div>
</div>
<?php endif; endif; ?>

<!-- Create/Edit slot modal -->
<div id="slotModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-lg p-6 my-6">
    <h2 id="slotTitle" class="text-lg font-bold text-navy mb-4">New Slot</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="s_action" value="create">
      <input type="hidden" name="id" id="s_id">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="block text-sm font-medium mb-1">Round</label><select name="round_no" id="s_round" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"><option value="1">Round 1</option><option value="2">Round 2</option></select></div>
        <div><label class="block text-sm font-medium mb-1">Slot name *</label><input name="slot_name" id="s_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Start time *</label><input type="datetime-local" name="start_time" id="s_start" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">End time *</label><input type="datetime-local" name="end_time" id="s_end" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Slot duration (min)</label><input type="number" min="1" name="slot_duration_min" id="s_sd" value="<?= $defaults['slot'] ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Quiz duration (min)</label><input type="number" min="1" name="quiz_duration_min" id="s_qd" value="<?= $defaults['quiz'] ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Question count</label><input type="number" min="1" name="question_count" id="s_qc" value="<?= $defaults['count'] ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('slotModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
  function toLocal(dt){ return dt ? dt.replace(' ','T').slice(0,16) : ''; }
  function openCreate(){
    document.getElementById('s_action').value='create';
    document.getElementById('s_id').value='';
    document.getElementById('slotTitle').textContent='New Slot';
    document.getElementById('s_name').value='';
    document.getElementById('s_round').value='1';
    document.getElementById('s_start').value='';
    document.getElementById('s_end').value='';
    document.getElementById('s_sd').value='<?= $defaults['slot'] ?>';
    document.getElementById('s_qd').value='<?= $defaults['quiz'] ?>';
    document.getElementById('s_qc').value='<?= $defaults['count'] ?>';
    document.getElementById('slotModal').classList.remove('hidden');
  }
  function openEdit(s){
    document.getElementById('s_action').value='update';
    document.getElementById('s_id').value=s.id;
    document.getElementById('slotTitle').textContent='Edit Slot';
    document.getElementById('s_name').value=s.slot_name||'';
    document.getElementById('s_round').value=s.round_no||'1';
    document.getElementById('s_start').value=toLocal(s.start_time);
    document.getElementById('s_end').value=toLocal(s.end_time);
    document.getElementById('s_sd').value=s.slot_duration_min;
    document.getElementById('s_qd').value=s.quiz_duration_min;
    document.getElementById('s_qc').value=s.question_count;
    document.getElementById('slotModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
