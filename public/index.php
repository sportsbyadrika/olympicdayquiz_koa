<?php
/**
 * Public home page: hero, competition-process stepper, about, login CTA.
 * Mobile-first responsive with a collapsing navbar.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
auth_boot();

$pageTitle = 'Home';
require dirname(__DIR__) . '/includes/header.php';
?>

<!-- Hero -->
<section class="relative -mx-4 -mt-6 mb-10 bg-navy text-white overflow-hidden">
  <div class="absolute inset-0 opacity-10" style="background-image:radial-gradient(circle at 20% 20%, #00897B 0, transparent 40%), radial-gradient(circle at 80% 60%, #00897B 0, transparent 35%);"></div>
  <div class="relative max-w-7xl mx-auto px-6 py-16 sm:py-24 text-center">
    <span class="inline-block bg-teal/20 text-teal-100 border border-teal/40 rounded-full px-4 py-1 text-xs font-medium mb-5">Kerala Olympic Association</span>
    <h1 class="text-3xl sm:text-5xl font-extrabold leading-tight mb-4">Olympic Day Celebrations 2026<br class="hidden sm:block"> Sports Quiz Competition</h1>
    <p class="max-w-2xl mx-auto text-white/80 text-base sm:text-lg mb-8">A celebration of sporting knowledge — schools across Kerala compete in a two-round quiz curated by sports associations and expert reviewers.</p>
    <div class="flex flex-col sm:flex-row gap-3 justify-center">
      <a href="<?= e(BASE_URL) ?>/public/login.php" class="bg-teal hover:bg-teal/80 text-white font-semibold rounded-lg px-6 py-3 min-h-[44px] transition">Login to your portal</a>
      <a href="#process" class="bg-white/10 hover:bg-white/20 text-white font-semibold rounded-lg px-6 py-3 min-h-[44px] transition">How it works</a>
    </div>
  </div>
</section>

<!-- Competition Process stepper -->
<section id="process" class="mb-14 scroll-mt-20">
  <h2 class="text-2xl font-bold text-navy text-center mb-2">Competition Process</h2>
  <p class="text-center text-gray-500 mb-8 max-w-2xl mx-auto">From question capture to the final result, every stage is managed on this platform.</p>

  <?php
  $steps = [
      ['📝', 'Associations Submit', 'Sports associations contribute questions through the official capture form.'],
      ['✅', 'Experts Curate', 'Experts review, accept, edit and author questions into a master bank.'],
      ['🏁', 'Round 1', 'Schools attend their assigned slot — a timed, server-controlled quiz.'],
      ['🎯', 'Select Qualifiers', 'Experts review Round 1 results and pick qualifying teams.'],
      ['🥇', 'Round 2', 'Qualified teams compete again in their Round 2 slot.'],
      ['🏆', 'Final Result', 'Experts declare results; schools view scores and explanations.'],
  ];
  ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($steps as $i => [$icon, $title, $desc]): ?>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex gap-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-lightgrey flex items-center justify-center text-2xl"><?= $icon ?></div>
        <div>
          <div class="text-xs font-semibold text-teal mb-0.5">STEP <?= $i + 1 ?></div>
          <h3 class="font-semibold text-navy mb-1"><?= e($title) ?></h3>
          <p class="text-sm text-gray-600"><?= e($desc) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- About -->
<section id="about" class="mb-12 scroll-mt-20">
  <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-10 grid md:grid-cols-2 gap-8 items-center">
    <div>
      <h2 class="text-2xl font-bold text-navy mb-3">About the Competition</h2>
      <p class="text-gray-600 mb-3">The Olympic Day Celebrations 2026 Sports Quiz Competition is organised by the Kerala Olympic Association to promote sporting awareness among school students across the state.</p>
      <p class="text-gray-600">Each participating school fields one team of two participants. The competition runs in two rounds, with questions curated by sports associations and a panel of expert reviewers to ensure quality and fairness.</p>
    </div>
    <div class="grid grid-cols-3 gap-4 text-center">
      <div class="bg-lightgrey rounded-xl p-4">
        <div class="text-2xl font-extrabold text-navy">2</div>
        <div class="text-xs text-gray-500 mt-1">Rounds</div>
      </div>
      <div class="bg-lightgrey rounded-xl p-4">
        <div class="text-2xl font-extrabold text-navy">2</div>
        <div class="text-xs text-gray-500 mt-1">Per team</div>
      </div>
      <div class="bg-lightgrey rounded-xl p-4">
        <div class="text-2xl font-extrabold text-navy">30</div>
        <div class="text-xs text-gray-500 mt-1">Questions</div>
      </div>
    </div>
  </div>
</section>

<!-- Login CTA -->
<section class="mb-6">
  <div class="bg-teal rounded-2xl p-8 text-center text-white">
    <h2 class="text-xl sm:text-2xl font-bold mb-2">Ready to take part?</h2>
    <p class="text-white/90 mb-5">Log in with the credentials provided by the organisers.</p>
    <a href="<?= e(BASE_URL) ?>/public/login.php" class="inline-block bg-white text-teal font-semibold rounded-lg px-6 py-3 min-h-[44px] hover:bg-white/90 transition">Go to Login</a>
  </div>
</section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
