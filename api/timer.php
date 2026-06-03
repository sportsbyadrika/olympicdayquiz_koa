<?php
/**
 * AJAX · Server-side quiz timer.
 * Returns remaining_seconds for the school's session. The client only displays
 * this value — it never computes time locally. When the clock reaches zero the
 * server force-submits the session, even on a later request after the browser
 * was closed.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/quiz.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if (current_role() !== 'school') {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$school = current_profile();
$schoolId = (int) ($school['id'] ?? 0);
$slotId = int_val(post('slot_id') ?: get('slot_id'));

$session = get_session($schoolId, $slotId);
$slot = get_slot($slotId);
if (!$session || !$slot) {
    json_response(['ok' => false, 'error' => 'No active session.'], 404);
}

$status = $session['status'];

// Already finalized → locked out of timing.
if (in_array($status, ['submitted', 'force_submitted'], true)) {
    json_response(['ok' => true, 'remaining' => 0, 'status' => $status, 'expired' => true]);
}

$remaining = remaining_seconds($session, $slot);
if ($remaining <= 0) {
    // Force-submit on the server regardless of client state.
    if ((int) setting('force_submit_on_timeout', '1') === 1) {
        finalize_session((int) $session['id'], 'force_submitted');
        $status = 'force_submitted';
    }
    json_response(['ok' => true, 'remaining' => 0, 'status' => $status, 'expired' => true]);
}

json_response(['ok' => true, 'remaining' => $remaining, 'status' => $status, 'expired' => false]);
