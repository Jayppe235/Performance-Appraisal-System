<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/report_generator.php';

$user = current_user();
if ($user === null) {
    redirect('/login.php');
}

if (($user['role'] ?? '') === 'teacher') {
    if (admin_setting('teacher_results_released', '1') !== '1') {
        http_response_code(403);
        echo 'Personal evaluation results are not released by HR yet.';
        exit;
    }

    $faculty = admin_one(
        'SELECT f.* FROM faculty f JOIN users u ON u.id = f.user_id WHERE u.id = :user_id LIMIT 1',
        ['user_id' => (int) $user['id']]
    );

    if (!$faculty) {
        http_response_code(404);
        echo 'No linked faculty record found for this teacher account.';
        exit;
    }

    $rows = admin_all(
        'SELECT p.cycle_name, p.assignment_type,
                ROUND((es.communication_score + es.teaching_score + es.classroom_management_score + es.job_knowledge_score) / 4, 2) AS average_score,
                es.communication_score, es.teaching_score, es.classroom_management_score, es.job_knowledge_score,
                es.behavioral_evidence, es.overall_comments, es.submitted_at
         FROM evaluation_submissions es
         JOIN peer_assignments p ON p.id = es.assignment_id
         WHERE es.evaluatee_faculty_id = :faculty_id
           AND COALESCE(p.is_archived, 0) = 0
         ORDER BY es.submitted_at DESC',
        ['faculty_id' => (int) $faculty['id']]
    );

    $format = $_GET['format'] ?? 'csv';
    $headers = ['Faculty', 'Department', 'Cycle', 'Evaluation Type', 'Average', 'Communication', 'Teaching', 'Classroom Management', 'Job Commitment', 'Behavioral Evidence', 'Comments', 'Submitted At'];
    $data = [];

    foreach ($rows as $row) {
        $data[] = [
            $faculty['full_name'],
            $faculty['department'],
            $row['cycle_name'],
            admin_status_label($row['assignment_type']),
            $row['average_score'],
            $row['communication_score'],
            $row['teaching_score'],
            $row['classroom_management_score'],
            $row['job_knowledge_score'],
            secure_decrypt_value($row['behavioral_evidence'] ?? ''),
            secure_decrypt_value($row['overall_comments'] ?? ''),
            $row['submitted_at'],
        ];
    }

    $generator = new ReportGenerator(
        'DIPASCAF Personal Evaluation Report',
        'Personal Evaluation Report',
        $headers,
        $data
    );

    if ($format === 'pdf') {
        $generator->exportPdf();
    } elseif ($format === 'excel') {
        $generator->exportExcel();
    } else {
        $generator->exportCsv();
    }
}

require_role('admin_hr');
admin_ensure_archive_schema();

$format = $_GET['format'] ?? 'csv';
$reportType = $_GET['report_type'] ?? 'complete_export';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$facultyId = (int) ($_GET['faculty_id'] ?? 0);
$evaluationType = trim($_GET['evaluation_type'] ?? '');
$evaluationForm = trim($_GET['evaluation_form'] ?? '');
if (!in_array($evaluationForm, ['', 'form_a', 'form_b', 'self'], true)) {
    $evaluationForm = '';
}
$periodId = (int) ($_GET['period_id'] ?? 0);

function admin_report_result_subquery(string $evaluationForm = ''): string
{
    $parts = [];
    if ($evaluationForm === '' || $evaluationForm === 'form_a') {
        $parts[] = "
            SELECT assignment_id, average_rating, weighted_score, submitted_at
            FROM pmas_form_a_category_results
            WHERE COALESCE(is_archived, 0) = 0
              AND status = 'completed'";
    }
    if ($evaluationForm === '' || $evaluationForm === 'form_b') {
        $parts[] = "
            SELECT assignment_id, average_rating, weighted_score, submitted_at
            FROM pmas_form_b_category_results
            WHERE COALESCE(is_archived, 0) = 0
              AND status = 'completed'";
    }
    if ($evaluationForm === '' || $evaluationForm === 'self') {
        $parts[] = "
            SELECT assignment_id, overall_rating AS average_rating, overall_rating AS weighted_score, submitted_at
            FROM pmas_self_evaluations
            WHERE status IN ('submitted', 'approved')";
    }
    $unionSql = implode(' UNION ALL ', $parts);

    return "
        SELECT assignment_id,
               ROUND(AVG(average_rating), 2) AS average_rating,
               ROUND(SUM(weighted_score), 2) AS weighted_score,
               MAX(submitted_at) AS submitted_at
        FROM (
            {$unionSql}
        ) report_results
        GROUP BY assignment_id
    ";
}

