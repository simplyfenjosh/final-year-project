<?php
require __DIR__ . '/app/bootstrap.php';
$r = $_SESSION['just_submitted'] ?? null;
if (!$r) {
    redirect('index.php');
}
page_header('Case received', 'submit.php');
?>
<div class="narrow">
  <h1>We received your case</h1>
  <p class="muted">Write down the reference number<?= $r['anonymous'] ? ' and the tracking code. This is the only time we show the tracking code' : '' ?>.</p>
  <div class="slip">
    <div class="slip-ref"><?= e($r['reference']) ?></div>
    <dl>
      <?php if ($r['anonymous']): ?>
        <dt>Tracking code (keep it safe)</dt><dd class="num" style="font-size:1.3rem;letter-spacing:.12em"><?= e($r['tracking_code']) ?></dd>
      <?php endif; ?>
      <dt>Sent to</dt><dd><?= e($r['department']) ?> (<?= e($r['category']) ?>)</dd>
      <dt>Handler</dt><dd><?= $r['handler'] ? e($r['handler']) : 'Waiting for an officer to be assigned' ?></dd>
      <dt>Response due</dt><dd><?= countdown_span($r['due_at']) ?> (<?= e(fmt_dt($r['due_at'])) ?>)</dd>
      <?php if ($r['urgent']): ?><dt>Priority</dt><dd><?= priority_badge('Urgent') ?> It mentions a safety or welfare risk, so the time limit is shorter.</dd><?php endif; ?>
    </dl>
  </div>
  <p class="muted" style="margin-top:1rem">If nobody responds in time, your case moves up to the next level and you will be told.</p>
  <div class="row no-print">
    <a class="button" href="<?= e(url('case.php?id=' . (int)$r['id'])) ?>">Open the case</a>
    <button class="button secondary" type="button" onclick="window.print()">Print this slip</button>
  </div>
</div>
<?php page_footer();
