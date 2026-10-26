<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$mails = db()->query('SELECT * FROM email_outbox ORDER BY id DESC LIMIT 100')->fetchAll();

page_header('Emails', 'admin/outbox.php');
?>
<h1>Emails</h1>
<p class="muted">Every alert is also queued as an email. While real email is switched off in config/config.php, messages stay here with the status "logged", so you can show them in your report.</p>
<?php if (!$mails): ?>
  <div class="empty"><strong>No emails yet.</strong>They appear when cases are submitted, escalated or resolved.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($mails as $m): ?>
      <tr><td class="num"><?= e(fmt_dt($m['created_at'])) ?></td><td><?= e($m['to_email']) ?></td>
        <td><details><summary><?= e($m['subject']) ?></summary><pre class="email"><?= e($m['body']) ?></pre></details></td>
        <td><span class="badge <?= $m['status'] === 'sent' ? 'status-resolved' : ($m['status'] === 'failed' ? 'prio-critical' : 'status-submitted') ?>"><?= e($m['status']) ?></span>
          <?= $m['error'] ? '<br><span class="muted small">' . e($m['error']) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php page_footer();