function admin_report_assignment_filters(int $facultyId, string $evaluationType, string $evaluationForm, string $dateFrom, string $dateTo, int $periodId = 0): array
{
    $where = [];
    $params = [];

    $where[] = 'COALESCE(p.is_archived, 0) = 0';

    if ($dateFrom !== '') {
        $where[] = 'p.deadline >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'p.deadline <= :date_to';
        $params['date_to'] = $dateTo;
    }

    if ($facultyId > 0) {
        $where[] = 'p.evaluatee_faculty_id = :faculty_id';
        $params['faculty_id'] = $facultyId;
    }

    if ($evaluationType !== '') {
        $where[] = 'p.assignment_type = :evaluation_type';
        $params['evaluation_type'] = $evaluationType;
    }
    if ($evaluationForm === 'form_a') {
        $where[] = "p.questionnaire_type = 'admin' AND p.assignment_type <> 'self'";
    } elseif ($evaluationForm === 'form_b') {
        $where[] = "p.questionnaire_type = 'faculty' AND p.assignment_type <> 'self'";
    } elseif ($evaluationForm === 'self') {
        $where[] = "p.assignment_type = 'self'";
    }

    if ($periodId > 0) {
        $where[] = 'p.cycle_name = (SELECT period_name FROM appraisal_periods WHERE id = :period_id LIMIT 1)';
        $params['period_id'] = $periodId;
    }

    return [$where, $params];
}

