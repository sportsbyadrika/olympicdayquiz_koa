<?php
/** Expert dashboard. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert');

// Save the school dashboard announcement (shared setting, also editable by admin).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('action') === 'save_message') {
    csrf_check();
    db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (\'school_message\', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([':v' => post('school_message')]);
    audit_log('school_message_update', 'settings');
    flash('success', 'School announcement updated.');
    redirect('/expert/index.php');
}

$pending = (int) db()->query("SELECT COUNT(*) FROM question_submissions WHERE status='submitted'")->fetchColumn();
$pendingQ = (int) db()->query("SELECT COUNT(*) FROM questions_association WHERE status='submitted'")->fetchColumn();
$master  = (int) db()->query('SELECT COUNT(*) FROM questions_master')->fetchColumn();
$slots   = (int) db()->query('SELECT COUNT(*) FROM slots')->fetchColumn();

$pageTitle = 'Expert Dashboard';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Expert Dashboard</h1>
<p class="text-gray-500 mb-6">Curate questions, manage slots, and declare results.</p>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <a href="<?= e(BASE_URL) ?>/expert/submissions.php" class="bg-navy text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $pending ?></div><div class="text-sm text-white/80 mt-1">Pending batches</div></a>
  <a href="<?= e(BASE_URL) ?>/expert/submissions.php" class="bg-teal text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $pendingQ ?></div><div class="text-sm text-white/80 mt-1">Questions to review</div></a>
  <a href="<?= e(BASE_URL) ?>/expert/master_questions.php" class="bg-navy text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $master ?></div><div class="text-sm text-white/80 mt-1">Master questions</div></a>
  <a href="<?= e(BASE_URL) ?>/expert/slots.php" class="bg-teal text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $slots ?></div><div class="text-sm text-white/80 mt-1">Slots</div></a>
</div>

<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
  <a href="<?= e(BASE_URL) ?>/expert/submissions.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Review Submissions</a>
  <a href="<?= e(BASE_URL) ?>/expert/master_questions.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Master Bank</a>
  <a href="<?= e(BASE_URL) ?>/expert/slots.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Slots & Assignment</a>
  <a href="<?= e(BASE_URL) ?>/expert/results.php" class="bg-white border border-gray-100 rounded-lg p-4 text-center hover:shadow-sm">Results & Declaration</a>
</div>

<div class="bg-white border border-gray-100 rounded-xl shadow-sm p-5 mt-6 max-w-2xl">
  <h2 class="font-semibold text-navy mb-1">School dashboard announcement</h2>
  <p class="text-xs text-gray-500 mb-3">Shown to every school on their dashboard. Leave empty to remove it.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_message">
    <textarea name="school_message" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3" placeholder="e.g. Round 1 starts at 10:00 AM. Please log in 10 minutes early."><?= e((string) setting('school_message', '')) ?></textarea>
    <button class="bg-navy text-white rounded-lg px-5 py-2.5 text-sm font-medium min-h-[44px]">Save Announcement</button>
  </form>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
