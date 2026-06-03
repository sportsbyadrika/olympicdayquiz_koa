<?php
/**
 * AJAX · Auto-save a single answer as a draft response.
 * Verifies session + school role + CSRF + slot window + session liveness.
 * Rejects (and force-submits) once the quiz clock has expired.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quiz.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if (current_role() !== 'school') {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}
csrf_check();

$school = current_profile();
$schoolId = (int) ($school['id'] ?? 0);
$slotId = int_val(post('slot_id'));
$questionId = int_val(post('question_id'));
$option = strtoupper(post('selected_option'));
if (!in_array($option, ['A', 'B', 'C', 'D'], true)) {
    $option = null; // allow clearing
}

$session = get_session($schoolId, $slotId);
$slot = get_slot($slotId);
if (!$session || !$slot) {
    json_response(['ok' => false, 'error' => 'No active session.'], 404);
}

// Post-submit lockout.
if (in_array($session['status'], ['submitted', 'force_submitted'], true)) {
    json_response(['ok' => false, 'error' => 'Session already submitted.', 'locked' => true], 423);
}

// Expired → force-submit and reject the save.
if (session_expired($session, $slot)) {
    finalize_session((int) $session['id'], 'force_submitted');
    json_response(['ok' => false, 'error' => 'Time is up.', 'expired' => true], 410);
}

// The question must belong to this slot.
$valid = db()->prepare('SELECT 1 FROM slot_questions WHERE slot_id=? AND question_id=? LIMIT 1');
$valid->execute([$slotId, $questionId]);
if (!$valid->fetchColumn()) {
    json_response(['ok' => false, 'error' => 'Question not in this slot.'], 422);
}

// Upsert the draft response.
db()->prepare(
    'INSERT INTO responses (session_id, question_id, selected_option, status, answered_at)
     VALUES (:sid,:qid,:opt,\'draft\',NOW())
     ON DUPLICATE KEY UPDATE selected_option=VALUES(selected_option), answered_at=NOW()'
)->execute([':sid' => (int) $session['id'], ':qid' => $questionId, ':opt' => $option]);

json_response(['ok' => true, 'saved' => $option]);
