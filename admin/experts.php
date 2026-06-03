<?php
/** Admin · Experts CRUD. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/users.php';
require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $name = post('name');
        $email = strtolower(post('email'));
        $phone = post('phone');
        $details = post('details');
        $errors = [];
        if ($name === '')             $errors[] = 'Name is required.';
        if (!is_email($email))        $errors[] = 'A valid email is required.';
        elseif (email_exists($email)) $errors[] = 'That email is already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $password = generate_password();
                $uid = create_user($email, $password, 'expert');
                $stmt = $pdo->prepare('INSERT INTO experts (user_id, name, email, phone, details) VALUES (:uid,:n,:e,:p,:d)');
                $stmt->execute([':uid' => $uid, ':n' => $name, ':e' => $email, ':p' => $phone, ':d' => $details]);
                $pdo->commit();
                audit_log('expert_create', 'experts', (int) $pdo->lastInsertId());
                flash('success', "Expert created. Temporary password: {$password}");
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Could not create expert.');
            }
        }
        redirect('/admin/experts.php');
    }

    if ($action === 'update') {
        $id = int_val(post('id'));
        $name = post('name');
        $email = strtolower(post('email'));
        $phone = post('phone');
        $details = post('details');

        $u = db()->prepare('SELECT user_id FROM experts WHERE id=:id');
        $u->execute([':id' => $id]);
        $userId = (int) $u->fetchColumn();

        $errors = [];
        if (!$id || $userId === 0) $errors[] = 'Expert not found.';
        if ($name === '')          $errors[] = 'Name is required.';
        if (!is_email($email))     $errors[] = 'A valid email is required.';
        elseif (email_exists($email, $userId)) $errors[] = 'That email is already in use.';

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            update_user_email($userId, $email);
            db()->prepare('UPDATE experts SET name=:n, email=:e, phone=:p, details=:d WHERE id=:id')
                ->execute([':n' => $name, ':e' => $email, ':p' => $phone, ':d' => $details, ':id' => $id]);
            audit_log('expert_update', 'experts', $id);
            flash('success', 'Expert updated.');
        }
        redirect('/admin/experts.php');
    }

    if ($action === 'reset_password') {
        $id = int_val(post('id'));
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $u = db()->prepare('SELECT user_id FROM experts WHERE id=:id');
        $u->execute([':id' => $id]);
        $userId = (int) $u->fetchColumn();

        if (!$userId)                 flash('error', 'Expert not found.');
        elseif (mb_strlen($new) < 8)  flash('error', 'Password must be at least 8 characters.');
        elseif ($new !== $confirm)    flash('error', 'Passwords do not match.');
        else {
            set_user_password($userId, $new);
            audit_log('expert_password_reset', 'experts', $id);
            flash('success', 'Password updated.');
        }
        redirect('/admin/experts.php');
    }

    if ($action === 'email_reset') {
        $id = int_val(post('id'));
        $st = db()->prepare('SELECT e.name, u.id AS user_id, u.email FROM experts e JOIN users u ON u.id=e.user_id WHERE e.id=:id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if ($row) {
            $password = generate_password();
            set_user_password((int) $row['user_id'], $password);
            $sent = email_login_credentials($row['email'], $row['name'], $password);
            audit_log($sent ? 'expert_password_emailed' : 'expert_password_email_failed', 'experts', $id, $row['email']);
            flash($sent ? 'success' : 'warning',
                $sent ? "New password emailed to {$row['email']}." : "Password was reset but the email could not be sent to {$row['email']}.");
        }
        redirect('/admin/experts.php');
    }

    if ($action === 'delete') {
        $id = int_val(post('id'));
        if ($id) {
            $uid = db()->prepare('SELECT user_id FROM experts WHERE id=:id');
            $uid->execute([':id' => $id]);
            $userId = $uid->fetchColumn();
            if ($userId) {
                db()->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $userId]);
                audit_log('expert_delete', 'experts', $id);
                flash('success', 'Expert deleted.');
            }
        }
        redirect('/admin/experts.php');
    }
}

$rows = db()->query(
    "SELECT e.*, u.email, u.status, ll.last_login
     FROM experts e
     JOIN users u ON u.id = e.user_id
     LEFT JOIN (SELECT user_id, MAX(created_at) AS last_login FROM audit_log WHERE action='login_success' GROUP BY user_id) ll ON ll.user_id = u.id
     ORDER BY e.name"
)->fetchAll();

$pageTitle = 'Experts';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
  <h1 class="text-2xl font-bold text-navy">Experts</h1>
  <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="bg-navy text-white rounded-lg px-4 py-2 text-sm font-medium min-h-[44px]">+ Add Expert</button>
</div>

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-lightgrey text-gray-600 text-left">
      <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3 text-center">Last login</th><th class="px-4 py-3 text-right">Actions</th></tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No experts yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-3 font-medium text-navy"><?= e($r['name']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['email']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['phone']) ?></td>
          <td class="px-4 py-3 text-center"><?= last_login_badge($r['last_login']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <div class="inline-flex items-center gap-3">
              <button title="Edit" class="text-navy hover:opacity-70" onclick='openEdit(<?= json_encode(["id"=>(int)$r["id"],"name"=>$r["name"],"email"=>$r["email"],"phone"=>$r["phone"],"details"=>$r["details"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <button title="Reset password" class="text-teal hover:opacity-70" onclick="openReset(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['name']), ENT_QUOTES) ?>)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a4 4 0 11-8 0 4 4 0 018 0zM7 11v3m0 0H4m3 0h3m4-3l2 2m0 0l2 2m-2-2l2-2m-2 2l-2 2"/></svg>
              </button>
              <form method="post" class="inline" onsubmit="return confirm('Generate a new password and email it to this expert?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button title="Email new password" class="text-navy hover:opacity-70 align-middle">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </button>
              </form>
              <form method="post" class="inline" onsubmit="return confirm('Delete this expert and login?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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

<div id="createModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-4">Add Expert</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label class="block text-sm font-medium mb-1">Name *</label>
      <input name="name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Login email *</label>
      <input name="email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Phone</label>
      <input name="phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Details</label>
      <textarea name="details" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4" placeholder="Areas of expertise, notes, etc."></textarea>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Create</button>
      </div>
    </form>
  </div>
</div>

<div id="editModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6">
    <h2 class="text-lg font-bold text-navy mb-4">Edit Expert</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit_id">
      <label class="block text-sm font-medium mb-1">Name *</label>
      <input name="name" id="edit_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Login email *</label>
      <input name="email" id="edit_email" type="email" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Phone</label>
      <input name="phone" id="edit_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-3">
      <label class="block text-sm font-medium mb-1">Details</label>
      <textarea name="details" id="edit_details" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4"></textarea>
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Save</button>
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
  function openReset(id, label){document.getElementById('reset_id').value=id;document.getElementById('reset_label').textContent='For: '+label;document.getElementById('resetModal').classList.remove('hidden');}
  function openEdit(d){document.getElementById('edit_id').value=d.id;document.getElementById('edit_name').value=d.name||'';document.getElementById('edit_email').value=d.email||'';document.getElementById('edit_phone').value=d.phone||'';document.getElementById('edit_details').value=d.details||'';document.getElementById('editModal').classList.remove('hidden');}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
