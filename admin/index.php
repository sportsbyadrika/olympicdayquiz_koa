<?php
/** Admin dashboard: high-level counts. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');

$counts = [
    'Associations' => (int) db()->query('SELECT COUNT(*) FROM associations')->fetchColumn(),
    'Schools'      => (int) db()->query('SELECT COUNT(*) FROM schools')->fetchColumn(),
    'Experts'      => (int) db()->query('SELECT COUNT(*) FROM experts')->fetchColumn(),
    'Submissions'  => (int) db()->query("SELECT COUNT(*) FROM question_submissions WHERE status='submitted'")->fetchColumn(),
    'Master Qs'    => (int) db()->query('SELECT COUNT(*) FROM questions_master')->fetchColumn(),
    'Slots'        => (int) db()->query('SELECT COUNT(*) FROM slots')->fetchColumn(),
];

$cards = [
    ['Associations', $counts['Associations'], '/admin/associations.php', 'bg-navy'],
    ['Schools', $counts['Schools'], '/admin/schools.php', 'bg-teal'],
    ['Experts', $counts['Experts'], '/admin/experts.php', 'bg-navy'],
    ['Submitted Batches', $counts['Submissions'], '#', 'bg-teal'],
    ['Master Questions', $counts['Master Qs'], '#', 'bg-navy'],
    ['Slots', $counts['Slots'], '#', 'bg-teal'],
];

$pageTitle = 'Admin Dashboard';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Admin Dashboard</h1>
<p class="text-gray-500 mb-6">Overview of the competition setup.</p>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
  <?php foreach ($cards as [$label, $value, $href, $bg]): ?>
    <a href="<?= $href === '#' ? '#' : e(BASE_URL . $href) ?>" class="<?= $bg ?> text-white rounded-xl p-5 shadow-sm hover:opacity-95 transition">
      <div class="text-3xl font-extrabold"><?= (int) $value ?></div>
      <div class="text-sm text-white/80 mt-1"><?= e($label) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
  <a href="<?= e(BASE_URL) ?>/admin/associations.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Manage Associations</a>
  <a href="<?= e(BASE_URL) ?>/admin/experts.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Manage Experts</a>
  <a href="<?= e(BASE_URL) ?>/admin/schools.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Manage Schools</a>
  <a href="<?= e(BASE_URL) ?>/admin/email_credentials.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Email Credentials</a>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
