<?php
/**
 * My Account — shared by all roles.
 * View profile (role-specific, read-only) and change password.
 * Profile fields are managed by the admin; here the user can only view them
 * and update their own password.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
require_login();

$uid = current_user_id();
$role = current_role();

// Load the user (login) record.
$st = db()->prepare('SELECT id, email, role, status, created_at FROM users WHERE id = ?');
$st->execute([$uid]);
$user = $st->fetch();
if (!$user) { redirect('/public/logout.php'); }

$profile = current_profile(); // null for admin

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('action') === 'change_password') {
    csrf_check();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $errors = [];
    // Re-fetch the hash for verification.
    $hs = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $hs->execute([$uid]);
    $hash = (string) $hs->fetchColumn();

    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All password fields are required.';
    } elseif (!password_verify($current, $hash)) {
        $errors[] = 'Your current password is incorrect.';
    } elseif (mb_strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    } elseif ($new === $current) {
        $errors[] = 'New password must be different from the current one.';
    }

    if ($errors) {
        flash('error', implode(' ', $errors));
    } else {
        set_user_password($uid, $new);
        audit_log('password_change', 'users', $uid);
        flash('success', 'Your password has been updated.');
    }
    redirect('/public/account.php');
}

/** Build the read-only profile rows to display for this role. */
function profile_rows(string $role, array $user, ?array $profile): array
{
    $rows = [
        ['Login email', $user['email']],
        ['Account type', ucfirst($role)],
        ['Status', ucfirst($user['status'])],
        ['Member since', date('d M Y', strtotime((string) $user['created_at']))],
    ];
    if (!$profile) {
        return $rows; // admin: no extra profile table
    }
    switch ($role) {
        case 'association':
            $extra = [
                ['Association name', $profile['name'] ?? ''],
                ['Contact person', $profile['contact_person'] ?? ''],
                ['Contact phone', $profile['contact_phone'] ?? ''],
                ['Event conductor', ((int) ($profile['is_event_conductor'] ?? 0) === 1) ? 'Yes' : 'No'],
            ];
            break;
        case 'expert':
            $extra = [
                ['Name', $profile['name'] ?? ''],
                ['Phone', $profile['phone'] ?? ''],
            ];
            break;
        case 'school':
            $extra = [
                ['School name', $profile['name'] ?? ''],
                ['School code', $profile['code'] ?? ''],
                ['Participant 1', $profile['participant1_name'] ?? ''],
                ['Participant 2', $profile['participant2_name'] ?? ''],
                ['Address', $profile['address'] ?? ''],
                ['Contact', $profile['contact'] ?? ''],
            ];
            break;
        default:
            $extra = [];
    }
    // Insert role profile rows after the login email line.
    array_splice($rows, 1, 0, $extra);
    return $rows;
}

$rows = profile_rows($role, $user, $profile);

$pageTitle = 'My Account';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-6">My Account</h1>

<div class="grid lg:grid-cols-2 gap-6">
  <!-- Profile -->
  <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <h2 class="font-semibold text-navy mb-4">Profile</h2>
    <dl class="divide-y divide-gray-100">
      <?php foreach ($rows as [$label, $value]): ?>
        <div class="flex justify-between gap-4 py-2.5">
          <dt class="text-sm text-gray-500"><?= e($label) ?></dt>
          <dd class="text-sm font-medium text-navy text-right break-words"><?= $value !== '' && $value !== null ? e((string) $value) : '<span class="text-gray-300">—</span>' ?></dd>
        </div>
      <?php endforeach; ?>
    </dl>
    <p class="text-xs text-gray-400 mt-4">Profile details are maintained by the organisers. Contact an administrator to update them.</p>
  </section>

  <!-- Change password -->
  <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
    <h2 class="font-semibold text-navy mb-4">Change Password</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">

      <label class="block text-sm font-medium text-gray-700 mb-1" for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
             class="w-full rounded-lg border border-gray-300 px-3 py-2.5 mb-4 focus:ring-2 focus:ring-teal focus:border-teal outline-none">

      <label class="block text-sm font-medium text-gray-700 mb-1" for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password"
             class="w-full rounded-lg border border-gray-300 px-3 py-2.5 mb-1 focus:ring-2 focus:ring-teal focus:border-teal outline-none">
      <p class="text-xs text-gray-400 mb-4">At least 8 characters.</p>

      <label class="block text-sm font-medium text-gray-700 mb-1" for="confirm_password">Confirm new password</label>
      <input id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password"
             class="w-full rounded-lg border border-gray-300 px-3 py-2.5 mb-6 focus:ring-2 focus:ring-teal focus:border-teal outline-none">

      <button type="submit" class="bg-navy hover:bg-navy/90 text-white font-semibold rounded-lg px-5 py-2.5 min-h-[44px] transition">Update Password</button>
    </form>
  </section>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
