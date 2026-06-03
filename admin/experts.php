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
                $stmt = $pdo->prepare('INSERT INTO experts (user_id, name, email, phone) VALUES (:uid,:n,:e,:p)');
                $stmt->execute([':uid' => $uid, ':n' => $name, ':e' => $email, ':p' => $phone]);
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
        $phone = post('phone');
        if ($id && $name !== '') {
            db()->prepare('UPDATE experts SET name=:n, phone=:p WHERE id=:id')
                ->execute([':n' => $name, ':p' => $phone, ':id' => $id]);
            audit_log('expert_update', 'experts', $id);
            flash('success', 'Expert updated.');
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

$rows = db()->query('SELECT e.*, u.email, u.status FROM experts e JOIN users u ON u.id=e.user_id ORDER BY e.name')->fetchAll();

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
      <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3 text-right">Actions</th></tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No experts yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="px-4 py-3 font-medium text-navy"><?= e($r['name']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['email']) ?></td>
          <td class="px-4 py-3 text-gray-600"><?= e($r['phone']) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <button class="text-navy hover:underline mr-3" onclick='openEdit(<?= json_encode(["id"=>(int)$r["id"],"name"=>$r["name"],"phone"=>$r["phone"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete this expert and login?');">
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
      <input name="phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4">
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
      <label class="block text-sm font-medium mb-1">Phone</label>
      <input name="phone" id="edit_phone" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 mb-4">
      <div class="flex justify-end gap-2">
        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-gray-300">Cancel</button>
        <button class="px-4 py-2 rounded-lg bg-navy text-white font-medium">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openEdit(d){document.getElementById('edit_id').value=d.id;document.getElementById('edit_name').value=d.name||'';document.getElementById('edit_phone').value=d.phone||'';document.getElementById('editModal').classList.remove('hidden');}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
