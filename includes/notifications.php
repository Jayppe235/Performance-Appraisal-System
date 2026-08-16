<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_data.php';

function notify_log(string $message): void
{
    error_log('[APPRAISIA Notifications] ' . $message);
}

function notify_delivery_result(bool $ok, ?int $id = null, string $status = 'created', string $error = ''): array
{
    return ['ok' => $ok, 'notification_id' => $id, 'status' => $status, 'error' => $error];
}

function notify_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (db()->inTransaction()) {
            return;
        }

        if (admin_one("SHOW TABLES LIKE 'notifications'") === null) {
            db()->exec(
                "CREATE TABLE notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NULL,
                    recipient_id INT NULL,
                    recipient_role VARCHAR(50) NULL,
                    sender_id INT NULL,
                    type VARCHAR(40) NOT NULL DEFAULT 'info',
                    title VARCHAR(255) NOT NULL,
                    description TEXT NULL,
                    message TEXT NULL,
                    link VARCHAR(500) NULL,
                    action_url VARCHAR(500) NULL,
                    module VARCHAR(80) NULL,
                    related_entity_type VARCHAR(80) NULL,
                    related_entity_id INT NULL,
                    related_record_id INT NULL,
                    event_key VARCHAR(191) NULL,
                    event_payload JSON NULL,
                    delivery_status VARCHAR(30) NOT NULL DEFAULT 'created',
                    delivery_error TEXT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    read_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notifications_recipient_read (recipient_id, is_read),
                    INDEX idx_notifications_user_read (user_id, is_read),
                    INDEX idx_notifications_module_record (module, related_record_id),
                    INDEX idx_notifications_event_key (event_key),
                    INDEX idx_notifications_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
            return;
        }

        $columns = [];
        foreach (admin_all('SHOW COLUMNS FROM notifications') as $column) {
            $columns[(string) ($column['Field'] ?? '')] = (string) ($column['Type'] ?? '');
        }

        if (isset($columns['type']) && str_starts_with(strtolower($columns['type']), 'enum(')) {
            db()->exec("ALTER TABLE notifications MODIFY type VARCHAR(40) NOT NULL DEFAULT 'info'");
        }

        $additions = [
            'recipient_id' => 'ALTER TABLE notifications ADD COLUMN recipient_id INT NULL AFTER user_id',
            'recipient_role' => 'ALTER TABLE notifications ADD COLUMN recipient_role VARCHAR(50) NULL AFTER recipient_id',
            'sender_id' => 'ALTER TABLE notifications ADD COLUMN sender_id INT NULL AFTER recipient_role',
            'message' => 'ALTER TABLE notifications ADD COLUMN message TEXT NULL AFTER description',
            'action_url' => 'ALTER TABLE notifications ADD COLUMN action_url VARCHAR(500) NULL AFTER link',
            'module' => 'ALTER TABLE notifications ADD COLUMN module VARCHAR(80) NULL AFTER action_url',
            'related_record_id' => 'ALTER TABLE notifications ADD COLUMN related_record_id INT NULL AFTER related_entity_id',
            'read_at' => 'ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read',
            'event_key' => 'ALTER TABLE notifications ADD COLUMN event_key VARCHAR(191) NULL AFTER related_record_id',
            'event_payload' => 'ALTER TABLE notifications ADD COLUMN event_payload JSON NULL AFTER event_key',
            'delivery_status' => "ALTER TABLE notifications ADD COLUMN delivery_status VARCHAR(30) NOT NULL DEFAULT 'created' AFTER event_payload",
            'delivery_error' => 'ALTER TABLE notifications ADD COLUMN delivery_error TEXT NULL AFTER delivery_status',
        ];

        foreach ($additions as $name => $sql) {
            if (!array_key_exists($name, $columns)) {
                db()->exec($sql);
            }
        }

        db()->exec('UPDATE notifications SET recipient_id = user_id WHERE recipient_id IS NULL AND user_id IS NOT NULL');
        db()->exec('UPDATE notifications SET message = description WHERE message IS NULL AND description IS NOT NULL');
        db()->exec('UPDATE notifications SET action_url = link WHERE action_url IS NULL AND link IS NOT NULL');
        db()->exec('UPDATE notifications SET module = related_entity_type WHERE module IS NULL AND related_entity_type IS NOT NULL');
        db()->exec('UPDATE notifications SET related_record_id = related_entity_id WHERE related_record_id IS NULL AND related_entity_id IS NOT NULL');
        try {
            db()->exec('CREATE INDEX idx_notifications_event_key ON notifications (event_key)');
        } catch (Throwable) {
            // Index already exists.
        }
    } catch (Throwable $exception) {
        notify_log('Schema check failed: ' . $exception->getMessage());
    }
}

