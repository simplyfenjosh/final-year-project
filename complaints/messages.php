<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_role(['vice_chancellor', 'officer']);
$pdo = db();
$isViceChancellor = $user['role'] === 'vice_chancellor';
$userId = (int)$user['id'];

$recipients = $isViceChancellor
    ? $pdo->query("SELECT id, full_name, email, role FROM users WHERE role = 'officer' AND is_active = 1 ORDER BY officer_level, full_name")->fetchAll()
    : $pdo->query("SELECT id, full_name, email, role FROM users WHERE role = 'vice_chancellor' AND is_active = 1 ORDER BY full_name")->fetchAll();
$recipientIds = array_map(static fn($recipient) => (int)$recipient['id'], $recipients);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));
    if (!in_array($recipientId, $recipientIds, true)) {
        http_response_code(403);
        fatal_page('No access', 'Messages can only be sent between the Vice Chancellor and active officers.');
    }
    if ($message === '' || mb_strlen($message) > 4000) {
        flash('error', 'Write a message of 1 to 4,000 characters.');
        redirect('messages.php?with=' . $recipientId);
    }
    $pdo->prepare('INSERT INTO staff_messages (sender_id, recipient_id, message, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $recipientId, $message, now()]);
    flash('success', 'Message sent.');
    redirect('messages.php?with=' . $recipientId);
}

$threads = $pdo->prepare(
    'SELECT DISTINCT other_user.id, other_user.full_name, other_user.email, other_user.role
     FROM staff_messages m
     JOIN users other_user ON other_user.id = CASE WHEN m.sender_id = ? THEN m.recipient_id ELSE m.sender_id END
     WHERE m.sender_id = ? OR m.recipient_id = ?
     ORDER BY other_user.full_name'
);
$threads->execute([$userId, $userId, $userId]);
$threads = $threads->fetchAll();

$selectedId = (int)($_GET['with'] ?? 0);
if (!$selectedId && $threads) {
    $selectedId = (int)$threads[0]['id'];
}
$selected = null;
foreach ($recipients as $recipient) {
    if ((int)$recipient['id'] === $selectedId) {
        $selected = $recipient;
        break;
    }
}
if (!$selected && $selectedId) {
    foreach ($threads as $thread) {
        if ((int)$thread['id'] === $selectedId) {
            $selected = $thread;
            break;
        }
    }
}
if ($selectedId && !$selected) {
    http_response_code(403);
    fatal_page('No access', 'You can only open messages between the Vice Chancellor and active officers.');
}

$messages = [];
if ($selected) {
    $pdo->prepare('UPDATE staff_messages SET read_at = ? WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL')
        ->execute([now(), $selectedId, $userId]);
    $st = $pdo->prepare(
        'SELECT m.*, sender.full_name sender_name
         FROM staff_messages m JOIN users sender ON sender.id = m.sender_id
         WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)
         ORDER BY m.id'
    );
    $st->execute([$userId, $selectedId, $selectedId, $userId]);
    $messages = $st->fetchAll();
}

page_header('Staff messages', 'messages.php');
?>
<h1>Staff messages</h1>
<p class="muted"><?= $isViceChancellor ? 'Contact active officers about school-wide operations. These messages are separate from case handling.' : 'Exchange operational messages with the Vice Chancellor. Case replies and case actions remain on the case page.' ?></p>

<div class="two-col">
  <section class="panel">
    <h2>Conversations</h2>
    <?php if (!$threads): ?><p class="muted">No conversations yet.</p><?php else: ?>
      <ul class="plain">
        <?php foreach ($threads as $thread): ?>
          <li><a href="<?= e(url('messages.php?with=' . (int)$thread['id'])) ?>"><?= e($thread['full_name']) ?></a>
            <div class="muted small"><?= e(role_label($thread['role'])) ?> · <?= e($thread['email']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2><?= $selected ? 'Conversation with ' . e($selected['full_name']) : 'Start a conversation' ?></h2>
    <?php if (!$recipients): ?>
      <p class="muted"><?= $isViceChancellor ? 'No active officers are available.' : 'No active Vice Chancellor account is available.' ?></p>
    <?php else: ?>
      <form method="get" action="<?= e(url('messages.php')) ?>" class="field">
        <label for="with">Recipient</label>
        <select id="with" name="with" required>
          <option value="">Choose a recipient</option>
          <?php foreach ($recipients as $recipient): ?>
            <option value="<?= (int)$recipient['id'] ?>" <?= $selectedId === (int)$recipient['id'] ? 'selected' : '' ?>>
              <?= e($recipient['full_name']) ?> (<?= e($recipient['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button class="button secondary small" type="submit">Open conversation</button>
      </form>

      <?php if ($selected): ?>
        <div class="message-thread" aria-live="polite">
          <?php if (!$messages): ?><p class="muted">No messages in this conversation yet.</p><?php endif; ?>
          <?php foreach ($messages as $message): ?>
            <article class="message-item">
              <strong><?= e($message['sender_name']) ?></strong>
              <div><?= nl2br(e($message['message'])) ?></div>
              <time class="muted small" datetime="<?= e($message['created_at']) ?>"><?= e(fmt_dt($message['created_at'])) ?></time>
            </article>
          <?php endforeach; ?>
        </div>
        <form method="post" action="<?= e(url('messages.php?with=' . $selectedId)) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="recipient_id" value="<?= $selectedId ?>">
          <div class="field"><label for="message">Message</label>
            <textarea id="message" name="message" maxlength="4000" required></textarea></div>
          <button class="button" type="submit">Send message</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php page_footer();
