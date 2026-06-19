<?php
/**
 * Shared page header: <head>, Tailwind (CDN), theme config, and a
 * mobile-first responsive navbar whose links vary by role.
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   require __DIR__ . '/../includes/header.php';
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();

$pageTitle = $pageTitle ?? APP_SHORT_NAME;
$role = current_role();

/** Build the nav links for the current role. */
function nav_links(?string $role): array
{
    switch ($role) {
        case 'admin':
            return [
                ['/admin/index.php', 'Dashboard'],
                ['/admin/associations.php', 'Associations'],
                ['/admin/experts.php', 'Experts'],
                ['/admin/schools.php', 'Schools'],
                ['/admin/online_status.php', 'Online Status'],
                ['/admin/login_history.php', 'Login History'],
                ['/admin/settings.php', 'Settings'],
            ];
        case 'association':
            return [
                ['/association/index.php', 'Dashboard'],
                ['/association/question_bank.php', 'My Question Bank'],
                ['/association/submissions.php', 'My Submissions'],
            ];
        case 'expert':
            return [
                ['/expert/index.php', 'Dashboard'],
                ['/expert/submissions.php', 'Submissions'],
                ['/expert/master_questions.php', 'Master Bank'],
                ['/expert/slots.php', 'Slots'],
                ['/expert/online_status.php', 'Online Status'],
                ['/expert/results.php', 'Results'],
            ];
        case 'school':
            return [
                ['/school/index.php', 'Dashboard'],
            ];
        default:
            return [
                ['/public/index.php', 'Home'],
                ['/public/index.php#about', 'About'],
                ['/public/index.php#process', 'Process'],
            ];
    }
}

$links = nav_links($role);
// Every authenticated role gets a My Account entry (profile + password change).
if (is_logged_in()) {
    $links[] = ['/public/account.php', 'My Account'];
}
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e(APP_SHORT_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!--
      NOTE: Tailwind via CDN is convenient for development. For production,
      switch to a Tailwind CLI build (compiled stylesheet) to remove the
      runtime compiler and shrink CSS. See README.md.
    -->
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              navy:  '#1A2B49',
              teal:  '#00897B',
              lightgrey: '#F5F7FA',
              textdark: '#333333',
            }
          }
        }
      }
    </script>
    <style>
      body { color: #333333; }
      [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full bg-lightgrey text-textdark antialiased flex flex-col min-h-screen">
<nav class="bg-navy text-white shadow-md sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-4">
    <div class="flex items-center justify-between h-16">
      <div class="flex items-center gap-2 min-w-0">
        <a href="<?= e(BASE_URL) ?><?= is_logged_in() ? e(role_dashboard($role)) : '/public/index.php' ?>" class="flex items-center gap-2 font-semibold truncate">
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-teal text-white font-bold">OD</span>
          <span class="truncate hidden sm:inline"><?= e(APP_SHORT_NAME) ?></span>
        </a>
      </div>

      <!-- Desktop links -->
      <div class="hidden md:flex items-center gap-1">
        <?php foreach ($links as [$href, $label]):
            $active = ($currentPath === parse_url($href, PHP_URL_PATH));
        ?>
          <a href="<?= e(BASE_URL . $href) ?>"
             class="px-3 py-2 rounded-md text-sm font-medium hover:bg-white/10 transition <?= $active ? 'bg-white/15' : '' ?>">
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>

        <?php if (is_logged_in()): ?>
          <span class="mx-2 text-white/40">|</span>
          <span class="text-xs text-white/70 hidden lg:inline"><?= e($_SESSION['email'] ?? '') ?></span>
          <a href="<?= e(BASE_URL) ?>/public/logout.php" class="ml-2 px-3 py-2 rounded-md text-sm font-medium bg-teal hover:bg-teal/80 transition">Logout</a>
        <?php else: ?>
          <a href="<?= e(BASE_URL) ?>/public/login.php" class="ml-2 px-4 py-2 rounded-md text-sm font-semibold bg-teal hover:bg-teal/80 transition">Login</a>
        <?php endif; ?>
      </div>

      <!-- Mobile hamburger -->
      <button id="navToggle" class="md:hidden inline-flex items-center justify-center w-11 h-11 rounded-md hover:bg-white/10" aria-label="Toggle menu" aria-expanded="false">
        <svg id="navIconOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        <svg id="navIconClose" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
  </div>

  <!-- Mobile menu -->
  <div id="navMenu" class="md:hidden hidden border-t border-white/10 px-4 pb-4">
    <div class="flex flex-col gap-1 pt-2">
      <?php foreach ($links as [$href, $label]): ?>
        <a href="<?= e(BASE_URL . $href) ?>" class="px-3 py-3 rounded-md text-sm font-medium hover:bg-white/10"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (is_logged_in()): ?>
        <div class="text-xs text-white/60 px-3 pt-2"><?= e($_SESSION['email'] ?? '') ?></div>
        <a href="<?= e(BASE_URL) ?>/public/logout.php" class="px-3 py-3 rounded-md text-sm font-semibold bg-teal mt-1">Logout</a>
      <?php else: ?>
        <a href="<?= e(BASE_URL) ?>/public/login.php" class="px-3 py-3 rounded-md text-sm font-semibold bg-teal mt-1">Login</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<script>
  (function () {
    var btn = document.getElementById('navToggle');
    var menu = document.getElementById('navMenu');
    var iOpen = document.getElementById('navIconOpen');
    var iClose = document.getElementById('navIconClose');
    if (btn) {
      btn.addEventListener('click', function () {
        var open = menu.classList.toggle('hidden') === false;
        iOpen.classList.toggle('hidden', open);
        iClose.classList.toggle('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }
  })();
</script>

<main class="flex-1 w-full">
  <div class="max-w-7xl mx-auto px-4 py-6">
    <?= render_flashes() ?>
