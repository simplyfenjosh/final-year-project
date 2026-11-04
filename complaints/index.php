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
    <?php if ($user): ?>
      <div class="row">
      <?php if ($user['role'] === 'student'): ?>
        <a class="button secondary" href="<?= e(url('my-cases.php')) ?>">My cases</a>
      <?php else: ?>
        <a class="button secondary" href="<?= e(url(home_for($user))) ?>">Go to my work</a>
      <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="hero-side">
    <?php if (!$user): ?>
      <form class="track-box" method="post" action="<?= e(url('login.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="home">
        <h2>Log in</h2>
        <div class="field"><label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required></div>
        <div class="field"><label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="button" type="submit">Log in</button>
      </form>
      <p style="margin-top:1.25rem">Are you a new student? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    <?php endif; ?>
  </div>
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
  <aside class="level-key" aria-labelledby="level-key-title">
    <strong id="level-key-title">Quick key</strong>
    <ul class="level-key-items">
      <li><span class="key-swatch key-level-1" aria-hidden="true"></span>Level 1</li>
      <li><span class="key-swatch key-level-2" aria-hidden="true"></span>Level 2</li>
      <li><span class="key-swatch key-level-3" aria-hidden="true"></span>Level 3</li>
      <li><span class="key-swatch key-level-4" aria-hidden="true"></span>Level 4</li>
      <li><span class="key-swatch key-previous" aria-hidden="true"></span>Previous level</li>
    </ul>
    <p class="muted">“Responds within” is the time allowed before a case escalates to the next level.</p>
    <details class="level-key-details">
      <summary>What do the other terms mean?</summary>
      <dl>
        <dt>Current handler</dt><dd>The staff member responsible for the case now.</dd>
        <dt>Received</dt><dd>When the current handler was assigned the case.</dd>
        <dt>Resolve within</dt><dd>After responding, the handler’s time to resolve the case.</dd>
        <dt>Escalation</dt><dd>If a deadline is missed, the case moves to the next available level.</dd>
      </dl>
      <p class="priority-key-title"><strong>Priority labels</strong></p>
      <ul class="priority-key">
        <li><?= priority_badge('Normal') ?> Routine case</li>
        <li><?= priority_badge('High') ?> Needs attention</li>
        <li><?= priority_badge('Urgent') ?> Shorter response time</li>
        <li><?= priority_badge('Critical') ?> Senior management is alerted</li>
      </ul>
    </details>
  </aside>
</section>
<?php page_footer();
