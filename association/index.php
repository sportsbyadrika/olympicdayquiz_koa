<?php
/** Association dashboard. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('association');

$assoc = current_profile();
if (!$assoc) {
    flash('error', 'Association profile not found.');
    redirect('/public/logout.php');
}
$aid = (int) $assoc['id'];

// Counts
$st = db()->prepare("SELECT COUNT(*) FROM questions_association qa JOIN question_submissions s ON s.id=qa.submission_id WHERE s.association_id=? AND qa.status='draft'");
$st->execute([$aid]);
$drafted = (int) $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM questions_association qa JOIN question_submissions s ON s.id=qa.submission_id WHERE s.association_id=? AND qa.status IN ('submitted','accepted','rejected')");
$st->execute([$aid]);
$submitted = (int) $st->fetchColumn();

$st = db()->prepare("SELECT MAX(submitted_at) FROM question_submissions WHERE association_id=? AND status='submitted'");
$st->execute([$aid]);
$lastSub = $st->fetchColumn();

$pageTitle = 'Association Dashboard';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1"><?= e($assoc['name']) ?></h1>
<p class="text-gray-500 mb-6">Association dashboard<?= (int) $assoc['is_event_conductor'] === 1 ? ' · <span class="text-teal font-medium">Event Conducting Association</span>' : '' ?></p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
  <div class="bg-navy text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $drafted ?></div><div class="text-sm text-white/80 mt-1">Draft questions</div></div>
  <div class="bg-teal text-white rounded-xl p-5"><div class="text-3xl font-extrabold"><?= $submitted ?></div><div class="text-sm text-white/80 mt-1">Submitted questions</div></div>
  <div class="bg-white border border-gray-100 rounded-xl p-5"><div class="text-lg font-bold text-navy"><?= $lastSub ? e(date('d M Y', strtotime((string) $lastSub))) : '—' ?></div><div class="text-sm text-gray-500 mt-1">Last submission</div></div>
</div>

<a href="<?= e(BASE_URL) ?>/association/question_bank.php" class="inline-block bg-navy text-white rounded-lg px-5 py-3 font-medium min-h-[44px]">Open My Question Bank</a>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
