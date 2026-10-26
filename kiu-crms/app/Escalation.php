<?php
/*
 * The escalation engine. This is the heart of the system.
 *
 * Every case has a deadline (due_at). The engine looks for open cases whose deadline has passed and then:
 *   1. If the case is below the top level, it moves the case up to the next level, gives it to the officer at that
 *      level with the lightest workload, raises the priority, starts a new clock and alerts everyone involved.
 *   2. If the case is already at the top level (or nobody is available above), it marks the case Critical and alerts
 *      senior management. The clock restarts, so the alert repeats until somebody resolves the case.
 * It also closes resolved cases that the student never confirmed.
 *
 * The engine runs in two ways:
 *   - automatically, whenever somebody opens a page (at most once every few seconds, see config.php), and
 *   - from the command line with cron/escalate.php, which you can schedule every minute.
 */
class Escalation
{
    /** Runs the engine, but not more often than lazy_escalation_interval seconds. */
    public static function maybeRun(PDO $pdo): void
    {
        $interval = (int)config('lazy_escalation_interval', 30);
        if (sla_unit() !== 'hours' && $interval > 0) {
            $interval = min($interval, 5);   // demo mode: look often, so escalations appear quickly
        }
        if ($interval <= 0 || PHP_SAPI === 'cli') {
            return;
        }
        $last = state_get('last_escalation_run');
        if ($last !== null && (time() - strtotime($last)) < $interval) {
            return;
        }
        state_set('last_escalation_run', now());   // claim this turn first, so two visitors do not both run it
        self::run($pdo);
    }

