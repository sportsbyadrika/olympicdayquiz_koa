<?php
/**
 * Expert · Master Question Bank.
 * Filterable, paginated list. Add own questions and edit any master question
 * (all fields including Suggested Round).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

$expert = current_profile();
$expertId = $expert['id'] ?? null;

/** Read + validate master question input. */
function read_master_input(): array
{
    $d = [
        'question_text' => post('question_text'),
        'option_a' => post('option_a'), 'option_b' => post('option_b'),
        'option_c' => post('option_c'), 'option_d' => post('option_d'),
        'correct_option' => strtoupper(post('correct_option')),
        'sport' => post('sport'), 'category' => post('category'),
        'difficulty' => post('difficulty', 'Medium'),
        'reference_source' => post('reference_source'),
        'explanation' => post('explanation'),
        'suggested_round' => post('suggested_round', 'either'),
    ];
    $errors = [];
    if ($d['question_text'] === '') $errors[] = 'Question text is required.';
    foreach (['a','b','c','d'] as $o) {
        if ($d["option_{$o}"] === '') $errors[] = 'Option ' . strtoupper($o) . ' is required.';
    }
    if (!in_array($d['correct_option'], ['A','B','C','D'], true)) $errors[] = 'Select a correct option.';
    if (!in_array($d['difficulty'], ['Easy','Medium','Hard'], true)) $d['difficulty'] = 'Medium';
    if (!in_array($d['suggested_round'], ['round1','round2','either'], true)) $d['suggested_round'] = 'either';
    return [$d, $errors];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'add') {
        [$d, $errors] = read_master_input();
        if ($errors) { flash('error', implode(' ', $errors)); }
        else {
            db()->prepare(
                'INSERT INTO questions_master
                 (question_text, option_a, option_b, option_c, option_d, correct_option, sport, category, difficulty, reference_source, explanation, suggested_round, created_by_expert_id)
                 VALUES (:qt,:a,:b,:c,:d,:co,:sport,:cat,:diff,:ref,:exp,:sr,:eid)'
            )->execute([
                ':qt' => $d['question_text'], ':a' => $d['option_a'], ':b' => $d['option_b'], ':c' => $d['option_c'], ':d' => $d['option_d'],
                ':co' => $d['correct_option'], ':sport' => $d['sport'], ':cat' => $d['category'], ':diff' => $d['difficulty'],
                ':ref' => $d['reference_source'], ':exp' => $d['explanation'], ':sr' => $d['suggested_round'], ':eid' => $expertId,
            ]);
            audit_log('master_add', 'questions_master', (int) db()->lastInsertId());
            flash('success', 'Question added to the master bank.');
        }
        redirect('/expert/master_questions.php');
    }

    if ($action === 'edit') {
        $id = int_val(post('id'));
        [$d, $errors] = read_master_input();
        if ($id && !$errors) {
            db()->prepare(
                'UPDATE questions_master SET question_text=:qt, option_a=:a, option_b=:b, option_c=:c, option_d=:d,
                 correct_option=:co, sport=:sport, category=:cat, difficulty=:diff, reference_source=:ref, explanation=:exp, suggested_round=:sr WHERE id=:id'
            )->execute([
                ':qt' => $d['question_text'], ':a' => $d['option_a'], ':b' => $d['option_b'], ':c' => $d['option_c'], ':d' => $d['option_d'],
                ':co' => $d['correct_option'], ':sport' => $d['sport'], ':cat' => $d['category'], ':diff' => $d['difficulty'],
                ':ref' => $d['reference_source'], ':exp' => $d['explanation'], ':sr' => $d['suggested_round'], ':id' => $id,
            ]);
            audit_log('master_edit', 'questions_master', $id);
            flash('success', 'Question updated.');
        } else { flash('error', implode(' ', $errors ?: ['Invalid request.'])); }
        redirect('/expert/master_questions.php');
    }

    if ($action === 'delete') {
        $id = int_val(post('id'));
        if ($id) {
            db()->prepare('DELETE FROM questions_master WHERE id=?')->execute([$id]);
            audit_log('master_delete', 'questions_master', $id);
            flash('success', 'Question removed from master bank.');
        }
        redirect('/expert/master_questions.php');
    }
}

