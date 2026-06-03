<?php
/**
 * Admin · Schools CRUD + CSV bulk upload.
 * Each school gets an auto-generated random password (bcrypt-hashed).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
require_once dirname(__DIR__) . '/includes/lookups.php';
require_role('admin');

/** Does a school code already exist? */
function school_code_exists(string $code, ?int $exceptId = null): bool
{
    $sql = 'SELECT id FROM schools WHERE code = :c';
    $p = [':c' => $code];
    if ($exceptId !== null) { $sql .= ' AND id <> :id'; $p[':id'] = $exceptId; }
    $st = db()->prepare($sql . ' LIMIT 1');
    $st->execute($p);
    return (bool) $st->fetch();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $name = post('school_name');
        $code = post('school_code');
        $email = strtolower(post('email'));
        $p1 = post('participant1_name');
        $p2 = post('participant2_name');
        $person = post('contact_person');
        $addr = post('address');
        $contact = post('contact');
        $typeId = int_val(post('school_type_id')) ?: null;
        $syllabusId = int_val(post('syllabus_id')) ?: null;

        $errors = [];
        if ($name === '')              $errors[] = 'School name is required.';
        if ($code === '')              $errors[] = 'School code is required.';
        elseif (school_code_exists($code)) $errors[] = 'School code already exists.';
        if (!is_email($email))         $errors[] = 'A valid email is required.';
        elseif (email_exists($email))  $errors[] = 'Email already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $password = generate_password();
                $uid = create_user($email, $password, 'school');
                $stmt = $pdo->prepare(
                    'INSERT INTO schools (user_id, name, code, email, participant1_name, participant2_name, contact_person, address, contact, school_type_id, syllabus_id)
                     VALUES (:uid,:name,:code,:email,:p1,:p2,:person,:addr,:contact,:type,:syllabus)'
                );
                $stmt->execute([
                    ':uid' => $uid, ':name' => $name, ':code' => $code, ':email' => $email,
                    ':p1' => $p1, ':p2' => $p2, ':person' => $person, ':addr' => $addr, ':contact' => $contact,
                    ':type' => $typeId, ':syllabus' => $syllabusId,
                ]);
                $pdo->commit();
                audit_log('school_create', 'schools', (int) $pdo->lastInsertId());
                flash('success', "School created. Temporary password: {$password}");
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Could not create school.');
            }
        }
        redirect('/admin/schools.php');
    }

    if ($action === 'update') {
        $id = int_val(post('id'));
        $name = post('school_name');
        $email = strtolower(post('email'));
        $p1 = post('participant1_name');
        $p2 = post('participant2_name');
        $person = post('contact_person');
        $addr = post('address');
        $contact = post('contact');
        $typeId = int_val(post('school_type_id')) ?: null;
        $syllabusId = int_val(post('syllabus_id')) ?: null;

        $u = db()->prepare('SELECT user_id FROM schools WHERE id=:id');
        $u->execute([':id' => $id]);
        $userId = (int) $u->fetchColumn();

        $errors = [];
        if (!$id || $userId === 0) $errors[] = 'School not found.';
        if ($name === '')          $errors[] = 'School name is required.';
        if (!is_email($email))     $errors[] = 'A valid email is required.';
        elseif (email_exists($email, $userId)) $errors[] = 'Email already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            update_user_email($userId, $email);
            db()->prepare(
                'UPDATE schools SET name=:name, email=:email, participant1_name=:p1, participant2_name=:p2,
                 contact_person=:person, address=:addr, contact=:contact, school_type_id=:type, syllabus_id=:syllabus WHERE id=:id'
            )->execute([
                ':name' => $name, ':email' => $email, ':p1' => $p1, ':p2' => $p2, ':person' => $person,
                ':addr' => $addr, ':contact' => $contact, ':type' => $typeId, ':syllabus' => $syllabusId, ':id' => $id,
            ]);
            audit_log('school_update', 'schools', $id);
            flash('success', 'School updated.');
        }
        redirect('/admin/schools.php');
    }

    if ($action === 'delete') {
        $id = int_val(post('id'));
        if ($id) {
            $st = db()->prepare('SELECT user_id FROM schools WHERE id=:id');
            $st->execute([':id' => $id]);
            $userId = $st->fetchColumn();
            if ($userId) {
                db()->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $userId]);
                audit_log('school_delete', 'schools', $id);
                flash('success', 'School deleted.');
            }
        }
        redirect('/admin/schools.php');
    }

    if ($action === 'csv_upload') {
        $result = handle_school_csv();
        $_SESSION['csv_report'] = $result;
        flash($result['ok'] ? 'success' : 'warning',
            "CSV processed: {$result['inserted']} added, " . count($result['errors']) . ' row error(s).');
        redirect('/admin/schools.php');
    }
}

