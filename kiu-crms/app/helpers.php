<?php
/*
 * Small helper functions used by every page:
 * settings, safe output, login and roles, CSRF protection, time limits and the page layout.
 */

const PRIORITY_RANK = ['Normal' => 0, 'High' => 1, 'Urgent' => 2, 'Critical' => 3];

/* ---------- Settings and database ---------- */

function config(?string $key = null, $default = null)
{
    $cfg = $GLOBALS['CONFIG'] ?? [];
    if ($key === null) {
        return $cfg;
    }
    foreach (explode('.', $key) as $part) {
        if (!is_array($cfg) || !array_key_exists($part, $cfg)) {
            return $default;
        }
        $cfg = $cfg[$part];
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        fatal_page(
            'The database is not ready',
            'Start MySQL in XAMPP, then open install.php once to create the tables. '
            . (config('debug') ? 'Details: ' . $e->getMessage() : '')
        );
    }
    $pdo->exec("SET time_zone = '" . config('app.db_timezone_offset', '+03:00') . "'");
    return $pdo;
}

/* ---------- Safe output and links ---------- */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2br_e($value): string
{
    return nl2br(e($value));
}

function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = config('app.base_url');
    if ($configured) {
        return $base = rtrim($configured, '/');
    }
    $root = str_replace('\\', '/', (string)realpath(APP_ROOT));
    $doc  = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = ($doc !== '' && stripos($root, $doc) === 0) ? rtrim(substr($root, strlen($doc)), '/') : '';
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path)
{
    header('Location: ' . url($path));
    exit;
}

function fatal_page(string $title, string $message)
{
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head>'
        . '<body><main class="container narrow"><h1>' . e($title) . '</h1><p>' . e($message) . '</p>'
        . '<p><a href="' . e(url('')) . '">Back to the home page</a></p></main></body></html>';
    exit;
}

/* ---------- Dates, durations and the SLA clock ---------- */

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d M Y, H:i', strtotime($dt)) : '-';
}

function iso(?string $dt): string
{
    return $dt ? date('c', strtotime($dt)) : '';
}

function human_duration(int $s): string
{
    if ($s < 90) {
        return $s . 's';
    }
    if ($s < 5400) {
        return round($s / 60) . 'm';
    }
    if ($s < 172800) {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return $h . 'h' . ($m ? ' ' . $m . 'm' : '');
    }
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    return $d . 'd' . ($h ? ' ' . $h . 'h' : '');
}

function sla_unit(): string
{
    $u = config('sla_unit', 'hours');
    return in_array($u, ['hours', 'minutes', 'seconds'], true) ? $u : 'hours';
}

function sla_unit_seconds(): int
{
    return ['hours' => 3600, 'minutes' => 60, 'seconds' => 1][sla_unit()];
}

/** The deadline that is $amount SLA units from now (or from $from). */
function sla_add(float $amount, ?int $from = null): string
{
    return date('Y-m-d H:i:s', ($from ?? time()) + (int)round($amount * sla_unit_seconds()));
}

/** Wording for a limit, for example "24 hours" (or "24 minutes" in demo mode). */
function sla_label(float $amount): string
{
    $n = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    $unit = sla_unit();
    return $n . ' ' . ($n === '1' ? rtrim($unit, 's') : $unit);
}

function due_text(?string $due): string
{
    if (!$due) {
        return '';
    }
    $diff = strtotime($due) - time();
    return $diff >= 0 ? 'due in ' . human_duration($diff) : 'overdue by ' . human_duration(-$diff);
}

/** A live countdown: the server writes the first text, app.js keeps it ticking. */
function countdown_span(?string $due, bool $reload = false): string
{
    if (!$due) {
        return '<span class="muted">No clock running</span>';
    }
    $over = strtotime($due) < time();
    return '<span class="countdown' . ($over ? ' overdue' : '') . '" data-due="' . e(iso($due)) . '"'
        . ($reload ? ' data-reload="1"' : '') . '>' . e(due_text($due)) . '</span>';
}

/* ---------- Escalation levels and priorities ---------- */

function levels(): array
{
    static $levels = null;
    if ($levels === null) {
        $levels = [];
        foreach (db()->query('SELECT * FROM escalation_levels ORDER BY level') as $row) {
            $levels[(int)$row['level']] = $row;
        }
    }
    return $levels;
}

function max_level(): int
{
    $l = levels();
    return $l ? max(array_keys($l)) : 1;
}

function max_priority(string $a, string $b): string
{
    return PRIORITY_RANK[$a] >= PRIORITY_RANK[$b] ? $a : $b;
}

function priority_badge(string $p): string
{
    return '<span class="badge prio-' . e(strtolower($p)) . '">' . e($p) . '</span>';
}

function status_badge(string $s): string
{
    return '<span class="badge status-' . e(strtolower(str_replace(' ', '-', $s))) . '">' . e($s) . '</span>';
}

/* ---------- App state (small key/value store) ---------- */

function state_get(string $key): ?string
{
    $st = db()->prepare('SELECT state_value FROM app_state WHERE state_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function state_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO app_state (state_key, state_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)'
    )->execute([$key, $value]);
}

