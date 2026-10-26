<?php
require __DIR__ . '/app/bootstrap.php';
$user = current_user();
$levels = levels();
page_header('Home', 'index.php');
?>
<section class="hero">
  <div>
    <h1>Every complaint and request gets an owner and a deadline.</h1>
    <p class="lead">Send your case to the right KIU office, get a reference number, and follow it until it is solved. If nobody responds in time, the system moves your case up to the next level on its own.</p>
    <div class="row">
      <a class="button" href="<?= e(url('submit.php')) ?>">Submit a case</a>
      <?php if ($user && $user['role'] === 'student'): ?>
        <a class="button secondary" href="<?= e(url('my-cases.php')) ?>">My cases</a>
      <?php elseif (!$user): ?>
        <a class="button secondary" href="<?= e(url('login.php')) ?>">Log in</a>
      <?php else: ?>
        <a class="button secondary" href="<?= e(url(home_for($user))) ?>">Go to my work</a>
      <?php endif; ?>
    </div>
    <p class="muted small" style="margin-top:1rem">You can also submit without an account. Choose "anonymous" on the form and keep the tracking code you receive.</p>
  </div>
  <form class="track-box" method="post" action="<?= e(url('track.php')) ?>">
    <?= csrf_field() ?>
    <h2>Track a case</h2>
    <div class="field">
      <label for="reference">Reference number</label>
      <input id="reference" name="reference" type="text" placeholder="CRM-2026-00012" autocomplete="off" required>
    </div>
    <div class="field">
      <label for="code">Tracking code</label>
      <input id="code" name="code" type="text" placeholder="8 letters and numbers" autocomplete="off" required>
    </div>
    <button class="button" type="submit">Track case</button>
  </form>
</section>

<section class="section-gap">
  <h2>What happens when nobody answers</h2>
  <p class="muted">Each level has a time limit. When it runs out, the case moves up and its priority rises.</p>
  <ol class="ladder">
    <?php foreach ($levels as $lv): ?>
      <li class="rung">
        <div class="level">Level <?= (int)$lv['level'] ?></div>
        <div class="who"><?= e($lv['title']) ?></div>
        <div class="limit">Responds within <?= e(sla_label((float)$lv['response_hours'])) ?>
          <?= (int)$lv['level'] === 1 ? '(' . e(sla_label((float)$lv['urgent_response_hours'])) . ' if urgent)' : '' ?></div>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php page_footer();
