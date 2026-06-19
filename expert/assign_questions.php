<?php
/**
 * Expert · Drag-and-drop question → slot assignment (SortableJS).
 * Left: filterable master bank (drag from). Right: this slot's questions
 * (drop into / reorder). All writes go through /api/slot_questions.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

$slotId = int_val(get('slot_id'));
$st = db()->prepare('SELECT sl.*, r.round_no FROM slots sl JOIN rounds r ON r.id=sl.round_id WHERE sl.id=?');
$st->execute([$slotId]);
$slot = $st->fetch();
if (!$slot) { flash('error', 'Slot not found.'); redirect('/expert/slots.php'); }

// Filters for the source bank.
$fRound = get('round');
$fDiff = get('difficulty');
$fSource = get('source');

$where = '1=1';
$params = [];
if (in_array($fRound, ['round1','round2','either'], true)) { $where .= ' AND qm.suggested_round = :r'; $params[':r'] = $fRound; }
if (in_array($fDiff, ['Easy','Medium','Hard'], true)) { $where .= ' AND qm.difficulty = :d'; $params[':d'] = $fDiff; }
if ($fSource === 'association') { $where .= ' AND qm.source_association_id IS NOT NULL'; }
elseif ($fSource === 'expert') { $where .= ' AND qm.source_association_id IS NULL'; }

// Questions already in this slot.
$cancelSel = db_column_exists('slot_questions', 'cancelled') ? 'sq.cancelled' : '0 AS cancelled';
$assignedStmt = db()->prepare(
    "SELECT qm.*, $cancelSel FROM slot_questions sq JOIN questions_master qm ON qm.id=sq.question_id
     WHERE sq.slot_id=? ORDER BY sq.sequence_no"
);
$assignedStmt->execute([$slotId]);
$assigned = $assignedStmt->fetchAll();
$assignedIds = array_column($assigned, 'id');

// Source bank excluding already-assigned.
$sql = "SELECT qm.* FROM questions_master qm WHERE $where";
if ($assignedIds) {
    $sql .= ' AND qm.id NOT IN (' . implode(',', array_map('intval', $assignedIds)) . ')';
}
$sql .= ' ORDER BY qm.id DESC LIMIT 300';
$bankStmt = db()->prepare($sql);
$bankStmt->execute($params);
$bank = $bankStmt->fetchAll();

$pageTitle = 'Assign Questions';
require dirname(__DIR__) . '/includes/header.php';
?>
<a href="<?= e(BASE_URL) ?>/expert/slots.php" class="text-sm text-gray-500 hover:text-navy">&larr; Back to slots</a>
<div class="flex flex-wrap items-center justify-between gap-3 mt-2 mb-1">
  <h1 class="text-2xl font-bold text-navy">Assign Questions — <?= e($slot['slot_name']) ?></h1>
  <div class="text-sm font-medium px-3 py-1.5 rounded-full bg-teal/10 text-teal">
    <span id="liveCount"><?= count($assigned) ?></span> / <?= (int)$slot['question_count'] ?>
  </div>
</div>
<p class="text-gray-500 mb-6 text-sm">Round <?= (int)$slot['round_no'] ?> · Drag questions from the bank into the slot. Drag within the slot to reorder.</p>

<div class="grid lg:grid-cols-2 gap-6 items-start">
  <!-- Source bank -->
  <div>
    <div class="flex items-center justify-between mb-2">
      <h2 class="font-semibold text-navy">Master Bank</h2>
    </div>
    <form method="get" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
      <input type="hidden" name="slot_id" value="<?= $slotId ?>">
      <select name="round" class="border border-gray-300 rounded-lg px-2 py-2 text-xs">
        <option value="">Any round</option>
        <option value="round1" <?= $fRound==='round1'?'selected':'' ?>>R1</option>
        <option value="round2" <?= $fRound==='round2'?'selected':'' ?>>R2</option>
        <option value="either" <?= $fRound==='either'?'selected':'' ?>>Either</option>
      </select>
      <select name="difficulty" class="border border-gray-300 rounded-lg px-2 py-2 text-xs">
        <option value="">Any level</option>
        <?php foreach (['Easy','Medium','Hard'] as $lv): ?><option <?= $fDiff===$lv?'selected':'' ?>><?= $lv ?></option><?php endforeach; ?>
      </select>
      <select name="source" class="border border-gray-300 rounded-lg px-2 py-2 text-xs">
        <option value="">Any source</option>
        <option value="association" <?= $fSource==='association'?'selected':'' ?>>Association</option>
        <option value="expert" <?= $fSource==='expert'?'selected':'' ?>>Expert</option>
      </select>
      <button class="bg-navy text-white rounded-lg px-3 py-2 text-xs font-medium">Filter</button>
    </form>
    <ul id="bankList" class="space-y-2 min-h-[120px] max-h-[65vh] overflow-y-auto bg-lightgrey rounded-xl p-3 border border-dashed border-gray-300">
      <?php foreach ($bank as $q): ?>
        <li class="qcard bg-white rounded-lg border border-gray-200 p-3 cursor-move" data-id="<?= (int)$q['id'] ?>">
          <div class="text-sm font-medium text-navy"><?= e(mb_strimwidth($q['question_text'],0,90,'…')) ?></div>
          <div class="text-xs text-gray-400 mt-1"><?= e(ucfirst($q['suggested_round'])) ?> · <?= e($q['difficulty']) ?><?= $q['sport'] ? ' · ' . e($q['sport']) : '' ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Slot target -->
  <div class="lg:sticky lg:top-20">
    <h2 class="font-semibold text-navy mb-2">In this Slot</h2>
    <ul id="slotList" class="space-y-2 min-h-[120px] max-h-[65vh] overflow-y-auto bg-teal/5 rounded-xl p-3 border border-dashed border-teal/40">
      <?php foreach ($assigned as $q): $isCancelled = (int)($q['cancelled'] ?? 0) === 1; ?>
        <li class="qcard bg-white rounded-lg border <?= $isCancelled ? 'border-red-300 bg-red-50' : 'border-teal/30' ?> p-3 cursor-move" data-id="<?= (int)$q['id'] ?>">
          <div class="flex items-start justify-between gap-2">
            <div class="text-sm font-medium <?= $isCancelled ? 'text-red-700 line-through' : 'text-navy' ?>"><?= e(mb_strimwidth($q['question_text'],0,90,'…')) ?></div>
            <button type="button" class="cancelBtn no-drag shrink-0 text-xs <?= $isCancelled ? 'text-teal' : 'text-red-600' ?> hover:underline" data-id="<?= (int)$q['id'] ?>"><?= $isCancelled ? 'Restore' : 'Cancel' ?></button>
          </div>
          <div class="text-xs text-gray-400 mt-1"><?= e(ucfirst($q['suggested_round'])) ?> · <?= e($q['difficulty']) ?><?= $isCancelled ? ' · <span class="text-red-600 font-medium">CANCELLED</span>' : '' ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="text-xs text-gray-400 mt-2">Drag a card back to the bank to remove it. Use <span class="text-red-600">Cancel</span> to void a wrong question (it stays for past attempts but is excluded from scoring after cancellation).</p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
  const CSRF = '<?= e(csrf_token()) ?>';
  const SLOT_ID = <?= $slotId ?>;
  const TARGET = <?= (int)$slot['question_count'] ?>;
  const API = '<?= e(BASE_URL) ?>/api/slot_questions.php';

  function post(data){
    return fetch(API, {
      method:'POST',
      headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams(data)
    }).then(r=>r.json());
  }
  function updateCount(c){
    const el=document.getElementById('liveCount');
    el.textContent=c;
    el.parentElement.classList.toggle('bg-amber-100', c>TARGET);
    el.parentElement.classList.toggle('text-amber-800', c>TARGET);
  }
  function sendReorder(){
    const order=[...document.querySelectorAll('#slotList .qcard')].map(li=>li.dataset.id);
    const params=new URLSearchParams();
    params.append('action','reorder');
    params.append('slot_id',SLOT_ID);
    order.forEach(id=>params.append('order[]',id));
    fetch(API,{method:'POST',headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:params}).catch(()=>{});
  }

  const bankEl=document.getElementById('bankList');
  const slotEl=document.getElementById('slotList');

  new Sortable(bankEl,{group:'questions',animation:150,scroll:true,bubbleScroll:true,scrollSensitivity:60,scrollSpeed:12,
    onAdd:function(evt){
      // Card moved from slot back to bank => remove from slot.
      const id=evt.item.dataset.id;
      post({action:'remove',slot_id:SLOT_ID,question_id:id}).then(r=>{ if(r.ok) updateCount(r.count); });
    }
  });
  new Sortable(slotEl,{group:'questions',animation:150,scroll:true,bubbleScroll:true,scrollSensitivity:60,scrollSpeed:12,
    filter:'.no-drag', preventOnFilter:false,
    onAdd:function(evt){
      const id=evt.item.dataset.id;
      post({action:'add',slot_id:SLOT_ID,question_id:id}).then(r=>{ if(r.ok){ updateCount(r.count); sendReorder(); } });
    },
    onUpdate:function(){ sendReorder(); }
  });

  // Cancel / restore a question within the slot.
  slotEl.addEventListener('click', function(ev){
    const btn = ev.target.closest('.cancelBtn');
    if (!btn) return;
    ev.stopPropagation();
    const id = btn.dataset.id;
    post({action:'toggle_cancel', slot_id:SLOT_ID, question_id:id}).then(r=>{
      if (!r.ok) { alert(r.error || 'Could not update the question.'); return; }
      const card = btn.closest('.qcard');
      const title = card.querySelector('.text-sm');
      const meta = card.querySelector('.text-xs');
      if (r.cancelled) {
        card.classList.add('border-red-300','bg-red-50'); card.classList.remove('border-teal/30');
        title.classList.add('text-red-700','line-through'); title.classList.remove('text-navy');
        btn.textContent = 'Restore'; btn.classList.add('text-teal'); btn.classList.remove('text-red-600');
      } else {
        card.classList.remove('border-red-300','bg-red-50'); card.classList.add('border-teal/30');
        title.classList.remove('text-red-700','line-through'); title.classList.add('text-navy');
        btn.textContent = 'Cancel'; btn.classList.remove('text-teal'); btn.classList.add('text-red-600');
      }
    });
  });
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
