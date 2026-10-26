<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$pdo = db();

[$where, $params] = case_filter_sql($_GET);
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$total   = (int)scalar("SELECT COUNT(*) FROM cases c WHERE $where", $params);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$st = $pdo->prepare(
    "SELECT c.*, cat.name AS category_name, a.full_name AS handler
     FROM cases c JOIN categories cat ON cat.id = c.category_id LEFT JOIN users a ON a.id = c.assigned_to
     WHERE $where ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$cases = $st->fetchAll();
$cats  = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$query = $_GET;
unset($query['page']);
$qs = http_build_query($query);

page_header('All cases', 'admin/cases.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>All cases</h1>
  <a class="button secondary" href="<?= e(url('admin/export.php' . ($qs ? '?' . $qs : ''))) ?>">Export this list (CSV)</a>
</div>

<form class="filters" method="get" action="<?= e(url('admin/cases.php')) ?>">
  <div class="field"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Reference or title"></div>
  <div class="field"><label for="status">Status</label>
    <select id="status" name="status"><option value="">Any</option><option value="open" <?= ($_GET['status'] ?? '') === 'open' ? 'selected' : '' ?>>Open (not resolved)</option>
      <?php foreach (['Submitted', 'In Progress', 'Resolved', 'Closed', 'Reopened'] as $s): ?><option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="category">Category</label>
    <select id="category" name="category"><option value="">Any</option>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($_GET['category'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="priority">Priority</label>
    <select id="priority" name="priority"><option value="">Any</option>
      <?php foreach (array_keys(PRIORITY_RANK) as $p): ?><option <?= ($_GET['priority'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="level">Level</label>
    <select id="level" name="level"><option value="">Any</option>
      <?php foreach (levels() as $n => $l): ?><option value="<?= $n ?>" <?= (int)($_GET['level'] ?? 0) === $n ? 'selected' : '' ?>>Level <?= $n ?></option><?php endforeach; ?></select></div>
  <div class="field"><label class="check"><input type="checkbox" name="escalated" value="1" <?= ($_GET['escalated'] ?? '') === '1' ? 'checked' : '' ?>><span>Escalated only</span></label></div>
  <button class="button" type="submit">Filter</button>
</form>

<p class="muted"><?= $total ?> case<?= $total === 1 ? '' : 's' ?> found.</p>
<?php if (!$cases): ?>
  <div class="empty"><strong>No cases match these filters.</strong>Clear a filter or search for a different reference.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Case</th><th>Status</th><th>Level and handler</th><th>Clock</th></tr></thead>
    <tbody>
    <?php foreach ($cases as $c): $open = in_array($c['status'], CaseService::OPEN, true); ?>
      <tr class="prio-row-<?= e(strtolower($c['priority'])) ?>">
        <td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a><br><span class="muted small"><?= e(fmt_dt($c['created_at'])) ?></span></td>
        <td><?= e($c['title']) ?><br><span class="muted small"><?= e($c['category_name']) ?> &middot; <?= $c['is_anonymous'] ? 'Anonymous' : 'Named' ?><?= $c['escalation_count'] ? ' &middot; escalated ' . (int)$c['escalation_count'] . 'x' : '' ?></span></td>
        <td><?= status_badge($c['status']) ?> <?= priority_badge($c['priority']) ?></td>
        <td>Level <?= (int)$c['current_level'] ?><br><span class="muted small"><?= e($c['handler'] ?? 'Unassigned') ?></span></td>
        <td><?= $open ? countdown_span($c['due_at']) : '<span class="muted">-</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="button secondary small" href="<?= e(url('admin/cases.php?' . $qs . '&page=' . ($page - 1))) ?>">Previous</a><?php endif; ?>
      <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="button secondary small" href="<?= e(url('admin/cases.php?' . $qs . '&page=' . ($page + 1))) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php page_footer();
