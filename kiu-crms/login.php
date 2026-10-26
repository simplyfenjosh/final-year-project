<?php
require __DIR__ . '/app/bootstrap.php';
if ($u = current_user()) {
    redirect(home_for($u));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked($email)) {
        $error = 'Too many failed attempts. Wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $st->execute([$email]);
        $user = $st->fetch();
        // Always run password_verify, so a wrong email takes as long as a wrong password.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (password_verify($password, $hash) && $user) {
            login_user($user);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            redirect(home_for($user));
        }
        record_login_failure($email);
        $error = 'The email or password is not correct.';
    }
}

page_header('Log in', 'login.php');
?>
<div class="narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('login.php')) ?>">
    <?= csrf_field() ?>
    <div class="field"><label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="email" required autofocus></div>
    <div class="field"><label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="button" type="submit">Log in</button>
  </form>
  <p style="margin-top:1.25rem">New student? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
</div>
<?php page_footer();