function notify_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    $aliases = [
        'system_update' => 'system',
        'account_activity' => 'info',
        'account' => 'info',
        'review' => 'approval',
        'returned' => 'revision',
    ];
    $type = $aliases[$type] ?? $type;
    $allowed = ['info', 'success', 'warning', 'error', 'approval', 'revision', 'evaluation', 'report', 'system'];
    return in_array($type, $allowed, true) ? $type : 'info';
}

function notify_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'admin', 'hrdm', 'hrdm_director' => 'admin_hr',
        'faculty', 'faculty_member' => 'teacher',
        default => $role,
    };
}

function notify_current_sender_id(): ?int
{
    $user = current_user();
    return $user !== null ? (int) ($user['id'] ?? 0) ?: null : null;
}

function notify_user_row(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    return admin_one(
        'SELECT id, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $userId]
    );
}

function notify_send(array $payload): ?int
{
    try {
        notify_ensure_schema();

        $recipientId = (int) ($payload['recipient_id'] ?? $payload['user_id'] ?? 0);
        $systemWide = $recipientId <= 0 && (bool) ($payload['system_wide'] ?? false);
        $recipient = $systemWide ? null : notify_user_row($recipientId);

        if (!$systemWide && $recipient === null) {
            return null;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $message = trim((string) ($payload['message'] ?? $payload['description'] ?? ''));
        if ($title === '' || $message === '') {
            return null;
        }

        $type = notify_normalize_type((string) ($payload['type'] ?? 'info'));
        $module = trim((string) ($payload['module'] ?? $payload['related_entity_type'] ?? 'system'));
        $relatedType = trim((string) ($payload['related_entity_type'] ?? $module));
        $relatedRecordId = (int) ($payload['related_record_id'] ?? $payload['related_entity_id'] ?? 0);
        $relatedRecordId = $relatedRecordId > 0 ? $relatedRecordId : null;
        $actionUrl = trim((string) ($payload['action_url'] ?? $payload['link'] ?? ''));
        $actionUrl = $actionUrl !== '' ? $actionUrl : null;
        $senderId = isset($payload['sender_id']) ? (int) $payload['sender_id'] : notify_current_sender_id();
        $senderId = $senderId > 0 ? $senderId : null;
        $dedupe = (bool) ($payload['dedupe'] ?? true);
        $eventKey = trim((string) ($payload['event_key'] ?? ''));
        $eventKey = $eventKey !== '' ? substr($eventKey, 0, 191) : null;
        $eventPayload = $payload['event_payload'] ?? null;
        $eventPayloadJson = $eventPayload === null ? null : json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($eventKey !== null) {
            $existingEvent = admin_one(
                'SELECT id FROM notifications WHERE event_key = :event_key AND (recipient_id = :recipient_id OR user_id = :user_id) LIMIT 1',
                ['event_key' => $eventKey, 'recipient_id' => $recipientId, 'user_id' => $recipientId]
            );
            if ($existingEvent !== null) {
                return (int) $existingEvent['id'];
            }
        }

        if ($dedupe) {
            $params = [
                'type' => $type,
                'title' => $title,
                'module' => $module,
                'record_id' => $relatedRecordId ?? 0,
            ];
            $recipientWhere = $systemWide
                ? 'recipient_id IS NULL AND user_id IS NULL'
                : '(recipient_id = :recipient_id OR user_id = :legacy_user_id)';
            if (!$systemWide) {
                $params['recipient_id'] = $recipientId;
                $params['legacy_user_id'] = $recipientId;
            }

            $existing = admin_one(
                "SELECT id FROM notifications
                 WHERE {$recipientWhere}
                   AND type = :type
                   AND title = :title
                   AND COALESCE(module, '') = :module
                   AND COALESCE(related_record_id, related_entity_id, 0) = :record_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 LIMIT 1",
                $params
            );
            if ($existing !== null) {
                return (int) $existing['id'];
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO notifications
                (user_id, recipient_id, recipient_role, sender_id, type, title, description, message, link, action_url, module, related_entity_type, related_entity_id, related_record_id, event_key, event_payload, delivery_status, is_read)
             VALUES
                (:user_id, :recipient_id, :recipient_role, :sender_id, :type, :title, :description, :message, :link, :action_url, :module, :related_entity_type, :related_entity_id, :related_record_id, :event_key, :event_payload, :delivery_status, 0)'
        );
        $stmt->execute([
            'user_id' => $systemWide ? null : $recipientId,
            'recipient_id' => $systemWide ? null : $recipientId,
            'recipient_role' => $systemWide ? 'system' : (string) ($recipient['role'] ?? $payload['recipient_role'] ?? ''),
            'sender_id' => $senderId,
            'type' => $type,
            'title' => $title,
            'description' => $message,
            'message' => $message,
            'link' => $actionUrl,
            'action_url' => $actionUrl,
            'module' => $module,
            'related_entity_type' => $relatedType !== '' ? $relatedType : null,
            'related_entity_id' => $relatedRecordId,
            'related_record_id' => $relatedRecordId,
            'event_key' => $eventKey,
            'event_payload' => $eventPayloadJson,
            'delivery_status' => 'created',
        ]);

        return (int) db()->lastInsertId();
    } catch (Throwable $exception) {
        notify_log('Creation failed: ' . $exception->getMessage());
        return null;
    }
}

function notify_send_with_result(array $payload): array
{
    $id = notify_send($payload);
    if ($id === null) {
        $recipientId = (int) ($payload['recipient_id'] ?? $payload['user_id'] ?? 0);
        $message = 'Notification was not created for recipient ' . $recipientId . '.';
        notify_log($message . ' Event: ' . (string) ($payload['event_key'] ?? 'unspecified'));
        return notify_delivery_result(false, null, 'failed', $message);
    }
    return notify_delivery_result(true, $id);
}

function notify_create(
    ?int $userId,
    string $type,
    string $title,
    string $description,
    ?string $link = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    notify_send([
        'recipient_id' => $userId,
        'system_wide' => $userId === null,
        'type' => $type,
        'title' => $title,
        'message' => $description,
        'action_url' => $link,
        'module' => $entityType ?? 'system',
        'related_entity_type' => $entityType,
        'related_record_id' => $entityId,
    ]);
}

function notify_many(array $userIds, string $type, string $title, string $description, ?string $link = null, ?string $module = null, ?int $recordId = null): int
{
    $sent = 0;
    foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
        if ($userId <= 0) {
            continue;
        }
        if (notify_send([
            'recipient_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $description,
            'action_url' => $link,
            'module' => $module ?? 'system',
            'related_record_id' => $recordId,
        ]) !== null) {
            $sent++;
        }
    }
    return $sent;
}

function notify_system(string $title, string $description, ?string $link = null): void
{
    $rows = admin_all('SELECT id FROM users WHERE is_active = 1');
    notify_many(array_column($rows, 'id'), 'system', $title, $description, $link, 'system');
}

function notify_role(string $role, string $type, string $title, string $description, ?string $link = null, ?string $module = null, ?int $recordId = null): int
{
    $normalizedRole = notify_normalize_role($role);
    $rows = admin_all(
        'SELECT id FROM users WHERE role = :role AND is_active = 1',
        ['role' => $normalizedRole]
    );
    return notify_many(array_column($rows, 'id'), $type, $title, $description, $link, $module, $recordId);
}

function notify_department(string $department, string $type, string $title, string $description, ?string $link = null, ?string $module = null, ?int $recordId = null): int
{
    try {
        notify_ensure_schema();
        $aliases = admin_matching_department_aliases($department);
        if ($aliases === []) {
            return 0;
        }

        $params = [];
        $placeholders = [];
        foreach ($aliases as $i => $alias) {
            $key = 'department_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $alias;
        }

        $rows = admin_all(
            'SELECT id FROM users WHERE is_active = 1 AND department IN (' . implode(',', $placeholders) . ')',
            $params
        );
        return notify_many(array_column($rows, 'id'), $type, $title, $description, $link, $module, $recordId);
    } catch (Throwable $exception) {
        notify_log('Department notification failed: ' . $exception->getMessage());
        return 0;
    }
}

function notify_program(string $program, string $type, string $title, string $description, ?string $link = null, ?string $module = null, ?int $recordId = null): int
{
    $program = trim($program);
    if ($program === '') {
        return 0;
    }
    $rows = admin_all(
        'SELECT id FROM users WHERE is_active = 1 AND program = :program',
        ['program' => $program]
    );
    return notify_many(array_column($rows, 'id'), $type, $title, $description, $link, $module, $recordId);
}

function notify_fetch(int $userId, int $limit = 20, bool $unreadOnly = false, array $filters = []): array
{
    notify_ensure_schema();
    $params = ['recipient_user_id' => $userId, 'legacy_user_id' => $userId];
    $where = ['(n.recipient_id = :recipient_user_id OR n.user_id = :legacy_user_id)'];

    if ($unreadOnly) {
        $where[] = 'n.is_read = 0';
    }

    $filter = strtolower(trim((string) ($filters['filter'] ?? $filters['tab'] ?? 'all')));
    if ($filter === 'unread') {
        $where[] = 'n.is_read = 0';
    } elseif ($filter === 'evaluations') {
        $where[] = "(n.type = 'evaluation' OR n.module IN ('evaluation', 'evaluation_period', 'self_evaluation', 'peer_assignment'))";
    } elseif ($filter === 'approvals') {
        $where[] = "(n.type = 'approval' OR n.type = 'revision')";
    } elseif ($filter === 'reports') {
        $where[] = "(n.type = 'report' OR n.module IN ('report', 'reports', 'ai_insights', 'evaluation_summary'))";
    } elseif ($filter === 'system') {
        $where[] = "(n.type = 'system' OR n.module = 'system')";
    }

    return admin_all(
        'SELECT n.*
         FROM notifications n
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ' . max(1, min(100, $limit)),
        $params
    );
}

function notify_mark_read(int $notificationId, int $userId): bool
{
    try {
        notify_ensure_schema();
        $stmt = db()->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE id = :id
               AND (recipient_id = :recipient_user_id OR user_id = :legacy_user_id)'
        );
        $stmt->execute(['id' => $notificationId, 'recipient_user_id' => $userId, 'legacy_user_id' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $exception) {
        notify_log('Mark read failed: ' . $exception->getMessage());
        return false;
    }
}

function notify_mark_all_read(int $userId): bool
{
    try {
        notify_ensure_schema();
        $stmt = db()->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE (recipient_id = :recipient_user_id OR user_id = :legacy_user_id)
               AND is_read = 0'
        );
        $stmt->execute(['recipient_user_id' => $userId, 'legacy_user_id' => $userId]);
        return true;
    } catch (Throwable $exception) {
        notify_log('Mark all read failed: ' . $exception->getMessage());
        return false;
    }
}

function notify_delete(int $notificationId, int $userId): bool
{
    try {
        notify_ensure_schema();
        $stmt = db()->prepare(
            'DELETE FROM notifications
             WHERE id = :id
               AND (recipient_id = :recipient_user_id OR user_id = :legacy_user_id)'
        );
        $stmt->execute(['id' => $notificationId, 'recipient_user_id' => $userId, 'legacy_user_id' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $exception) {
        notify_log('Delete failed: ' . $exception->getMessage());
        return false;
    }
}

function notify_unread_count(int $userId): int
{
    notify_ensure_schema();
    return admin_count(
        'SELECT COUNT(*) FROM notifications
         WHERE (recipient_id = :recipient_user_id OR user_id = :legacy_user_id)
           AND is_read = 0',
        ['recipient_user_id' => $userId, 'legacy_user_id' => $userId]
    );
}

function notify_format(array $notification): array
{
    $createdAt = (string) ($notification['created_at'] ?? '');
    $relativeTime = '';

    if ($createdAt !== '') {
        $timestamp = strtotime($createdAt);
        if ($timestamp !== false) {
            $diff = max(0, time() - $timestamp);
            $relativeTime = match (true) {
                $diff < 60 => 'Just now',
                $diff < 3600 => floor($diff / 60) . 'm ago',
                $diff < 86400 => floor($diff / 3600) . 'h ago',
                $diff < 604800 => floor($diff / 86400) . 'd ago',
                default => date('M j, Y', $timestamp),
            };
        }
    }

    $message = (string) ($notification['message'] ?? $notification['description'] ?? '');
    $actionUrl = (string) ($notification['action_url'] ?? $notification['link'] ?? '');
    $relatedRecordId = $notification['related_record_id'] ?? $notification['related_entity_id'] ?? null;

    return [
        'id' => (int) ($notification['id'] ?? 0),
        'user_id' => isset($notification['user_id']) && $notification['user_id'] !== null ? (int) $notification['user_id'] : null,
        'recipient_id' => isset($notification['recipient_id']) && $notification['recipient_id'] !== null ? (int) $notification['recipient_id'] : null,
        'recipient_role' => (string) ($notification['recipient_role'] ?? ''),
        'sender_id' => isset($notification['sender_id']) && $notification['sender_id'] !== null ? (int) $notification['sender_id'] : null,
        'type' => notify_normalize_type((string) ($notification['type'] ?? 'info')),
        'title' => (string) ($notification['title'] ?? ''),
        'description' => $message,
        'message' => $message,
        'link' => $actionUrl,
        'action_url' => $actionUrl,
        'module' => (string) ($notification['module'] ?? $notification['related_entity_type'] ?? ''),
        'related_entity_type' => (string) ($notification['related_entity_type'] ?? ''),
        'related_entity_id' => isset($notification['related_entity_id']) && $notification['related_entity_id'] !== null ? (int) $notification['related_entity_id'] : null,
        'related_record_id' => $relatedRecordId !== null ? (int) $relatedRecordId : null,
        'event_key' => (string) ($notification['event_key'] ?? ''),
        'delivery_status' => (string) ($notification['delivery_status'] ?? 'created'),
        'is_read' => (int) ($notification['is_read'] ?? 0) === 1,
        'read_at' => (string) ($notification['read_at'] ?? ''),
        'created_at' => $createdAt,
        'relative_time' => $relativeTime,
    ];
}