/** Process the schools CSV upload. */
function handle_school_csv(): array
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

    $expected = ['school_name', 'school_code', 'email', 'participant1_name', 'participant2_name', 'address', 'contact', 'contact_person', 'school_type', 'syllabus'];
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
    $maxRows = 2000;
    $createdPasswords = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if ($rowNum - 1 > $maxRows) {
            $report['errors'][] = ['row' => $rowNum, 'message' => "Max {$maxRows} rows exceeded."];
            break;
        }
        if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) continue;

        [$name, $code, $email, $p1, $p2, $addr, $contact, $person, $typeName, $syllabusName] = array_pad(
            array_map(fn($c) => trim((string) $c), $row), 10, ''
        );
        $email = strtolower($email);

        if ($name === '' || $code === '' || !is_email($email)) {
            $report['errors'][] = ['row' => $rowNum, 'message' => 'Missing name/code or invalid email.'];
            continue;
        }
        if (school_code_exists($code)) {
            $report['errors'][] = ['row' => $rowNum, 'message' => "School code exists: {$code}"];
            continue;
        }
        if (email_exists($email)) {
            $report['errors'][] = ['row' => $rowNum, 'message' => "Email exists: {$email}"];
            continue;
        }
        // Resolve optional master-table values; unknown values are an error.
        $typeId = null;
        if ($typeName !== '') {
            $typeId = lookup_id_by_name('school_types', $typeName);
            if ($typeId === null) {
                $report['errors'][] = ['row' => $rowNum, 'message' => "Unknown school_type: {$typeName}"];
                continue;
            }
        }
        $syllabusId = null;
        if ($syllabusName !== '') {
            $syllabusId = lookup_id_by_name('syllabi', $syllabusName);
            if ($syllabusId === null) {
                $report['errors'][] = ['row' => $rowNum, 'message' => "Unknown syllabus: {$syllabusName}"];
                continue;
            }
        }
        try {
            $pdo->beginTransaction();
            $password = generate_password();
            $uid = create_user($email, $password, 'school');
            $stmt = $pdo->prepare(
                'INSERT INTO schools (user_id, name, code, email, participant1_name, participant2_name, contact_person, address, contact, school_type_id, syllabus_id)
                 VALUES (:uid,:name,:code,:email,:p1,:p2,:person,:addr,:contact,:type,:syllabus)'
            );
            $stmt->execute([':uid' => $uid, ':name' => $name, ':code' => $code, ':email' => $email,
                ':p1' => $p1, ':p2' => $p2, ':person' => $person, ':addr' => $addr, ':contact' => $contact,
                ':type' => $typeId, ':syllabus' => $syllabusId]);
            $pdo->commit();
            $report['inserted']++;
            $createdPasswords[] = ['code' => $code, 'email' => $email, 'password' => $password];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $report['errors'][] = ['row' => $rowNum, 'message' => 'Insert failed.'];
        }
    }
    fclose($handle);
    $report['ok'] = $report['inserted'] > 0;
    $report['passwords'] = $createdPasswords;
    audit_log('school_csv_upload', 'schools', null, "inserted={$report['inserted']}");
    return $report;
}

$schoolTypes = lookup_map('school_types');
$syllabi = lookup_map('syllabi');

// Filters: name (textbox) + type (dropdown).
$fName = get('q');
$fType = int_val(get('type'));
$listSql = 'SELECT s.*, u.email AS login_email, u.status, st.name AS type_name, sy.name AS syllabus_name
            FROM schools s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN school_types st ON st.id = s.school_type_id
            LEFT JOIN syllabi sy ON sy.id = s.syllabus_id
            WHERE 1=1';
