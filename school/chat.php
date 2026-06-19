<?php
/**
 * School · Chat with Admin/Expert (single thread, polled for near-live updates).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('school');

$pageTitle = 'Chat';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Chat with Organisers</h1>
<p class="text-gray-500 mb-4 text-sm">Send a message to the admin / expert team. Messages cannot be deleted.</p>

<div class="bg-white border border-gray-100 rounded-xl shadow-sm flex flex-col" style="height:70vh">
  <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-3">
    <div class="text-center text-sm text-gray-400">Loading…</div>
  </div>
  <form id="chatForm" class="border-t border-gray-100 p-3 flex gap-2">
    <?= csrf_field() ?>
    <input id="msgInput" name="body" autocomplete="off" maxlength="4000" placeholder="Type your message…" class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-teal focus:border-teal outline-none">
    <button class="bg-navy text-white rounded-lg px-5 py-2.5 text-sm font-medium min-h-[44px]">Send</button>
  </form>
</div>

<script>
  const API = '<?= e(BASE_URL) ?>/api/chat.php';
  const CSRF = '<?= e(csrf_token()) ?>';
  let lastId = 0;
  const box = document.getElementById('messages');
  const esc = s => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

  function bubble(m) {
    const mine = m.mine;
    const side = mine ? 'items-end' : 'items-start';
    const color = mine ? 'bg-navy text-white' : 'bg-lightgrey text-textdark';
    return '<div class="flex flex-col ' + side + '">'
      + '<div class="max-w-[80%] rounded-2xl px-4 py-2 text-sm ' + color + '">' + esc(m.body).replace(/\n/g, '<br>') + '</div>'
      + '<div class="text-[11px] text-gray-400 mt-0.5">' + esc(m.label) + ' · ' + esc(m.time) + '</div>'
      + '</div>';
  }

  function append(messages) {
    if (!messages.length) return;
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
    messages.forEach(m => { box.insertAdjacentHTML('beforeend', bubble(m)); lastId = Math.max(lastId, m.id); });
    if (atBottom) box.scrollTop = box.scrollHeight;
  }

  function poll() {
    fetch(API + '?action=fetch&after_id=' + lastId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(d => {
        if (!d.ok) return;
        if (lastId === 0) box.innerHTML = ''; // clear "Loading…" on first load
        append(d.messages);
        if (lastId === 0 && !d.messages.length) box.innerHTML = '<div class="text-center text-sm text-gray-400">No messages yet. Say hello!</div>';
      }).catch(() => {});
  }

  document.getElementById('chatForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    const input = document.getElementById('msgInput');
    const body = input.value.trim();
    if (!body) return;
    input.value = '';
    fetch(API, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: CSRF, action: 'send', body: body })
    }).then(r => r.json()).then(d => {
      if (d.ok && d.message) { if (lastId === 0) box.innerHTML = ''; append([d.message]); }
      else { input.value = body; alert(d.error || 'Could not send.'); }
    }).catch(() => { input.value = body; });
  });

  poll();
  setInterval(poll, 5000);
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
