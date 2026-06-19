<?php
/**
 * AJAX · Chat endpoint.
 *   action=send     (POST, CSRF)  body[, thread_id]   → append a message
 *   action=fetch    (GET/POST)    [thread_id][, after_id] → messages (marks read)
 *   action=threads  (GET)         support only         → thread list with unread
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/chat.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$role = current_role();
$userId = (int) current_user_id();
$action = $_REQUEST['action'] ?? '';

/** Resolve the thread for this request, honouring role-based access. */
function resolve_thread(string $role, bool $createForSchool = false): int
{
    if ($role === 'school') {
        $profile = current_profile();
        if (!$profile) { return 0; }
        return chat_school_thread((int) $profile['id'], $createForSchool);
    }
    // admin / expert must specify the thread
    return int_val($_REQUEST['thread_id'] ?? 0);
}

if ($action === 'threads') {
    if (!in_array($role, ['admin', 'expert'], true)) {
        json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
    $rows = chat_support_threads($userId);
    $threads = array_map(fn($t) => [
        'thread_id' => (int) $t['thread_id'],
        'school'    => $t['school_name'] . ' (' . $t['code'] . ')',
        'preview'   => mb_strimwidth((string) $t['last_body'], 0, 60, '…'),
        'time'      => $t['last_message_at'] ? date('d M, H:i', strtotime((string) $t['last_message_at'])) : '',
        'unread'    => (int) $t['unread'],
    ], $rows);
    json_response(['ok' => true, 'threads' => $threads]);
}

if ($action === 'send') {
    csrf_check();
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        json_response(['ok' => false, 'error' => 'Message is empty.'], 422);
    }
    if (mb_strlen($body) > 4000) {
        $body = mb_substr($body, 0, 4000);
    }
    $threadId = resolve_thread($role, true);
    if (!$threadId || !chat_can_access($threadId)) {
        json_response(['ok' => false, 'error' => 'Thread not found.'], 404);
    }
    $msg = chat_post($threadId, $userId, $role, $body);
    chat_mark_read($threadId, $userId);
    json_response(['ok' => true, 'message' => format_msg($msg, $role)]);
}

if ($action === 'fetch') {
    $threadId = resolve_thread($role, false);
    if (!$threadId) {
        json_response(['ok' => true, 'messages' => [], 'thread_id' => 0]);
    }
    if (!chat_can_access($threadId)) {
        json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
    $afterId = int_val($_REQUEST['after_id'] ?? 0);
    $rows = chat_fetch($threadId, $afterId);
    chat_mark_read($threadId, $userId);
    $messages = array_map(fn($m) => format_msg($m, $role), $rows);
    json_response(['ok' => true, 'messages' => $messages, 'thread_id' => $threadId]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 422);

/** Shape a message row for the client, marking whether the viewer sent it. */
function format_msg(array $m, string $viewerRole): array
{
    $labels = ['school' => 'School', 'admin' => 'Admin', 'expert' => 'Expert'];
    return [
        'id'    => (int) $m['id'],
        'role'  => $m['sender_role'],
        'label' => $labels[$m['sender_role']] ?? ucfirst($m['sender_role']),
        'body'  => $m['body'],
        'time'  => date('d M, H:i', strtotime((string) $m['created_at'])),
        'mine'  => ($m['sender_role'] === $viewerRole)
                   || ($viewerRole !== 'school' && in_array($m['sender_role'], ['admin', 'expert'], true)),
    ];
}
