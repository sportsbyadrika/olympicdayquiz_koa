<?php
/**
 * Shared support chat view (admin + expert).
 * Left: scrollable list of school threads with unread badges.
 * Right: selected thread history + new-message box. Polled for updates.
 * Included by admin/chat.php and expert/chat.php after the role check.
 */
declare(strict_types=1);

$pageTitle = 'Chat';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Chat</h1>
<p class="text-gray-500 mb-4 text-sm">Conversations with schools. Messages cannot be deleted.</p>

<div class="grid lg:grid-cols-[300px_1fr] gap-4" style="height:72vh">
  <!-- Threads list -->
  <div class="bg-white border border-gray-100 rounded-xl shadow-sm flex flex-col overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 font-semibold text-navy text-sm">Schools</div>
    <div id="threadList" class="flex-1 overflow-y-auto divide-y divide-gray-100">
      <div class="p-4 text-sm text-gray-400">Loading…</div>
    </div>
  </div>

  <!-- Conversation -->
  <div class="bg-white border border-gray-100 rounded-xl shadow-sm flex flex-col overflow-hidden">
    <div id="convHeader" class="px-4 py-3 border-b border-gray-100 font-semibold text-navy text-sm">Select a school to view the chat</div>
    <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-3">
      <div class="text-center text-sm text-gray-400">No conversation selected.</div>
    </div>
    <form id="chatForm" class="border-t border-gray-100 p-3 flex gap-2 hidden">
      <?= csrf_field() ?>
      <input id="msgInput" name="body" autocomplete="off" maxlength="4000" placeholder="Type your reply…" class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-teal focus:border-teal outline-none">
      <button class="bg-navy text-white rounded-lg px-5 py-2.5 text-sm font-medium min-h-[44px]">Send</button>
    </form>
  </div>
</div>

<script>
  const API = '<?= e(BASE_URL) ?>/api/chat.php';
  const CSRF = '<?= e(csrf_token()) ?>';
  let currentThread = 0;
  let currentName = '';
  let lastId = 0;
  const box = document.getElementById('messages');
  const esc = s => (s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])));

  function bubble(m) {
    const mine = m.mine;                // admin/expert messages render on the right
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

  function loadThreads() {
    fetch(API + '?action=threads', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(d => {
        const list = document.getElementById('threadList');
        if (!d.ok) { list.innerHTML = '<div class="p-4 text-sm text-red-600">' + esc(d.error || 'Could not load.') + '</div>'; return; }
        if (!d.threads.length) { list.innerHTML = '<div class="p-4 text-sm text-gray-400">No conversations yet.</div>'; return; }
        list.innerHTML = d.threads.map(t =>
          '<button type="button" class="threadItem w-full text-left px-4 py-3 hover:bg-lightgrey ' + (t.thread_id === currentThread ? 'bg-lightgrey' : '') + '" data-id="' + t.thread_id + '" data-name="' + esc(t.school) + '">'
          + '<div class="flex items-center justify-between gap-2">'
          + '<span class="font-medium text-navy text-sm truncate">' + esc(t.school) + '</span>'
          + (t.unread > 0 ? '<span class="shrink-0 bg-teal text-white text-[11px] font-semibold rounded-full px-2 py-0.5">' + t.unread + '</span>' : '<span class="text-[11px] text-gray-400">' + esc(t.time) + '</span>')
          + '</div>'
          + '<div class="text-xs text-gray-500 truncate mt-0.5">' + esc(t.preview) + '</div>'
          + '</button>'
        ).join('');
        document.querySelectorAll('.threadItem').forEach(b => {
          b.addEventListener('click', () => openThread(parseInt(b.dataset.id, 10), b.dataset.name));
        });
      }).catch(() => {
        document.getElementById('threadList').innerHTML = '<div class="p-4 text-sm text-red-600">Could not load conversations. Please retry.</div>';
      });
  }

  function openThread(id, name) {
    currentThread = id; currentName = name; lastId = 0;
    document.getElementById('convHeader').textContent = name;
    document.getElementById('chatForm').classList.remove('hidden');
    box.innerHTML = '<div class="text-center text-sm text-gray-400">Loading…</div>';
    pollMessages(true);
    loadThreads();
  }

  function pollMessages(first) {
    if (!currentThread) return;
    fetch(API + '?action=fetch&thread_id=' + currentThread + '&after_id=' + lastId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json()).then(d => {
        if (!d.ok) return;
        if (first) box.innerHTML = '';
        append(d.messages);
        if (first && !d.messages.length) box.innerHTML = '<div class="text-center text-sm text-gray-400">No messages yet.</div>';
      }).catch(() => {});
  }

  document.getElementById('chatForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!currentThread) return;
    const input = document.getElementById('msgInput');
    const body = input.value.trim();
    if (!body) return;
    input.value = '';
    fetch(API, {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: CSRF, action: 'send', thread_id: currentThread, body: body })
    }).then(r => r.json()).then(d => {
      if (d.ok && d.message) { if (box.querySelector('.text-center')) box.innerHTML = ''; append([d.message]); }
      else { input.value = body; alert(d.error || 'Could not send.'); }
    }).catch(() => { input.value = body; });
  });

  loadThreads();
  setInterval(loadThreads, 10000);
  setInterval(() => pollMessages(false), 5000);
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
