<?php
/**
 * AJAX · Finish & submit the quiz.
 * Verifies session + school role + CSRF, then finalizes the session and writes
 * the (undeclared) results row. Idempotent.
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

$session = get_session($schoolId, $slotId);
$slot = get_slot($slotId);
if (!$session || !$slot) {
    json_response(['ok' => false, 'error' => 'No active session.'], 404);
}

// If the clock already expired, finalize as force_submitted; else normal submit.
$status = session_expired($session, $slot) ? 'force_submitted' : 'submitted';
$ok = finalize_session((int) $session['id'], $status);

json_response(['ok' => $ok, 'status' => $status, 'redirect' => BASE_URL . '/school/index.php']);
