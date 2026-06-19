<?php
/**
 * Chat helpers — school <-> admin/expert messaging.
 * One thread per school; admin and expert share the support side.
 * Messages are append-only (never deleted).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/helpers.php';

/** Get (optionally create) the chat thread id for a school. 0 if none/!create. */
function chat_school_thread(int $schoolId, bool $create = false): int
{
    $st = db()->prepare('SELECT id FROM chat_threads WHERE school_id = ? LIMIT 1');
    $st->execute([$schoolId]);
    $id = (int) $st->fetchColumn();
    if (!$id && $create) {
        db()->prepare('INSERT INTO chat_threads (school_id) VALUES (?)')->execute([$schoolId]);
        $id = (int) db()->lastInsertId();
    }
    return $id;
}

/** The school_id owning a thread, or 0. */
function chat_thread_school(int $threadId): int
{
    $st = db()->prepare('SELECT school_id FROM chat_threads WHERE id = ? LIMIT 1');
    $st->execute([$threadId]);
    return (int) $st->fetchColumn();
}

/** Can the current user access this thread? Admin/expert: any. School: own only. */
function chat_can_access(int $threadId): bool
{
    if (!$threadId) {
        return false;
    }
    $role = current_role();
    if (in_array($role, ['admin', 'expert'], true)) {
        return chat_thread_school($threadId) > 0;
    }
    if ($role === 'school') {
        $profile = current_profile();
        return $profile && chat_thread_school($threadId) === (int) $profile['id'];
    }
    return false;
}

/** Append a message; updates the thread's last_message_at. Returns the new row. */
function chat_post(int $threadId, int $userId, string $role, string $body): array
{
    $body = trim($body);
    db()->prepare(
        'INSERT INTO chat_messages (thread_id, sender_user_id, sender_role, body) VALUES (?,?,?,?)'
    )->execute([$threadId, $userId, $role, $body]);
    $id = (int) db()->lastInsertId();
    db()->prepare('UPDATE chat_threads SET last_message_at = NOW() WHERE id = ?')->execute([$threadId]);
    audit_log('chat_message', 'chat_threads', $threadId, 'msg=' . $id);
    return [
        'id' => $id, 'sender_role' => $role, 'body' => $body,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

/** Messages in a thread after a given id (0 = all). */
function chat_fetch(int $threadId, int $afterId = 0): array
{
    $st = db()->prepare(
        'SELECT id, sender_role, body, created_at FROM chat_messages
         WHERE thread_id = ? AND id > ? ORDER BY id'
    );
    $st->execute([$threadId, $afterId]);
    return $st->fetchAll();
}

/** Mark a thread read up to now for a user. */
function chat_mark_read(int $threadId, int $userId): void
{
    db()->prepare(
        'INSERT INTO chat_reads (thread_id, user_id, last_read_at) VALUES (?,?,NOW())
         ON DUPLICATE KEY UPDATE last_read_at = NOW()'
    )->execute([$threadId, $userId]);
}

/**
 * Threads for the support side (admin/expert), with school info, last message
 * preview and unread count (messages from schools newer than this user's read).
 */
function chat_support_threads(int $userId): array
{
    $sql =
        "SELECT t.id AS thread_id, t.last_message_at, s.name AS school_name, s.code,
            (SELECT m.body FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body,
            (SELECT COUNT(*) FROM chat_messages m
                WHERE m.thread_id = t.id AND m.sender_role = 'school'
                  AND m.created_at > COALESCE((SELECT cr.last_read_at FROM chat_reads cr WHERE cr.thread_id = t.id AND cr.user_id = ?), '1970-01-01')
            ) AS unread
         FROM chat_threads t
         JOIN schools s ON s.id = t.school_id
         WHERE t.last_message_at IS NOT NULL
         ORDER BY t.last_message_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** Unread count for a school user's own thread (messages from support). */
function chat_school_unread(int $threadId, int $userId): int
{
    if (!$threadId) {
        return 0;
    }
    $st = db()->prepare(
        "SELECT COUNT(*) FROM chat_messages m
         WHERE m.thread_id = ? AND m.sender_role IN ('admin','expert')
           AND m.created_at > COALESCE((SELECT cr.last_read_at FROM chat_reads cr WHERE cr.thread_id = ? AND cr.user_id = ?), '1970-01-01')"
    );
    $st->execute([$threadId, $threadId, $userId]);
    return (int) $st->fetchColumn();
}
