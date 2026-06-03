<?php
/**
 * Reset password — consume a one-time token and set a new password.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
auth_boot();

/** Find a valid (unused, unexpired) reset row for a raw token. */
function find_reset(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $st = db()->prepare(
        "SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1"
    );
    $st->execute([$hash]);
    return $st->fetch() ?: null;
}

$token = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('token') : get('token');
$reset = find_reset($token);
$error = '';
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$reset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (mb_strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            set_user_password((int) $reset['user_id'], $new);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([(int) $reset['id']]);
            // Invalidate any other outstanding tokens for this user.
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([(int) $reset['user_id']]);
            $pdo->commit();
            audit_log('password_reset_done', 'users', (int) $reset['user_id']);
            $done = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$validLink = $done || (bool) $reset;

$pageTitle = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password · <?= e(APP_SHORT_NAME) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{navy:'#1A2B49',teal:'#00897B',lightgrey:'#F5F7FA',textdark:'#333333'}}}}</script>
</head>
<body class="h-full bg-lightgrey text-textdark">
<div class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
  <a href="<?= e(BASE_URL) ?>/public/index.php" class="flex items-center gap-2 mb-6">
    <span class="inline-flex items-center justify-center w-11 h-11 rounded-full bg-teal text-white font-bold text-lg">OD</span>
    <span class="font-semibold text-navy text-lg">Olympic Day Quiz 2026</span>
  </a>

  <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-gray-100 p-6 sm:p-8">
    <?php if ($done): ?>
      <h1 class="text-xl font-bold text-navy mb-2">Password updated</h1>
      <p class="text-sm text-gray-600 mb-6">Your password has been changed. You can now sign in with your new password.</p>
      <a href="<?= e(BASE_URL) ?>/public/login.php" class="inline-block bg-navy text-white font-semibold rounded-lg px-5 py-2.5 min-h-[44px]">Go to login</a>
    <?php elseif (!$validLink): ?>
      <h1 class="text-xl font-bold text-navy mb-2">Link invalid or expired</h1>
      <p class="text-sm text-gray-600 mb-6">This reset link is no longer valid. Please request a new one.</p>
      <a href="<?= e(BASE_URL) ?>/public/forgot_password.php" class="inline-block bg-navy text-white font-semibold rounded-lg px-5 py-2.5 min-h-[44px]">Request new link</a>
    <?php else: ?>
      <h1 class="text-xl font-bold text-navy mb-1">Set a new password</h1>
      <p class="text-sm text-gray-500 mb-6">Choose a password of at least 8 characters.</p>

      <?php if ($error !== ''): ?>
        <div class="bg-red-50 text-red-800 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="block text-sm font-medium text-gray-700 mb-1" for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password"
               class="w-full rounded-lg border border-gray-300 px-3 py-3 mb-4 focus:ring-2 focus:ring-teal focus:border-teal outline-none">
        <label class="block text-sm font-medium text-gray-700 mb-1" for="confirm_password">Confirm new password</label>
        <input id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password"
               class="w-full rounded-lg border border-gray-300 px-3 py-3 mb-6 focus:ring-2 focus:ring-teal focus:border-teal outline-none">
        <button type="submit" class="w-full bg-navy hover:bg-navy/90 text-white font-semibold rounded-lg py-3 min-h-[44px] transition">Update password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
