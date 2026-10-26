<?php
require __DIR__ . '/app/bootstrap.php';
$pdo  = db();
$user = current_user();
$id   = (int)($_GET['id'] ?? 0);
$case = $id ? CaseService::fetch($pdo, $id) : null;

if (!$case || !CaseService::canView($pdo, $case, $user)) {
    if (!$user) {
        flash('error', 'Log in, or use your reference number and tracking code, to open a case.');
        redirect('login.php');
    }
    http_response_code(404);
    fatal_page('Case not found', 'This case does not exist, or you do not have access to it.');
}

$isStaff   = $user && in_array($user['role'], ['officer', 'admin'], true);
$isAdmin   = $user && $user['role'] === 'admin';
$canAct    = $isStaff && ($isAdmin || (int)$case['assigned_to'] === (int)$user['id']);
$isOpen    = in_array($case['status'], CaseService::OPEN, true);
$isOwner   = !$isStaff;       // the student (or an anonymous visitor with the tracking code)
$events    = CaseService::events($pdo, $id, $isStaff);
$files     = CaseService::attachments($pdo, $id);
$levels    = levels();
$cats      = $canAct ? $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
$officers  = $isAdmin ? $pdo->query("SELECT id, full_name, officer_level FROM users WHERE role = 'officer' AND is_active = 1 ORDER BY officer_level, full_name")->fetchAll() : [];
$act       = url('case-action.php');

page_header($case['reference'], '');
?>
<div class="case-head">
  <h1 class="num" style="margin:0"><?= e($case['reference']) ?></h1>
  <?= status_badge($case['status']) ?>
  <?= $isOpen || $case['priority'] !== 'Normal' ? priority_badge($case['priority']) : '' ?>
  <span class="badge prio-normal"><?= e(ucfirst($case['type'])) ?></span>
</div>
<p class="muted" style="font-size:1.1rem"><?= e($case['title']) ?></p>

