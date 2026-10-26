<?php
/* Receives every button press on the case page and passes it to CaseService. */
require __DIR__ . '/app/bootstrap.php';
require_post();

$pdo    = db();
$user   = current_user();
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$case   = $id ? CaseService::fetch($pdo, $id) : null;

if (!$case || !CaseService::canView($pdo, $case, $user)) {
    http_response_code(403);
    fatal_page('No access', 'You cannot change this case.');
}

$isStaff = $user && in_array($user['role'], ['officer', 'admin'], true);
$message = (string)($_POST['message'] ?? '');

try {
    if ($isStaff) {
        switch ($action) {
            case 'acknowledge':
                CaseService::acknowledge($pdo, $id, $user);
                flash('success', 'Case acknowledged. The resolution clock has started.');
                break;
            case 'respond':
                CaseService::respond($pdo, $id, $user, $message);
                flash('success', 'Your reply was sent to the student.');
                break;
            case 'note':
                CaseService::note($pdo, $id, $user, $message);
                flash('success', 'Internal note saved.');
                break;
            case 'resolve':
                CaseService::resolve($pdo, $id, $user, $message);
                flash('success', 'Case marked as resolved. The student will be asked to confirm.');
                break;
            case 'category':
                CaseService::changeCategory($pdo, $id, $user, (int)($_POST['category_id'] ?? 0));
                flash('success', 'Case moved. The system will learn from your correction.');
                break;
            case 'reassign':
                CaseService::reassign($pdo, $id, $user, (int)($_POST['officer_id'] ?? 0));
                flash('success', 'Case reassigned.');
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
    } else {
        // The student, or an anonymous visitor who proved the tracking code
        $label     = $case['is_anonymous'] ? 'Anonymous student' : 'Student';
        $studentId = $user ? (int)$user['id'] : null;
        switch ($action) {
            case 'comment':
                CaseService::studentComment($pdo, $id, $label, $message, $studentId);
                flash('success', 'Your message was added to the case.');
                break;
            case 'confirm':
                CaseService::confirm($pdo, $id, $label, $studentId, (int)($_POST['rating'] ?? 0), (string)($_POST['comment'] ?? ''));
                flash('success', 'Thank you. The case is closed.');
                break;
            case 'reopen':
                CaseService::reopen($pdo, $id, $label, $studentId, $message);
                flash('success', 'The case was reopened and sent back to the handler.');
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
    }
    Mailer::flush($pdo);
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}
redirect('case.php?id=' . $id);
