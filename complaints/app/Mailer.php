<?php
/*
 * Email.
 * Every email is first saved in the email_outbox table (queue()). If real email is switched on in config.php
 * and PHPMailer is installed, flush() sends the queued emails through your SMTP account (for example Gmail).
 * If real email is off, the email stays in the outbox with status "logged". That is perfect for demos and
 * screenshots, and nothing is ever lost.
 * Sending happens after the database work is finished, so a slow mail server never blocks a case.
 */
class Mailer
{
    public static function queue(PDO $pdo, string $to, string $subject, string $body): void
    {
        $status = !empty(config('mail.enabled')) ? 'queued' : 'logged';
        $pdo->prepare(
            'INSERT INTO email_outbox (to_email, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$to, mb_substr($subject, 0, 200), $body, $status, now()]);
    }

    /** Sends everything that is waiting in the queue. Safe to call often. */
    public static function flush(PDO $pdo): void
    {
        if (empty(config('mail.enabled'))) {
            return;
        }
        $rows = $pdo->query("SELECT * FROM email_outbox WHERE status = 'queued' ORDER BY id LIMIT 20")->fetchAll();
        if (!$rows) {
            return;
        }
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $update = $pdo->prepare('UPDATE email_outbox SET status = ?, error = ? WHERE id = ?');
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            foreach ($rows as $r) {
                $update->execute(['failed', 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer', $r['id']]);
            }
            return;
        }
        $cfg = config('mail');
        foreach ($rows as $r) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $cfg['host'];
                $mail->Port       = (int)$cfg['port'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $cfg['user'];
                $mail->Password   = $cfg['pass'];
                $mail->SMTPSecure = $cfg['secure'] === 'ssl' ? 'ssl' : 'tls';
                $mail->setFrom($cfg['from_email'], $cfg['from_name']);
                $mail->addAddress($r['to_email']);
                $mail->Subject = $r['subject'];
                $mail->Body    = $r['body'];
                $mail->send();
                $update->execute(['sent', null, $r['id']]);
            } catch (Throwable $e) {
                $update->execute(['failed', substr($e->getMessage(), 0, 250), $r['id']]);
            }
        }
    }
}
