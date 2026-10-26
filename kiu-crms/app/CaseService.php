<?php
/*
 * Everything that can happen to a case:
 *  create (with AI routing), acknowledge, respond, add a note, resolve, confirm, reopen, change category, reassign.
 * Every step is written to the case_events table, so the full history can always be shown (the audit trail).
 * Errors are thrown as RuntimeException with a message that is safe to show to the user.
 */
class CaseService
{
    /** Statuses where the case is still waiting for an officer. */
    public const OPEN = ['Submitted', 'In Progress', 'Reopened'];

    /* ------------------------------------------------------------------ helpers */

    public static function trackingCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I, so it is easy to read out
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    public static function actorLabel(array $user): string
    {
        if ($user['role'] === 'admin') {
            return $user['full_name'] . ' (Admin)';
        }
        return $user['full_name'] . ' (Level ' . (int)$user['officer_level'] . ')';
    }

    public static function fallbackCategoryId(PDO $pdo): int
    {
        $id = $pdo->query("SELECT id FROM categories WHERE name = 'General and other' AND is_active = 1 LIMIT 1")->fetchColumn();
        if (!$id) {
            $id = $pdo->query('SELECT id FROM categories WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        }
        if (!$id) {
            throw new RuntimeException('No categories are set up yet. Ask the administrator to add one.');
        }
        return (int)$id;
    }

    /** Picks the officer at a level with the fewest open cases (a simple way to share the work fairly). */
    public static function findAssignee(PDO $pdo, int $categoryId, int $level, ?int $excludeUserId = null): ?array
    {
        $sql = "SELECT u.id, u.full_name, u.email, u.officer_level,
                       (SELECT COUNT(*) FROM cases c
                         WHERE c.assigned_to = u.id AND c.status IN ('Submitted','In Progress','Reopened')) AS open_cases
                FROM users u
                WHERE u.role = 'officer' AND u.is_active = 1 AND u.officer_level = ?
                  AND (u.category_id = ? OR u.category_id IS NULL)";
        $params = [$level, $categoryId];
        if ($excludeUserId) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUserId;
        }
        // Officers who specialise in this category come before "all categories" officers.
        $sql .= ' ORDER BY (u.category_id IS NULL) ASC, open_cases ASC, u.id ASC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: null;
    }

    public static function addEvent(
        PDO $pdo, int $caseId, string $type, string $actorLabel, ?int $actorId = null,
        string $message = '', array $extra = []
    ): void {
        $pdo->prepare(
            'INSERT INTO case_events
             (case_id, event_type, actor_id, actor_label, from_level, to_level, from_user, to_user, message, is_internal, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $caseId, $type, $actorId, $actorLabel,
            $extra['from_level'] ?? null, $extra['to_level'] ?? null,
            $extra['from_user'] ?? null, $extra['to_user'] ?? null,
            $message === '' ? null : $message,
            !empty($extra['internal']) ? 1 : 0,
            now(),
        ]);
    }

    /** Runs a piece of work as one all-or-nothing unit. */
    private static function txn(PDO $pdo, callable $work)
    {
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Reads a case and locks it so the escalation engine and a person cannot change it at the same moment. */
    private static function lock(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare('SELECT * FROM cases WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $case = $st->fetch();
        if (!$case) {
            throw new RuntimeException('That case does not exist.');
        }
        return $case;
    }

    private static function assertCanAct(array $case, array $actor): void
    {
        if ($actor['role'] === 'admin') {
            return;
        }
        if ($actor['role'] === 'officer' && (int)$case['assigned_to'] === (int)$actor['id']) {
            return;
        }
        throw new RuntimeException('Only the officer handling this case, or an admin, can do that.');
    }

    private static function assertOpen(array $case): void
    {
        if (!in_array($case['status'], self::OPEN, true)) {
            throw new RuntimeException('This case is not open any more.');
        }
    }

    /* ------------------------------------------------------------------ reading */

    public static function fetch(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT c.*, cat.name AS category_name, cat.department,
                    a.full_name AS assignee_name, s.full_name AS student_name, s.email AS student_email, s.reg_no AS student_reg
             FROM cases c
             JOIN categories cat ON cat.id = c.category_id
             LEFT JOIN users a ON a.id = c.assigned_to
             LEFT JOIN users s ON s.id = c.student_id
             WHERE c.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function events(PDO $pdo, int $caseId, bool $includeInternal): array
    {
        $sql = 'SELECT e.*, fu.full_name AS from_name, tu.full_name AS to_name
                FROM case_events e
                LEFT JOIN users fu ON fu.id = e.from_user
                LEFT JOIN users tu ON tu.id = e.to_user
                WHERE e.case_id = ?';
        if (!$includeInternal) {
            $sql .= ' AND e.is_internal = 0';
        }
        $sql .= ' ORDER BY e.created_at ASC, e.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute([$caseId]);
        return $st->fetchAll();
    }

    public static function attachments(PDO $pdo, int $caseId): array
    {
        $st = $pdo->prepare('SELECT * FROM attachments WHERE case_id = ? ORDER BY id');
        $st->execute([$caseId]);
        return $st->fetchAll();
    }

    /** Who may open a case: admins, the assigned (or earlier assigned) officer, the student who owns it, or someone with the tracking code. */
    public static function canView(PDO $pdo, array $case, ?array $user): bool
    {
        if (in_array((int)$case['id'], $_SESSION['case_access'] ?? [], true)) {
            return true;
        }
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        if ($user['role'] === 'student') {
            return $case['student_id'] !== null && (int)$case['student_id'] === (int)$user['id'];
        }
        if ((int)$case['assigned_to'] === (int)$user['id']) {
            return true;
        }
        $st = $pdo->prepare('SELECT 1 FROM case_events WHERE case_id = ? AND (actor_id = ? OR from_user = ? OR to_user = ?) LIMIT 1');
        $st->execute([$case['id'], $user['id'], $user['id'], $user['id']]);
        return (bool)$st->fetchColumn();
    }

    /* ------------------------------------------------------------------ uploads */

    /** Checks an uploaded file. Returns ['none' => true] when no file was chosen. */
    public static function validateUpload(?array $file): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'none' => true];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The file could not be uploaded. Try again with a smaller file.'];
        }
        $max = (int)config('upload.max_bytes');
        if ($file['size'] > $max) {
            return ['ok' => false, 'error' => 'The file is too big. The limit is ' . round($max / 1048576) . ' MB.'];
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, config('upload.allowed'), true)) {
            return ['ok' => false, 'error' => 'Only these file types are allowed: ' . implode(', ', config('upload.allowed')) . '.'];
        }
        $expected = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (($expected[$ext] ?? '') !== $mime) {
            return ['ok' => false, 'error' => 'The file content does not match its type. Upload a real JPG, PNG or PDF.'];
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime];
    }

