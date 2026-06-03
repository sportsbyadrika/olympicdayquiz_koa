<?php
/**
 * Admin · Bulk Email Credentials.
 * Select schools, (re)generate a password for each, and email login details
 * via PHPMailer. Per-school send status is reported and audit-logged.
 *
 * Passwords are stored bcrypt-hashed and cannot be recovered, so emailing
 * credentials resets the school's password to a freshly generated one.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
require_role('admin');

$results = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $ids = $_POST['school_ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn($i) => $i > 0));

    if (!$ids) {
        flash('warning', 'No schools selected.');
        redirect('/admin/email_credentials.php');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT s.id, s.name, s.code, u.id AS user_id, u.email
         FROM schools s JOIN users u ON u.id = s.user_id
         WHERE s.id IN ($in)"
    );
    $stmt->execute($ids);
    $schools = $stmt->fetchAll();

    foreach ($schools as $sc) {
        $password = generate_password();
        set_user_password((int) $sc['user_id'], $password);

        $loginUrl = ((COOKIE_SECURE ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) . BASE_URL . '/public/login.php';
        $subject = 'Your login — Olympic Day Quiz 2026';
        $html = '<div style="font-family:Arial,sans-serif;color:#333">'
            . '<h2 style="color:#1A2B49">Olympic Day Celebrations 2026 — Sports Quiz</h2>'
            . '<p>Dear ' . e($sc['name']) . ',</p>'
            . '<p>Your login credentials for the quiz portal:</p>'
            . '<table style="border-collapse:collapse">'
            . '<tr><td style="padding:4px 12px 4px 0"><b>Login URL</b></td><td><a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a></td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0"><b>Email</b></td><td>' . e($sc['email']) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0"><b>Password</b></td><td>' . e($password) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0"><b>School code</b></td><td>' . e($sc['code']) . '</td></tr>'
            . '</table>'
            . '<p style="color:#888;font-size:12px">Please keep these details safe. Log in only during your assigned slot.</p>'
            . '</div>';

        $sent = send_email($sc['email'], $sc['name'], $subject, $html);
        $results[] = ['school' => $sc['name'], 'email' => $sc['email'], 'sent' => $sent];
        audit_log($sent ? 'credentials_email_sent' : 'credentials_email_failed', 'schools', $sc['id'], $sc['email']);
    }

    $okCount = count(array_filter($results, fn($r) => $r['sent']));
    flash($okCount ? 'success' : 'error', "Emailed {$okCount}/" . count($results) . ' school(s).');
    $_SESSION['email_results'] = $results;
    redirect('/admin/email_credentials.php');
}

$emailResults = $_SESSION['email_results'] ?? null;
unset($_SESSION['email_results']);

$q = get('q');
$sql = 'SELECT s.id, s.name, s.code, u.email FROM schools s JOIN users u ON u.id=s.user_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE s.name LIKE :q OR s.code LIKE :q OR u.email LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY s.name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$schools = $stmt->fetchAll();

$pageTitle = 'Email Credentials';
require dirname(__DIR__) . '/includes/header.php';
?>
<h1 class="text-2xl font-bold text-navy mb-1">Bulk Email Credentials</h1>
<p class="text-gray-500 mb-6 text-sm">Select schools and send login credentials. A fresh password is generated and emailed to each selected school.</p>

<?php if ($emailResults): ?>
  <div class="bg-white border border-gray-100 rounded-lg p-4 mb-6">
    <div class="font-semibold text-navy mb-2">Send results</div>
    <ul class="text-sm space-y-1">
      <?php foreach ($emailResults as $r): ?>
        <li class="flex items-center gap-2">
          <?php if ($r['sent']): ?><span class="text-green-600 font-bold">&check;</span><?php else: ?><span class="text-red-600 font-bold">&times;</span><?php endif; ?>
          <?= e($r['school']) ?> <span class="text-gray-400">(<?= e($r['email']) ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="get" class="mb-4 flex gap-2">
  <input name="q" value="<?= e($q) ?>" placeholder="Search name / code / email" class="border border-gray-300 rounded-lg px-3 py-2.5 w-full sm:w-80">
  <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Search</button>
</form>

<form method="post">
  <?= csrf_field() ?>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-lightgrey text-gray-600 text-left">
        <tr>
          <th class="px-4 py-3"><input type="checkbox" id="selectAll" class="rounded"></th>
          <th class="px-4 py-3">Code</th><th class="px-4 py-3">School</th><th class="px-4 py-3">Email</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (!$schools): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No schools found.</td></tr><?php endif; ?>
        <?php foreach ($schools as $s): ?>
          <tr>
            <td class="px-4 py-3"><input type="checkbox" name="school_ids[]" value="<?= (int)$s['id'] ?>" class="rowCheck rounded"></td>
            <td class="px-4 py-3 font-mono text-xs text-gray-600"><?= e($s['code']) ?></td>
            <td class="px-4 py-3 font-medium text-navy"><?= e($s['name']) ?></td>
            <td class="px-4 py-3 text-gray-600"><?= e($s['email']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="mt-4 flex justify-end">
    <button type="submit" onclick="return confirm('Generate new passwords and email selected schools?');" class="bg-teal text-white rounded-lg px-5 py-2.5 font-medium min-h-[44px]">Send Credentials</button>
  </div>
</form>

<script>
  document.getElementById('selectAll').addEventListener('change', function(){
    document.querySelectorAll('.rowCheck').forEach(c => c.checked = this.checked);
  });
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
