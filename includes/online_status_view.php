<?php
/**
 * Shared "Online Status" live dashboard view.
 * Included by admin/online_status.php and expert/online_status.php after the
 * role check. Pulls data from /api/online_status.php and auto-refreshes.
 */
declare(strict_types=1);

$pageTitle = 'Online Status';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-1">
  <h1 class="text-2xl font-bold text-navy">Online Status</h1>
  <div class="text-xs text-gray-500">Auto-refreshing · <span id="serverTime">—</span></div>
</div>
<p class="text-gray-500 mb-6 text-sm">Live view of school logins and exam progress. Click any number to see the schools.</p>

<!-- Summary cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-8">
  <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
    <div id="c_schools" class="text-3xl font-extrabold text-navy">—</div>
    <div class="text-xs text-gray-500 mt-1">Total schools</div>
  </div>
  <button type="button" data-metric="logged_in" class="drill text-left bg-teal text-white rounded-xl p-4 shadow-sm hover:opacity-95">
    <div id="c_logged_in" class="text-3xl font-extrabold">—</div>
    <div class="text-xs text-white/85 mt-1">Logged in</div>
  </button>
  <button type="button" data-metric="not_logged_in" class="drill text-left bg-white border border-gray-100 rounded-xl p-4 shadow-sm hover:shadow">
    <div id="c_not_logged_in" class="text-3xl font-extrabold text-gray-700">—</div>
    <div class="text-xs text-gray-500 mt-1">Not logged in</div>
  </button>
  <button type="button" data-metric="started" class="drill text-left bg-navy text-white rounded-xl p-4 shadow-sm hover:opacity-95">
    <div id="c_started" class="text-3xl font-extrabold">—</div>
    <div class="text-xs text-white/85 mt-1">Exam started</div>
  </button>
  <button type="button" data-metric="completed" class="drill text-left bg-navy text-white rounded-xl p-4 shadow-sm hover:opacity-95">
    <div id="c_completed" class="text-3xl font-extrabold">—</div>
    <div class="text-xs text-white/85 mt-1">Exam completed</div>
  </button>
</div>

<div class="grid lg:grid-cols-2 gap-6">
  <!-- Login activity bar chart -->
  <div class="bg-white border border-gray-100 rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-navy mb-1">Logins by hour</h2>
    <p class="text-xs text-gray-500 mb-4">Number of schools whose last login falls in each 1-hour interval.</p>
    <div id="chart" class="space-y-2"></div>
    <div id="chartEmpty" class="text-sm text-gray-400 hidden">No logins recorded yet.</div>
  </div>

  <!-- Per-slot progress -->
  <div class="bg-white border border-gray-100 rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-navy mb-4">Exam progress by slot</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-lightgrey text-gray-600 text-left">
          <tr><th class="px-3 py-2">Slot</th><th class="px-3 py-2">Assigned</th><th class="px-3 py-2">Started</th><th class="px-3 py-2">Completed</th></tr>
        </thead>
        <tbody id="slotBody" class="divide-y divide-gray-100">
          <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Drill-down modal -->
<div id="listModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-3xl p-6 my-8">
    <div class="flex items-center justify-between mb-4">
      <h2 id="listTitle" class="text-lg font-bold text-navy">Schools</h2>
      <button onclick="document.getElementById('listModal').classList.add('hidden')" class="text-gray-500 text-xl leading-none">&times;</button>
    </div>
    <div id="listBody" class="overflow-x-auto max-h-[70vh] overflow-y-auto"></div>
  </div>
</div>