    private static function saveUpload(PDO $pdo, int $caseId, array $file, array $check): ?string
    {
        $stored = bin2hex(random_bytes(16)) . '.' . $check['ext'];
        if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/uploads/' . $stored)) {
            // Fallback for scripts that are not real HTTP uploads (for example tests).
            if (!@rename($file['tmp_name'], APP_ROOT . '/uploads/' . $stored)) {
                throw new RuntimeException('The file could not be saved on the server.');
            }
        }
        $pdo->prepare(
            'INSERT INTO attachments (case_id, original_name, stored_name, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$caseId, mb_substr(basename($file['name']), 0, 200), $stored, $check['mime'], (int)$file['size'], now()]);
        return $stored;
    }

    /* ------------------------------------------------------------------ create */

    /**
     * Creates a case and routes it.
     * $in: type, title, description, category_id (0 = let the AI choose), student_id (null when anonymous).
     */
    public static function create(PDO $pdo, array $in, ?array $file = null): array
    {
        $title = mb_substr(trim($in['title']), 0, 160);
        $desc  = mb_substr(trim($in['description']), 0, 4000);
        $type  = $in['type'] === 'request' ? 'request' : 'complaint';
        $text  = $title . ' ' . $desc;

        $upload = self::validateUpload($file);
        if (!$upload['ok']) {
            throw new RuntimeException($upload['error']);
        }

        // 1. The AI reads the text: which category, and is it urgent?
        $prediction = Classifier::predict(Classifier::train($pdo), $text);
        $urgent     = Classifier::urgency($text)['urgent'];
        $categoryId = (int)$in['category_id'];
        $aiPicked   = false;
        if ($categoryId === 0) {
            $aiPicked = true;
            $sure = $prediction['category_id'] !== null && $prediction['confidence'] >= (float)config('ai_min_confidence', 0.4);
            $categoryId = $sure ? (int)$prediction['category_id'] : self::fallbackCategoryId($pdo);
        }
        $cat = $pdo->prepare('SELECT name, department FROM categories WHERE id = ? AND is_active = 1');
        $cat->execute([$categoryId]);
        $category = $cat->fetch();
        if (!$category) {
            throw new RuntimeException('Please choose a valid category.');
        }

        // 2. Level 1 officer and the first deadline
        $levels   = levels();
        $level1   = $levels[1] ?? ['priority' => 'Normal', 'response_hours' => 24, 'urgent_response_hours' => 4];
        $base     = $urgent ? 'Urgent' : 'Normal';
        $priority = max_priority($base, $level1['priority']);
        $hours    = (float)($urgent ? $level1['urgent_response_hours'] : $level1['response_hours']);
        $due      = sla_add($hours);
        $assignee = self::findAssignee($pdo, $categoryId, 1);
        $code     = self::trackingCode();
        $anon     = empty($in['student_id']);
        $savedFile = null;

        $result = self::txn($pdo, function () use (
            $pdo, $in, $title, $desc, $type, $categoryId, $category, $base, $priority, $due, $assignee, $code,
            $anon, $aiPicked, $prediction, $file, $upload, $hours, &$savedFile
        ) {
            $now = now();
            $pdo->prepare(
                "INSERT INTO cases
                 (tracking_code, type, title, description, category_id, student_id, is_anonymous, status, base_priority, priority,
                  current_level, assigned_to, stage, due_at, level_entered_at, ai_confidence, ip_hash, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Submitted', ?, ?, 1, ?, 'response', ?, ?, ?, ?, ?, ?)"
            )->execute([
                $code, $type, $title, $desc, $categoryId, $anon ? null : (int)$in['student_id'], $anon ? 1 : 0,
                $base, $priority, $assignee['id'] ?? null, $due, $now,
                $aiPicked ? min(99.0, round($prediction['confidence'] * 100, 2)) : null,
                ip_hash(), $now, $now,
            ]);
            $id  = (int)$pdo->lastInsertId();
            $ref = sprintf('CRM-%s-%05d', date('Y'), $id);
            $pdo->prepare('UPDATE cases SET reference = ? WHERE id = ?')->execute([$ref, $id]);

            $label = $anon ? 'Anonymous student' : 'Student';
            self::addEvent($pdo, $id, 'SUBMITTED', $label, $anon ? null : (int)$in['student_id'],
                ucfirst($type) . ' submitted' . ($base === 'Urgent' ? ' and marked urgent because it mentions a safety or welfare risk.' : '.'));

            $how = $aiPicked
                ? 'Category chosen by the AI (' . Classifier::percent($prediction['confidence']) . '% sure).'
                : 'Category chosen by the student.';
            self::addEvent($pdo, $id, 'ROUTED', 'System', null,
                'Sent to ' . $category['department'] . '. ' . $how
                . ($assignee ? ' Handler: ' . $assignee['full_name'] . ' (Level 1).' : ' No Level 1 officer is available yet.')
                . ' Time allowed to respond: ' . sla_label($hours) . '.',
                ['to_level' => 1, 'to_user' => $assignee['id'] ?? null]);

            if ($upload['ok'] && empty($upload['none'])) {
                $savedFile = self::saveUpload($pdo, $id, $file, $upload);
            }

            // Alerts
            $limit = sla_label($hours);
            if ($assignee) {
                Notifier::user($pdo, (int)$assignee['id'], $id,
                    "New {$type} {$ref}: {$title} (priority {$priority}). Please respond within {$limit}.");
            } else {
                Notifier::admins($pdo, $id, "{$ref} has no Level 1 officer for {$category['name']}. Please assign one.");
            }
            if (!$anon) {
                Notifier::user($pdo, (int)$in['student_id'], $id,
                    "We received your {$type} {$ref}. It was sent to {$category['department']}. Expect a response within {$limit}.");
            }
            return ['id' => $id, 'reference' => $ref];
        });

        return [
            'id'            => $result['id'],
            'reference'     => $result['reference'],
            'tracking_code' => $code,
            'category'      => $category['name'],
            'department'    => $category['department'],
            'handler'       => $assignee['full_name'] ?? null,
            'due_at'        => $due,
            'urgent'        => $urgent,
            'anonymous'     => $anon,
        ];
    }

    /* ------------------------------------------------------------------ officer actions */

    private static function startResolution(PDO $pdo, array $case, string $label, ?int $actorId, bool $logEvent): void
    {
        $lv  = levels()[(int)$case['current_level']] ?? ['resolution_hours' => 72];
        $hrs = (float)$lv['resolution_hours'];
        $pdo->prepare(
            "UPDATE cases SET status = 'In Progress', stage = 'resolution', due_at = ?,
                    first_response_at = COALESCE(first_response_at, ?), updated_at = ? WHERE id = ?"
        )->execute([sla_add($hrs), now(), now(), $case['id']]);
        if ($logEvent) {
            self::addEvent($pdo, (int)$case['id'], 'ACKNOWLEDGED', $label, $actorId,
                'Case acknowledged. Time allowed to resolve it: ' . sla_label($hrs) . '.');
        }
    }

    /** The officer confirms they have seen the case and are working on it. This stops the response clock. */
    public static function acknowledge(PDO $pdo, int $id, array $actor): void
    {
        self::txn($pdo, function () use ($pdo, $id, $actor) {
            $case = self::lock($pdo, $id);
            self::assertCanAct($case, $actor);
            self::assertOpen($case);
            if ($case['stage'] !== 'response') {
                throw new RuntimeException('This case was already acknowledged.');
            }
            self::startResolution($pdo, $case, self::actorLabel($actor), (int)$actor['id'], true);
            if ($case['student_id']) {
                Notifier::user($pdo, (int)$case['student_id'], $id,
                    "{$case['reference']}: your case was acknowledged and is being handled.");
            }
        });
    }

    /** A message the student can read. It also counts as acknowledging the case. */
    public static function respond(PDO $pdo, int $id, array $actor, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Write a message first.');
        }
        self::txn($pdo, function () use ($pdo, $id, $actor, $message) {
            $case = self::lock($pdo, $id);
            self::assertCanAct($case, $actor);
            self::assertOpen($case);
            if ($case['stage'] === 'response') {
                self::startResolution($pdo, $case, self::actorLabel($actor), (int)$actor['id'], false);
            } else {
                $pdo->prepare('UPDATE cases SET updated_at = ? WHERE id = ?')->execute([now(), $id]);
            }
            self::addEvent($pdo, $id, 'RESPONDED', self::actorLabel($actor), (int)$actor['id'], mb_substr($message, 0, 3000));
            if ($case['student_id']) {
                Notifier::user($pdo, (int)$case['student_id'], $id, "{$case['reference']}: there is a new reply on your case.");
            }
        });
    }

