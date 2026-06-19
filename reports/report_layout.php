<?php
/**
 * Shared report chrome for printable HTML reports.
 * Provides report_header() and report_footer(). Screen view uses Tailwind;
 * a dedicated @media print block produces clean paper output with repeating
 * table headers (display: table-header-group) and page numbers via @page.
 *
 * Reports are gated to expert + admin roles.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('expert', 'admin');

function report_header(string $title, string $subtitle = '', string $extraActionsHtml = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @page { size: A4; margin: 16mm 14mm; }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff !important; }
      thead { display: table-header-group; }
      tr { page-break-inside: avoid; }
      .report-card { box-shadow: none !important; border: none !important; }
      /* Page number footer via running counters */
      .page-number:after { content: counter(page); }
    }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #d1d5db; padding: 8px 10px; text-align: left; font-size: 13px; }
    thead th { background: #F5F7FA; color: #1A2B49; }
  </style>
</head>
<body class="bg-gray-100 text-[#333]">
  <div class="no-print bg-[#1A2B49] text-white px-4 py-3 flex items-center justify-between">
    <span class="text-sm">Printable report — opens in this tab</span>
    <div class="flex items-center gap-2">
      <?= $extraActionsHtml ?>
      <button onclick="window.print()" class="bg-[#00897B] text-white rounded px-4 py-2 text-sm font-semibold">Print / Save as PDF</button>
    </div>
  </div>
  <div class="max-w-4xl mx-auto bg-white my-6 p-6 sm:p-10 report-card shadow">
    <header class="border-b-2 border-[#1A2B49] pb-4 mb-6">
      <div class="text-xs font-semibold text-[#00897B] uppercase tracking-wide">Kerala Olympic Association</div>
      <h1 class="text-2xl font-bold text-[#1A2B49] mt-1"><?= e($title) ?></h1>
      <?php if ($subtitle !== ''): ?><p class="text-gray-500 mt-1"><?= e($subtitle) ?></p><?php endif; ?>
      <p class="text-xs text-gray-400 mt-2">Generated <?= e(date('d M Y, H:i')) ?> · Olympic Day Celebrations 2026 — Sports Quiz Competition</p>
    </header>
    <main>
    <?php
}

function report_footer(): void
{
    ?>
    </main>
    <footer class="mt-8 pt-4 border-t border-gray-200 text-xs text-gray-400 flex justify-between">
      <span>Olympic Day Celebrations 2026 — Sports Quiz Competition</span>
      <span class="hidden print:inline">Page <span class="page-number"></span></span>
    </footer>
  </div>
</body>
</html>
    <?php
}
