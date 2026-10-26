<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    flash('success', 'All alerts are marked as read.');
    redirect('notifications.php');
}

$st = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
$st->execute([$user['id']]);
$items = $st->fetchAll();

page_header('Alerts', 'notifications.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>Alerts</h1>
  <?php if (unread_count((int)$user['id'])): ?>
    <form method="post" action="<?= e(url('notifications.php')) ?>"><?= csrf_field() ?><button class="button secondary small" type="submit">Mark all as read</button></form>
  <?php endif; ?>
</div>
<?php if (!$items): ?>
  <div class="empty"><strong>No alerts yet.</strong>You will see updates about your cases here, and in your email.</div>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($items as $n): ?>
      <li>
        <?= $n['is_read'] ? '' : '<span class="badge status-in-progress">New</span> ' ?>
        <?php if ($n['case_id']): ?><a href="<?= e(url('case.php?id=' . (int)$n['case_id'])) ?>"><?= e($n['message']) ?></a><?php else: ?><?= e($n['message']) ?><?php endif; ?>
        <div class="muted small"><?= e(fmt_dt($n['created_at'])) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php page_footer();
