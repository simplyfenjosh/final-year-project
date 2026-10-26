<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$pdo = db();
$cats = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$names = array_column($cats, 'name', 'id');
$test = null;
$eval = null;
$text = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'test') {
        $text = mb_substr(trim($_POST['text'] ?? ''), 0, 2000);
        if ($text !== '') {
            $test = Classifier::predict(Classifier::train($pdo), $text);
            $test['urgency'] = Classifier::urgency($text);
        }
    } elseif ($do === 'evaluate') {
        $eval = Classifier::evaluate($pdo);
    } elseif ($do === 'add') {
        $cid = (int)($_POST['category_id'] ?? 0);
        $t   = mb_substr(trim($_POST['sample'] ?? ''), 0, 500);
        if (!isset($names[$cid]) || mb_strlen($t) < 10) {
            flash('error', 'Choose a category and write an example of at least 10 characters.');
        } else {
            $pdo->prepare("INSERT INTO training_samples (category_id, text, source, created_at) VALUES (?, ?, 'admin', ?)")->execute([$cid, $t, now()]);
            flash('success', 'Example added. The AI uses it from the next case.');
        }
        redirect('admin/classifier.php');
    } elseif ($do === 'delete') {
        $pdo->prepare('DELETE FROM training_samples WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Example removed.');
        redirect('admin/classifier.php');
    }
}

$counts = $pdo->query(
    "SELECT c.id, c.name,
            SUM(t.source = 'seed') AS seed, SUM(t.source = 'officer') AS officer, SUM(t.source = 'admin') AS admin, COUNT(t.id) AS total
     FROM categories c LEFT JOIN training_samples t ON t.category_id = c.id WHERE c.is_active = 1
     GROUP BY c.id, c.name ORDER BY c.name"
)->fetchAll();
$recent = $pdo->query(
    "SELECT t.*, c.name AS category_name FROM training_samples t JOIN categories c ON c.id = t.category_id
     WHERE t.source <> 'seed' ORDER BY t.id DESC LIMIT 20"
)->fetchAll();
$totalSamples = array_sum(array_column($counts, 'total'));

page_header('AI classifier', 'admin/classifier.php');
?>
<h1>AI classifier</h1>
<p class="muted">The system reads each new complaint and chooses the office that should handle it. It uses a Naive Bayes text classifier that learns from <?= (int)$totalSamples ?> example complaints.
  Every time an officer moves a case to another category, that case is added as a new example, so the AI improves with use.</p>

<div class="two-col">
  <section class="panel">
    <h2>Try it</h2>
    <form method="post" action="<?= e(url('admin/classifier.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="test">
      <div class="field"><label for="text">Type a complaint</label><textarea id="text" name="text" required><?= e($text) ?></textarea></div>
      <button class="button" type="submit">Classify</button>
    </form>
    <?php if ($test): ?>
      <h3 style="margin-top:1.25rem">Result</h3>
      <?php if ($test['known_words'] === 0): ?>
        <p class="muted">The AI does not recognise any word in this text, so it would use the general category.</p>
      <?php else: ?>
        <?php foreach (array_slice($test['probabilities'], 0, 5, true) as $cid => $p): if (!isset($names[$cid])) continue; ?>
          <div class="prob-row"><span><?= e($names[$cid]) ?></span><span class="bar"><span class="track" style="grid-column:1 / -1"><span class="fill <?= $cid === $test['category_id'] ? '' : 'warn' ?>" style="width:<?= round($p * 100) ?>%"></span></span></span><span class="num"><?= Classifier::percent($p) ?>%</span></div>
        <?php endforeach; ?>
        <p class="muted small">It would be used automatically only when the top result is at least <?= round(config('ai_min_confidence') * 100) ?>%. Below that the case goes to General and other.</p>
      <?php endif; ?>
      <p><?= $test['urgency']['urgent'] ? priority_badge('Urgent') . ' Danger words found: ' . e(implode(', ', $test['urgency']['matched'])) : 'No danger words found, so the priority starts as Normal.' ?></p>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>How accurate is it?</h2>
    <p class="muted">The test takes one example away, trains on the rest, and checks whether the AI classifies the missing example correctly. It repeats this for every example (leave-one-out testing).
      For your report, also test it on new complaints that you collect yourself.</p>
    <form method="post" action="<?= e(url('admin/classifier.php')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="evaluate"><button class="button secondary" type="submit">Measure accuracy</button></form>
    <?php if ($eval): ?>
      <p style="margin-top:1rem;font-size:1.4rem;font-weight:800"><?= round($eval['accuracy'] * 100, 1) ?>% <span class="muted" style="font-size:1rem;font-weight:400">(<?= (int)$eval['correct'] ?> of <?= (int)$eval['total'] ?> examples correct)</span></p>
      <?php $rows = []; foreach ($counts as $c) { $pc = $eval['per_category'][$c['id']] ?? ['total' => 0, 'correct' => 0]; $rows[] = [$c['name'], $pc['total'] ? round(($pc['correct'] ?? 0) / $pc['total'] * 100) : 0]; } ?>
      <?= bars($rows, 100) ?>
      <p class="muted small">Bars show the percentage correct for each category.</p>
    <?php endif; ?>
  </section>
</div>

<section class="section-gap">
  <h2>Training examples</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th class="right">Starting examples</th><th class="right">Added by officers</th><th class="right">Added by admin</th><th class="right">Total</th></tr></thead><tbody>
    <?php foreach ($counts as $c): ?><tr><td><?= e($c['name']) ?></td><td class="right"><?= (int)$c['seed'] ?></td><td class="right"><?= (int)$c['officer'] ?></td><td class="right"><?= (int)$c['admin'] ?></td><td class="right"><?= (int)$c['total'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>

  <h3 style="margin-top:1.5rem">Add an example</h3>
  <form method="post" action="<?= e(url('admin/classifier.php')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <div class="field"><label for="cid">Category</label><select id="cid" name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="grid-column: span 2"><label for="sample">Example complaint</label><input id="sample" name="sample" type="text" maxlength="500" required></div>
    <div class="field" style="align-self:end"><button class="button" type="submit">Add example</button></div>
  </form>

  <?php if ($recent): ?>
    <h3 style="margin-top:1.5rem">Latest additions</h3>
    <div class="table-wrap"><table><thead><tr><th>Example</th><th>Category</th><th>Source</th><th></th></tr></thead><tbody>
      <?php foreach ($recent as $r): ?>
        <tr><td><?= e($r['text']) ?></td><td><?= e($r['category_name']) ?></td><td><?= e($r['source']) ?></td>
          <td><form class="inline" method="post" action="<?= e(url('admin/classifier.php')) ?>" data-confirm="Remove this example?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="button secondary small" type="submit">Remove</button></form></td></tr>
      <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<?php page_footer();
