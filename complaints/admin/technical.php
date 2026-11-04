<?php
require __DIR__ . '/../app/bootstrap.php';
require_role(['admin', 'technical_admin']);

$activeCategories = (int)scalar('SELECT COUNT(*) FROM categories WHERE is_active = 1');
$activeUsers = (int)scalar('SELECT COUNT(*) FROM users WHERE is_active = 1');
$failedEmails = (int)scalar("SELECT COUNT(*) FROM email_outbox WHERE status = 'failed'");
$queuedEmails = (int)scalar("SELECT COUNT(*) FROM email_outbox WHERE status = 'queued'");

page_header('Technical admin dashboard', 'admin/technical.php');
?>
<h1>Technical admin dashboard</h1>
<p class="muted">Maintain the system configuration and the services that support case routing.</p>

<div class="stats">
  <div class="stat"><div class="figure"><?= $activeCategories ?></div><div class="label">Active case categories</div></div>
  <div class="stat"><div class="figure"><?= $activeUsers ?></div><div class="label">Active accounts</div></div>
  <div class="stat <?= $failedEmails ? 'crit' : 'good' ?>"><div class="figure"><?= $failedEmails ?></div><div class="label">Failed email deliveries</div></div>
  <div class="stat"><div class="figure"><?= $queuedEmails ?></div><div class="label">Queued emails</div></div>
</div>

<div class="two-col section-gap">
  <section class="panel">
    <h2>System configuration</h2>
    <p>Manage routing categories and case response time limits.</p>
    <a class="button" href="<?= e(url('admin/settings.php')) ?>">Open settings</a>
  </section>
  <section class="panel">
    <h2>Routing classifier</h2>
    <p>Test the classifier and maintain the examples it uses to route new cases.</p>
    <a class="button" href="<?= e(url('admin/classifier.php')) ?>">Open AI classifier</a>
  </section>
  <section class="panel">
    <h2>Email delivery</h2>
    <p>Inspect queued and failed system email deliveries.</p>
    <a class="button" href="<?= e(url('admin/outbox.php')) ?>">Open email outbox</a>
  </section>
</div>
<?php page_footer();
