<?php
/**
 * Forgot password — request a reset link.
 * Always shows a generic confirmation (never reveals whether an email exists).
 * On a real match, a one-hour, single-use token is emailed as a reset link.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();

if (is_logged_in()) {
    redirect(role_dashboard(current_role() ?? ''));
}

$done = false;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $email = strtolower(post('email'));

    if (!is_email($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Look up an active user. We branch silently to avoid account enumeration.
        $st = db()->prepare("SELECT id, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            // Invalidate previous unused tokens for this user.
            db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([(int) $user['id']]);

            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)')
                ->execute([(int) $user['id'], $hash, $expires]);

            $link = app_url('/public/reset_password.php?token=' . $token);
            $html = '<div style="font-family:Arial,sans-serif;color:#333">'
                . '<h2 style="color:#1A2B49">Password reset</h2>'
                . '<p>We received a request to reset your password for the Olympic Day Quiz 2026 portal.</p>'
                . '<p><a href="' . e($link) . '" style="background:#00897B;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Reset my password</a></p>'
                . '<p style="font-size:12px;color:#888">Or paste this link into your browser:<br>' . e($link) . '</p>'
                . '<p style="font-size:12px;color:#888">This link expires in 1 hour and can be used once. If you did not request this, you can ignore this email.</p>'
                . '</div>';
            send_email($user['email'], $user['email'], 'Reset your password — Olympic Day Quiz 2026', $html);
            audit_log('password_reset_requested', 'users', $user['id'], 'email=' . $email);
        } else {
            audit_log('password_reset_requested_unknown', 'users', null, 'email=' . $email);
        }
        $done = true;
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password · <?= e(APP_SHORT_NAME) ?></title>
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
      <h1 class="text-xl font-bold text-navy mb-2">Check your email</h1>
      <p class="text-sm text-gray-600 mb-6">If an account exists for that address, we've sent a link to reset your password. The link expires in 1 hour.</p>
      <a href="<?= e(BASE_URL) ?>/public/login.php" class="inline-block bg-navy text-white font-semibold rounded-lg px-5 py-2.5 min-h-[44px]">Back to login</a>
    <?php else: ?>
      <h1 class="text-xl font-bold text-navy mb-1">Forgot password</h1>
      <p class="text-sm text-gray-500 mb-6">Enter your account email and we'll send you a reset link.</p>

      <?php if ($error !== ''): ?>
        <div class="bg-red-50 text-red-800 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="email">Email</label>
        <input id="email" name="email" type="email" required autocomplete="username"
               value="<?= e($_POST['email'] ?? '') ?>"
               class="w-full rounded-lg border border-gray-300 px-3 py-3 mb-5 focus:ring-2 focus:ring-teal focus:border-teal outline-none"
               placeholder="you@example.com">
        <button type="submit" class="w-full bg-navy hover:bg-navy/90 text-white font-semibold rounded-lg py-3 min-h-[44px] transition">Send reset link</button>
      </form>
      <a href="<?= e(BASE_URL) ?>/public/login.php" class="block mt-5 text-sm text-gray-500 hover:text-navy">&larr; Back to login</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
