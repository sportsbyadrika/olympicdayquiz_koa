<?php
/**
 * Admin · Associations CRUD + bulk CSV upload.
 * Exclusive `is_event_conductor` toggle (only one at a time).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
require_role('admin');

$action = post('action', get('action'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();

    // ---- Create ----------------------------------------------------------
    if ($action === 'create') {
        $name  = post('name');
        $email = strtolower(post('email'));
        $person = post('contact_person');
        $phone = post('contact_phone');
        $district = post('district');
        $address = post('address');
        $conductor = post('is_event_conductor') === '1';

        $errors = [];
        if ($name === '')              $errors[] = 'Name is required.';
        if (!is_email($email))         $errors[] = 'A valid email is required.';
        elseif (email_exists($email))  $errors[] = 'That email is already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $password = generate_password();
                $uid = create_user($email, $password, 'association');
                $stmt = $pdo->prepare(
                    'INSERT INTO associations (user_id, name, contact_person, contact_email, contact_phone, district, address, is_event_conductor)
                     VALUES (:uid, :name, :person, :email, :phone, :district, :address, 0)'
                );
                $stmt->execute([
                    ':uid' => $uid, ':name' => $name, ':person' => $person,
                    ':email' => $email, ':phone' => $phone,
                    ':district' => $district, ':address' => $address,
                ]);
                $aid = (int) $pdo->lastInsertId();
                $pdo->commit();
                if ($conductor) {
                    set_event_conductor($aid);
                }
                audit_log('association_create', 'associations', $aid, 'email=' . $email);
                flash('success', "Association created. Temporary password: {$password}");
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Could not create association.');
            }
        }
        redirect('/admin/associations.php');
    }

    // ---- Update ----------------------------------------------------------
    if ($action === 'update') {
        $id = int_val(post('id'));
        $name = post('name');
        $email = strtolower(post('email'));
        $person = post('contact_person');
        $phone = post('contact_phone');
        $district = post('district');
        $address = post('address');

        // Resolve this association's login user id.
        $u = db()->prepare('SELECT user_id FROM associations WHERE id=:id');
        $u->execute([':id' => $id]);
        $userId = (int) $u->fetchColumn();

        $errors = [];
        if (!$id || $userId === 0)  $errors[] = 'Association not found.';
        if ($name === '')           $errors[] = 'Name is required.';
        if (!is_email($email))      $errors[] = 'A valid email is required.';
        elseif (email_exists($email, $userId)) $errors[] = 'That email is already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            update_user_email($userId, $email);
            $stmt = db()->prepare(
                'UPDATE associations SET name=:n, contact_email=:e, contact_person=:p, contact_phone=:ph,
                 district=:district, address=:address WHERE id=:id'
            );
            $stmt->execute([
                ':n' => $name, ':e' => $email, ':p' => $person, ':ph' => $phone,
                ':district' => $district, ':address' => $address, ':id' => $id,
            ]);
            audit_log('association_update', 'associations', $id);
            flash('success', 'Association updated.');
        }
        redirect('/admin/associations.php');
    }

    // ---- Reset password (admin types it directly) ------------------------
    if ($action === 'reset_password') {
        $id = int_val(post('id'));
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $u = db()->prepare('SELECT user_id FROM associations WHERE id=:id');
        $u->execute([':id' => $id]);
        $userId = (int) $u->fetchColumn();

        if (!$userId)                 flash('error', 'Association not found.');
        elseif (mb_strlen($new) < 8)  flash('error', 'Password must be at least 8 characters.');
        elseif ($new !== $confirm)    flash('error', 'Passwords do not match.');
        else {
            set_user_password($userId, $new);
            audit_log('association_password_reset', 'associations', $id);
            flash('success', 'Password updated.');
        }
        redirect('/admin/associations.php');
    }

    // ---- Email a freshly generated password ------------------------------
    if ($action === 'email_reset') {
        $id = int_val(post('id'));
        $st = db()->prepare('SELECT a.name, u.id AS user_id, u.email FROM associations a JOIN users u ON u.id=a.user_id WHERE a.id=:id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if ($row) {
            $password = generate_password();
            set_user_password((int) $row['user_id'], $password);
            $sent = email_login_credentials($row['email'], $row['name'], $password);
            audit_log($sent ? 'association_password_emailed' : 'association_password_email_failed', 'associations', $id, $row['email']);
            flash($sent ? 'success' : 'warning',
                $sent ? "New password emailed to {$row['email']}." : "Password was reset but the email could not be sent to {$row['email']}.");
        }
        redirect('/admin/associations.php');
    }

    // ---- Set conductor ---------------------------------------------------
    if ($action === 'set_conductor') {
        $id = int_val(post('id'));
        if ($id) {
            set_event_conductor($id);
            audit_log('association_set_conductor', 'associations', $id);
            flash('success', 'Event Conducting Association updated.');
        }
        redirect('/admin/associations.php');
    }

    // ---- Delete ----------------------------------------------------------
    if ($action === 'delete') {
        $id = int_val(post('id'));
        if ($id) {
            $stmt = db()->prepare('SELECT user_id FROM associations WHERE id=:id');
            $stmt->execute([':id' => $id]);
            $uid = $stmt->fetchColumn();
            if ($uid) {
                // Deleting the user cascades to the association profile.
                db()->prepare('DELETE FROM users WHERE id=:uid')->execute([':uid' => $uid]);
                audit_log('association_delete', 'associations', $id);
                flash('success', 'Association deleted.');
            }
        }
        redirect('/admin/associations.php');
    }

    // ---- Bulk CSV upload -------------------------------------------------
    if ($action === 'csv_upload') {
        $result = handle_association_csv();
        $_SESSION['csv_report'] = $result;
        flash($result['ok'] ? 'success' : 'warning',
            "CSV processed: {$result['inserted']} added, " . count($result['errors']) . ' row error(s).');
        redirect('/admin/associations.php');
    }
}

/** Process the uploaded associations CSV. */
function handle_association_csv(): array
{
    $report = ['ok' => false, 'inserted' => 0, 'errors' => []];
    $file = $_FILES['csv_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $report['errors'][] = ['row' => 0, 'message' => 'No file uploaded or upload error.'];
        return $report;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $report['errors'][] = ['row' => 0, 'message' => 'File exceeds 2 MB limit.'];
        return $report;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
        $report['errors'][] = ['row' => 0, 'message' => "Unexpected file type ({$mime})."];
        return $report;
    }

    $expected = ['name', 'email', 'contact_person', 'contact_phone', 'district', 'address'];
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $report['errors'][] = ['row' => 0, 'message' => 'Could not read file.'];
        return $report;
    }

    $header = fgetcsv($handle);
    $header = $header ? array_map(fn($h) => strtolower(trim((string) $h)), $header) : [];
    if (array_slice($header, 0, count($expected)) !== $expected) {
        $report['errors'][] = ['row' => 1, 'message' => 'Header must be: ' . implode(', ', $expected)];
        fclose($handle);
        return $report;
    }

    $pdo = db();
    $rowNum = 1;
    $maxRows = 1000;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if ($rowNum - 1 > $maxRows) {
            $report['errors'][] = ['row' => $rowNum, 'message' => "Max {$maxRows} rows exceeded."];
            break;
        }
        if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) {
            continue; // skip blank line
        }
        $name = trim((string) ($row[0] ?? ''));
        $email = strtolower(trim((string) ($row[1] ?? '')));
        $person = trim((string) ($row[2] ?? ''));
        $phone = trim((string) ($row[3] ?? ''));
        $district = trim((string) ($row[4] ?? ''));
        $address = trim((string) ($row[5] ?? ''));

        if ($name === '' || !is_email($email)) {
            $report['errors'][] = ['row' => $rowNum, 'message' => 'Missing name or invalid email.'];
            continue;
        }
        if (email_exists($email)) {
            $report['errors'][] = ['row' => $rowNum, 'message' => "Email already exists: {$email}"];
            continue;
        }
        try {
            $pdo->beginTransaction();
            $password = generate_password();
            $uid = create_user($email, $password, 'association');
            $stmt = $pdo->prepare(
                'INSERT INTO associations (user_id, name, contact_person, contact_email, contact_phone, district, address, is_event_conductor)
                 VALUES (:uid, :name, :person, :email, :phone, :district, :address, 0)'
            );
            $stmt->execute([':uid' => $uid, ':name' => $name, ':person' => $person, ':email' => $email, ':phone' => $phone, ':district' => $district, ':address' => $address]);
            $pdo->commit();
            $report['inserted']++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $report['errors'][] = ['row' => $rowNum, 'message' => 'Insert failed.'];
        }
    }
    fclose($handle);
    $report['ok'] = $report['inserted'] > 0;
    audit_log('association_csv_upload', 'associations', null, "inserted={$report['inserted']}");
    return $report;
}

