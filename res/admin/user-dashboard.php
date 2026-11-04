<?php
require __DIR__ . '/../app/bootstrap.php';
require_role(['admin', 'user_admin']);

$counts = db()->query('SELECT role, COUNT(*) total, SUM(is_active = 1) active FROM users GROUP BY role ORDER BY role')->fetchAll();
$total = (int)scalar('SELECT COUNT(*) FROM users');
$inactive = (int)scalar('SELECT COUNT(*) FROM users WHERE is_active = 0');

page_header('User admin dashboard', 'admin/user-dashboard.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>User admin dashboard</h1>
  <a class="button" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
</div>
<p class="muted">Manage account records and review the current distribution of user roles.</p>

<div class="stats">
  <div class="stat"><div class="figure"><?= $total ?></div><div class="label">Accounts</div></div>
  <div class="stat <?= $inactive ? 'warn' : 'good' ?>"><div class="figure"><?= $inactive ?></div><div class="label">Inactive accounts</div></div>
</div>

<section class="panel section-gap">
  <h2>Accounts by role</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Role</th><th class="right">Accounts</th><th class="right">Active</th></tr></thead>
    <tbody>
      <?php foreach ($counts as $row): ?>
        <tr><td><?= e(role_label($row['role'])) ?></td><td class="right"><?= (int)$row['total'] ?></td><td class="right"><?= (int)$row['active'] ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php page_footer();
