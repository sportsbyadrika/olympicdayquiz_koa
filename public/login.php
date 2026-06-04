<?php
/**
 * Common login page for all roles.
 * Validates credentials, regenerates the session, routes to the role dashboard.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();

// Already logged in? Go to dashboard.
if (is_logged_in()) {
    redirect(role_dashboard(current_role() ?? ''));
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $email = strtolower(post('email'));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (!is_email($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = attempt_login($email, $password);
        if ($user) {
            redirect(role_dashboard($user['role']));
        }
        $error = 'Invalid credentials, or your account is inactive.';
    }
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login · <?= e(APP_SHORT_NAME) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: {
      navy:'#1A2B49', teal:'#00897B', lightgrey:'#F5F7FA', textdark:'#333333' } } } }
  </script>
</head>
<body class="bg-lightgrey text-textdark min-h-screen flex flex-col">
<div class="flex-1 flex flex-col items-center justify-center px-4 py-10">
  <a href="<?= e(BASE_URL) ?>/public/index.php" class="flex items-center gap-2 mb-6">
    <span class="inline-flex items-center justify-center w-11 h-11 rounded-full bg-teal text-white font-bold text-lg">OD</span>
    <span class="font-semibold text-navy text-lg">Olympic Day Quiz 2026</span>
  </a>

  <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-gray-100 p-6 sm:p-8">
    <h1 class="text-xl font-bold text-navy mb-1">Sign in</h1>
    <p class="text-sm text-gray-500 mb-6">Use the credentials provided by the organisers.</p>

    <?php if ($error !== ''): ?>
      <div class="bg-red-50 text-red-800 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <label class="block text-sm font-medium text-gray-700 mb-1" for="email">Email</label>
      <input id="email" name="email" type="email" required autocomplete="username"
             value="<?= e($_POST['email'] ?? '') ?>"
             class="w-full rounded-lg border border-gray-300 px-3 py-3 mb-4 focus:ring-2 focus:ring-teal focus:border-teal outline-none"
             placeholder="you@example.com">

      <label class="block text-sm font-medium text-gray-700 mb-1" for="password">Password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password"
             class="w-full rounded-lg border border-gray-300 px-3 py-3 mb-6 focus:ring-2 focus:ring-teal focus:border-teal outline-none"
             placeholder="••••••••">

      <button type="submit" class="w-full bg-navy hover:bg-navy/90 text-white font-semibold rounded-lg py-3 min-h-[44px] transition">
        Sign in
      </button>
    </form>

    <div class="mt-4 text-center">
      <a href="<?= e(BASE_URL) ?>/public/forgot_password.php" class="text-sm text-teal hover:underline">Forgot password?</a>
    </div>
  </div>

  <a href="<?= e(BASE_URL) ?>/public/index.php" class="mt-6 text-sm text-gray-500 hover:text-navy">&larr; Back to home</a>
</div>
<?= site_footer_html() ?>
</body>
</html>