$fName = get('q');
$where = '';
$listParams = [];
if ($fName !== '') {
    $where = ' WHERE a.name LIKE :q';
    $listParams[':q'] = '%' . $fName . '%';
}

// Pagination (25 per page).
$perPage = 25;
$page = max(1, int_val(get('page')));
$countStmt = db()->prepare('SELECT COUNT(*) FROM associations a' . $where);
$countStmt->execute($listParams);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare(
    "SELECT a.*, u.email, u.status, ll.last_login
     FROM associations a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN (SELECT user_id, MAX(created_at) AS last_login FROM audit_log WHERE action='login_success' GROUP BY user_id) ll ON ll.user_id = u.id"
    . $where . " ORDER BY a.name LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($listParams);
$rows = $listStmt->fetchAll();

$csvReport = $_SESSION['csv_report'] ?? null;
unset($_SESSION['csv_report']);

$pageTitle = 'Associations';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-bold text-navy">Associations</h1>
  <div class="flex gap-2">
    <button onclick="document.getElementById('csvModal').classList.remove('hidden')" class="bg-white border border-navy text-navy rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">Bulk CSV Upload</button>
    <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ Add Association</button>
  </div>
</div>

<?php if ($csvReport && !empty($csvReport['errors'])): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
    <div class="font-semibold text-amber-800 mb-2">CSV row errors</div>
    <ul class="text-sm text-amber-800 list-disc list-inside space-y-0.5">
      <?php foreach ($csvReport['errors'] as $err): ?>
        <li>Row <?= (int) $err['row'] ?>: <?= e($err['message']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="get" class="mb-4 flex gap-2">
  <input name="q" value="<?= e($fName) ?>" placeholder="Search by name" class="border border-gray-300 rounded-lg px-3 py-2.5 w-full sm:w-72">
  <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Search</button>
  <?php if ($fName !== ''): ?><a href="<?= e(BASE_URL) ?>/admin/associations.php" class="px-4 py-2 rounded-lg border border-gray-300 text-sm self-center">Clear</a><?php endif; ?>
</form>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr>
        <th class="px-4 py-3">Name</th>
        <th class="px-4 py-3">Email</th>
        <th class="px-4 py-3">Contact</th>
        <th class="px-4 py-3">Conductor</th>
        <th class="px-4 py-3 text-center">Last login</th>
        <th class="px-4 py-3 text-right">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No associations yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-3 font-medium text-navy"><?= e($r['name']) ?><?= $r['district'] ? '<br><span class="text-xs text-gray-400">' . e($r['district']) . '</span>' : '' ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['email']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['contact_person']) ?><?= $r['contact_phone'] ? '<br><span class="text-xs text-gray-400">' . e($r['contact_phone']) . '</span>' : '' ?></td>
          <td class="px-4 py-3">
            <?php if ((int) $r['is_event_conductor'] === 1): ?>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-teal/10 text-teal">Event Conductor</span>
            <?php else: ?>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_conductor">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="text-xs text-gray-500 hover:text-teal underline">Make conductor</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center"><?= last_login_badge($r['last_login']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <div class="inline-flex items-center gap-3">
              <button title="Edit" class="text-navy hover:opacity-70"
                onclick='openEdit(<?= json_encode([
                  "id" => (int)$r["id"], "name" => $r["name"], "email" => $r["email"],
                  "contact_person" => $r["contact_person"], "contact_phone" => $r["contact_phone"],
                  "district" => $r["district"], "address" => $r["address"],
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <button title="Reset password" class="text-teal hover:opacity-70"
                onclick="openReset(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['name']), ENT_QUOTES) ?>)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a4 4 0 11-8 0 4 4 0 018 0zM7 11v3m0 0H4m3 0h3m4-3l2 2m0 0l2 2m-2-2l2-2m-2 2l-2 2"/></svg>
              </button>
              <form method="post" class="inline" onsubmit="return confirm('Generate a new password and email it to this association?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_reset">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button title="Email new password" class="text-navy hover:opacity-70 align-middle">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </button>
              </form>
              <form method="post" class="inline" onsubmit="return confirm('Delete this association and its login?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button title="Delete" class="text-red-600 hover:opacity-70 align-middle">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?= paginate_links($page, $pages) ?>

<!-- Create modal -->
<div id="createModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-4">Add Association</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label class="block text-sm font-medium mb-1">Association name *</label>
      <input name="name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Login email *</label>
      <input name="email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Contact person</label>
      <input name="contact_person" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Contact phone</label>
      <input name="contact_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">District (association office)</label>
      <input name="district" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Address</label>
      <textarea name="address" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3"></textarea>
      <label class="flex items-center gap-2 mb-4 text-sm">
        <input type="checkbox" name="is_event_conductor" value="1" class="rounded"> Mark as Event Conducting Association
      </label>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-4">Edit Association</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">
      <label class="block text-sm font-medium mb-1">Association name *</label>
      <input name="name" id="edit_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Login email *</label>
      <input name="email" id="edit_email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Contact person</label>
      <input name="contact_person" id="edit_person" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Contact phone</label>
      <input name="contact_phone" id="edit_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">District (association office)</label>
      <input name="district" id="edit_district" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Address</label>
      <textarea name="address" id="edit_address" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4"></textarea>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- CSV modal -->
<div id="csvModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-2">Bulk Upload Associations</h2>
    <p class="text-sm text-gray-500 mb-4">CSV columns (in order): <code class="text-xs bg-lightgrey px-1 rounded">name, email, contact_person, contact_phone, district, address</code></p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="csv_upload">
      <input type="file" name="csv_file" accept=".csv,text/csv" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4">
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('csvModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Upload</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset password modal -->
<div id="resetModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-1">Reset Password</h2>
    <p id="reset_label" class="text-sm text-gray-500 mb-4"></p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" id="reset_id">
      <label class="block text-sm font-medium mb-1">New password</label>
      <input name="new_password" type="text" required minlength="8" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-1 font-mono">
      <p class="text-xs text-gray-400 mb-3">At least 8 characters. You can type any password to set it directly.</p>
      <label class="block text-sm font-medium mb-1">Confirm password</label>
      <input name="confirm_password" type="text" required minlength="8" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4 font-mono">
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('resetModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-teal text-white font-medium">Set Password</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openReset(id, label) {
    document.getElementById('reset_id').value = id;
    document.getElementById('reset_label').textContent = 'For: ' + label;
    document.getElementById('resetModal').classList.remove('hidden');
  }
  function openEdit(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.name || '';
    document.getElementById('edit_email').value = data.email || '';
    document.getElementById('edit_person').value = data.contact_person || '';
    document.getElementById('edit_phone').value = data.contact_phone || '';
    document.getElementById('edit_district').value = data.district || '';
    document.getElementById('edit_address').value = data.address || '';
    document.getElementById('editModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
