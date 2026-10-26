<?php
require __DIR__ . '/app/bootstrap.php';
$user = current_user();
if ($user && $user['role'] !== 'student') {
    flash('error', 'Only students submit cases. Officers and admins handle them from their own pages.');
    redirect(home_for($user));
}

$pdo  = db();
$cats = $pdo->query('SELECT id, name, department FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$old  = ['type' => 'complaint', 'title' => '', 'description' => '', 'category_id' => '0', 'anonymous' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['website'])) {            // hidden trap field: real people leave it empty
        redirect('index.php');
    }
    $old['type']        = ($_POST['type'] ?? '') === 'request' ? 'request' : 'complaint';
    $old['title']       = trim($_POST['title'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $old['category_id'] = (string)(int)($_POST['category_id'] ?? 0);
    $old['anonymous']   = !empty($_POST['anonymous']) ? '1' : '';

    if (mb_strlen($old['title']) < 5 || mb_strlen($old['title']) > 160) {
        $errors[] = 'Give the case a short title of 5 to 160 characters.';
    }
    if (mb_strlen($old['description']) < 20) {
        $errors[] = 'Describe the problem in at least 20 characters, so the right office can understand it.';
    }
    if (mb_strlen($old['description']) > 4000) {
        $errors[] = 'The description is too long. Keep it under 4000 characters.';
    }
    $valid = array_column($cats, 'id');
    if ($old['category_id'] !== '0' && !in_array((int)$old['category_id'], array_map('intval', $valid), true)) {
        $errors[] = 'Choose a category from the list.';
    }
    if (!$user && $old['anonymous'] === '') {
        $errors[] = 'Log in to submit with your name, or tick the anonymous box.';
    }
    $recent = $pdo->prepare('SELECT COUNT(*) FROM cases WHERE ip_hash = ? AND created_at >= ?');
    $recent->execute([ip_hash(), date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$recent->fetchColumn() >= 10) {
        $errors[] = 'Too many cases were sent from this computer in the last hour. Please try again later.';
    }

    if (!$errors) {
        try {
            $result = CaseService::create($pdo, [
                'type'        => $old['type'],
                'title'       => $old['title'],
                'description' => $old['description'],
                'category_id' => (int)$old['category_id'],
                'student_id'  => ($user && $old['anonymous'] === '') ? (int)$user['id'] : null,
            ], $_FILES['attachment'] ?? null);
            Mailer::flush($pdo);
            $_SESSION['case_access'][] = $result['id'];
            $_SESSION['just_submitted'] = $result;
            redirect('submitted.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

page_header('Submit a case', 'submit.php');
?>
<div class="narrow">
  <h1>Submit a case</h1>
  <p class="muted">In an emergency, contact campus security or emergency services first. Do not wait for this form.</p>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

  <form id="case-form" method="post" action="<?= e(url('submit.php')) ?>" enctype="multipart/form-data"
        data-suggest-url="<?= e(url('api/suggest.php')) ?>">
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <div class="field">
      <label id="type-label">What is this?</label>
      <div class="choice" role="radiogroup" aria-labelledby="type-label">
        <label><input type="radio" name="type" value="complaint" <?= $old['type'] === 'complaint' ? 'checked' : '' ?>><span>Complaint</span></label>
        <label><input type="radio" name="type" value="request" <?= $old['type'] === 'request' ? 'checked' : '' ?>><span>Request</span></label>
      </div>
      <div class="hint">A complaint is something that went wrong. A request asks the university to do something, such as issue a transcript.</div>
    </div>

    <div class="field">
      <label for="title">Short title</label>
      <input id="title" name="title" type="text" maxlength="160" value="<?= e($old['title']) ?>" required>
    </div>

    <div class="field">
      <label for="description">Describe the problem</label>
      <textarea id="description" name="description" maxlength="4000" required><?= e($old['description']) ?></textarea>
      <div class="hint">Say what happened, when, and where. Add names of offices or staff if you know them.</div>
      <div id="ai-hint" class="ai-hint" aria-live="polite"></div>
    </div>

    <div class="field">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id">
        <option value="0" <?= $old['category_id'] === '0' ? 'selected' : '' ?>>Let the system choose (recommended)</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $old['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['department']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="attachment">Attach evidence (optional)</label>
      <input id="attachment" name="attachment" type="file" accept=".jpg,.jpeg,.png,.pdf">
      <div class="hint">JPG, PNG or PDF, up to <?= (int)(config('upload.max_bytes') / 1048576) ?> MB.</div>
    </div>

    <div class="field">
      <label class="check"><input type="checkbox" name="anonymous" value="1" <?= $old['anonymous'] ? 'checked' : '' ?>>
        <span>Submit anonymously<br><span class="hint">Your name is not stored with the case, and it will not appear in "My cases". You get a tracking code to follow it. Keep that code safe.</span></span></label>
    </div>

    <button class="button" type="submit">Submit case</button>
  </form>
</div>
<?php page_footer();
