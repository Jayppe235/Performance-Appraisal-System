<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/evaluation_assignment_generator.php';
require_once __DIR__ . '/evaluation_status_sync.php';

function dipascaf_sync_evaluation_consistency(?string $periodName = null): array
{
    $db = db();
    $periodName = trim((string) $periodName);
    $periodWhere = $periodName !== '' ? ' AND pa.cycle_name = :period_name' : '';
    $periodParams = $periodName !== '' ? ['period_name' => $periodName] : [];

    $summary = [
        'deadline_assignments_updated' => 0,
        'deadline_schedules_updated' => 0,
        'submitted_assignments_synced' => 0,
        'submitted_at_filled' => 0,
        'peer_status_synced' => 0,
        'result_periods_synced' => 0,
        'faculty_departments_synced' => 0,
        'user_departments_synced' => 0,
        'archived_invalid_assignments' => 0,
        'archived_invalid_peer_rows' => 0,
    ];

    $periods = $periodName !== ''
        ? $db->prepare('SELECT id, period_name, date_end FROM appraisal_periods WHERE period_name = :period_name')
        : $db->query('SELECT id, period_name, date_end FROM appraisal_periods');

    if ($periods instanceof PDOStatement) {
        if ($periodName !== '') {
            $periods->execute(['period_name' => $periodName]);
        }
        foreach ($periods->fetchAll() as $period) {
            $deadlineSync = dipascaf_sync_period_assignment_deadlines(
                (string) ($period['period_name'] ?? ''),
                (string) ($period['date_end'] ?? ''),
                null,
                (int) ($period['id'] ?? 0)
            );
            $summary['deadline_assignments_updated'] += (int) ($deadlineSync['assignments_updated'] ?? 0);
            $summary['deadline_schedules_updated'] += (int) ($deadlineSync['schedules_updated'] ?? 0);
        }
    }

    $summary['submitted_assignments_synced'] = dipascaf_sync_submitted_peer_assignments($periodName !== '' ? $periodName : null);

    $stmt = $db->prepare("
        UPDATE peer_assignments pa
        JOIN (
            SELECT assignment_id, MAX(submitted_at) AS submitted_at
            FROM (
                SELECT assignment_id, submitted_at
                FROM pmas_form_a_category_results
                WHERE status = 'completed' AND COALESCE(is_archived, 0) = 0
                UNION ALL
                SELECT assignment_id, submitted_at
                FROM pmas_form_b_category_results
                WHERE status = 'completed' AND COALESCE(is_archived, 0) = 0
                UNION ALL
                SELECT assignment_id, submitted_at
                FROM pmas_self_evaluations
                WHERE status IN ('submitted', 'approved')
            ) submitted_sources
            GROUP BY assignment_id
        ) submitted_rows ON submitted_rows.assignment_id = pa.id
        SET pa.submitted_at = COALESCE(pa.submitted_at, submitted_rows.submitted_at, NOW())
        WHERE COALESCE(pa.is_archived, 0) = 0
          AND pa.status = 'submitted'
          AND pa.submitted_at IS NULL{$periodWhere}
    ");
    $stmt->execute($periodParams);
    $summary['submitted_at_filled'] = $stmt->rowCount();

    $stmt = $db->prepare("
        UPDATE peer_evaluation_assignments pea
        JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
        SET pea.status = 'completed'
        WHERE COALESCE(pea.is_archived, 0) = 0
          AND COALESCE(pa.is_archived, 0) = 0
          AND pa.status = 'submitted'
          AND pea.status <> 'completed'{$periodWhere}
    ");
    $stmt->execute($periodParams);
    $summary['peer_status_synced'] += $stmt->rowCount();

    $stmt = $db->prepare("
        UPDATE peer_assignments pa
        JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
        SET pa.status = 'submitted',
            pa.submitted_at = COALESCE(pa.submitted_at, NOW())
        WHERE COALESCE(pea.is_archived, 0) = 0
          AND COALESCE(pa.is_archived, 0) = 0
          AND pea.status = 'completed'
          AND pa.status <> 'submitted'{$periodWhere}
    ");
    $stmt->execute($periodParams);
    $summary['peer_status_synced'] += $stmt->rowCount();

    foreach (['pmas_form_a_category_results', 'pmas_form_b_category_results'] as $table) {
        $stmt = $db->prepare("
            UPDATE {$table} r
            JOIN peer_assignments pa ON pa.id = r.assignment_id
            SET r.evaluation_period = pa.cycle_name
            WHERE COALESCE(r.is_archived, 0) = 0
              AND r.evaluation_period <> pa.cycle_name{$periodWhere}
        ");
        $stmt->execute($periodParams);
        $summary['result_periods_synced'] += $stmt->rowCount();
    }

    $stmt = $db->prepare("
        UPDATE faculty f
        JOIN programs p ON p.program_code = f.program_code
        JOIN departments d ON d.id = p.department_id
        SET f.department = d.department_name
        WHERE COALESCE(f.is_archived, 0) = 0
          AND COALESCE(f.program_code, '') <> ''
          AND f.department NOT IN (d.department_code, d.department_name)
    ");
    $stmt->execute();
    $summary['faculty_departments_synced'] = $stmt->rowCount();

    $stmt = $db->prepare("
        UPDATE users u
        JOIN faculty f ON f.user_id = u.id
        SET u.department = f.department,
            u.program = COALESCE(NULLIF(f.program_code, ''), u.program)
        WHERE u.is_active = 1
          AND COALESCE(f.is_archived, 0) = 0
          AND u.role IN ('teacher', 'program_head', 'dean')
          AND (COALESCE(u.department, '') <> COALESCE(f.department, '')
               OR COALESCE(u.program, '') <> COALESCE(f.program_code, ''))
    ");
    $stmt->execute();
    $summary['user_departments_synced'] = $stmt->rowCount();

    $stmt = $db->prepare("
        UPDATE peer_assignments pa
        JOIN users u ON u.id = pa.evaluator_user_id
        JOIN faculty f ON f.id = pa.evaluatee_faculty_id
        SET pa.is_archived = 1,
            pa.archived_at = COALESCE(pa.archived_at, NOW())
        WHERE COALESCE(pa.is_archived, 0) = 0
          AND (u.is_active = 0 OR COALESCE(f.is_archived, 0) = 1 OR COALESCE(f.is_active, 1) = 0){$periodWhere}
    ");
    $stmt->execute($periodParams);
    $summary['archived_invalid_assignments'] = $stmt->rowCount();

    $stmt = $db->prepare("
        UPDATE peer_evaluation_assignments pea
        LEFT JOIN peer_assignments pa ON pa.id = pea.peer_assignment_id
        LEFT JOIN users u ON u.id = pea.evaluator_id
        LEFT JOIN faculty f ON f.id = pea.evaluatee_faculty_id
        SET pea.is_archived = 1,
            pea.archived_at = COALESCE(pea.archived_at, NOW())
        WHERE COALESCE(pea.is_archived, 0) = 0
          AND (pa.id IS NULL
               OR COALESCE(pa.is_archived, 0) = 1
               OR u.is_active = 0
               OR COALESCE(f.is_archived, 0) = 1
               OR COALESCE(f.is_active, 1) = 0)
    ");
    $stmt->execute();
    $summary['archived_invalid_peer_rows'] = $stmt->rowCount();

    return $summary;
}
