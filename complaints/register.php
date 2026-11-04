<?php
require __DIR__ . '/app/bootstrap.php';
if ($u = current_user()) {
    redirect(home_for($u));
}

$pdo = db();
$departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
$offices = $pdo->query(
    'SELECT o.id, o.name, o.department_id
     FROM offices o JOIN departments d ON d.id = o.department_id
     WHERE o.is_active = 1 AND d.is_active = 1 ORDER BY o.name'
)->fetchAll();
$old = ['full_name' => '', 'email' => '', 'reg_no' => '', 'department_id' => '', 'office_id' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email']     = strtolower(trim($_POST['email'] ?? ''));
    $old['reg_no']    = trim($_POST['reg_no'] ?? '');
    $old['department_id'] = (string)(int)($_POST['department_id'] ?? 0);
    $old['office_id']     = (string)(int)($_POST['office_id'] ?? 0);
    $password         = (string)($_POST['password'] ?? '');
    $password2        = (string)($_POST['password2'] ?? '');

    if (mb_strlen($old['full_name']) < 3 || mb_strlen($old['full_name']) > 120) {
        $errors[] = 'Enter your full name.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150) {
        $errors[] = 'Enter a valid email address.';
    }
    if (mb_strlen($old['reg_no']) > 40) {
        $errors[] = 'The registration number is too long.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'The password must have at least 8 characters.';
    }
    if ($password !== $password2) {
        $errors[] = 'The two passwords do not match.';
    }

    $departmentId = (int)$old['department_id'];
    $officeId = (int)$old['office_id'];
    if ($departments) {
        $st = $pdo->prepare(
            'SELECT 1 FROM offices o JOIN departments d ON d.id = o.department_id
             WHERE o.id = ? AND o.department_id = ? AND o.is_active = 1 AND d.is_active = 1'
        );
        $st->execute([$officeId, $departmentId]);
        if (!$st->fetchColumn()) {
            $errors[] = 'Choose a department and an office within that department.';
        }
    } elseif ($departmentId || $officeId) {
        $errors[] = 'Department and office choices are not available. Please contact the administrator.';
    }

    if (!$errors) {
        try {
            db()->prepare(
                "INSERT INTO users (full_name, email, reg_no, password_hash, role, department_id, office_id, is_active, created_at)
                 VALUES (?, ?, ?, ?, 'student', ?, ?, 1, ?)"
            )->execute([$old['full_name'], $old['email'], $old['reg_no'] ?: null, password_hash($password, PASSWORD_DEFAULT),
                        $departmentId ?: null, $officeId ?: null, now()]);
            $st = db()->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$old['email']]);
            login_user($st->fetch());
            flash('success', 'Your account is ready. You can submit your first case now.');
            redirect('submit.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'An account with that email already exists. Try logging in.';
            } else {
                throw $e;
            }
        }
    }
}

page_header('Create account', 'register.php');
?>
<div class="narrow">
  <h1>Create a student account</h1>
  <p class="muted">An account lets you see all your cases in one place. You can still submit anonymously without one.</p>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
  <?php if (!$departments): ?><p class="hint">Department and office choices have not been set up yet. You can still create an account; contact an administrator to add them.</p><?php endif; ?>
  <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="full_name">Full name</label>
      <input id="full_name" name="full_name" type="text" value="<?= e($old['full_name']) ?>" autocomplete="name" required></div>
    <div class="field"><label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" autocomplete="email" required></div>
    <div class="field"><label for="reg_no" >Registration number </label>
      <input id="reg_no" name="reg_no" type="text" placeholder="2021-08-23245" value="<?= e($old['reg_no']) ?>"></div>
    <?php if ($departments): ?>
    <div class="field"><label for="department_id">Department / school</label>
      <select id="department_id" name="department_id" required>
        <option value="">Choose your department or school</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $old['department_id'] === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="office_id">Office</label>
      <select id="office_id" name="office_id" required>
        <option value="">Choose your office</option>
        <?php foreach ($offices as $o): ?>
          <option value="<?= (int)$o['id'] ?>" data-department="<?= (int)$o['department_id'] ?>" <?= $old['office_id'] === (string)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="field"><label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="new-password" required>
      <div class="hint">At least 8 characters.</div></div>
    <div class="field"><label for="password2">Repeat the password</label>
      <input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
    <button class="button" type="submit">Create account</button>
  </form>
  <p style="margin-top:1.25rem">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
</div>
<?php page_footer();
