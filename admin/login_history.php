<?php
/**
 * Admin · Login history report.
 * Reads login events from audit_log with a date-range filter, showing the
 * IP address and a best-effort region (resolved from the IP on demand).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_role('admin');

$loginActions = ['login_success', 'login_failed', 'login_blocked_inactive'];

// Filters
$from = get('from') ?: date('Y-m-d', strtotime('-30 days'));
$to   = get('to') ?: date('Y-m-d');
$result = get('result'); // '', 'success', 'failed'
$q = get('q');
$showRegion = get('region') === '1';

// Normalise date bounds.
$fromDt = date('Y-m-d 00:00:00', strtotime($from) ?: time());
$toDt   = date('Y-m-d 23:59:59', strtotime($to) ?: time());

$actionFilter = $loginActions;
if ($result === 'success') {
    $actionFilter = ['login_success'];
} elseif ($result === 'failed') {
    $actionFilter = ['login_failed', 'login_blocked_inactive'];
}

$in = implode(',', array_fill(0, count($actionFilter), '?'));
$where = "al.action IN ($in) AND al.created_at BETWEEN ? AND ?";
$params = array_merge($actionFilter, [$fromDt, $toDt]);
if ($q !== '') {
    $where .= ' AND (u.email LIKE ? OR al.details LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

// Pagination (50 per page).
$perPage = 50;
$page = max(1, int_val(get('page')));
$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON u.id = al.user_id WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT al.created_at, al.action, al.ip, al.user_agent, al.details, u.email AS user_email
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE $where
     ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/** Email shown for a row: joined user, else parsed from details "email=...". */
function row_email(array $r): string
{
    if (!empty($r['user_email'])) {
        return $r['user_email'];
    }
    if (preg_match('/email=([^\s;,]+)/', (string) $r['details'], $m)) {
        return $m[1];
    }
    return '—';
}

$pageTitle = 'Login History';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Login History</h1>
<p class="text-gray-500 mb-6 text-sm">Login activity recorded in the audit log — <?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> in range.</p>

<form method="get" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
    <input type="date" name="from" value="<?= e($from) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
    <input type="date" name="to" value="<?= e($to) ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">Result</label>
    <select name="result" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
      <option value="">All</option>
      <option value="success" <?= $result==='success'?'selected':'' ?>>Success</option>
      <option value="failed" <?= $result==='failed'?'selected':'' ?>>Failed</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
    <input name="q" value="<?= e($q) ?>" placeholder="Search email" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm">
  </div>
  <div class="flex items-end gap-2">
    <label class="flex items-center gap-2 text-sm text-gray-600 mb-1">
      <input type="checkbox" name="region" value="1" <?= $showRegion?'checked':'' ?> class="rounded"> Region
    </label>
    <button class="bg-navy text-white rounded-lg px-4 py-2.5 text-sm font-medium">Apply</button>
  </div>
</form>

<?php if ($showRegion): ?>
  <p class="text-xs text-gray-400 mb-3">Region is looked up from a free IP geolocation service and may be approximate or unavailable for some addresses.</p>
<?php endif; ?>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr>
        <th class="px-4 py-3">Date / Time</th>
        <th class="px-4 py-3">Email</th>
        <th class="px-4 py-3">Result</th>
        <th class="px-4 py-3">IP address</th>
        <?php if ($showRegion): ?><th class="px-4 py-3">Region</th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $showRegion ? 5 : 4 ?>" class="px-4 py-8 text-center text-gray-400">No login activity in this range.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $ok = $r['action'] === 'login_success';
      ?>
        <tr>
          <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= e(date('d M Y, H:i:s', strtotime((string)$r['created_at']))) ?></td>
          <td class="px-4 py-3 font-medium text-navy"><?= e(row_email($r)) ?></td>
          <td class="px-4 py-3">
            <?php if ($ok): ?>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Success</span>
            <?php elseif ($r['action'] === 'login_blocked_inactive'): ?>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Blocked (inactive)</span>
            <?php else: ?>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Failed</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 font-mono text-xs text-gray-600"><?= e($r['ip'] ?? '—') ?></td>
          <?php if ($showRegion): ?>
            <td class="px-4 py-3 text-gray-600 text-xs"><?= e(ip_region($r['ip']) ?? '—') ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?= paginate_links($page, $pages) ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
