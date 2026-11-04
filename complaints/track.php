<?php
require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Allow only 8 wrong guesses in 10 minutes per visitor, so codes cannot be guessed.
    $_SESSION['track_fail'] = array_values(array_filter($_SESSION['track_fail'] ?? [], fn($t) => $t > time() - 600));
    if (count($_SESSION['track_fail']) >= 8) {
        flash('error', 'Too many wrong tries. Wait a few minutes and try again.');
        redirect('track.php');
    }
    $ref  = strtoupper(trim($_POST['reference'] ?? ''));
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $st = db()->prepare('SELECT id FROM cases WHERE reference = ? AND tracking_code = ?');
    $st->execute([$ref, $code]);
    $id = $st->fetchColumn();
    if ($id) {
        $_SESSION['case_access'][] = (int)$id;
        redirect('case.php?id=' . (int)$id);
    }
    $_SESSION['track_fail'][] = time();
    flash('error', 'No case matches that reference number and tracking code. Check both and try again.');
    redirect('track.php');
}

page_header('Track a case', 'track.php');
?>
<div class="narrow">
  <h1>Track a case</h1>
  <p class="muted">Enter the reference number and the tracking code you received when you submitted the case.</p>
  <form method="post" action="<?= e(url('track.php')) ?>">
    <?= csrf_field() ?>
    <div class="field"><label for="reference">Reference number</label>
      <input id="reference" name="reference" type="text" placeholder="CRM-2026-00012" autocomplete="off" required></div>
    <div class="field"><label for="code">Tracking code</label>
      <input id="code" name="code" type="text" placeholder="8 letters and numbers" autocomplete="off" required></div>
    <button class="button" type="submit">Track case</button>
  </form>
</div>
<?php page_footer();
