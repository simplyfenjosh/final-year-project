<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('vice_chancellor');

$open = "status IN ('Submitted','In Progress','Reopened')";
$total = (int)scalar('SELECT COUNT(*) FROM cases');
$openCount = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open");
$overdue = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open AND due_at < ?", [now()]);
$critical = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open AND priority = 'Critical'");
$unassigned = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open AND assigned_to IS NULL");
$resolved = (int)scalar("SELECT COUNT(*) FROM cases WHERE status IN ('Resolved','Closed')");
$avgFirstResponse = scalar('SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) FROM cases WHERE first_response_at IS NOT NULL');
$avgRating = scalar('SELECT AVG(rating) FROM cases WHERE rating IS NOT NULL');

$byStatus = db()->query('SELECT status, COUNT(*) total FROM cases GROUP BY status ORDER BY total DESC')->fetchAll();
$byCategory = db()->query(
    "SELECT cat.name, COUNT(*) total, SUM(c.status IN ('Submitted','In Progress','Reopened')) open_total
     FROM cases c JOIN categories cat ON cat.id = c.category_id
     GROUP BY cat.id, cat.name ORDER BY total DESC LIMIT 10"
)->fetchAll();
$byLevel = db()->query(
    "SELECT current_level, COUNT(*) total FROM cases WHERE $open GROUP BY current_level ORDER BY current_level"
)->fetchAll();
$officers = db()->query(
    "SELECT u.id, u.full_name, u.email, u.officer_level, c.name category_name,
            (SELECT COUNT(*) FROM cases active_case
             WHERE active_case.assigned_to = u.id AND active_case.status IN ('Submitted','In Progress','Reopened')) open_cases
     FROM users u LEFT JOIN categories c ON c.id = u.category_id
     WHERE u.role = 'officer' AND u.is_active = 1
     ORDER BY u.officer_level, u.full_name LIMIT 200"
)->fetchAll();

$recommendations = [];
if ($critical > 0) {
    $recommendations[] = "{$critical} critical open case(s) need senior operational follow-up.";
}
if ($overdue > 0) {
    $recommendations[] = "{$overdue} open case(s) are past their response or resolution deadline; review capacity and escalation support.";
}
if ($unassigned > 0) {
    $recommendations[] = "{$unassigned} open case(s) are not assigned to an officer; ask the operational team to review routing coverage.";
}
if ($avgRating !== null && $avgRating !== false && (float)$avgRating < 3.5) {
    $recommendations[] = 'Student satisfaction is below 3.5 out of 5; discuss recurring concerns with the responsible offices.';
}
if (!$recommendations) {
    $recommendations[] = 'No urgent system-wide concern is currently flagged. Continue reviewing case trends and response performance with the operational team.';
}

page_header('Vice Chancellor dashboard', 'admin/vice-chancellor.php');
?>
<h1>Vice Chancellor dashboard</h1>
<p class="muted">A school-wide overview of complaint and request trends. This dashboard is read-only for cases; contact officers through staff messages.</p>

<div class="stats">
  <div class="stat"><div class="figure"><?= $total ?></div><div class="label">Cases received</div></div>
  <div class="stat"><div class="figure"><?= $openCount ?></div><div class="label">Open cases</div></div>
  <div class="stat <?= $overdue ? 'crit' : 'good' ?>"><div class="figure"><?= $overdue ?></div><div class="label">Past deadline</div></div>
  <div class="stat <?= $critical ? 'crit' : 'good' ?>"><div class="figure"><?= $critical ?></div><div class="label">Critical and open</div></div>
  <div class="stat"><div class="figure"><?= $unassigned ?></div><div class="label">Unassigned and open</div></div>
  <div class="stat good"><div class="figure"><?= $resolved ?></div><div class="label">Resolved or closed</div></div>
</div>

<div class="stats section-gap">
  <div class="stat"><div class="figure"><?= e(dur($avgFirstResponse)) ?></div><div class="label">Average first response</div></div>
  <div class="stat"><div class="figure"><?= $avgRating !== null && $avgRating !== false ? number_format((float)$avgRating, 1) . ' / 5' : '-' ?></div><div class="label">Average student satisfaction</div></div>
</div>

<section class="section-gap panel">
  <h2>Leadership recommendations</h2>
  <ul class="plain">
    <?php foreach ($recommendations as $recommendation): ?>
      <li><?= e($recommendation) ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<div class="two-col section-gap">
  <section class="panel">
    <h2>Cases by status</h2>
    <?php if (!$byStatus): ?><p class="muted">No cases have been submitted.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Status</th><th class="right">Cases</th></tr></thead>
        <tbody><?php foreach ($byStatus as $row): ?>
          <tr><td><?= e($row['status']) ?></td><td class="right"><?= (int)$row['total'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Open cases by escalation level</h2>
    <?php if (!$byLevel): ?><p class="muted">No cases are currently open.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Level</th><th class="right">Open cases</th></tr></thead>
        <tbody><?php foreach ($byLevel as $row): ?>
          <tr><td>Level <?= (int)$row['current_level'] ?></td><td class="right"><?= (int)$row['total'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Cases by category</h2>
    <?php if (!$byCategory): ?><p class="muted">No category data yet.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Category</th><th class="right">Total</th><th class="right">Open</th></tr></thead>
        <tbody><?php foreach ($byCategory as $row): ?>
          <tr><td><?= e($row['name']) ?></td><td class="right"><?= (int)$row['total'] ?></td><td class="right"><?= (int)$row['open_total'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
</div>

<section class="section-gap">
  <h2>Staff directory</h2>
  <?php if (!$officers): ?><div class="empty"><strong>No active officers found.</strong>Contact the user administrator to review staff accounts.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Officer</th><th>Level</th><th>Category</th><th>Open cases</th><th></th></tr></thead>
      <tbody><?php foreach ($officers as $officer): ?>
        <tr>
          <td><?= e($officer['full_name']) ?><br><a href="mailto:<?= e($officer['email']) ?>"><?= e($officer['email']) ?></a></td>
          <td><?= (int)$officer['officer_level'] ?></td>
          <td><?= e($officer['category_name'] ?? 'All categories') ?></td>
          <td class="right"><?= (int)$officer['open_cases'] ?></td>
          <td><a class="button secondary small" href="<?= e(url('messages.php?with=' . (int)$officer['id'])) ?>">Message</a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php page_footer();
