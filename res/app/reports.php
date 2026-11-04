<?php
/* Shared helpers for the admin pages: case filters, simple queries and the bar charts. */

/** Builds the WHERE part of a case search from the page's query string. Returns [sql, params]. */
function case_filter_sql(array $get): array
{
    $where = ['1 = 1'];
    $params = [];
    if (($q = trim((string)($get['q'] ?? ''))) !== '') {
        $where[] = '(c.reference LIKE ? OR c.title LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if (in_array($get['status'] ?? '', ['Submitted', 'In Progress', 'Resolved', 'Closed', 'Reopened'], true)) {
        $where[] = 'c.status = ?';
        $params[] = $get['status'];
    } elseif (($get['status'] ?? '') === 'open') {
        $where[] = "c.status IN ('Submitted','In Progress','Reopened')";
    }
    if ((int)($get['category'] ?? 0) > 0) {
        $where[] = 'c.category_id = ?';
        $params[] = (int)$get['category'];
    }
    if (isset(PRIORITY_RANK[$get['priority'] ?? ''])) {
        $where[] = 'c.priority = ?';
        $params[] = $get['priority'];
    }
    if ((int)($get['level'] ?? 0) > 0) {
        $where[] = 'c.current_level = ?';
        $params[] = (int)$get['level'];
    }
    if (($get['escalated'] ?? '') === '1') {
        $where[] = 'c.escalation_count > 0';
    }
    return [implode(' AND ', $where), $params];
}

/** Runs a query that returns one value. */
function scalar(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

/** Draws a list of horizontal bars. $items = [[label, value, optional css class], ...] */
function bars(array $items, ?float $max = null): string
{
    if (!$items) {
        return '<p class="muted">Nothing to show yet.</p>';
    }
    $max = $max ?: max(1, max(array_map(fn($i) => (float)$i[1], $items)));
    $html = '<div class="bars">';
    foreach ($items as $i) {
        $pct = $max > 0 ? round(((float)$i[1]) / $max * 100) : 0;
        $html .= '<div class="bar"><span>' . e($i[0]) . '</span><span class="track"><span class="fill ' . e($i[2] ?? '') . '" style="width:' . $pct . '%"></span></span><span class="val">' . e($i[1]) . '</span></div>';
    }
    return $html . '</div>';
}

/** "3h 12m" for a number of seconds, or a dash when there is no data. */
function dur($seconds): string
{
    return $seconds === null || $seconds === false ? '-' : human_duration((int)round((float)$seconds));
}