// Filters + pagination
$fQ = get('q');
$fRound = get('round');
$fDiff = get('difficulty');
$fSport = get('sport');
$fSource = get('source'); // 'association' | 'expert'
$page = max(1, int_val(get('page')));
$perPage = 15;

$where = '1=1';
$params = [];
if ($fQ !== '') {
    $where .= ' AND (question_text LIKE :q1 OR option_a LIKE :q2 OR option_b LIKE :q3 OR option_c LIKE :q4 OR option_d LIKE :q5)';
    $like = '%' . $fQ . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
}
if (in_array($fRound, ['round1','round2','either'], true)) { $where .= ' AND suggested_round = :r'; $params[':r'] = $fRound; }
if (in_array($fDiff, ['Easy','Medium','Hard'], true)) { $where .= ' AND difficulty = :diff'; $params[':diff'] = $fDiff; }
if ($fSport !== '') { $where .= ' AND sport LIKE :sp'; $params[':sp'] = '%' . $fSport . '%'; }
if ($fSource === 'association') { $where .= ' AND source_association_id IS NOT NULL'; }
elseif ($fSource === 'expert') { $where .= ' AND source_association_id IS NULL'; }

$countStmt = db()->prepare("SELECT COUNT(*) FROM questions_master WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT qm.*, a.name AS source_assoc FROM questions_master qm
    LEFT JOIN associations a ON a.id = qm.source_association_id
    WHERE $where ORDER BY qm.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Master Bank';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <h1 class="text-2xl font-bold text-navy">Master Question Bank <span class="text-gray-400 font-normal text-base">(<?= $total ?>)</span></h1>
  <button onclick="openAdd()" class="bg-teal text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ Add Question</button>
</div>

<form method="get" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6 grid sm:grid-cols-2 lg:grid-cols-6 gap-3">
  <input name="q" value="<?= e($fQ) ?>" placeholder="Search question / options" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm sm:col-span-2 lg:col-span-2">
  <select name="round" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
    <option value="">Any round</option>
    <option value="round1" <?= $fRound==='round1'?'selected':'' ?>>Round 1</option>
    <option value="round2" <?= $fRound==='round2'?'selected':'' ?>>Round 2</option>
    <option value="either" <?= $fRound==='either'?'selected':'' ?>>Either</option>
  </select>
  <select name="difficulty" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
    <option value="">Any level</option>
    <?php foreach (['Easy','Medium','Hard'] as $lv): ?><option <?= $fDiff===$lv?'selected':'' ?>><?= $lv ?></option><?php endforeach; ?>
  </select>
  <input name="sport" value="<?= e($fSport) ?>" placeholder="Sport" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
  <select name="source" class="border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
    <option value="">Any source</option>
    <option value="association" <?= $fSource==='association'?'selected':'' ?>>From association</option>
    <option value="expert" <?= $fSource==='expert'?'selected':'' ?>>Expert-authored</option>
  </select>
  <button class="bg-navy text-white rounded-lg px-4 py-2.5 text-sm font-medium">Filter</button>
</form>

<div class="space-y-3 mb-6">
  <?php if (!$rows): ?><div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400">No questions match.</div><?php endif; ?>
  <?php foreach ($rows as $q): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="font-medium text-navy"><?= e($q['question_text']) ?></div>
        <div class="flex gap-2 shrink-0">
          <button class="text-navy hover:underline text-sm" onclick='openEdit(<?= json_encode($q, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" class="inline" onsubmit="return confirm('Delete from master bank?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="text-red-600 hover:underline text-sm">Delete</button>
          </form>
        </div>
      </div>
      <div class="grid sm:grid-cols-4 gap-1 text-xs text-gray-600 mt-2">
        <div>A: <?= e(mb_strimwidth($q['option_a'],0,40,'…')) ?></div>
        <div>B: <?= e(mb_strimwidth($q['option_b'],0,40,'…')) ?></div>
        <div>C: <?= e(mb_strimwidth($q['option_c'],0,40,'…')) ?></div>
        <div>D: <?= e(mb_strimwidth($q['option_d'],0,40,'…')) ?></div>
      </div>
      <div class="flex flex-wrap gap-2 mt-3 text-xs">
        <span class="px-2 py-0.5 rounded-full bg-teal/10 text-teal font-medium">Correct: <?= e($q['correct_option']) ?></span>
        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= e(ucfirst($q['suggested_round'])) ?></span>
        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= e($q['difficulty']) ?></span>
        <?php if ($q['sport']): ?><span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= e($q['sport']) ?></span><?php endif; ?>
        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500"><?= $q['source_assoc'] ? 'From: ' . e($q['source_assoc']) : 'Expert-authored' ?></span>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<div class="flex justify-center gap-1 mb-8 flex-wrap">
  <?php for ($p = 1; $p <= $pages; $p++):
    $qs = http_build_query(array_merge($_GET, ['page' => $p])); ?>
    <a href="?<?= e($qs) ?>" class="px-3 py-2 rounded-lg text-sm <?= $p===$page ? 'bg-navy text-white' : 'bg-white border border-gray-200 text-gray-600' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Add/Edit modal -->
<div id="mModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-2xl p-6 my-6">
    <h2 id="mTitle" class="text-lg font-bold text-navy mb-4">Add Question</h2>
    <form method="post" id="mForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="m_action" value="add">
      <input type="hidden" name="id" id="m_id">
      <label class="block text-sm font-medium mb-1">Question text *</label>
      <textarea name="question_text" id="m_text" rows="3" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3"></textarea>
      <div class="grid sm:grid-cols-2 gap-3">
        <?php foreach (['a','b','c','d'] as $o): ?>
          <div><label class="block text-sm font-medium mb-1">Option <?= strtoupper($o) ?> *</label><input name="option_<?= $o ?>" id="m_opt_<?= $o ?>" maxlength="500" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <?php endforeach; ?>
      </div>
      <div class="grid sm:grid-cols-3 gap-3 mt-3">
        <div>
          <label class="block text-sm font-medium mb-1">Correct *</label>
          <div class="flex gap-3 mt-1">
            <?php foreach (['A','B','C','D'] as $c): ?><label class="flex items-center gap-1 text-sm"><input type="radio" name="correct_option" value="<?= $c ?>" class="m_correct"> <?= $c ?></label><?php endforeach; ?>
          </div>
        </div>
        <div><label class="block text-sm font-medium mb-1">Difficulty</label><select name="difficulty" id="m_diff" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"><option>Easy</option><option selected>Medium</option><option>Hard</option></select></div>
        <div><label class="block text-sm font-medium mb-1">Suggested Round</label><select name="suggested_round" id="m_round" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"><option value="either" selected>Either</option><option value="round1">Round 1</option><option value="round2">Round 2</option></select></div>
        <div><label class="block text-sm font-medium mb-1">Sport</label><input name="sport" id="m_sport" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Category</label><input name="category" id="m_cat" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Reference</label><input name="reference_source" id="m_ref" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-3"><label class="block text-sm font-medium mb-1">Explanation</label><textarea name="explanation" id="m_exp" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></textarea></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('mModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-teal text-white font-medium">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
  function resetM(){document.getElementById('mForm').reset();document.getElementById('m_action').value='add';document.getElementById('m_id').value='';document.getElementById('mTitle').textContent='Add Question';}
  function openAdd(){resetM();document.getElementById('mModal').classList.remove('hidden');}
  function openEdit(q){
    resetM();
    document.getElementById('m_action').value='edit';
    document.getElementById('m_id').value=q.id;
    document.getElementById('mTitle').textContent='Edit Question';
    document.getElementById('m_text').value=q.question_text||'';
    document.getElementById('m_opt_a').value=q.option_a||'';
    document.getElementById('m_opt_b').value=q.option_b||'';
    document.getElementById('m_opt_c').value=q.option_c||'';
    document.getElementById('m_opt_d').value=q.option_d||'';
    document.querySelectorAll('.m_correct').forEach(r=>r.checked=(r.value===q.correct_option));
    document.getElementById('m_diff').value=q.difficulty||'Medium';
    document.getElementById('m_round').value=q.suggested_round||'either';
    document.getElementById('m_sport').value=q.sport||'';
    document.getElementById('m_cat').value=q.category||'';
    document.getElementById('m_ref').value=q.reference_source||'';
    document.getElementById('m_exp').value=q.explanation||'';
    document.getElementById('mModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
