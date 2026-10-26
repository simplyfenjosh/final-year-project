<?php
/* Downloads the cases as a CSV file that opens in Excel. Useful for the evaluation chapter. */
define('SKIP_ESCALATION', true);
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');

[$where, $params] = case_filter_sql($_GET);
$st = db()->prepare(
    "SELECT c.*, cat.name AS category_name, cat.department
     FROM cases c JOIN categories cat ON cat.id = c.category_id WHERE $where ORDER BY c.id"
);
$st->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kiu-cases-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8

// Stops spreadsheet formulas from running if someone typed "=..." in a title.
$safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;

fputcsv($out, ['Reference', 'Type', 'Title', 'Category', 'Office', 'Status', 'Priority', 'Level', 'Escalations', 'Critical alerts',
    'Reopened', 'Anonymous', 'Created', 'First response', 'Resolved', 'Closed', 'Seconds to first response', 'Seconds to resolution', 'Rating']);
foreach ($st as $c) {
    fputcsv($out, [
        $c['reference'], $c['type'], $safe($c['title']), $c['category_name'], $c['department'], $c['status'], $c['priority'],
        $c['current_level'], $c['escalation_count'], $c['alert_count'], $c['reopen_count'], $c['is_anonymous'] ? 'Yes' : 'No',
        $c['created_at'], $c['first_response_at'], $c['resolved_at'], $c['closed_at'],
        $c['first_response_at'] ? strtotime($c['first_response_at']) - strtotime($c['created_at']) : '',
        $c['resolved_at'] ? strtotime($c['resolved_at']) - strtotime($c['created_at']) : '',
        $c['rating'],
    ]);
}
fclose($out);