<div class="case-layout">
  <div>
    <section class="panel">
      <h2>What was reported</h2>
      <p class="desc"><?= e($case['description']) ?></p>
      <?php if ($files): ?>
        <h3>Attachments</h3>
        <ul class="plain">
          <?php foreach ($files as $f): ?>
            <li><a href="<?= e(url('files.php?id=' . (int)$f['id'])) ?>"><?= e($f['original_name']) ?></a> <span class="muted small">(<?= e(round($f['size_bytes'] / 1024)) ?> KB)</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>History</h2>
      <ol class="timeline">
        <?php foreach ($events as $ev): ?>
          <li class="t-<?= e(strtolower($ev['event_type'])) ?>">
            <span class="who"><?= e($ev['actor_label']) ?></span><span class="when"><?= e(fmt_dt($ev['created_at'])) ?></span>
            <?php if ($ev['is_internal']): ?><span class="tag">Internal note</span><?php endif; ?>
            <div class="small muted"><?= e(str_replace('_', ' ', ucfirst(strtolower($ev['event_type'])))) ?></div>
            <?php if ($ev['message']): ?><p class="msg"><?= e($ev['message']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <?php /* ---------------- Student / anonymous actions ---------------- */ ?>
    <?php if ($isOwner && $case['status'] === 'Resolved'): ?>
      <section class="panel">
        <h2>Is the problem solved?</h2>
        <p>The handler marked this case as resolved. Please tell us if it really is.</p>
        <details class="action" open>
          <summary>Yes, it is solved</summary>
          <div class="body">
            <form method="post" action="<?= e($act) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="confirm">
              <div class="field"><label id="rate-label">How satisfied are you with how it was handled?</label>
                <div class="rating" role="radiogroup" aria-labelledby="rate-label">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <label><input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>><span><?= $i ?></span></label>
                  <?php endfor; ?>
                </div>
                <div class="hint">1 is very unhappy, 5 is very happy.</div></div>
              <div class="field"><label for="rc">Comment (optional)</label><input id="rc" name="comment" type="text" maxlength="255"></div>
              <button class="button" type="submit">Confirm and close the case</button>
            </form>
          </div>
        </details>
        <details class="action">
          <summary>No, it is still a problem</summary>
          <div class="body">
            <form method="post" action="<?= e($act) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reopen">
              <div class="field"><label for="reason">What is still wrong?</label><textarea id="reason" name="message" required></textarea></div>
              <button class="button secondary" type="submit">Reopen the case</button>
            </form>
          </div>
        </details>
      </section>
    <?php elseif ($isOwner && $isOpen): ?>
      <section class="panel">
        <h2>Add a message</h2>
        <form method="post" action="<?= e($act) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="comment">
          <div class="field"><label for="msg">Message to the handler</label><textarea id="msg" name="message" required></textarea></div>
          <button class="button" type="submit">Send message</button>
        </form>
      </section>
    <?php endif; ?>

    <?php /* ---------------- Staff actions ---------------- */ ?>
    <?php if ($canAct && $isOpen): ?>
      <section class="panel">
        <h2>Handle this case</h2>
        <?php if ($case['stage'] === 'response'): ?>
          <form method="post" action="<?= e($act) ?>" class="row" style="margin-bottom:.8rem">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="acknowledge">
            <button class="button" type="submit">Acknowledge and start working</button>
            <span class="muted small">This stops the response clock and starts the resolution clock.</span>
          </form>
        <?php endif; ?>
        <details class="action"><summary>Reply to the student</summary><div class="body">
          <form method="post" action="<?= e($act) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="respond">
            <div class="field"><label for="reply">Message (the student can read this)</label><textarea id="reply" name="message" required></textarea></div>
            <button class="button" type="submit">Send reply</button>
          </form></div></details>
        <details class="action"><summary>Mark as resolved</summary><div class="body">
          <form method="post" action="<?= e($act) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resolve">
            <div class="field"><label for="res">What was done to solve it?</label><textarea id="res" name="message" required></textarea></div>
            <button class="button" type="submit">Mark as resolved</button>
          </form></div></details>
        <details class="action"><summary>Add an internal note</summary><div class="body">
          <form method="post" action="<?= e($act) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
            <div class="field"><label for="note">Note (only staff can read this)</label><textarea id="note" name="message" required></textarea></div>
            <button class="button secondary" type="submit">Save note</button>
          </form></div></details>
        <details class="action"><summary>Wrong office? Move to another category</summary><div class="body">
          <form method="post" action="<?= e($act) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="category">
            <div class="field"><label for="newcat">New category</label>
              <select id="newcat" name="category_id">
                <?php foreach ($cats as $c): if ((int)$c['id'] === (int)$case['category_id']) continue; ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint">The case goes to an officer in that category and the system learns from your correction.</div></div>
            <button class="button secondary" type="submit">Move case</button>
          </form></div></details>
        <?php if ($isAdmin): ?>
          <details class="action"><summary>Reassign to a named officer (admin)</summary><div class="body">
            <form method="post" action="<?= e($act) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reassign">
              <div class="field"><label for="off">Officer</label>
                <select id="off" name="officer_id">
                  <?php foreach ($officers as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)$case['assigned_to'] ? 'selected' : '' ?>><?= e($o['full_name']) ?> (Level <?= (int)$o['officer_level'] ?>)</option>
                  <?php endforeach; ?>
                </select></div>
              <button class="button secondary" type="submit">Reassign</button>
            </form></div></details>
        <?php endif; ?>
      </section>
    <?php elseif ($isStaff && $isOpen): ?>
      <div class="flash">You can read this case, but it is assigned to <?= e($case['assignee_name'] ?? 'nobody') ?>.</div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="slip">
      <div class="slip-ref"><?= e($case['reference']) ?></div>
      <dl>
        <dt>Office</dt><dd><?= e($case['department']) ?><br><span class="muted small" style="font-weight:400"><?= e($case['category_name']) ?><?= $case['ai_confidence'] !== null ? ' (chosen by AI, ' . round((float)$case['ai_confidence']) . '% sure)' : '' ?></span></dd>
        <dt>Submitted</dt><dd><?= e(fmt_dt($case['created_at'])) ?></dd>
        <dt>Submitted by</dt><dd><?= $case['is_anonymous'] ? 'Anonymous' : e($case['student_name'] ?? 'Student') ?>
          <?php if ($isStaff && !$case['is_anonymous'] && $case['student_reg']): ?><span class="muted small" style="font-weight:400"> (<?= e($case['student_reg']) ?>)</span><?php endif; ?></dd>
        <dt>Handler</dt><dd><?= e($case['assignee_name'] ?? 'Not assigned yet') ?></dd>
        <?php if ($isOpen): ?>
          <dt><?= $case['stage'] === 'response' ? 'Must respond' : 'Must resolve' ?></dt><dd><?= countdown_span($case['due_at'], true) ?><br><span class="muted small" style="font-weight:400"><?= e(fmt_dt($case['due_at'])) ?></span></dd>
        <?php endif; ?>
        <?php if ($case['first_response_at']): ?><dt>First response</dt><dd><?= e(fmt_dt($case['first_response_at'])) ?></dd><?php endif; ?>
        <?php if ($case['resolved_at']): ?><dt>Resolved</dt><dd><?= e(fmt_dt($case['resolved_at'])) ?></dd><?php endif; ?>
        <?php if ($case['rating']): ?><dt>Satisfaction</dt><dd><?= (int)$case['rating'] ?> out of 5</dd><?php endif; ?>
        <?php if ($case['escalation_count']): ?><dt>Escalations</dt><dd><?= (int)$case['escalation_count'] ?><?= $case['alert_count'] ? ' (critical alerts: ' . (int)$case['alert_count'] . ')' : '' ?></dd><?php endif; ?>
      </dl>
      <hr>
      <h3 style="margin-bottom:.2rem">Escalation ladder</h3>
      <ol class="ladder-v">
        <?php foreach ($levels as $lv):
            $n = (int)$lv['level'];
            $cur = (int)$case['current_level'];
            $isCurrent = ($n === $cur) && $isOpen;
            $cls = $isCurrent ? 'current' : ($n <= $cur ? 'done' : ''); ?>
          <li class="<?= e($cls) ?>" data-level="<?= $n ?>">
            <span class="name"><?= e($lv['title']) ?></span>
            <span class="sub"><?php if ($isCurrent): ?><?= e($case['assignee_name'] ?? 'No officer assigned') ?> &middot; <?= priority_badge($case['priority']) ?><?php elseif ($n === $cur): ?><?= e($case['assignee_name'] ?? 'Handled') ?> &middot; handled the case<?php elseif ($n < $cur): ?>Did not respond in time<?php else: ?>Responds within <?= e(sla_label((float)$lv['response_hours'])) ?><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </aside>
</div>
<?php page_footer();
