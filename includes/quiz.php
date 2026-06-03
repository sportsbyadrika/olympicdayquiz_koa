<?php
/**
 * Quiz engine helpers shared by the school pages and the AJAX endpoints.
 * The server is the single source of truth for timing and scoring; the client
 * clock is never trusted.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** All slots assigned to a school, with round info and session status. */
function school_slots(int $schoolId): array
{
    $st = db()->prepare(
        'SELECT sl.*, r.round_no, r.name AS round_name,
                qs.id AS session_id, qs.status AS session_status, qs.start_time AS session_start,
                (SELECT COUNT(*) FROM slot_questions sq WHERE sq.slot_id=sl.id) AS question_total
         FROM slot_schools ss
         JOIN slots sl ON sl.id = ss.slot_id
         JOIN rounds r ON r.id = sl.round_id
         LEFT JOIN quiz_sessions qs ON qs.slot_id = sl.id AND qs.school_id = ss.school_id
         WHERE ss.school_id = ?
         ORDER BY r.round_no, sl.start_time'
    );
    $st->execute([$schoolId]);
    return $st->fetchAll();
}

/** Load one slot row. */
function get_slot(int $slotId): ?array
{
    $st = db()->prepare('SELECT sl.*, r.round_no FROM slots sl JOIN rounds r ON r.id=sl.round_id WHERE sl.id=?');
    $st->execute([$slotId]);
    return $st->fetch() ?: null;
}

/** Is the given school assigned to this slot? */
function school_in_slot(int $schoolId, int $slotId): bool
{
    $st = db()->prepare('SELECT 1 FROM slot_schools WHERE school_id=? AND slot_id=? LIMIT 1');
    $st->execute([$schoolId, $slotId]);
    return (bool) $st->fetchColumn();
}

/** Current server time as a unix timestamp. */
function server_now(): int
{
    return time();
}

/** True if `now` is within the slot's [start_time, end_time] window. */
function slot_window_open(array $slot): bool
{
    $now = server_now();
    return $now >= strtotime((string) $slot['start_time']) && $now <= strtotime((string) $slot['end_time']);
}

/** Get an existing session for a school+slot, or null. */
function get_session(int $schoolId, int $slotId): ?array
{
    $st = db()->prepare('SELECT * FROM quiz_sessions WHERE school_id=? AND slot_id=? LIMIT 1');
    $st->execute([$schoolId, $slotId]);
    return $st->fetch() ?: null;
}

/** Load a session by id. */
function get_session_by_id(int $sessionId): ?array
{
    $st = db()->prepare('SELECT * FROM quiz_sessions WHERE id=? LIMIT 1');
    $st->execute([$sessionId]);
    return $st->fetch() ?: null;
}

/**
 * Start a new session (or resume an existing one). Validates the slot window.
 * Seeds draft response rows for every slot question. Returns the session row,
 * or null if the window is not open / no questions.
 */
function start_or_resume_session(int $schoolId, int $slotId): ?array
{
    $slot = get_slot($slotId);
    if (!$slot || !school_in_slot($schoolId, $slotId)) {
        return null;
    }

    $existing = get_session($schoolId, $slotId);
    if ($existing) {
        return $existing; // resume (even if window edge — handled by timer)
    }
    if (!slot_window_open($slot)) {
        return null; // can only start within the window
    }

    // Must have questions assigned.
    $qids = slot_question_ids($slotId);
    if (!$qids) {
        return null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO quiz_sessions (school_id, slot_id, round_id, start_time, status)
             VALUES (?,?,?,?,\'in_progress\')'
        );
        $ins->execute([$schoolId, $slotId, (int) $slot['round_id'], date('Y-m-d H:i:s', server_now())]);
        $sessionId = (int) $pdo->lastInsertId();

        $r = $pdo->prepare('INSERT IGNORE INTO responses (session_id, question_id, status) VALUES (?,?,\'draft\')');
        foreach ($qids as $qid) {
            $r->execute([$sessionId, $qid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return null;
    }
    audit_log('quiz_start', 'quiz_sessions', $sessionId);
    return get_session($schoolId, $slotId);
}

/** Ordered master-question ids for a slot. */
function slot_question_ids(int $slotId): array
{
    $st = db()->prepare('SELECT question_id FROM slot_questions WHERE slot_id=? ORDER BY sequence_no, id');
    $st->execute([$slotId]);
    return array_map('intval', array_column($st->fetchAll(), 'question_id'));
}

/** Remaining seconds for a session (>= 0). */
function remaining_seconds(array $session, array $slot): int
{
    $deadline = strtotime((string) $session['start_time']) + ((int) $slot['quiz_duration_min'] * 60);
    return max(0, $deadline - server_now());
}

/** True if the quiz clock has expired for a session. */
function session_expired(array $session, array $slot): bool
{
    return remaining_seconds($session, $slot) <= 0;
}

/**
 * Finalize a session: flip responses to submitted, compute score, mark the
 * session and write/update the results row (declared stays 0 until expert).
 * $status is 'submitted' or 'force_submitted'. Idempotent.
 */
function finalize_session(int $sessionId, string $status = 'submitted'): bool
{
    $session = get_session_by_id($sessionId);
    if (!$session) {
        return false;
    }
    if (in_array($session['status'], ['submitted', 'force_submitted'], true)) {
        return true; // already finalized
    }

    $slotId = (int) $session['slot_id'];
    $qids = slot_question_ids($slotId);
    $total = count($qids);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Score: count responses whose selected option matches the correct one.
        $scoreStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM responses rsp
             JOIN questions_master qm ON qm.id = rsp.question_id
             WHERE rsp.session_id = ? AND rsp.selected_option IS NOT NULL
               AND rsp.selected_option = qm.correct_option'
        );
        $scoreStmt->execute([$sessionId]);
        $score = (int) $scoreStmt->fetchColumn();

        // Flip all responses to submitted.
        $pdo->prepare("UPDATE responses SET status='submitted' WHERE session_id=?")->execute([$sessionId]);

        // Mark session.
        $pdo->prepare('UPDATE quiz_sessions SET status=?, end_time=?, score=? WHERE id=?')
            ->execute([$status, date('Y-m-d H:i:s', server_now()), $score, $sessionId]);

        // Upsert results row (not declared yet).
        $pdo->prepare(
            'INSERT INTO results (school_id, round_id, session_id, score, total_questions, declared)
             VALUES (:sch,:rnd,:sid,:score,:total,0)
             ON DUPLICATE KEY UPDATE session_id=VALUES(session_id), score=VALUES(score), total_questions=VALUES(total_questions)'
        )->execute([
            ':sch' => (int) $session['school_id'], ':rnd' => (int) $session['round_id'],
            ':sid' => $sessionId, ':score' => $score, ':total' => $total,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return false;
    }
    audit_log($status === 'force_submitted' ? 'quiz_force_submit' : 'quiz_submit', 'quiz_sessions', $sessionId, "score={$score}/{$total}");
    return true;
}

/** Has the expert declared this school's result for the round? */
function result_declared(int $schoolId, int $roundId): bool
{
    $st = db()->prepare('SELECT declared FROM results WHERE school_id=? AND round_id=? LIMIT 1');
    $st->execute([$schoolId, $roundId]);
    return (bool) $st->fetchColumn();
}