<script>
  const API = '<?= e(BASE_URL) ?>/api/online_status.php';
  const el = id => document.getElementById(id);
  const esc = s => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

  function renderStats(d) {
    el('serverTime').textContent = d.server_time || '';
    el('c_schools').textContent = d.totals.schools;
    el('c_logged_in').textContent = d.totals.logged_in;
    el('c_not_logged_in').textContent = d.totals.not_logged_in;
    el('c_started').textContent = d.totals.started;
    el('c_completed').textContent = d.totals.completed;

    // Bar chart
    const chart = el('chart');
    const max = d.buckets.reduce((m, b) => Math.max(m, b.count), 0);
    el('chartEmpty').classList.toggle('hidden', d.buckets.length > 0);
    chart.innerHTML = d.buckets.map(b => {
      const w = max ? Math.round(b.count / max * 100) : 0;
      return '<div class="flex items-center gap-2">'
        + '<div class="w-24 shrink-0 text-xs text-gray-500 text-right">' + esc(b.label) + '</div>'
        + '<div class="flex-1 bg-gray-100 rounded h-5 overflow-hidden"><div class="bg-teal h-5 rounded" style="width:' + w + '%"></div></div>'
        + '<div class="w-8 text-xs font-medium text-navy text-right">' + b.count + '</div>'
        + '</div>';
    }).join('');

    // Slot table
    const body = el('slotBody');
    if (!d.slots.length) {
      body.innerHTML = '<tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No slots yet.</td></tr>';
    } else {
      body.innerHTML = d.slots.map(s =>
        '<tr>'
        + '<td class="px-3 py-2 font-medium text-navy">' + esc(s.name) + ' <span class="text-xs text-gray-400">R' + s.round_no + '</span></td>'
        + '<td class="px-3 py-2"><button class="drill text-navy hover:underline" data-metric="assigned" data-slot="' + s.slot_id + '">' + s.assigned + '</button></td>'
        + '<td class="px-3 py-2"><button class="drill text-navy hover:underline" data-metric="started" data-slot="' + s.slot_id + '">' + s.started + '</button></td>'
        + '<td class="px-3 py-2"><button class="drill text-navy hover:underline" data-metric="completed" data-slot="' + s.slot_id + '">' + s.completed + '</button></td>'
        + '</tr>'
      ).join('');
      bindDrill();
    }
  }

  function loadStats() {
    fetch(API + '?data=stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(d => { if (d.ok) renderStats(d); }).catch(() => {});
  }

  function openList(metric, slot) {
    const qs = '?data=list&metric=' + encodeURIComponent(metric) + (slot ? '&slot_id=' + slot : '');
    el('listBody').innerHTML = '<div class="text-sm text-gray-400 py-6 text-center">Loading…</div>';
    el('listModal').classList.remove('hidden');
    fetch(API + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(d => {
        if (!d.ok) { el('listBody').innerHTML = '<div class="text-sm text-red-600 py-6 text-center">Could not load.</div>'; return; }
        el('listTitle').textContent = d.title + ' (' + d.rows.length + ')';
        if (!d.rows.length) { el('listBody').innerHTML = '<div class="text-sm text-gray-400 py-6 text-center">No schools.</div>'; return; }
        let h = '<table class="min-w-full text-sm"><thead class="bg-lightgrey text-gray-600 text-left"><tr>'
          + d.headers.map(x => '<th class="px-3 py-2">' + esc(x) + '</th>').join('') + '</tr></thead><tbody class="divide-y divide-gray-100">'
          + d.rows.map(row => '<tr>' + row.map(c => '<td class="px-3 py-2">' + esc(c) + '</td>').join('') + '</tr>').join('')
          + '</tbody></table>';
        el('listBody').innerHTML = h;
      }).catch(() => { el('listBody').innerHTML = '<div class="text-sm text-red-600 py-6 text-center">Network error.</div>'; });
  }

  function bindDrill() {
    document.querySelectorAll('.drill').forEach(b => {
      if (b._bound) return; b._bound = true;
      b.addEventListener('click', () => openList(b.dataset.metric, b.dataset.slot || ''));
    });
  }

  bindDrill();
  loadStats();
  setInterval(loadStats, 20000); // refresh every 20s
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
