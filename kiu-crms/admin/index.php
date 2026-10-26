<?php
require __DIR__ . '/../app/bootstrap.php';
$admin = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $s = Escalation::run($pdo);
    flash('success', "Escalation check finished: {$s['checked']} overdue case(s) checked, {$s['escalated']} escalated, {$s['alerts']} critical alert(s), {$s['closed']} auto-closed.");
    redirect('admin/index.php');
}

$open = "status IN ('Submitted','In Progress','Reopened')";
$total    = (int)scalar('SELECT COUNT(*) FROM cases');
$openCnt  = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open");
$overdue  = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open AND due_at < ?", [now()]);
$critical = (int)scalar("SELECT COUNT(*) FROM cases WHERE $open AND priority = 'Critical'");
$done     = (int)scalar("SELECT COUNT(*) FROM cases WHERE status IN ('Resolved','Closed')");
$avgFirst = scalar('SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) FROM cases WHERE first_response_at IS NOT NULL');
$avgRes   = scalar('SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, resolved_at)) FROM cases WHERE resolved_at IS NOT NULL');
$avgRate  = scalar('SELECT AVG(rating) FROM cases WHERE rating IS NOT NULL');
$noEsc    = (int)scalar('SELECT COUNT(*) FROM cases WHERE escalation_count = 0');
$pctNoEsc = $total ? round($noEsc / $total * 100) : 0;
$escEvents = (int)scalar("SELECT COUNT(*) FROM case_events WHERE event_type = 'ESCALATED'");

$byStatus = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM cases GROUP BY status ORDER BY n DESC') as $r) {
    $byStatus[] = [$r['status'], (int)$r['n'], $r['status'] === 'Closed' || $r['status'] === 'Resolved' ? 'good' : ''];
}
$byCat = [];
foreach ($pdo->query('SELECT cat.name, COUNT(*) n FROM cases c JOIN categories cat ON cat.id = c.category_id GROUP BY cat.id, cat.name ORDER BY n DESC') as $r) {
    $byCat[] = [$r['name'], (int)$r['n']];
}
$byLevel = [];
$lv = $pdo->query("SELECT current_level l, COUNT(*) n FROM cases WHERE $open GROUP BY current_level")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (levels() as $n => $row) {
    $byLevel[] = ['Level ' . $n . ': ' . $row['title'], (int)($lv[$n] ?? 0), $n >= 3 ? 'warn' : ''];
}
$missed = [];
foreach ($pdo->query("SELECT from_level l, COUNT(*) n FROM case_events WHERE event_type = 'ESCALATED' GROUP BY from_level ORDER BY from_level") as $r) {
    $missed[] = ['Level ' . $r['l'] . ' missed a deadline', (int)$r['n'], 'warn'];
}
$days = [];
$rows = $pdo->prepare('SELECT DATE(created_at) d, COUNT(*) n FROM cases WHERE created_at >= ? GROUP BY DATE(created_at)');
$rows->execute([date('Y-m-d 00:00:00', strtotime('-13 days'))]);
$map = $rows->fetchAll(PDO::FETCH_KEY_PAIR);
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = [date('D j M', strtotime($d)), (int)($map[$d] ?? 0)];
}
$offices = $pdo->query(
    'SELECT cat.department, COUNT(*) cases, SUM(c.escalation_count) escalations, SUM(c.alert_count) alerts,
            AVG(TIMESTAMPDIFF(SECOND, c.created_at, c.first_response_at)) avg_first
     FROM cases c JOIN categories cat ON cat.id = c.category_id
     GROUP BY cat.department ORDER BY escalations DESC, avg_first DESC'
)->fetchAll();
$officers = $pdo->query(
    "SELECT u.full_name, u.officer_level, COUNT(*) missed
     FROM case_events e JOIN users u ON u.id = e.from_user
     WHERE e.event_type = 'ESCALATED' GROUP BY u.id, u.full_name, u.officer_level ORDER BY missed DESC LIMIT 5"
)->fetchAll();
$crit = $pdo->query(
    "SELECT c.id, c.reference, c.title, c.current_level, c.alert_count, c.due_at, a.full_name handler
     FROM cases c LEFT JOIN users a ON a.id = c.assigned_to
     WHERE c.status IN ('Submitted','In Progress','Reopened') AND c.priority = 'Critical' ORDER BY c.alert_count DESC, c.created_at LIMIT 10"
)->fetchAll();
$lastRun = state_get('last_escalation_run');

