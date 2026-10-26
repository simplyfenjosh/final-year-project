<?php
/*
 * One-time installer. Open http://localhost/kiu-crms/install.php in your browser (or run "php install.php").
 *  1. Creates the database and all tables.
 *  2. Adds the escalation levels, the categories and the AI's training examples.
 *  3. Creates demo accounts (admin, students, officers at every level).
 *  4. Creates demo cases and lets a few of them escalate for real, so the dashboard has something to show.
 * When it finishes it writes config/installed.lock so nobody can run it twice. Delete this file after installing.
 *
 * Start again from scratch (command line only):  php install.php --reset
 */
define('SKIP_ESCALATION', true);
require __DIR__ . '/app/bootstrap.php';

const DEMO_PASSWORD = 'Demo@1234';

$isCli = PHP_SAPI === 'cli';
$lines = [];
$ok    = true;
function say(string $text): void
{
    global $lines;
    $lines[] = $text;
}

function finish(bool $isCli, array $lines, bool $ok, string $title)
{
    if ($isCli) {
        echo implode("\n", $lines) . "\n";
        exit($ok ? 0 : 1);
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><link rel="stylesheet" href="' . e(url('assets/style.css')) . '"></head>'
        . '<body><main class="container narrow"><h1>' . e($title) . '</h1><ul class="plain">';
    foreach ($lines as $l) {
        echo '<li>' . e($l) . '</li>';
    }
    echo '</ul>';
    if ($ok) {
        echo '<p><a class="button" href="' . e(url('login.php')) . '">Go to the login page</a></p>'
           . '<p class="muted">For safety, delete install.php from the folder now.</p>';
    }
    echo '</main></body></html>';
    exit;
}

$lock = APP_ROOT . '/config/installed.lock';
$reset = $isCli && in_array('--reset', $argv ?? [], true);
if (is_file($lock) && !$reset) {
    say('The system is already installed. To start again, delete config/installed.lock and the tables in the database (or run: php install.php --reset).');
    finish($isCli, $lines, false, 'Already installed');
}

try {
    $c = config('db');

    // 1. Create the database
    $server = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbName = str_replace('`', '', $c['name']);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    say("Database '{$dbName}' is ready.");

    $pdo = db();

    if ($reset) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE `{$t}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        say('Old tables removed.');
    }

    // 2. Create the tables
    $sql = file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // drop comment-only lines
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    say('Tables created.');

    $now = now();

    // 3. Escalation levels (the ladder from the report)
    if (!(int)$pdo->query('SELECT COUNT(*) FROM escalation_levels')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO escalation_levels (level, title, priority, response_hours, urgent_response_hours, resolution_hours) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([1, 'Responsible office officer', 'Normal', 24, 4, 72]);
        $ins->execute([2, 'Head of department or section', 'High', 24, 4, 72]);
        $ins->execute([3, 'Dean of school or Dean of Students', 'Urgent', 24, 4, 72]);
        $ins->execute([4, "Registrar or Deputy Vice Chancellor's office", 'Critical', 24, 4, 72]);
        say('Escalation levels created (24 hours per level, 4 hours for urgent cases).');
    }

    // 4. Categories and AI training examples
    $data = require APP_ROOT . '/database/training_data.php';
    $catIds = [];
    if (!(int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn()) {
        $insCat = $pdo->prepare('INSERT INTO categories (name, department) VALUES (?, ?)');
        $insSample = $pdo->prepare("INSERT INTO training_samples (category_id, text, source, created_at) VALUES (?, ?, 'seed', ?)");
        $samples = 0;
        foreach ($data as $cat) {
            $insCat->execute([$cat['name'], $cat['department']]);
            $id = (int)$pdo->lastInsertId();
            $catIds[$cat['slug']] = $id;
            foreach ($cat['samples'] as $text) {
                $insSample->execute([$id, $text, $now]);
                $samples++;
            }
        }
        say(count($data) . " categories and {$samples} training examples created.");
    } else {
        foreach ($data as $cat) {
            $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
            $st->execute([$cat['name']]);
            $catIds[$cat['slug']] = (int)$st->fetchColumn();
        }
    }

    // 5. Demo accounts
    $accounts = [];
    if (!(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
        $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
        $insUser = $pdo->prepare(
            'INSERT INTO users (full_name, email, reg_no, password_hash, role, officer_level, category_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $add = function (string $name, string $email, ?string $reg, string $role, ?int $level, ?int $cat) use ($insUser, $hash, $now, &$accounts) {
            $insUser->execute([$name, $email, $reg, $hash, $role, $level, $cat, $now]);
            $accounts[] = [$role . ($level ? " L{$level}" : ''), $email];
        };
        $add('Admin User', 'admin@kiu.test', null, 'admin', null, null);
        $add('Amina (demo student)', 'student@kiu.test', '2026/DIT/001', 'student', null, null);
        $add('Brian (demo student)', 'student2@kiu.test', '2026/DIT/002', 'student', null, null);
        $short = ['results' => 'Results', 'fees' => 'Fees', 'hostel' => 'Hostel', 'registry' => 'Registry', 'lecturers' => 'Teaching',
                  'ict' => 'ICT', 'facilities' => 'Facilities', 'safety' => 'Safety', 'general' => 'General'];
        foreach ($data as $cat) {
            $label = $short[$cat['slug']] ?? ucfirst($cat['slug']);
            $add($label . ' Officer', $cat['slug'] . '.l1@kiu.test', null, 'officer', 1, $catIds[$cat['slug']]);
            $add($label . ' Head', $cat['slug'] . '.l2@kiu.test', null, 'officer', 2, $catIds[$cat['slug']]);
        }
        $add('Dean of Students', 'dean.students@kiu.test', null, 'officer', 3, null);
        $add('Dean of School', 'dean.school@kiu.test', null, 'officer', 3, null);
        $add('Academic Registrar', 'registrar@kiu.test', null, 'officer', 4, null);
        say(count($accounts) . ' demo accounts created. Every demo password is: ' . DEMO_PASSWORD);
    }

    // 6. Demo cases that really travel through the system
    if (!(int)$pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn()) {
        $user = function (string $email) use ($pdo) {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            return $st->fetch();
        };
        $amina = $user('student@kiu.test');
        $brian = $user('student2@kiu.test');
        $make = function (string $type, string $title, string $desc, $student) use ($pdo) {
            return CaseService::create($pdo, [
                'type' => $type, 'title' => $title, 'description' => $desc,
                'category_id' => 0, 'student_id' => $student ? (int)$student['id'] : null,
            ]);
        };
        $overdue = function (int $id) use ($pdo) {
            $pdo->prepare('UPDATE cases SET due_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $id]);
            Escalation::run($pdo);
        };
        $shift = function (int $id, int $days) use ($pdo) {
            // Makes a finished case look as if it happened some days ago (so the charts have a history).
            $sec = $days * 86400;
            foreach (['created_at', 'updated_at', 'level_entered_at', 'first_response_at', 'resolved_at', 'closed_at'] as $col) {
                $pdo->prepare("UPDATE cases SET {$col} = DATE_SUB({$col}, INTERVAL {$sec} SECOND) WHERE id = ? AND {$col} IS NOT NULL")->execute([$id]);
            }
            $pdo->prepare('UPDATE case_events SET created_at = DATE_SUB(created_at, INTERVAL ' . $sec . ' SECOND) WHERE case_id = ?')->execute([$id]);
        };

        $timeline = function (int $id, int $respondSec, int $resolveSec, ?int $closeSec) use ($pdo) {
            // Gives a finished demo case realistic times: first response, resolution and closing.
            $setCase = function (string $col, int $sec) use ($pdo, $id) {
                $pdo->prepare("UPDATE cases SET {$col} = DATE_ADD(created_at, INTERVAL {$sec} SECOND), updated_at = DATE_ADD(created_at, INTERVAL {$sec} SECOND) WHERE id = ?")->execute([$id]);
            };
            $setEvent = function (array $types, int $sec) use ($pdo, $id) {
                $in = "'" . implode("','", $types) . "'";
                $pdo->prepare("UPDATE case_events SET created_at = (SELECT DATE_ADD(created_at, INTERVAL {$sec} SECOND) FROM cases WHERE id = ?) WHERE case_id = ? AND event_type IN ({$in}) ORDER BY id ASC LIMIT 1")->execute([$id, $id]);
            };
            $setCase('first_response_at', $respondSec);
            $setEvent(['ACKNOWLEDGED', 'RESPONDED'], $respondSec);
            $setCase('resolved_at', $resolveSec);
            $setEvent(['RESOLVED'], $resolveSec);
            if ($closeSec !== null) {
                $setCase('closed_at', $closeSec);
                $setEvent(['CONFIRMED'], $closeSec);
            }
        };

        // Finished cases, spread over the last two weeks
        $c1 = $make('complaint', 'Tuition balance still shows after payment', 'I paid my tuition by mobile money last week but the portal still shows a balance and I cannot register.', $amina);
        $o = $user('fees.l1@kiu.test');
        CaseService::acknowledge($pdo, $c1['id'], $o);
        CaseService::respond($pdo, $c1['id'], $o, 'Thank you. Please attach the mobile money receipt so we can match the payment.');
        CaseService::resolve($pdo, $c1['id'], $o, 'Payment matched and the balance was cleared on the portal.');
        CaseService::confirm($pdo, $c1['id'], 'Student', (int)$amina['id'], 5, 'Fast and clear.');
        $shift($c1['id'], 9);
        $timeline($c1['id'], 3 * 3600, 26 * 3600, 31 * 3600);

        $c2 = $make('request', 'Transcript request', 'I applied for my transcript two months ago and I have not received it. I need it for a job application.', $brian);
        $o = $user('registry.l1@kiu.test');
        CaseService::respond($pdo, $c2['id'], $o, 'Your transcript is being printed.');
        CaseService::resolve($pdo, $c2['id'], $o, 'Transcript is ready for collection at the Registrar\'s office.');
        CaseService::confirm($pdo, $c2['id'], 'Student', (int)$brian['id'], 4, '');
        $shift($c2['id'], 6);
        $timeline($c2['id'], 7 * 3600, 52 * 3600, 76 * 3600);

        $c3 = $make('complaint', 'No running water in hostel', 'There has been no running water in our hostel block for three days and the toilets are blocked.', $brian);
        $o = $user('hostel.l1@kiu.test');
        CaseService::acknowledge($pdo, $c3['id'], $o);
        CaseService::resolve($pdo, $c3['id'], $o, 'The pump was repaired and water is back.');
        CaseService::confirm($pdo, $c3['id'], 'Student', (int)$brian['id'], 3, 'It took long.');
        $shift($c3['id'], 4);
        $timeline($c3['id'], 2 * 3600, 30 * 3600, 42 * 3600);

        $c4 = $make('complaint', 'Wi-Fi not working in the library', 'The Wi-Fi in the library has not been working for a week, I cannot upload my assignments to the online platform.', $amina);
        $o = $user('ict.l1@kiu.test');
        CaseService::resolve($pdo, $c4['id'], $o, 'The access point was replaced.');
        $shift($c4['id'], 2);
        $timeline($c4['id'], 20 * 3600, 20 * 3600, null);

        // Cases that escalate for real
        $e1 = $make('complaint', 'Lecturer does not come for classes', 'Our lecturer has not come for classes for two weeks and exams are near. The syllabus is not finished.', $amina);
        $overdue($e1['id']);                                      // Level 1 misses the deadline -> Level 2

        $e2 = $make('complaint', 'Marks missing on the portal', 'My coursework marks are missing on the portal and the lecturer has not recorded my CAT.', $brian);
        $overdue($e2['id']);
        $overdue($e2['id']);                                      // -> Level 3

        $e3 = $make('complaint', 'Fee penalty added by mistake', 'A late payment fine was added to my tuition by mistake. I have the proof of payment from the bank.', $amina);
        $overdue($e3['id']);
        $overdue($e3['id']);
        $overdue($e3['id']);                                      // -> Level 4 (Critical)
        $overdue($e3['id']);                                      // top level misses it too -> critical alert

        // Urgent anonymous case, and a fresh case waiting at Level 1
        $make('complaint', 'Harassment by a security guard', 'A security guard at the gate harassed me and threatened me when I came back late. I do not feel safe.', null);
        $make('request', 'Letter confirming I am a student', 'I need a letter confirming that I am a student for my visa application.', $amina);
        $make('complaint', 'Bed bugs in my hostel room', 'There are bed bugs in my hostel room and the matron has not sent anyone to spray. I cannot sleep.', $brian);
        $make('complaint', 'Cannot log in to the student portal', 'I cannot log in to the student portal. My password reset email never arrives and registration closes soon.', $amina);
        $make('request', 'Refund for double payment', 'I paid my tuition twice by mobile money by mistake and I need a refund of the second payment.', $brian);
        say('Demo cases created. Some finished, some escalated to Level 2, 3 and 4 by the real escalation engine.');
    }

    Mailer::flush($pdo);
    file_put_contents($lock, 'Installed on ' . now() . "\n");
    say('Done. Log in with admin@kiu.test, student@kiu.test or any officer (for example fees.l1@kiu.test). Password: ' . DEMO_PASSWORD);
    say('Remember to change the demo passwords and delete install.php before the system is used for real.');
} catch (Throwable $e) {
    $ok = false;
    say('Installation stopped: ' . $e->getMessage());
    say('Check that MySQL is running and that the login in config/config.php is correct.');
}

finish($isCli, $lines, $ok, $ok ? 'Installation complete' : 'Installation problem');
