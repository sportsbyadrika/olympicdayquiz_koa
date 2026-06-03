<?php
/**
 * School · Quiz engine UI.
 * One question per page, server-driven countdown, auto-save per selection,
 * side panel (slide-in drawer on mobile), and a Finish flow. Explanations are
 * never shown here — only on the post-declaration result screen.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quiz.php';
require_role('school');

$school = current_profile();
if (!$school) { redirect('/public/logout.php'); }
$schoolId = (int) $school['id'];
$slotId = int_val(get('slot_id'));

$slot = get_slot($slotId);
if (!$slot || !school_in_slot($schoolId, $slotId)) {
    flash('error', 'You are not assigned to that slot.');
    redirect('/school/index.php');
}

$session = start_or_resume_session($schoolId, $slotId);
if (!$session) {
    flash('error', 'The quiz is not available — the slot window may be closed or no questions are assigned.');
    redirect('/school/index.php');
}

// Post-submit lockout: cannot re-enter the quiz once finalized.
if (in_array($session['status'], ['submitted', 'force_submitted'], true)) {
    flash('info', 'Your quiz has been submitted. Results will be shown after declaration.');
    redirect('/school/index.php');
}

// Expired on load → force-submit and bounce out.
if (session_expired($session, $slot)) {
    finalize_session((int) $session['id'], 'force_submitted');
    flash('warning', 'Time was up — your quiz was submitted automatically.');
    redirect('/school/index.php');
}

// Load ordered questions (no correct answer leaked).
$st = db()->prepare(
    'SELECT qm.id, qm.question_text, qm.option_a, qm.option_b, qm.option_c, qm.option_d, sq.sequence_no
     FROM slot_questions sq JOIN questions_master qm ON qm.id=sq.question_id
     WHERE sq.slot_id=? ORDER BY sq.sequence_no, sq.id'
);
$st->execute([$slotId]);
$questions = $st->fetchAll();

// Existing draft answers.
$rs = db()->prepare('SELECT question_id, selected_option FROM responses WHERE session_id=?');
$rs->execute([(int) $session['id']]);
$answers = [];
foreach ($rs->fetchAll() as $r) {
    if ($r['selected_option']) { $answers[(int) $r['question_id']] = $r['selected_option']; }
}

$remaining = remaining_seconds($session, $slot);

$pageTitle = 'Quiz';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex items-center justify-between gap-3 mb-4">
  <div>
    <div class="text-xs font-semibold text-teal">Round <?= (int)$slot['round_no'] ?></div>
    <h1 class="text-xl font-bold text-navy"><?= e($slot['slot_name']) ?></h1>
  </div>
  <div class="flex items-center gap-3">
    <div id="timer" class="font-mono text-lg font-bold px-3 py-1.5 rounded-lg bg-navy text-white">--:--</div>
    <button id="drawerToggle" class="lg:hidden bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm">Questions</button>
  </div>
</div>

<div class="grid lg:grid-cols-[1fr_280px] gap-6">
  <!-- Question area -->
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 sm:p-6">
    <div id="questionArea"></div>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-6 border-t border-gray-100 pt-4">
      <div class="flex gap-2">
        <button id="btnBack" class="bg-white border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-medium min-h-[44px]">&larr; Back</button>
        <button id="btnNext" class="bg-white border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-medium min-h-[44px]">Next &rarr;</button>
      </div>
      <div class="flex gap-2">
        <span id="saveStatus" class="text-xs text-gray-400 self-center"></span>
        <button id="btnFinish" class="bg-teal text-white rounded-lg px-5 py-2.5 text-sm font-semibold min-h-[44px]">Finish</button>
      </div>
    </div>
  </div>

  <!-- Side panel / drawer -->
  <div id="sidePanel" class="hidden lg:block">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:sticky lg:top-20">
      <div class="font-semibold text-navy mb-3 text-sm">Questions</div>
      <div id="qNav" class="grid grid-cols-6 lg:grid-cols-5 gap-2"></div>
      <div class="mt-4 space-y-1 text-xs text-gray-500">
        <div><span class="inline-block w-3 h-3 rounded bg-green-500 align-middle"></span> Answered</div>
        <div><span class="inline-block w-3 h-3 rounded bg-gray-300 align-middle"></span> Unanswered</div>
      </div>
    </div>
  </div>
</div>

<!-- Mobile drawer overlay -->
<div id="drawerOverlay" class="hidden fixed inset-0 bg-black/40 z-40 lg:hidden"></div>
<div id="mobileDrawer" class="fixed top-0 right-0 h-full w-72 bg-white shadow-xl z-50 transform translate-x-full transition-transform lg:hidden p-4 overflow-y-auto">
  <div class="flex items-center justify-between mb-4">
    <div class="font-semibold text-navy">Questions</div>
    <button id="drawerClose" class="text-gray-500 text-xl leading-none">&times;</button>
  </div>
  <div id="qNavMobile" class="grid grid-cols-5 gap-2"></div>
</div>

<!-- Finish modal -->
<div id="finishModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-sm p-6 text-center">
    <h2 class="text-lg font-bold text-navy mb-2">Submit your quiz?</h2>
    <p id="finishSummary" class="text-sm text-gray-500 mb-5"></p>
    <div class="flex gap-2 justify-center">
      <button onclick="document.getElementById('finishModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Keep going</button>
      <button id="btnConfirmFinish" class="px-4 py-2 rounded-lg bg-teal text-white font-medium">Submit</button>
    </div>
  </div>
</div>

<script>
  const SLOT_ID = <?= $slotId ?>;
  const CSRF = '<?= e(csrf_token()) ?>';
  const BASE = '<?= e(BASE_URL) ?>';
  const QUESTIONS = <?= json_encode(array_map(fn($q) => [
      'id' => (int) $q['id'],
      'text' => $q['question_text'],
      'options' => ['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']],
  ], $questions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  let answers = <?= json_encode((object) $answers, JSON_HEX_TAG) ?>;
  let current = 0;
  let remaining = <?= (int) $remaining ?>;

  const el = id => document.getElementById(id);
  const esc = s => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

  function renderQuestion() {
    const q = QUESTIONS[current];
    const sel = answers[q.id] || '';
    let html = '<div class="text-xs text-gray-400 mb-1">Question ' + (current + 1) + ' of ' + QUESTIONS.length + '</div>';
    html += '<div class="text-lg font-semibold text-navy mb-4">' + esc(q.text) + '</div>';
    html += '<div class="space-y-2">';
    for (const L of ['A','B','C','D']) {
      const checked = sel === L ? 'checked' : '';
      const active = sel === L ? 'border-teal bg-teal/5' : 'border-gray-200';
      html += '<label class="flex items-start gap-3 border ' + active + ' rounded-lg px-4 py-3 cursor-pointer min-h-[44px]">'
        + '<input type="radio" name="opt" value="' + L + '" ' + checked + ' class="mt-1">'
        + '<span><span class="font-semibold mr-1">' + L + '.</span>' + esc(q.options[L]) + '</span></label>';
    }
    html += '</div>';
    el('questionArea').innerHTML = html;
    el('questionArea').querySelectorAll('input[name=opt]').forEach(r => {
      r.addEventListener('change', () => selectOption(q.id, r.value));
    });
    el('btnBack').disabled = current === 0;
    el('btnBack').classList.toggle('opacity-40', current === 0);
    el('btnNext').disabled = current === QUESTIONS.length - 1;
    el('btnNext').classList.toggle('opacity-40', current === QUESTIONS.length - 1);
    renderNav();
  }

  function renderNav() {
    const build = (containerId) => {
      const c = el(containerId);
      if (!c) return;
      c.innerHTML = '';
      QUESTIONS.forEach((q, i) => {
        const answered = !!answers[q.id];
        const b = document.createElement('button');
        b.textContent = i + 1;
        b.className = 'h-9 rounded text-sm font-medium ' +
          (i === current ? 'ring-2 ring-navy ' : '') +
          (answered ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-600');
        b.addEventListener('click', () => { current = i; renderQuestion(); closeDrawer(); });
        c.appendChild(b);
      });
    };
    build('qNav');
    build('qNavMobile');
  }

  function selectOption(qid, value) {
    answers[qid] = value;
    renderQuestion();
    el('saveStatus').textContent = 'Saving…';
    fetch(BASE + '/api/autosave.php', {
      method: 'POST',
      headers: {'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({csrf_token: CSRF, slot_id: SLOT_ID, question_id: qid, selected_option: value})
    }).then(r => r.json()).then(d => {
      if (d.ok) { el('saveStatus').textContent = 'Saved'; setTimeout(() => el('saveStatus').textContent = '', 1200); }
      else if (d.expired) { lockedOut('Time is up — your quiz was submitted.'); }
      else if (d.locked) { lockedOut('Your quiz is already submitted.'); }
      else { el('saveStatus').textContent = 'Save failed'; }
    }).catch(() => { el('saveStatus').textContent = 'Offline — will retry'; });
  }

  // Navigation
  el('btnBack').addEventListener('click', () => { if (current > 0) { current--; renderQuestion(); } });
  el('btnNext').addEventListener('click', () => { if (current < QUESTIONS.length - 1) { current++; renderQuestion(); } });

  // Finish
  el('btnFinish').addEventListener('click', () => {
    const answered = Object.keys(answers).filter(k => answers[k]).length;
    el('finishSummary').textContent = answered + ' of ' + QUESTIONS.length + ' answered. Unanswered questions score zero. This cannot be undone.';
    el('finishModal').classList.remove('hidden');
  });
  el('btnConfirmFinish').addEventListener('click', () => {
    el('btnConfirmFinish').disabled = true;
    fetch(BASE + '/api/submit.php', {
      method: 'POST',
      headers: {'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({csrf_token: CSRF, slot_id: SLOT_ID})
    }).then(r => r.json()).then(d => { window.location = d.redirect || (BASE + '/school/index.php'); })
      .catch(() => { window.location = BASE + '/school/index.php'; });
  });

  // Drawer
  function openDrawer(){ el('mobileDrawer').classList.remove('translate-x-full'); el('drawerOverlay').classList.remove('hidden'); }
  function closeDrawer(){ el('mobileDrawer').classList.add('translate-x-full'); el('drawerOverlay').classList.add('hidden'); }
  el('drawerToggle').addEventListener('click', openDrawer);
  el('drawerClose').addEventListener('click', closeDrawer);
  el('drawerOverlay').addEventListener('click', closeDrawer);

  // Timer: local countdown for display, server sync every 10s.
  function fmt(s){ const m = Math.floor(s/60), x = s%60; return (m<10?'0':'')+m+':'+(x<10?'0':'')+x; }
  function paintTimer(){
    el('timer').textContent = fmt(Math.max(0, remaining));
    el('timer').classList.toggle('bg-red-600', remaining <= 60);
    el('timer').classList.toggle('bg-navy', remaining > 60);
  }
  function lockedOut(msg){ alert(msg); window.location = BASE + '/school/index.php'; }
  function tick(){
    remaining--;
    if (remaining <= 0) { remaining = 0; paintTimer(); syncTimer(); return; }
    paintTimer();
  }
  function syncTimer(){
    fetch(BASE + '/api/timer.php?slot_id=' + SLOT_ID, {headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r => r.json()).then(d => {
        if (!d.ok) return;
        remaining = d.remaining;
        paintTimer();
        if (d.expired) { lockedOut('Time is up — your quiz was submitted automatically.'); }
      }).catch(() => {});
  }

  renderQuestion();
  paintTimer();
  setInterval(tick, 1000);
  setInterval(syncTimer, 10000);
  syncTimer();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
