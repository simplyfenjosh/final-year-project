<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'levels') {
        $upd = $pdo->prepare('UPDATE escalation_levels SET title = ?, response_hours = ?, urgent_response_hours = ?, resolution_hours = ? WHERE level = ?');
        foreach (levels() as $n => $row) {
            $title = trim($_POST['title'][$n] ?? '');
            $resp  = (float)($_POST['response_hours'][$n] ?? 0);
            $urg   = (float)($_POST['urgent_response_hours'][$n] ?? 0);
            $reso  = (float)($_POST['resolution_hours'][$n] ?? 0);
            if ($title === '' || mb_strlen($title) > 120 || $resp <= 0 || $urg <= 0 || $reso <= 0 || max($resp, $urg, $reso) > 9999) {
                $errors[] = "Level {$n}: enter a title and time limits between 0.01 and 9999.";
                continue;
            }
            $upd->execute([$title, $resp, $urg, $reso, $n]);
        }
        if (!$errors) {
            flash('success', 'Escalation levels saved. New limits apply to cases from now on.');
            redirect('admin/settings.php');
        }
    }

    if ($do === 'category_add' || $do === 'category_save') {
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        if ($name === '' || $dept === '' || mb_strlen($name) > 80 || mb_strlen($dept) > 120) {
            $errors[] = 'Enter a category name and the office that handles it.';
        } else {
            try {
                if ($do === 'category_add') {
                    $pdo->prepare('INSERT INTO categories (name, department) VALUES (?, ?)')->execute([$name, $dept]);
                    flash('success', 'Category added. Add some example complaints for it at AI classifier, so the AI can learn it.');
                } else {
                    $pdo->prepare('UPDATE categories SET name = ?, department = ?, is_active = ? WHERE id = ?')
                        ->execute([$name, $dept, !empty($_POST['is_active']) ? 1 : 0, (int)($_POST['id'] ?? 0)]);
                    flash('success', 'Category saved.');
                }
                redirect('admin/settings.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { $errors[] = 'A category with that name already exists.'; } else { throw $e; }
            }
        }
    }
}

$levels = $pdo->query('SELECT * FROM escalation_levels ORDER BY level')->fetchAll();
$cats = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.category_id = c.id AND u.role = \'officer\' AND u.is_active = 1) AS officers,
            (SELECT COUNT(*) FROM training_samples t WHERE t.category_id = c.id) AS samples
     FROM categories c ORDER BY c.name'
)->fetchAll();

page_header('Settings', 'admin/settings.php');
?>
<h1>Settings</h1>
<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<section class="panel">
  <h2>Escalation levels and time limits</h2>
  <p class="muted">Limits are counted in <strong><?= e(sla_unit()) ?></strong>. To demonstrate escalation quickly, set <code>sla_unit</code> to minutes or seconds in config/config.php.
    "Respond" is the time an officer has to acknowledge or answer. "Resolve" is the time allowed after that.</p>
  <form method="post" action="<?= e(url('admin/settings.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="levels">
    <div class="table-wrap"><table>
      <thead><tr><th>Level</th><th>Who</th><th>Priority on arrival</th><th>Respond within</th><th>Respond (urgent)</th><th>Resolve within</th></tr></thead>
      <tbody>
      <?php foreach ($levels as $l): $n = (int)$l['level']; ?>
        <tr>
          <td><strong><?= $n ?></strong></td>
          <td><input type="text" name="title[<?= $n ?>]" value="<?= e($l['title']) ?>" maxlength="120" aria-label="Title of level <?= $n ?>"></td>
          <td><?= priority_badge($l['priority']) ?></td>
          <td><input type="number" step="0.01" min="0.01" max="9999" name="response_hours[<?= $n ?>]" value="<?= e(rtrim(rtrim($l['response_hours'], '0'), '.')) ?>" aria-label="Response limit, level <?= $n ?>"></td>
          <td><input type="number" step="0.01" min="0.01" max="9999" name="urgent_response_hours[<?= $n ?>]" value="<?= e(rtrim(rtrim($l['urgent_response_hours'], '0'), '.')) ?>" aria-label="Urgent response limit, level <?= $n ?>"></td>
          <td><input type="number" step="0.01" min="0.01" max="9999" name="resolution_hours[<?= $n ?>]" value="<?= e(rtrim(rtrim($l['resolution_hours'], '0'), '.')) ?>" aria-label="Resolution limit, level <?= $n ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p style="margin-top:1rem"><button class="button" type="submit">Save levels</button></p>
  </form>
</section>

<section class="panel">
  <h2>Categories and the offices that handle them</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th>Office</th><th>Officers</th><th>AI examples</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): $fid = 'cf' . (int)$c['id']; ?>
      <tr>
        <td><input type="text" form="<?= $fid ?>" name="name" value="<?= e($c['name']) ?>" maxlength="80" aria-label="Category name"></td>
        <td><input type="text" form="<?= $fid ?>" name="department" value="<?= e($c['department']) ?>" maxlength="120" aria-label="Office"></td>
        <td class="num"><?= (int)$c['officers'] ?></td>
        <td class="num"><?= (int)$c['samples'] ?></td>
        <td><label class="check" style="margin:0"><input type="checkbox" form="<?= $fid ?>" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>><span>Active</span></label></td>
        <td><button class="button secondary small" form="<?= $fid ?>" type="submit">Save</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php foreach ($cats as $c): ?>
    <form id="cf<?= (int)$c['id'] ?>" method="post" action="<?= e(url('admin/settings.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="category_save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    </form>
  <?php endforeach; ?>
  <h3 style="margin-top:1.25rem">Add a category</h3>
  <form method="post" action="<?= e(url('admin/settings.php')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="category_add">
    <div class="field"><label for="n">Category name</label><input id="n" name="name" type="text" maxlength="80" required></div>
    <div class="field"><label for="d">Office that handles it</label><input id="d" name="department" type="text" maxlength="120" required></div>
    <div class="field" style="align-self:end"><button class="button" type="submit">Add category</button></div>
  </form>
</section>

<section class="panel">
  <h2>Other settings</h2>
  <p class="muted">These live in config/config.php.</p>
  <ul class="plain">
    <li>Time limits are counted in: <strong><?= e(sla_unit()) ?></strong></li>
    <li>Resolved cases close by themselves after: <strong><?= e(sla_label((float)config('auto_close_hours'))) ?></strong> without a reply from the student</li>
    <li>Overdue cases are checked at most every: <strong><?= (int)config('lazy_escalation_interval') ?> seconds</strong> when pages are opened</li>
    <li>The AI chooses a category on its own when it is at least <strong><?= round(config('ai_min_confidence') * 100) ?>%</strong> sure</li>
    <li>Real email sending: <strong><?= config('mail.enabled') ? 'on' : 'off (emails are kept in the outbox)' ?></strong></li>
  </ul>
</section>
<?php page_footer();
