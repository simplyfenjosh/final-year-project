<?php
require __DIR__ . '/app/bootstrap.php';
if ($u = current_user()) {
    redirect(home_for($u));
}

$old = ['full_name' => '', 'email' => '', 'reg_no' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email']     = strtolower(trim($_POST['email'] ?? ''));
    $old['reg_no']    = trim($_POST['reg_no'] ?? '');
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

    if (!$errors) {
        try {
            db()->prepare(
                "INSERT INTO users (full_name, email, reg_no, password_hash, role, is_active, created_at)
                 VALUES (?, ?, ?, ?, 'student', 1, ?)"
            )->execute([$old['full_name'], $old['email'], $old['reg_no'] ?: null, password_hash($password, PASSWORD_DEFAULT), now()]);
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
  <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="full_name">Full name</label>
      <input id="full_name" name="full_name" type="text" value="<?= e($old['full_name']) ?>" autocomplete="name" required></div>
    <div class="field"><label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" autocomplete="email" required></div>
    <div class="field"><label for="reg_no">Registration number (optional)</label>
      <input id="reg_no" name="reg_no" type="text" value="<?= e($old['reg_no']) ?>"></div>
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
