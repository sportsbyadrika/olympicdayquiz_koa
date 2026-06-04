<?php declare(strict_types=1); ?>
  </div>
</main>

<footer class="bg-navy text-white/80 mt-8">
  <div class="max-w-7xl mx-auto px-4 py-6 text-sm flex flex-col sm:flex-row items-center justify-between gap-2">
    <div>
      &copy; <?= date('Y') ?>
      <a href="https://keralaolympic.org/olympicday-run2026.php" target="_blank" rel="noopener noreferrer" class="hover:text-white underline-offset-2 hover:underline"><?= e(APP_ORG) ?></a>
    </div>
    <div class="flex flex-col sm:flex-row items-center gap-1 sm:gap-3 text-white/60">
      <span><?= e(APP_NAME) ?></span>
      <span class="hidden sm:inline">·</span>
      <a href="https://sportsmis.com" target="_blank" rel="noopener noreferrer" class="hover:text-white underline-offset-2 hover:underline">Software by SportsMIS.com&reg;</a>
    </div>
  </div>
</footer>
</body>
</html>
