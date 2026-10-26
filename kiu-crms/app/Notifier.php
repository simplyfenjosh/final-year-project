<?php
/*
 * Sends alerts. Every alert appears in the person's "Alerts" page (in-app notification)
 * and is also queued as an email (see Mailer.php).
 */
class Notifier
{
    /** A full link to a case, used inside emails. */
    private static function caseLink(?int $caseId): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) {
            $host = 'localhost';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $path = $caseId ? 'case.php?id=' . $caseId : '';
        return $scheme . '://' . $host . url($path);
    }

    public static function user(PDO $pdo, int $userId, ?int $caseId, string $message, bool $email = true): void
    {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, case_id, message, is_read, created_at) VALUES (?, ?, ?, 0, ?)'
        )->execute([$userId, $caseId, mb_substr($message, 0, 255), now()]);

        if ($email) {
            $st = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ? AND is_active = 1');
            $st->execute([$userId]);
            if ($u = $st->fetch()) {
                $body = "Hello {$u['full_name']},\n\n{$message}\n\n"
                      . ($caseId ? 'Open the case: ' . self::caseLink($caseId) . "\n\n" : '')
                      . config('app.name');
                Mailer::queue($pdo, $u['email'], '[' . config('app.name') . '] ' . mb_substr($message, 0, 100), $body);
            }
        }
    }

    public static function admins(PDO $pdo, ?int $caseId, string $message): void
    {
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            self::user($pdo, (int)$id, $caseId, $message);
        }
    }

    /** Alerts every active officer at one level, for example the top level when a case turns critical. */
    public static function level(PDO $pdo, int $level, ?int $caseId, string $message, ?int $skipUserId = null): void
    {
        $st = $pdo->prepare("SELECT id FROM users WHERE role = 'officer' AND is_active = 1 AND officer_level = ?");
        $st->execute([$level]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($skipUserId !== null && (int)$id === $skipUserId) {
                continue;
            }
            self::user($pdo, (int)$id, $caseId, $message);
        }
    }
}
