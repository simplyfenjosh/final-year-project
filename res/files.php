<?php
/* Downloads an attachment, but only for people who are allowed to open the case. */
define('SKIP_ESCALATION', true);
require __DIR__ . '/app/bootstrap.php';
$pdo = db();
$st = $pdo->prepare('SELECT a.*, c.id AS case_id FROM attachments a JOIN cases c ON c.id = a.case_id WHERE a.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$file = $st->fetch();
$case = $file ? CaseService::fetch($pdo, (int)$file['case_id']) : null;
$path = $file ? APP_ROOT . '/uploads/' . basename($file['stored_name']) : '';

if (!$file || !$case || !CaseService::canView($pdo, $case, current_user()) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