function admin_report_payload(string $reportType, int $facultyId, string $evaluationType, string $evaluationForm, string $dateFrom, string $dateTo, int $periodId = 0): array
{
    [$where, $params] = admin_report_assignment_filters($facultyId, $evaluationType, $evaluationForm, $dateFrom, $dateTo, $periodId);
    $assignmentWhere = implode(' AND ', $where);
    $resultSubquery = admin_report_result_subquery($evaluationForm);

    if ($reportType === 'faculty_performance') {
        $rows = admin_all(
            "SELECT f.full_name, f.department, f.position_title, f.progress_percent,
                    COUNT(DISTINCT p.id) AS total_evaluations,
                    SUM(CASE WHEN p.status = 'submitted' THEN 1 ELSE 0 END) AS completed_evaluations,
                    SUM(CASE WHEN p.status <> 'submitted' THEN 1 ELSE 0 END) AS pending_evaluations,
                    ROUND(AVG(r.average_rating), 2) AS average_score,
                    MAX(COALESCE(p.submitted_at, r.submitted_at, p.assigned_at)) AS latest_update
             FROM faculty f
             JOIN peer_assignments p ON p.evaluatee_faculty_id = f.id
             LEFT JOIN ({$resultSubquery}) r ON r.assignment_id = p.id
             WHERE COALESCE(f.is_archived, 0) = 0
               AND {$assignmentWhere}
             GROUP BY f.id, f.full_name, f.department, f.position_title, f.progress_percent
             ORDER BY f.department, f.full_name",
            $params
        );

        return [
            'title' => 'DIPASCAF Faculty Performance Report',
            'slug' => 'faculty-performance',
            'headers' => ['Faculty', 'Department', 'Position', 'Progress %', 'Total Evaluations', 'Completed', 'Pending', 'Average Score', 'Latest Update'],
            'rows' => array_map(static fn (array $row): array => [
                $row['full_name'],
                $row['department'],
                $row['position_title'],
                $row['progress_percent'],
                $row['total_evaluations'],
                $row['completed_evaluations'],
                $row['pending_evaluations'],
                $row['average_score'] ?? 'N/A',
                $row['latest_update'] ?? '',
            ], $rows),
        ];
    }

    if ($reportType === 'department_summary') {
        $rows = admin_all(
            "SELECT f.department,
                    COUNT(DISTINCT f.id) AS faculty_count,
                    ROUND(AVG(f.progress_percent), 2) AS average_progress,
                    COUNT(DISTINCT p.id) AS total_evaluations,
                    SUM(CASE WHEN p.status = 'submitted' THEN 1 ELSE 0 END) AS completed_evaluations,
                    SUM(CASE WHEN p.status <> 'submitted' THEN 1 ELSE 0 END) AS pending_evaluations,
                    SUM(CASE WHEN p.status <> 'submitted' AND p.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue_evaluations
             FROM faculty f
             JOIN peer_assignments p ON p.evaluatee_faculty_id = f.id
             WHERE COALESCE(f.is_archived, 0) = 0
               AND {$assignmentWhere}
             GROUP BY f.department
             ORDER BY f.department",
            $params
        );

        return [
            'title' => 'DIPASCAF Department Summary Report',
            'slug' => 'department-summary',
            'headers' => ['Department', 'Faculty Count', 'Average Progress %', 'Total Evaluations', 'Completed', 'Pending', 'Overdue'],
            'rows' => array_map(static fn (array $row): array => [
                $row['department'],
                $row['faculty_count'],
                $row['average_progress'],
                $row['total_evaluations'],
                $row['completed_evaluations'],
                $row['pending_evaluations'],
                $row['overdue_evaluations'],
            ], $rows),
        ];
    }

    if ($reportType === 'peer_assignments') {
        $rows = admin_all(
            "SELECT p.cycle_name, u.full_name AS evaluator_name, u.role AS evaluator_role,
                    f.full_name AS evaluatee_name, f.department, f.position_title,
                    p.assignment_type, p.status, p.assigned_at, p.submitted_at
             FROM peer_assignments p
             JOIN users u ON u.id = p.evaluator_user_id
             JOIN faculty f ON f.id = p.evaluatee_faculty_id
             WHERE {$assignmentWhere}
               AND COALESCE(f.is_archived, 0) = 0
             ORDER BY p.cycle_name DESC, f.department, evaluator_name",
            $params
        );

        return [
            'title' => 'DIPASCAF Peer Assignment Report',
            'slug' => 'peer-assignments',
            'headers' => ['Cycle', 'Evaluator', 'Evaluator Role', 'Evaluatee', 'Department', 'Position', 'Assignment Type', 'Status', 'Assigned At', 'Submitted At', 'Confidential Notes'],
            'rows' => array_map(static fn (array $row): array => [
                $row['cycle_name'],
                $row['evaluator_name'],
                admin_status_label($row['evaluator_role']),
                $row['evaluatee_name'],
                $row['department'],
                $row['position_title'],
                admin_status_label($row['assignment_type']),
                admin_status_label($row['status']),
                $row['assigned_at'],
                $row['submitted_at'] ?? '',
                $row['assignment_type'] === 'peer' ? 'Peer comments and scores remain confidential.' : '',
            ], $rows),
        ];
    }

    if ($reportType === 'ai_training') {
        $rows = admin_all(
            'SELECT f.full_name, f.department, f.position_title,
                    ai.weak_area, ai.strength_area, ai.analysis_summary, ai.confidence_score,
                    ip.recommendation, ip.action_type, ip.status, ip.target_date
             FROM faculty f
             LEFT JOIN ai_insights ai ON ai.faculty_id = f.id
             LEFT JOIN intervention_plans ip ON ip.faculty_id = f.id
             WHERE COALESCE(f.is_archived, 0) = 0
             ORDER BY f.department, f.full_name, ip.target_date'
        );

        return [
            'title' => 'DIPASCAF AI Insights and Training Report',
            'slug' => 'ai-training',
            'headers' => ['Faculty', 'Department', 'Position', 'Weak Area', 'Strength Area', 'AI Analysis', 'Confidence %', 'Recommended Training', 'Action Type', 'Plan Status', 'Target Date'],
            'rows' => array_map(static fn (array $row): array => [
                $row['full_name'],
                $row['department'],
                $row['position_title'],
                $row['weak_area'] ?? '',
                $row['strength_area'] ?? '',
                $row['analysis_summary'] ?? '',
                $row['confidence_score'] ?? '',
                $row['recommendation'] ?? '',
                admin_status_label($row['action_type'] ?? ''),
                admin_status_label($row['status'] ?? ''),
                $row['target_date'] ?? '',
            ], $rows),
        ];
    }

    $sql = "SELECT p.cycle_name, p.assignment_type, p.evaluator_role, p.status, p.deadline,
                   p.assigned_at, p.submitted_at, f.full_name AS faculty_name, f.department,
                   u.full_name AS evaluator_name, ROUND(r.average_rating, 2) AS score,
                   ROUND(r.weighted_score, 2) AS weighted_score
            FROM peer_assignments p
            JOIN faculty f ON f.id = p.evaluatee_faculty_id
            JOIN users u ON u.id = p.evaluator_user_id
            LEFT JOIN ({$resultSubquery}) r ON r.assignment_id = p.id
            WHERE {$assignmentWhere}
              AND COALESCE(f.is_archived, 0) = 0
            ORDER BY p.deadline DESC, p.cycle_name DESC, f.department, f.full_name";
    $rows = admin_all($sql, $params);

    $title = $reportType === 'evaluation_status'
        ? 'DIPASCAF Evaluation Status Report'
        : 'DIPASCAF Complete Evaluation Export';
    $slug = $reportType === 'evaluation_status' ? 'evaluation-status' : 'complete-evaluation-export';

    return [
        'title' => $title,
        'slug' => $slug,
        'headers' => ['Faculty', 'Department', 'Cycle', 'Evaluator', 'Evaluator Role', 'Type', 'Status', 'Average Score', 'Weighted Score', 'Deadline', 'Assigned At', 'Submitted At'],
        'rows' => array_map(static fn (array $row): array => [
            $row['faculty_name'],
            $row['department'],
            $row['cycle_name'],
            $row['evaluator_name'],
            admin_status_label($row['evaluator_role']),
            admin_status_label($row['assignment_type']),
            admin_status_label($row['status']),
            $row['score'] ?? 'N/A',
            $row['weighted_score'] ?? 'N/A',
            $row['deadline'],
            $row['assigned_at'],
            $row['submitted_at'] ?? '',
        ], $rows),
    ];
}

$payload = admin_report_payload($reportType, $facultyId, $evaluationType, $evaluationForm, $dateFrom, $dateTo, $periodId);
$rows = $payload['rows'];
$headers = $payload['headers'];
$title = $payload['title'];

$generator = new ReportGenerator($title, $payload['slug'], $headers, $rows);

if ($format === 'pdf') {
    $generator->exportPdf();
} elseif ($format === 'excel') {
    $generator->exportExcel();
} else {
    $generator->exportCsv();
}
