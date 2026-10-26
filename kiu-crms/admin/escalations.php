<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$pdo = db();

$type = in_array($_GET['type'] ?? '', ['ESCALATED', 'CRITICAL_ALERT'], true) ? $_GET['type'] : '';
$sql = "SELECT e.*, c.reference, c.title, cat.name AS category_name, fu.full_name AS from_name, tu.full_name AS to_name
        FROM case_events e
        JOIN cases c ON c.id = e.case_id JOIN categories cat ON cat.id = c.category_id
        LEFT JOIN users fu ON fu.id = e.from_user LEFT JOIN users tu ON tu.id = e.to_user
        WHERE e.event_type IN ('ESCALATED','CRITICAL_ALERT')" . ($type ? ' AND e.event_type = ?' : '') . '
        ORDER BY e.created_at DESC, e.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($type ? [$type] : []);
$events = $st->fetchAll();

page_header('Escalation log', 'admin/escalations.php');
?>
<h1>Escalation log</h1>
<p class="muted">Every time the system moved a case up, or raised a critical alert, it is recorded here with the reason. This is the audit trail.</p>
<div class="row" style="margin-bottom:1rem">
  <a class="button <?= $type === '' ? '' : 'secondary' ?> small" href="<?= e(url('admin/escalations.php')) ?>">All</a>
  <a class="button <?= $type === 'ESCALATED' ? '' : 'secondary' ?> small" href="<?= e(url('admin/escalations.php?type=ESCALATED')) ?>">Escalations</a>
  <a class="button <?= $type === 'CRITICAL_ALERT' ? '' : 'secondary' ?> small" href="<?= e(url('admin/escalations.php?type=CRITICAL_ALERT')) ?>">Critical alerts</a>
</div>
<?php if (!$events): ?>
  <div class="empty"><strong>No escalations yet.</strong>They appear here when a handler misses a deadline.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Case</th><th>Move</th><th>What happened</th></tr></thead><tbody>
    <?php foreach ($events as $ev): ?>
      <tr class="prio-row-<?= $ev['event_type'] === 'CRITICAL_ALERT' ? 'critical' : 'urgent' ?>">
        <td class="num"><?= e(fmt_dt($ev['created_at'])) ?></td>
        <td><a class="ref" href="<?= e(url('case.php?id=' . (int)$ev['case_id'])) ?>"><?= e($ev['reference']) ?></a><br><span class="muted small"><?= e($ev['category_name']) ?></span></td>
        <td><?php if ($ev['event_type'] === 'ESCALATED'): ?>Level <?= (int)$ev['from_level'] ?> &rarr; Level <?= (int)$ev['to_level'] ?><br>
            <span class="muted small"><?= e($ev['from_name'] ?? 'Unassigned') ?> &rarr; <?= e($ev['to_name'] ?? '-') ?></span>
            <?php else: ?><?= priority_badge('Critical') ?><br><span class="muted small">Top level, Level <?= (int)$ev['from_level'] ?></span><?php endif; ?></td>
        <td><?= e($ev['message']) ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php page_footer();
