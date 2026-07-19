<?php
declare(strict_types=1);

function evaluator_monitor_normalize_self_status(?array $record, ?array $assignment): string
{
    if ($record !== null) {
        $status = strtolower((string) ($record['status'] ?? 'draft'));
        if (in_array($status, ['submitted', 'reopened'], true)) return $status;
        return !empty($record['updated_at']) ? 'in_progress' : 'pending';
    }
    if ($assignment === null) return 'not_required';
    $deadline = trim((string) ($assignment['deadline'] ?? ''));
    if ($deadline !== '' && strtotime($deadline) < strtotime(date('Y-m-d'))) return 'overdue';
    return (string) ($assignment['status'] ?? '') === 'pending' ? 'not_started' : 'pending';
}