    /** A private note for staff. It does not stop or start any clock. */
    public static function note(PDO $pdo, int $id, array $actor, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Write a note first.');
        }
        self::txn($pdo, function () use ($pdo, $id, $actor, $message) {
            $case = self::lock($pdo, $id);
            self::assertCanAct($case, $actor);
            self::addEvent($pdo, $id, 'NOTE', self::actorLabel($actor), (int)$actor['id'], mb_substr($message, 0, 3000), ['internal' => true]);
        });
    }

    public static function resolve(PDO $pdo, int $id, array $actor, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Explain what was done to resolve the case.');
        }
        self::txn($pdo, function () use ($pdo, $id, $actor, $message) {
            $case = self::lock($pdo, $id);
            self::assertCanAct($case, $actor);
            self::assertOpen($case);
            $pdo->prepare(
                "UPDATE cases SET status = 'Resolved', resolved_at = ?, due_at = NULL,
                        first_response_at = COALESCE(first_response_at, ?), updated_at = ? WHERE id = ?"
            )->execute([now(), now(), now(), $id]);
            self::addEvent($pdo, $id, 'RESOLVED', self::actorLabel($actor), (int)$actor['id'], mb_substr($message, 0, 3000));
            if ($case['student_id']) {
                Notifier::user($pdo, (int)$case['student_id'], $id,
                    "{$case['reference']}: your case was marked resolved. Please confirm, or reopen it if the problem is still there.");
            }
        });
    }

    /** Moves a wrongly routed case to the right office, and teaches the AI from the correction. */
    public static function changeCategory(PDO $pdo, int $id, array $actor, int $newCategoryId): void
    {
        self::txn($pdo, function () use ($pdo, $id, $actor, $newCategoryId) {
            $case = self::lock($pdo, $id);
            self::assertCanAct($case, $actor);
            self::assertOpen($case);
            if ((int)$case['category_id'] === $newCategoryId) {
                throw new RuntimeException('The case is already in that category.');
            }
            $st = $pdo->prepare('SELECT name, department FROM categories WHERE id = ? AND is_active = 1');
            $st->execute([$newCategoryId]);
            $cat = $st->fetch();
            if (!$cat) {
                throw new RuntimeException('Choose a valid category.');
            }
            $level = (int)$case['current_level'];
            $new = self::findAssignee($pdo, $newCategoryId, $level) ?? self::findAssignee($pdo, $newCategoryId, 1);
            if ($new) {
                $level = (int)$new['officer_level'];
            }
            $lv    = levels()[$level];
            $hours = (float)($case['base_priority'] === 'Urgent' ? $lv['urgent_response_hours'] : $lv['response_hours']);
            $pdo->prepare(
                "UPDATE cases SET category_id = ?, assigned_to = ?, current_level = ?, stage = 'response', due_at = ?,
                        level_entered_at = ?, priority = ?, updated_at = ? WHERE id = ?"
            )->execute([
                $newCategoryId, $new['id'] ?? $case['assigned_to'], $level, sla_add($hours), now(),
                max_priority($case['base_priority'], $lv['priority']), now(), $id,
            ]);
            self::addEvent($pdo, $id, 'CATEGORY_CHANGED', self::actorLabel($actor), (int)$actor['id'],
                'Moved to ' . $cat['name'] . ' (' . $cat['department'] . '). ' . ($new ? 'New handler: ' . $new['full_name'] . '.' : 'No officer found, the current handler keeps the case.')
                . ' Time allowed to respond: ' . sla_label($hours) . '.',
                ['from_user' => $case['assigned_to'], 'to_user' => $new['id'] ?? null, 'from_level' => $case['current_level'], 'to_level' => $level]);

            // Learning: the corrected example is added to the AI's training data.
            $pdo->prepare("INSERT INTO training_samples (category_id, text, source, created_at) VALUES (?, ?, 'officer', ?)")
                ->execute([$newCategoryId, mb_substr($case['title'] . '. ' . $case['description'], 0, 500), now()]);

            if ($new && (int)$new['id'] !== (int)$case['assigned_to']) {
                Notifier::user($pdo, (int)$new['id'], $id, "{$case['reference']} was moved to you ({$cat['name']}). Please respond within " . sla_label($hours) . '.');
            }
        });
    }

    /** Admin only: hand a case to a named officer. */
    public static function reassign(PDO $pdo, int $id, array $admin, int $officerId): void
    {
        if ($admin['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can reassign a case.');
        }
        self::txn($pdo, function () use ($pdo, $id, $admin, $officerId) {
            $case = self::lock($pdo, $id);
            self::assertOpen($case);
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'officer' AND is_active = 1");
            $st->execute([$officerId]);
            $officer = $st->fetch();
            if (!$officer) {
                throw new RuntimeException('Choose an active officer.');
            }
            $level = (int)$officer['officer_level'];
            $lv    = levels()[$level] ?? null;
            if (!$lv) {
                throw new RuntimeException('That officer has no valid level.');
            }
            $hours = (float)($case['base_priority'] === 'Urgent' ? $lv['urgent_response_hours'] : $lv['response_hours']);
            $pdo->prepare(
                "UPDATE cases SET assigned_to = ?, current_level = ?, stage = 'response', due_at = ?, level_entered_at = ?,
                        priority = ?, updated_at = ? WHERE id = ?"
            )->execute([$officerId, $level, sla_add($hours), now(), max_priority($case['base_priority'], $lv['priority']), now(), $id]);
            self::addEvent($pdo, $id, 'REASSIGNED', self::actorLabel($admin), (int)$admin['id'],
                'Reassigned to ' . $officer['full_name'] . ' (Level ' . $level . '). Time allowed to respond: ' . sla_label($hours) . '.',
                ['from_user' => $case['assigned_to'], 'to_user' => $officerId, 'from_level' => $case['current_level'], 'to_level' => $level]);
            Notifier::user($pdo, $officerId, $id, "{$case['reference']} was assigned to you by an admin. Please respond within " . sla_label($hours) . '.');
        });
    }

    /* ------------------------------------------------------------------ student actions */

    public static function studentComment(PDO $pdo, int $id, string $label, string $message, ?int $studentId): void
    {
        $message = trim($message);
        if ($message === '') {
            throw new RuntimeException('Write a message first.');
        }
        self::txn($pdo, function () use ($pdo, $id, $label, $message, $studentId) {
            $case = self::lock($pdo, $id);
            self::assertOpen($case);
            self::addEvent($pdo, $id, 'STUDENT_COMMENT', $label, $studentId, mb_substr($message, 0, 3000));
            $pdo->prepare('UPDATE cases SET updated_at = ? WHERE id = ?')->execute([now(), $id]);
            if ($case['assigned_to']) {
                Notifier::user($pdo, (int)$case['assigned_to'], $id, "{$case['reference']}: the student added a message.");
            }
        });
    }

    /** The student agrees the problem is solved, and rates the service from 1 to 5. */
    public static function confirm(PDO $pdo, int $id, string $label, ?int $studentId, int $rating, string $comment): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Choose a rating from 1 to 5.');
        }
        self::txn($pdo, function () use ($pdo, $id, $label, $studentId, $rating, $comment) {
            $case = self::lock($pdo, $id);
            if ($case['status'] !== 'Resolved') {
                throw new RuntimeException('Only a resolved case can be confirmed.');
            }
            $comment = mb_substr(trim($comment), 0, 255);
            $pdo->prepare(
                "UPDATE cases SET status = 'Closed', closed_at = ?, due_at = NULL, rating = ?, rating_comment = ?, updated_at = ? WHERE id = ?"
            )->execute([now(), $rating, $comment === '' ? null : $comment, now(), $id]);
            self::addEvent($pdo, $id, 'CONFIRMED', $label, $studentId,
                'The student confirmed the case is solved. Satisfaction rating: ' . $rating . ' out of 5.' . ($comment !== '' ? ' "' . $comment . '"' : ''));
            if ($case['assigned_to']) {
                Notifier::user($pdo, (int)$case['assigned_to'], $id, "{$case['reference']} was confirmed as solved (rating {$rating}/5).", false);
            }
        });
    }

    /** The student says the problem is not solved. The case goes back to the officer with a fresh clock. */
    public static function reopen(PDO $pdo, int $id, string $label, ?int $studentId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Tell us what is still wrong.');
        }
        self::txn($pdo, function () use ($pdo, $id, $label, $studentId, $reason) {
            $case = self::lock($pdo, $id);
            if ($case['status'] !== 'Resolved') {
                throw new RuntimeException('Only a resolved case can be reopened.');
            }
            $lv    = levels()[(int)$case['current_level']];
            $hours = (float)($case['base_priority'] === 'Urgent' ? $lv['urgent_response_hours'] : $lv['response_hours']);
            $pdo->prepare(
                "UPDATE cases SET status = 'Reopened', reopen_count = reopen_count + 1, stage = 'response', resolved_at = NULL,
                        due_at = ?, level_entered_at = ?, updated_at = ? WHERE id = ?"
            )->execute([sla_add($hours), now(), now(), $id]);
            self::addEvent($pdo, $id, 'REOPENED', $label, $studentId,
                mb_substr($reason, 0, 3000) . ' The case is back with the handler. Time allowed to respond: ' . sla_label($hours) . '.');
            if ($case['assigned_to']) {
                Notifier::user($pdo, (int)$case['assigned_to'], $id, "{$case['reference']} was reopened by the student. Please respond within " . sla_label($hours) . '.');
            } else {
                Notifier::admins($pdo, $id, "{$case['reference']} was reopened and has no handler.");
            }
            if ((int)$case['reopen_count'] >= 1) {
                Notifier::admins($pdo, $id, "{$case['reference']} has now been reopened more than once.");
            }
        });
    }
}
