<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_role('officer');
$pdo  = db();

$levelInfo = levels()[(int)$user['officer_level']] ?? null;
$scope = 'all categories';
if ($user['category_id']) {
    $st = $pdo->prepare('SELECT name, department FROM categories WHERE id = ?');
    $st->execute([$user['category_id']]);
    if ($c = $st->fetch()) {
        $scope = $c['department'];
    }
}

$order = "FIELD(c.priority, 'Critical', 'Urgent', 'High', 'Normal'), c.due_at ASC";
$base  = 'SELECT c.*, cat.name AS category_name FROM cases c JOIN categories cat ON cat.id = c.category_id WHERE c.assigned_to = ?';

$st = $pdo->prepare($base . " AND c.status IN ('Submitted','In Progress','Reopened') ORDER BY $order");
$st->execute([$user['id']]);
$open = $st->fetchAll();

$st = $pdo->prepare($base . " AND c.status = 'Resolved' ORDER BY c.resolved_at DESC");
$st->execute([$user['id']]);
$waiting = $st->fetchAll();

$st = $pdo->prepare($base . " AND c.status = 'Closed' ORDER BY c.closed_at DESC LIMIT 8");
$st->execute([$user['id']]);
$closed = $st->fetchAll();

$overdue = 0; $needResponse = 0; $escalatedIn = 0;
foreach ($open as $c) {
    if ($c['due_at'] && strtotime($c['due_at']) < time()) { $overdue++; }
    if ($c['stage'] === 'response') { $needResponse++; }
    if ((int)$c['current_level'] > 1) { $escalatedIn++; }
}

page_header('My queue', 'officer.php');
?>
<h1>My queue</h1>
<p class="muted"><?= e($user['full_name']) ?> &middot; Level <?= (int)$user['officer_level'] ?>
  <?= $levelInfo ? '(' . e($levelInfo['title']) . ')' : '' ?> &middot; <?= e($scope) ?></p>

<div class="stats">
  <div class="stat"><div class="figure"><?= count($open) ?></div><div class="label">Open cases</div></div>
  <div class="stat <?= $needResponse ? 'warn' : '' ?>"><div class="figure"><?= $needResponse ?></div><div class="label">Waiting for your first response</div></div>
  <div class="stat <?= $overdue ? 'crit' : 'good' ?>"><div class="figure"><?= $overdue ?></div><div class="label">Past their deadline</div></div>
  <div class="stat"><div class="figure"><?= $escalatedIn ?></div><div class="label">Escalated to you</div></div>
</div>

<h2>Open cases</h2>
<?php if (!$open): ?>
  <div class="empty"><strong>Nothing is waiting for you.</strong>New cases for your office appear here, with the deadline for each.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Case</th><th>Priority</th><th>Clock</th></tr></thead>
    <tbody>
    <?php foreach ($open as $c): ?>
      <tr class="prio-row-<?= e(strtolower($c['priority'])) ?>">
        <td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a><br><span class="muted small"><?= e(ucfirst($c['type'])) ?><?= (int)$c['current_level'] > 1 ? ' &middot; escalated' : '' ?></span></td>
        <td><?= e($c['title']) ?><br><span class="muted small"><?= e($c['category_name']) ?> &middot; <?= $c['is_anonymous'] ? 'Anonymous' : 'Named student' ?></span></td>
        <td><?= priority_badge($c['priority']) ?></td>
        <td><?= $c['stage'] === 'response' ? 'Respond: ' : 'Resolve: ' ?><?= countdown_span($c['due_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>

<?php if ($waiting): ?>
  <h2 class="section-gap">Waiting for the student to confirm</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Case</th><th>Resolved</th></tr></thead><tbody>
    <?php foreach ($waiting as $c): ?>
      <tr><td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a></td>
          <td><?= e($c['title']) ?></td><td><?= e(fmt_dt($c['resolved_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<?php if ($closed): ?>
  <h2 class="section-gap">Recently closed</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Case</th><th>Closed</th><th>Rating</th></tr></thead><tbody>
    <?php foreach ($closed as $c): ?>
      <tr><td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a></td>
          <td><?= e($c['title']) ?></td><td><?= e(fmt_dt($c['closed_at'])) ?></td>
          <td><?= $c['rating'] ? (int)$c['rating'] . ' / 5' : '<span class="muted">-</span>' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php page_footer();
