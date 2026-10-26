<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_role('student');
$st = db()->prepare(
    'SELECT c.*, cat.name AS category_name, a.full_name AS handler
     FROM cases c JOIN categories cat ON cat.id = c.category_id LEFT JOIN users a ON a.id = c.assigned_to
     WHERE c.student_id = ? ORDER BY c.created_at DESC'
);
$st->execute([$user['id']]);
$cases = $st->fetchAll();

page_header('My cases', 'my-cases.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>My cases</h1>
  <a class="button" href="<?= e(url('submit.php')) ?>">Submit a case</a>
</div>
<?php if (!$cases): ?>
  <div class="empty"><strong>You have not submitted a case yet.</strong>When you do, you can follow it here from the first response to the final answer.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Title</th><th>Status</th><th>With</th><th>Next deadline</th></tr></thead>
    <tbody>
    <?php foreach ($cases as $c): $open = in_array($c['status'], CaseService::OPEN, true); ?>
      <tr class="prio-row-<?= e(strtolower($c['priority'])) ?>">
        <td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a><br><span class="muted small"><?= e(fmt_dt($c['created_at'])) ?></span></td>
        <td><?= e($c['title']) ?><br><span class="muted small"><?= e($c['category_name']) ?> &middot; <?= e(ucfirst($c['type'])) ?></span></td>
        <td><?= status_badge($c['status']) ?><?= $open ? '<br>' . priority_badge($c['priority']) : '' ?></td>
        <td><?= $open ? 'Level ' . (int)$c['current_level'] . '<br><span class="muted small">' . e($c['handler'] ?? 'Unassigned') . '</span>' : '<span class="muted">-</span>' ?></td>
        <td><?= $open ? countdown_span($c['due_at']) : ($c['status'] === 'Resolved' ? 'Waiting for your confirmation' : '<span class="muted">-</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php page_footer();