page_header('Dashboard', 'admin/index.php');
?>
<div class="row" style="justify-content:space-between">
  <h1>Dashboard</h1>
  <div class="row no-print">
    <form method="post" action="<?= e(url('admin/index.php')) ?>"><?= csrf_field() ?><button class="button secondary" type="submit">Run escalation check now</button></form>
    <a class="button" href="<?= e(url('admin/export.php')) ?>">Export all cases (CSV)</a>
  </div>
</div>
<p class="muted">Escalation engine last ran: <?= $lastRun ? e(fmt_dt($lastRun)) : 'never' ?>. It runs whenever a page is opened and from cron/escalate.php.</p>

<div class="stats">
  <div class="stat"><div class="figure"><?= $total ?></div><div class="label">Cases in total</div></div>
  <div class="stat"><div class="figure"><?= $openCnt ?></div><div class="label">Open now</div></div>
  <div class="stat <?= $overdue ? 'crit' : 'good' ?>"><div class="figure"><?= $overdue ?></div><div class="label">Past their deadline</div></div>
  <div class="stat <?= $critical ? 'crit' : 'good' ?>"><div class="figure"><?= $critical ?></div><div class="label">Critical and open</div></div>
  <div class="stat good"><div class="figure"><?= $done ?></div><div class="label">Resolved or closed</div></div>
</div>

<h2>How well is the system working?</h2>
<p class="muted">These figures answer the research questions: how fast cases get a response and a resolution, and how satisfied students are.</p>
<div class="stats">
  <div class="stat"><div class="figure"><?= e(dur($avgFirst)) ?></div><div class="label">Average time to first response</div></div>
  <div class="stat"><div class="figure"><?= e(dur($avgRes)) ?></div><div class="label">Average time to resolution</div></div>
  <div class="stat"><div class="figure"><?= $avgRate !== null && $avgRate !== false ? number_format((float)$avgRate, 1) : '-' ?></div><div class="label">Average satisfaction (out of 5)</div></div>
  <div class="stat"><div class="figure"><?= $pctNoEsc ?>%</div><div class="label">Cases that never needed escalation</div></div>
  <div class="stat"><div class="figure"><?= $escEvents ?></div><div class="label">Automatic escalations so far</div></div>
</div>

<div class="two-col">
  <section class="panel"><h2>Submissions, last 14 days</h2><?= bars($days) ?></section>
  <section class="panel"><h2>Cases by status</h2><?= bars($byStatus) ?></section>
  <section class="panel"><h2>Open cases by level</h2><p class="muted small">Cases sitting at a high level show that lower levels are missing their deadlines.</p><?= bars($byLevel) ?></section>
  <section class="panel"><h2>Missed deadlines by level</h2><?= bars($missed) ?></section>
  <section class="panel"><h2>Cases by category</h2><?= bars($byCat) ?></section>
  <section class="panel"><h2>Officers who missed the most deadlines</h2>
    <?php if (!$officers): ?><p class="muted">No missed deadlines yet.</p><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Officer</th><th>Level</th><th class="right">Missed</th></tr></thead><tbody>
      <?php foreach ($officers as $o): ?><tr><td><?= e($o['full_name']) ?></td><td><?= (int)$o['officer_level'] ?></td><td class="right"><?= (int)$o['missed'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?></section>
</div>

<section class="section-gap">
  <h2>Which offices are slow?</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Office</th><th class="right">Cases</th><th class="right">Escalations</th><th class="right">Critical alerts</th><th class="right">Average first response</th></tr></thead>
    <tbody>
    <?php foreach ($offices as $o): ?>
      <tr><td><?= e($o['department']) ?></td><td class="right"><?= (int)$o['cases'] ?></td><td class="right"><?= (int)$o['escalations'] ?></td>
          <td class="right"><?= (int)$o['alerts'] ?></td><td class="right"><?= e(dur($o['avg_first'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="section-gap">
  <h2>Critical cases that need senior attention</h2>
  <?php if (!$crit): ?><div class="empty"><strong>No critical cases.</strong>Cases appear here when the top level misses its deadline.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Reference</th><th>Case</th><th>Handler</th><th class="right">Alerts</th><th>Next alert</th></tr></thead><tbody>
      <?php foreach ($crit as $c): ?>
        <tr class="prio-row-critical"><td><a class="ref" href="<?= e(url('case.php?id=' . (int)$c['id'])) ?>"><?= e($c['reference']) ?></a></td>
          <td><?= e($c['title']) ?></td><td><?= e($c['handler'] ?? 'Unassigned') ?> (Level <?= (int)$c['current_level'] ?>)</td>
          <td class="right"><?= (int)$c['alert_count'] ?></td><td><?= countdown_span($c['due_at']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php page_footer();