    /** Checks every overdue case once. Returns a short summary. */
    public static function run(PDO $pdo): array
    {
        $summary = ['checked' => 0, 'escalated' => 0, 'alerts' => 0, 'closed' => 0, 'errors' => 0, 'ran_at' => now()];
        state_set('last_escalation_run', now());

        $st = $pdo->prepare(
            "SELECT id FROM cases
             WHERE status IN ('Submitted','In Progress','Reopened') AND due_at IS NOT NULL AND due_at <= ?
             ORDER BY due_at ASC LIMIT 200"
        );
        $st->execute([now()]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $summary['checked']++;
            try {
                $result = self::processDueCase($pdo, (int)$id);
                if ($result === 'escalated') {
                    $summary['escalated']++;
                } elseif ($result === 'alert') {
                    $summary['alerts']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                error_log('Escalation error on case ' . $id . ': ' . $e->getMessage());
            }
        }
        $summary['closed'] = self::autoClose($pdo);
        Mailer::flush($pdo);
        return $summary;
    }

    /** Handles one overdue case. Returns 'escalated', 'alert' or null (nothing to do). */
    private static function processDueCase(PDO $pdo, int $id): ?string
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM cases WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $case = $st->fetch();

            // Someone may have acted a moment ago. Check again now that the case is locked.
            if (!$case || !in_array($case['status'], CaseService::OPEN, true)
                || !$case['due_at'] || strtotime($case['due_at']) > time()) {
                $pdo->commit();
                return null;
            }

            $levels = levels();
            $max    = max_level();
            $from   = (int)$case['current_level'];
            $fromLv = $levels[$from];
            $stage  = $case['stage'];
            $limit  = (float)($stage === 'response'
                ? ($case['base_priority'] === 'Urgent' ? $fromLv['urgent_response_hours'] : $fromLv['response_hours'])
                : $fromLv['resolution_hours']);
            $limitText = sla_label($limit);
            $what      = $stage === 'response' ? 'respond' : 'resolve the case';
            $prevUser  = $case['assigned_to'] ? (int)$case['assigned_to'] : null;

            // Find the next level up that has an officer who can take this category.
            $to = null;
            $assignee = null;
            for ($lv = $from + 1; $lv <= $max; $lv++) {
                if (!isset($levels[$lv])) {
                    continue;
                }
                $found = CaseService::findAssignee($pdo, (int)$case['category_id'], $lv);
                if ($found) {
                    $to = $lv;
                    $assignee = $found;
                    break;
                }
            }

            if ($to !== null) {
                $toLv     = $levels[$to];
                $priority = max_priority($case['base_priority'], $toLv['priority']);
                $hours    = (float)($case['base_priority'] === 'Urgent' ? $toLv['urgent_response_hours'] : $toLv['response_hours']);
                $pdo->prepare(
                    "UPDATE cases SET current_level = ?, assigned_to = ?, priority = ?, stage = 'response', due_at = ?,
                            level_entered_at = ?, escalation_count = escalation_count + 1, updated_at = ? WHERE id = ?"
                )->execute([$to, $assignee['id'], $priority, sla_add($hours), now(), now(), $id]);

                CaseService::addEvent($pdo, $id, 'ESCALATED', 'System', null,
                    "Level {$from} did not {$what} within {$limitText}. Escalated automatically to Level {$to} ({$toLv['title']}): "
                    . "{$assignee['full_name']}. Priority is now {$priority}. Time allowed to respond: " . sla_label($hours) . '.',
                    ['from_level' => $from, 'to_level' => $to, 'from_user' => $prevUser, 'to_user' => (int)$assignee['id']]);

                Notifier::user($pdo, (int)$assignee['id'], $id,
                    "Escalated to you: {$case['reference']} - {$case['title']} (priority {$priority}). Please respond within " . sla_label($hours) . '.');
                if ($prevUser && $prevUser !== (int)$assignee['id']) {
                    Notifier::user($pdo, $prevUser, $id,
                        "{$case['reference']} was escalated away from you because the time limit passed.");
                }
                if ($case['student_id']) {
                    Notifier::user($pdo, (int)$case['student_id'], $id,
                        "{$case['reference']}: your case moved up to {$toLv['title']} because the time limit passed. It is now a higher priority.");
                }
                if ($to === $max) {
                    Notifier::admins($pdo, $id, "{$case['reference']} reached the top level ({$toLv['title']}).");
                }
                $pdo->commit();
                return 'escalated';
            }

            // Top level reached (or nobody above): raise a critical alert and keep repeating it.
            $repeat = (float)$fromLv['response_hours'];
            $count  = (int)$case['alert_count'] + 1;
            $pdo->prepare(
                "UPDATE cases SET priority = 'Critical', alert_count = ?, due_at = ?, updated_at = ? WHERE id = ?"
            )->execute([$count, sla_add($repeat), now(), $id]);

            $where = $from >= $max ? "The top level (Level {$from})" : "Level {$from}, with no officer available above it,";
            CaseService::addEvent($pdo, $id, 'CRITICAL_ALERT', 'System', null,
                "{$where} did not {$what} within {$limitText}. Senior management was alerted (alert {$count}). "
                . 'The alert repeats every ' . sla_label($repeat) . ' until the case is resolved.',
                ['from_level' => $from, 'to_level' => $from, 'from_user' => $prevUser]);

            $text = "CRITICAL: {$case['reference']} - {$case['title']} is still waiting at the top level (alert {$count}).";
            Notifier::admins($pdo, $id, $text);
            Notifier::level($pdo, $max, $id, $text);
            if ($prevUser) {
                $isTopOfficer = (int)($pdo->query('SELECT officer_level FROM users WHERE id = ' . $prevUser)->fetchColumn()) === $max;
                if (!$isTopOfficer) {
                    Notifier::user($pdo, $prevUser, $id, $text);
                }
            }
            if ($case['student_id'] && $count === 1) {
                Notifier::user($pdo, (int)$case['student_id'], $id,
                    "{$case['reference']}: your case has been flagged critical and senior management was alerted.");
            }
            $pdo->commit();
            return 'alert';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Closes resolved cases that the student never confirmed or reopened. */
    private static function autoClose(PDO $pdo): int
    {
        $seconds = (int)round((float)config('auto_close_hours', 168) * sla_unit_seconds());
        $cutoff  = date('Y-m-d H:i:s', time() - $seconds);
        $st = $pdo->prepare("SELECT id FROM cases WHERE status = 'Resolved' AND resolved_at IS NOT NULL AND resolved_at <= ?");
        $st->execute([$cutoff]);
        $closed = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->beginTransaction();
            try {
                $row = $pdo->prepare("SELECT status FROM cases WHERE id = ? FOR UPDATE");
                $row->execute([$id]);
                if ($row->fetchColumn() === 'Resolved') {
                    $pdo->prepare("UPDATE cases SET status = 'Closed', closed_at = ?, due_at = NULL, updated_at = ? WHERE id = ?")
                        ->execute([now(), now(), $id]);
                    CaseService::addEvent($pdo, (int)$id, 'CLOSED', 'System', null,
                        'Closed automatically because the student did not reply within ' . sla_label((float)config('auto_close_hours', 168)) . '.');
                    $closed++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Auto-close error on case ' . $id . ': ' . $e->getMessage());
            }
        }
        return $closed;
    }
}
