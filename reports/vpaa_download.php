<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vpaa_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/report_generator.php';

require_role('vpaa');

$user = current_user();
$departments = vpaa_departments((int) $user['id']);
$selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
$periodName = $selectedPeriod !== null ? trim((string) ($selectedPeriod['period_name'] ?? '')) : '';
$periodLabel = $periodName !== '' ? $periodName : 'All periods';
$reportType = trim((string) ($_GET['report_type'] ?? 'complete_export'));
$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));

$allowedReportTypes = [
    'evaluation_status',
    'department_summary',
    'faculty_performance',
    'peer_assignments',
    'ai_training',
    'complete_export',
];
if (!in_array($reportType, $allowedReportTypes, true)) {
    $reportType = 'complete_export';
}

$assignments = vpaa_assignments($departments, $periodName);
$summary = vpaa_summary($departments);
$interventions = vpaa_interventions($departments);
$headers = ['Section', 'Department / Period', 'Faculty / Item', 'Metric', 'Value', 'Details'];
$data = [];

if (in_array($reportType, ['evaluation_status', 'department_summary', 'complete_export'], true)) {
    $data[] = [
        'Institution Summary',
        $periodLabel,
        'VPAA Scope',
        'Departments',
        (string) count($departments),
        $departments !== [] ? implode(', ', $departments) : 'No assigned departments',
    ];
    $data[] = [
        'Institution Summary',
        $periodLabel,
        'Evaluation Progress',
        'Completion Rate',
        (string) ($summary['completionRate'] ?? 0) . '%',
        'Pending: ' . (string) ($summary['pendingEvaluations'] ?? 0) .
        '; Completed: ' . (string) ($summary['completedEvaluations'] ?? 0),
    ];
}

if (in_array($reportType, ['evaluation_status', 'faculty_performance', 'peer_assignments', 'complete_export'], true)) {
    foreach ($assignments as $row) {
        $data[] = [
            'Evaluation Record',
            (string) ($row['department'] ?? 'Unassigned Department'),
            (string) ($row['faculty_name'] ?? ''),
            (string) ($row['status'] ?? 'pending'),
            ($row['average_score'] ?? null) === null ? 'N/A' : number_format((float) $row['average_score'], 2),
            'Program: ' . (string) ($row['program_code'] ?? 'Unassigned Program') .
            '; Evaluator: ' . (string) ($row['evaluator_name'] ?? 'N/A') .
            '; Type: ' . (string) ($row['assignment_type'] ?? 'evaluation') .
            '; Deadline: ' . (string) ($row['deadline'] ?? 'N/A'),
        ];
    }
}

if (in_array($reportType, ['ai_training', 'complete_export'], true)) {
    foreach ($interventions as $row) {
        $data[] = [
            'Development Plan',
            (string) ($row['department'] ?? 'Unassigned Department'),
            (string) ($row['faculty_name'] ?? ''),
            (string) ($row['weak_area'] ?? 'Development need'),
            (string) ($row['status'] ?? 'planned'),
            (string) ($row['recommended_action'] ?? $row['action_plan'] ?? $row['plan'] ?? '') .
            '; Target: ' . (string) ($row['target_date'] ?? 'N/A'),
        ];
    }
}

if ($data === []) {
    $data[] = ['Summary', $periodLabel, 'No matching records', 'Records', '0', 'No report data is available for the selected scope.'];
}

$generator = new ReportGenerator(
    'DIPASCAF VPAA Institutional Report',
    'VPAA Institutional Report',
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