/* ---------- Messages shown once ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF protection: every form carries a secret token ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent     = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['csrf'] ?? '';
    // Both must exist and match. (Two empty strings must never count as a match.)
    if (!is_string($sent) || $sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
        http_response_code(419);
        fatal_page('This page expired', 'The form was not valid any more. Go back, refresh the page and try again.');
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
    csrf_check();
}

/* ---------- Login and roles ---------- */

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($refresh) {
        $user = false;
    }
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $st->execute([(int)$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        if (!$user) {
            unset($_SESSION['uid']);
        }
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    current_user(true);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    current_user(true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    return $u;
}

function require_role($roles): array
{
    $u = require_login();
    if (!in_array($u['role'], (array)$roles, true)) {
        http_response_code(403);
        fatal_page('No access', 'Your account does not have access to this page.');
    }
    return $u;
}

function home_for(array $u): string
{
    return $u['role'] === 'admin' ? 'admin/index.php' : ($u['role'] === 'officer' ? 'officer.php' : 'my-cases.php');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_hash(): string
{
    return hash('sha256', client_ip() . config('app.secret'));
}

/** Too many wrong passwords from one email (5) or one computer (20) in 15 minutes. */
function login_blocked(string $email): bool
{
    $since = date('Y-m-d H:i:s', time() - 900);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= ? AND email = ?');
    $st->execute([$since, $email]);
    if ((int)$st->fetchColumn() >= 5) {
        return true;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= ? AND ip_hash = ?');
    $st->execute([$since, ip_hash()]);
    return (int)$st->fetchColumn() >= 20;
}

function record_login_failure(string $email): void
{
    db()->prepare('INSERT INTO login_attempts (email, ip_hash, attempted_at) VALUES (?, ?, ?)')
        ->execute([$email, ip_hash(), now()]);
}

function unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/* ---------- Page layout ---------- */

function nav_link(string $path, string $label, ?string $current): string
{
    $active = ($current === $path) ? ' aria-current="page"' : '';
    return '<a href="' . e(url($path)) . '"' . $active . '>' . e($label) . '</a>';
}

function page_header(string $title, string $current = ''): void
{
    $user = current_user();
    $app  = config('app.name');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> | <?= e($app) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
<script src="<?= e(url('assets/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e(url($user ? home_for($user) : '')) ?>"><?= e($app) ?></a>
    <nav class="nav" aria-label="Main">
      <?php if (!$user): ?>
        <?= nav_link('submit.php', 'Submit a case', $current) ?>
        <?= nav_link('track.php', 'Track a case', $current) ?>
        <?= nav_link('login.php', 'Log in', $current) ?>
        <?= nav_link('register.php', 'Create account', $current) ?>
      <?php else: ?>
        <?php if ($user['role'] === 'student'): ?>
          <?= nav_link('submit.php', 'Submit a case', $current) ?>
          <?= nav_link('my-cases.php', 'My cases', $current) ?>
        <?php elseif ($user['role'] === 'officer'): ?>
          <?= nav_link('officer.php', 'My queue', $current) ?>
        <?php else: ?>
          <?= nav_link('admin/index.php', 'Dashboard', $current) ?>
        <?php endif; ?>
        <?php $unread = unread_count((int)$user['id']); ?>
        <a href="<?= e(url('notifications.php')) ?>"<?= $current === 'notifications.php' ? ' aria-current="page"' : '' ?>>Alerts<?= $unread ? ' <span class="count">' . $unread . '</span>' : '' ?></a>
        <form class="inline" method="post" action="<?= e(url('logout.php')) ?>">
          <?= csrf_field() ?>
          <button class="linklike" type="submit">Log out (<?= e(explode(' ', $user['full_name'])[0]) ?>)</button>
        </form>
      <?php endif; ?>
    </nav>
  </div>
  <?php if ($user && $user['role'] === 'admin'): ?>
  <nav class="subnav" aria-label="Admin">
    <?= nav_link('admin/index.php', 'Dashboard', $current) ?>
    <?= nav_link('admin/cases.php', 'All cases', $current) ?>
    <?= nav_link('admin/escalations.php', 'Escalation log', $current) ?>
    <?= nav_link('admin/users.php', 'Users', $current) ?>
    <?= nav_link('admin/settings.php', 'Settings', $current) ?>
    <?= nav_link('admin/classifier.php', 'AI classifier', $current) ?>
    <?= nav_link('admin/outbox.php', 'Emails', $current) ?>
  </nav>
  <?php endif; ?>
</header>
<?php if (sla_unit() !== 'hours'): ?>
  <div class="demo-banner">Demo mode: time limits run in <?= e(sla_unit()) ?> instead of hours, so you can watch cases escalate quickly.</div>
<?php endif; ?>
<main id="main" class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="footer">
  <div class="footer-inner">
    <span><?= e(config('app.name')) ?>. Case study: Kampala International University.</span>
    <span>In an emergency, contact campus security or emergency services first. Do not wait for this system.</span>
  </div>
</footer>
</body>
</html>
<?php
}
