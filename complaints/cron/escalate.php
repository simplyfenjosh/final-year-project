<?php
/*
 * Command-line runner for the escalation engine.
 * Schedule it to run every minute so cases escalate on time even when nobody is using the website.
 *
 * Windows (XAMPP): open Task Scheduler > Create Basic Task > repeat every 1 minute > Start a program:
 *     C:\xampp\php\php.exe   with the argument   C:\xampp\htdocs\kiu-crms\cron\escalate.php
 * Linux:  * * * * * php /var/www/html/kiu-crms/cron/escalate.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}
define('SKIP_ESCALATION', true);
require __DIR__ . '/../app/bootstrap.php';

$summary = Escalation::run(db());
echo '[' . $summary['ran_at'] . '] checked ' . $summary['checked'] . ' overdue case(s): '
   . $summary['escalated'] . ' escalated, ' . $summary['alerts'] . ' critical alert(s), '
   . $summary['closed'] . ' auto-closed, ' . $summary['errors'] . " error(s)\n";
