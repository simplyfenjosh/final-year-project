<?php
/* Returns the AI's suggested category for the text typed on the submit form (used by assets/app.js). */
define('SKIP_ESCALATION', true);
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
    http_response_code(419);
    echo json_encode(['ok' => false]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text  = mb_substr(trim((string)($input['text'] ?? '')), 0, 2000);
if (mb_strlen($text) < 10) {
    echo json_encode(['ok' => true, 'category' => null, 'urgent' => false]);
    exit;
}

$pdo  = db();
$pred = Classifier::predict(Classifier::train($pdo), $text);
$urgent = Classifier::urgency($text)['urgent'];
$out = ['ok' => true, 'category' => null, 'department' => null, 'confidence' => $pred['confidence'], 'urgent' => $urgent];
if ($pred['category_id'] !== null && $pred['confidence'] >= (float)config('ai_min_confidence', 0.4)) {
    $st = $pdo->prepare('SELECT name, department FROM categories WHERE id = ? AND is_active = 1');
    $st->execute([$pred['category_id']]);
    if ($c = $st->fetch()) {
        $out['category'] = $c['name'];
        $out['department'] = $c['department'];
    }
}
echo json_encode($out);