$listParams = [];
if ($fName !== '') { $listSql .= ' AND s.name LIKE :q'; $listParams[':q'] = '%' . $fName . '%'; }
if ($fType) { $listSql .= ' AND s.school_type_id = :type'; $listParams[':type'] = $fType; }
$listSql .= ' ORDER BY s.name';
$listStmt = db()->prepare($listSql);
$listStmt->execute($listParams);
$rows = $listStmt->fetchAll();

$csvReport = $_SESSION['csv_report'] ?? null;
unset($_SESSION['csv_report']);

$pageTitle = 'Schools';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-bold text-navy">Schools</h1>
  <div class="flex gap-2 flex-wrap">
    <a href="<?= e(BASE_URL) ?>/admin/email_credentials.php" class="bg-white border border-teal text-teal rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">Email Credentials</a>
    <button onclick="document.getElementById('csvModal').classList.remove('hidden')" class="bg-white border border-navy text-navy rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">Bulk CSV Upload</button>
    <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ Add School</button>
  </div>
</div>

<?php if ($csvReport && !empty($csvReport['errors'])): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
    <div class="font-semibold text-amber-800 mb-2">CSV row errors</div>
    <ul class="text-sm text-amber-800 list-disc list-inside space-y-0.5">
      <?php foreach ($csvReport['errors'] as $err): ?><li>Row <?= (int) $err['row'] ?>: <?= e($err['message']) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($csvReport && !empty($csvReport['passwords'])): ?>
  <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
    <div class="font-semibold text-green-800 mb-2">Generated passwords (shown once — email them from the credentials page)</div>
    <div class="overflow-x-auto"><table class="text-sm"><thead><tr class="text-left text-green-900"><th class="pr-4">Code</th><th class="pr-4">Email</th><th>Password</th></tr></thead><tbody>
      <?php foreach ($csvReport['passwords'] as $pw): ?><tr><td class="pr-4"><?= e($pw['code']) ?></td><td class="pr-4"><?= e($pw['email']) ?></td><td><code><?= e($pw['password']) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
<?php endif; ?>

<form method="get" class="mb-4 flex flex-wrap gap-2">
  <input name="q" value="<?= e($fName) ?>" placeholder="Search by name" class="border border-gray-300 rounded-lg px-3 py-2.5 w-full sm:w-64">
  <select name="type" class="border border-gray-300 rounded-lg px-3 py-2.5">
    <option value="">All types</option>
    <?php foreach ($schoolTypes as $tid => $tname): ?>
      <option value="<?= (int)$tid ?>" <?= $fType === (int)$tid ? 'selected' : '' ?>><?= e($tname) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="bg-navy text-white rounded-lg px-4 py-2 text-sm">Search</button>
  <?php if ($fName !== '' || $fType): ?><a href="<?= e(BASE_URL) ?>/admin/schools.php" class="px-4 py-2 rounded-lg border border-gray-300 text-sm self-center">Clear</a><?php endif; ?>
</form>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">School</th><th class="px-4 py-3">Type / Syllabus</th><th class="px-4 py-3">Participants</th><th class="px-4 py-3">Email</th><th class="px-4 py-3 text-right">Actions</th></tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No schools found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-3 font-mono text-xs text-gray-600"><?= e($r['code']) ?></td>
          <td class="px-4 py-3 font-medium text-navy"><?= e($r['name']) ?><?php if ($r['address']): ?><br><span class="text-xs text-gray-400"><?= e($r['address']) ?></span><?php endif; ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['type_name'] ?: '—') ?><?= $r['syllabus_name'] ? '<br><span class="text-xs text-gray-400">' . e($r['syllabus_name']) . '</span>' : '' ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['participant1_name']) ?><?= $r['participant2_name'] ? '<br>' . e($r['participant2_name']) : '' ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['login_email']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <button class="text-navy hover:underline mr-3" onclick='openEdit(<?= json_encode([
              "id"=>(int)$r["id"],"school_name"=>$r["name"],"email"=>$r["login_email"],"participant1_name"=>$r["participant1_name"],
              "participant2_name"=>$r["participant2_name"],"contact_person"=>$r["contact_person"],"address"=>$r["address"],"contact"=>$r["contact"],
              "school_type_id"=>(int)$r["school_type_id"],"syllabus_id"=>(int)$r["syllabus_id"],
            ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete this school and login?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="text-red-600 hover:underline">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Create modal -->
