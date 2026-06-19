<?php
/**
 * AJAX · Expert question-to-slot assignment (drag & drop).
 * Verifies session + expert role + CSRF. Supports add / remove / reorder.
 * Returns JSON { ok, count, target } for the live counter.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if (current_role() !== 'expert') {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}
csrf_check(); // validates X-CSRF-Token header on POST

$action  = post('action');
$slotId  = int_val(post('slot_id'));
$questionId = int_val(post('question_id'));

if (!$slotId) {
    json_response(['ok' => false, 'error' => 'Missing slot.'], 422);
}

// Confirm the slot exists and get its target question count.
$st = db()->prepare('SELECT question_count FROM slots WHERE id = ?');
$st->execute([$slotId]);
$slot = $st->fetch();
if (!$slot) {
    json_response(['ok' => false, 'error' => 'Slot not found.'], 404);
}

try {
    if ($action === 'add') {
        // Next sequence number.
        $seqStmt = db()->prepare('SELECT COALESCE(MAX(sequence_no),0)+1 FROM slot_questions WHERE slot_id=?');
        $seqStmt->execute([$slotId]);
        $seq = (int) $seqStmt->fetchColumn();
        db()->prepare('INSERT IGNORE INTO slot_questions (slot_id, question_id, sequence_no) VALUES (?,?,?)')
            ->execute([$slotId, $questionId, $seq]);
        audit_log('slot_question_add', 'slot_questions', $slotId, 'q=' . $questionId);
    } elseif ($action === 'remove') {
        db()->prepare('DELETE FROM slot_questions WHERE slot_id=? AND question_id=?')->execute([$slotId, $questionId]);
        audit_log('slot_question_remove', 'slot_questions', $slotId, 'q=' . $questionId);
    } elseif ($action === 'reorder') {
        $order = $_POST['order'] ?? [];
        if (is_array($order)) {
            $pdo = db();
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE slot_questions SET sequence_no=? WHERE slot_id=? AND question_id=?');
            $seq = 1;
            foreach ($order as $qid) {
                $upd->execute([$seq++, $slotId, (int) $qid]);
            }
            $pdo->commit();
        }
    } elseif ($action === 'toggle_cancel') {
        if (!db_column_exists('slot_questions', 'cancelled')) {
            json_response(['ok' => false, 'error' => 'Question cancellation needs a database update. Please run database/migrations/2026_06_slot_question_cancel.sql.'], 200);
        }
        db()->prepare('UPDATE slot_questions SET cancelled = 1 - cancelled WHERE slot_id=? AND question_id=?')
            ->execute([$slotId, $questionId]);
        $cs = db()->prepare('SELECT cancelled FROM slot_questions WHERE slot_id=? AND question_id=?');
        $cs->execute([$slotId, $questionId]);
        $cancelled = (int) $cs->fetchColumn();
        audit_log('slot_question_cancel', 'slot_questions', $slotId, 'q=' . $questionId . ' cancelled=' . $cancelled);
        json_response(['ok' => true, 'cancelled' => $cancelled]);
    } else {
        json_response(['ok' => false, 'error' => 'Unknown action.'], 422);
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Operation failed.'], 500);
}

$cntStmt = db()->prepare('SELECT COUNT(*) FROM slot_questions WHERE slot_id=?');
$cntStmt->execute([$slotId]);
$count = (int) $cntStmt->fetchColumn();

json_response(['ok' => true, 'count' => $count, 'target' => (int) $slot['question_count']]);
