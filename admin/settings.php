<?php
/** Admin · Settings (configurable defaults). */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');

$keys = [
    'slot_duration'           => ['Slot duration (minutes)', 'number'],
    'quiz_duration'           => ['Quiz duration (minutes)', 'number'],
    'question_count'          => ['Questions per quiz', 'number'],
    'force_submit_on_timeout' => ['Force-submit on time-out (1=on, 0=off)', 'number'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k,:v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($keys as $k => $_) {
        $v = post($k);
        if ($v !== '') {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
    }
    // School dashboard announcement (free text, may be cleared).
    $stmt->execute([':k' => 'school_message', ':v' => post('school_message')]);
    audit_log('settings_update', 'settings');
    flash('success', 'Settings saved.');
    redirect('/admin/settings.php');
}

$current = [];
foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) {
    $current[$r['setting_key']] = $r['setting_value'];
}

$pageTitle = 'Settings';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-6">Settings</h1>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 max-w-lg">
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($keys as $k => [$label, $type]): ?>
      <label class="block text-sm font-medium mb-1"><?= e($label) ?></label>
      <input name="<?= e($k) ?>" type="<?= e($type) ?>" min="0" value="<?= e($current[$k] ?? '') ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4">
    <?php endforeach; ?>
    <p class="text-xs text-gray-500 mb-4">New slots inherit these defaults but can be overridden per slot.</p>

    <label class="block text-sm font-medium mb-1">School dashboard announcement</label>
    <textarea name="school_message" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-1" placeholder="Message shown to all schools on their dashboard (leave blank to hide)."><?= e($current['school_message'] ?? '') ?></textarea>
    <p class="text-xs text-gray-500 mb-4">Shown to every school on their dashboard. Leave empty to remove it.</p>

    <button class="bg-navy text-white rounded-lg px-5 py-2.5 font-medium min-h-[44px]">Save Settings</button>
  </form>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
