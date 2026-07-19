<?php

declare(strict_types=1);

function dipascaf_sync_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function dipascaf_sync_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Reconcile stale assignment statuses from immutable submitted/completed result rows.
 * This never deletes records and only promotes active pending/in-progress assignments to submitted.
 */
function dipascaf_sync_submitted_peer_assignments(?string $periodName = null): int
{
    $db = db();
    $periodName = trim((string) $periodName);
    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = :period_name' : '';
    $updated = 0;

    $sources = [
        ['table' => 'pmas_form_a_category_results', 'statuses' => ['completed']],
        ['table' => 'pmas_form_b_category_results', 'statuses' => ['completed']],
        ['table' => 'pmas_form_a_results', 'statuses' => ['completed', 'submitted']],
        ['table' => 'pmas_form_b_results', 'statuses' => ['completed', 'submitted']],
        ['table' => 'pmas_self_evaluations', 'statuses' => ['submitted', 'approved']],
    ];

    foreach ($sources as $source) {
        if (!dipascaf_sync_table_exists($db, $source['table']) || !dipascaf_sync_column_exists($db, $source['table'], 'assignment_id')) {
            continue;
        }
        $dateExpression = dipascaf_sync_column_exists($db, $source['table'], 'submitted_at') ? 'r.submitted_at' : 'NOW()';
        $statusSql = '1 = 1';
        $params = [];
        if (dipascaf_sync_column_exists($db, $source['table'], 'status')) {
            $statusPlaceholders = [];
            foreach ($source['statuses'] as $index => $status) {
                $key = 'status_' . $index;
                $statusPlaceholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $statusSql = 'r.status IN (' . implode(',', $statusPlaceholders) . ')';
        }
        if (dipascaf_sync_column_exists($db, $source['table'], 'is_archived')) {
            $statusSql .= ' AND COALESCE(r.is_archived, 0) = 0';
        }
        if ($periodName !== '') {
            $params['period_name'] = $periodName;
        }
        $sql = "
            UPDATE peer_assignments pa
            JOIN {$source['table']} r ON r.assignment_id = pa.id
            SET pa.status = 'submitted',
                pa.submitted_at = COALESCE(pa.submitted_at, {$dateExpression}, NOW())
            WHERE COALESCE(pa.is_archived, 0) = 0
              AND pa.status <> 'submitted'
              AND {$statusSql}{$periodSql}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $updated += $stmt->rowCount();
    }

    if (dipascaf_sync_table_exists($db, 'peer_evaluation_assignments')
        && dipascaf_sync_column_exists($db, 'peer_evaluation_assignments', 'peer_assignment_id')
    ) {
        $dateExpression = dipascaf_sync_column_exists($db, 'peer_evaluation_assignments', 'submitted_at') ? 'pea.submitted_at' : 'NOW()';
        $stmt = $db->prepare("
            UPDATE peer_assignments pa
            JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
            SET pa.status = 'submitted',
                pa.submitted_at = COALESCE(pa.submitted_at, {$dateExpression}, NOW())
            WHERE COALESCE(pa.is_archived, 0) = 0
              AND COALESCE(pea.is_archived, 0) = 0
              AND pa.status <> 'submitted'
              AND pea.status IN ('completed','submitted'){$periodSql}
        ");
        $stmt->execute($periodName !== '' ? ['period_name' => $periodName] : []);
        $updated += $stmt->rowCount();
    }

    return $updated;
}