<div id="createModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-lg p-6 my-8">
    <h2 class="text-lg font-bold text-navy mb-4">Add School</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="block text-sm font-medium mb-1">School name *</label><input name="school_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">School code *</label><input name="school_code" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Login email *</label><input name="email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Participant 1</label><input name="participant1_name" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Participant 2</label><input name="participant2_name" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div>
          <label class="block text-sm font-medium mb-1">School type</label>
          <select name="school_type_id" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
            <option value="">— Select —</option>
            <?php foreach ($schoolTypes as $tid => $tname): ?><option value="<?= (int)$tid ?>"><?= e($tname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Syllabus</label>
          <select name="syllabus_id" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
            <option value="">— Select —</option>
            <?php foreach ($syllabi as $sid => $sname): ?><option value="<?= (int)$sid ?>"><?= e($sname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label class="block text-sm font-medium mb-1">Contact person</label><input name="contact_person" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Contact number</label><input name="contact" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Address</label><input name="address" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 overflow-y-auto">
  <div class="bg-white rounded-2xl w-full max-w-lg p-6 my-8">
    <h2 class="text-lg font-bold text-navy mb-4">Edit School</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">School name *</label><input name="school_name" id="edit_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Login email *</label><input name="email" id="edit_email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Participant 1</label><input name="participant1_name" id="edit_p1" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Participant 2</label><input name="participant2_name" id="edit_p2" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div>
          <label class="block text-sm font-medium mb-1">School type</label>
          <select name="school_type_id" id="edit_type" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
            <option value="">— Select —</option>
            <?php foreach ($schoolTypes as $tid => $tname): ?><option value="<?= (int)$tid ?>"><?= e($tname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Syllabus</label>
          <select name="syllabus_id" id="edit_syllabus" class="w-full border border-gray-300 rounded-lg px-3 py-2.5">
            <option value="">— Select —</option>
            <?php foreach ($syllabi as $sid => $sname): ?><option value="<?= (int)$sid ?>"><?= e($sname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label class="block text-sm font-medium mb-1">Contact person</label><input name="contact_person" id="edit_person" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div><label class="block text-sm font-medium mb-1">Contact number</label><input name="contact" id="edit_contact" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
        <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1">Address</label><input name="address" id="edit_addr" class="w-full border border-gray-300 rounded-lg px-3 py-2.5"></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- CSV modal -->
<div id="csvModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-2">Bulk Upload Schools</h2>
    <p class="text-sm text-gray-500 mb-4">CSV columns: <code class="text-xs bg-lightgrey px-1 rounded">school_name, school_code, email, participant1_name, participant2_name, address, contact, contact_person, school_type, syllabus</code>. <code class="text-xs">school_type</code> must be one of <?= e(implode(', ', $schoolTypes)) ?>; <code class="text-xs">syllabus</code> one of <?= e(implode(', ', $syllabi)) ?> (or left blank).</p>
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

<script>
  function openEdit(d){
    document.getElementById('edit_id').value=d.id;
    document.getElementById('edit_name').value=d.school_name||'';
    document.getElementById('edit_email').value=d.email||'';
    document.getElementById('edit_p1').value=d.participant1_name||'';
    document.getElementById('edit_p2').value=d.participant2_name||'';
    document.getElementById('edit_person').value=d.contact_person||'';
    document.getElementById('edit_addr').value=d.address||'';
    document.getElementById('edit_contact').value=d.contact||'';
    document.getElementById('edit_type').value=d.school_type_id||'';
    document.getElementById('edit_syllabus').value=d.syllabus_id||'';
    document.getElementById('editModal').classList.remove('hidden');
  }
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
