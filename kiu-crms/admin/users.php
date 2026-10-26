<?php
require __DIR__ . '/../app/bootstrap.php';
$admin  = require_role('admin');
$pdo    = db();
$cats   = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$levels = levels();
$errors = [];
$edit   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'toggle') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === (int)$admin['id']) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            flash('success', 'Account updated.');
        }
        redirect('admin/users.php');
    }

    if ($do === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $edit = [
            'id'            => $id,
            'full_name'     => trim($_POST['full_name'] ?? ''),
            'email'         => strtolower(trim($_POST['email'] ?? '')),
            'reg_no'        => trim($_POST['reg_no'] ?? ''),
            'role'          => $_POST['role'] ?? 'student',
            'officer_level' => (int)($_POST['officer_level'] ?? 0),
            'category_id'   => (int)($_POST['category_id'] ?? 0),
        ];
        $password = (string)($_POST['password'] ?? '');

        if (mb_strlen($edit['full_name']) < 3 || mb_strlen($edit['full_name']) > 120) { $errors[] = 'Enter the full name.'; }
        if (!filter_var($edit['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($edit['email']) > 150) { $errors[] = 'Enter a valid email address.'; }
        if (!in_array($edit['role'], ['student', 'officer', 'admin'], true)) { $errors[] = 'Choose a role.'; }
        if ($edit['role'] === 'officer' && !isset($levels[$edit['officer_level']])) { $errors[] = 'Choose a level for the officer.'; }
        if (($id === 0 && strlen($password) < 8) || ($password !== '' && strlen($password) < 8)) { $errors[] = 'The password must have at least 8 characters.'; }
        if ($id === (int)$admin['id'] && $edit['role'] !== 'admin') { $errors[] = 'You cannot change your own role.'; }

        if (!$errors) {
            $level = $edit['role'] === 'officer' ? $edit['officer_level'] : null;
            $cat   = ($edit['role'] === 'officer' && $edit['category_id'] > 0) ? $edit['category_id'] : null;
            try {
                if ($id) {
                    $sql = 'UPDATE users SET full_name = ?, email = ?, reg_no = ?, role = ?, officer_level = ?, category_id = ?';
                    $args = [$edit['full_name'], $edit['email'], $edit['reg_no'] ?: null, $edit['role'], $level, $cat];
                    if ($password !== '') {
                        $sql .= ', password_hash = ?';
                        $args[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $pdo->prepare($sql . ' WHERE id = ?')->execute(array_merge($args, [$id]));
                    flash('success', 'Account saved.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO users (full_name, email, reg_no, password_hash, role, officer_level, category_id, is_active, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
                    )->execute([$edit['full_name'], $edit['email'], $edit['reg_no'] ?: null, password_hash($password, PASSWORD_DEFAULT),
                                $edit['role'], $level, $cat, now()]);
                    flash('success', 'Account created.');
                }
                redirect('admin/users.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { $errors[] = 'Another account already uses that email.'; } else { throw $e; }
            }
        }
    }
}

if ($edit === null && isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
} elseif ($edit === null && isset($_GET['new'])) {
    $edit = ['id' => 0, 'full_name' => '', 'email' => '', 'reg_no' => '', 'role' => 'officer', 'officer_level' => 1, 'category_id' => 0];
}

$role = in_array($_GET['role'] ?? '', ['student', 'officer', 'admin'], true) ? $_GET['role'] : '';
$q    = trim($_GET['q'] ?? '');
$where = ['1 = 1'];
$args  = [];
if ($role) { $where[] = 'u.role = ?'; $args[] = $role; }
if ($q !== '') { $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
$st = $pdo->prepare(
    "SELECT u.*, c.name AS category_name FROM users u LEFT JOIN categories c ON c.id = u.category_id
     WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(u.role, 'admin', 'officer', 'student'), u.officer_level, u.full_name LIMIT 150"
);
$st->execute($args);
$users = $st->fetchAll();

page_header('Users', 'admin/users.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>Users</h1>
  <a class="button" href="<?= e(url('admin/users.php?new=1')) ?>">Add a user</a>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($edit): ?>
  <section class="panel" style="margin-bottom:1.5rem">
    <h2><?= $edit['id'] ? 'Edit account' : 'New account' ?></h2>
    <form method="post" action="<?= e(url('admin/users.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="form-grid">
        <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" value="<?= e($edit['full_name']) ?>" required></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= e($edit['email']) ?>" required></div>
        <div class="field"><label for="role">Role</label>
          <select id="role" name="role">
            <?php foreach (['student', 'officer', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $edit['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="officer_level">Level (officers only)</label>
          <select id="officer_level" name="officer_level">
            <?php foreach ($levels as $n => $l): ?><option value="<?= $n ?>" <?= (int)$edit['officer_level'] === $n ? 'selected' : '' ?>>Level <?= $n ?>: <?= e($l['title']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="category_id">Category (officers only)</label>
          <select id="category_id" name="category_id">
            <option value="0">All categories (for example the Registrar)</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$edit['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="reg_no">Registration number (students)</label><input id="reg_no" name="reg_no" type="text" value="<?= e($edit['reg_no'] ?? '') ?>"></div>
        <div class="field"><label for="password">Password <?= $edit['id'] ? '(leave empty to keep the current one)' : '' ?></label>
          <input id="password" name="password" type="password" autocomplete="new-password" <?= $edit['id'] ? '' : 'required' ?>></div>
      </div>
      <div class="row"><button class="button" type="submit">Save</button><a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Cancel</a></div>
    </form>
  </section>
<?php endif; ?>

<form class="filters" method="get" action="<?= e(url('admin/users.php')) ?>">
  <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name or email"></div>
  <div class="field"><label for="rolef">Role</label>
    <select id="rolef" name="role"><option value="">All</option>
      <?php foreach (['student', 'officer', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
  <button class="button secondary" type="submit">Filter</button>
</form>

<div class="table-wrap"><table>
  <thead><tr><th>Name</th><th>Role</th><th>Handles</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['full_name']) ?><br><span class="muted small"><?= e($u['email']) ?><?= $u['reg_no'] ? ' &middot; ' . e($u['reg_no']) : '' ?></span></td>
      <td><?= e(ucfirst($u['role'])) ?><?= $u['officer_level'] ? ' (Level ' . (int)$u['officer_level'] . ')' : '' ?></td>
      <td><?= $u['role'] === 'officer' ? e($u['category_name'] ?? 'All categories') : '<span class="muted">-</span>' ?></td>
      <td><?= $u['is_active'] ? '<span class="badge status-resolved">Active</span>' : '<span class="badge status-closed">Inactive</span>' ?></td>
      <td class="row">
        <a class="button secondary small" href="<?= e(url('admin/users.php?edit=' . (int)$u['id'])) ?>">Edit</a>
        <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
          <form class="inline" method="post" action="<?= e(url('admin/users.php')) ?>" data-confirm="<?= $u['is_active'] ? 'Deactivate this account? They will not be able to log in.' : 'Activate this account?' ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="button secondary small" type="submit"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php page_footer();
