<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/dean_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/report_generator.php';

require_role('dean');

$user = current_user();
$departments = dean_departments((int) $user['id']);
$selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
$periodName = $selectedPeriod !== null ? trim((string) ($selectedPeriod['period_name'] ?? '')) : trim((string) ($_GET['period'] ?? ''));
$analytics = dean_analytics($departments, $periodName);
$reportType = trim((string) ($_GET['report_type'] ?? 'complete_export'));
$format = $_GET['format'] ?? 'csv';

$headers = ['Section', 'Program / Period', 'Faculty / Item', 'Metric', 'Value', 'Details'];
$data = [];
$periodLabel = $periodName !== '' ? $periodName : 'All periods';
$summary = $analytics['summary'] ?? [];

$data[] = ['Summary', $periodLabel, 'Department Scope', 'Departments', (string) count($departments), implode(', ', $departments)];
$data[] = ['Summary', $periodLabel, 'Programs', 'Count', (string) ($summary['program_count'] ?? 0), 'Faculty: ' . (string) ($summary['faculty_count'] ?? 0)];
$data[] = ['Summary', $periodLabel, 'Evaluation Completion', 'Completion Rate', (string) ($summary['completion_rate'] ?? 0) . '%', ($summary['submitted'] ?? 0) . '/' . ($summary['total_assignments'] ?? 0) . ' submitted'];
$data[] = ['Summary', $periodLabel, 'Average Score', 'Department Average', ($summary['average_score'] ?? null) === null ? 'N/A' : number_format((float) $summary['average_score'], 2), 'Weak category results: ' . (string) ($summary['weak_result_count'] ?? 0)];

if (in_array($reportType, ['department_summary', 'complete_export', 'evaluation_status'], true)) {
    foreach ($analytics['programPeriods'] ?? [] as $row) {
        $data[] = [
            'Program Period Matrix',
            (string) ($row['program_code'] ?? 'Unassigned Program'),
            (string) ($row['evaluation_period'] ?? ''),
            'Completion / Average',
            (string) ($row['completion_rate'] ?? 0) . '%',
            'Average: ' . (($row['average_score'] ?? null) === null ? 'N/A' : number_format((float) $row['average_score'], 2)) .
                '; Results: ' . (string) ($row['result_count'] ?? 0) .
                '; AI insights: ' . (string) ($row['insight_count'] ?? 0) .
                '; Weak areas: ' . (string) ($row['weak_area_count'] ?? 0),
        ];
    }

    foreach ($analytics['periods'] ?? [] as $row) {
        $data[] = [
            'Period Trend',
            (string) ($row['evaluation_period'] ?? ''),
            'All Programs',
            'Completion / Average',
            (string) ($row['completion_rate'] ?? 0) . '%',
            'Average: ' . (($row['average_score'] ?? null) === null ? 'N/A' : number_format((float) $row['average_score'], 2)) .
                '; Submitted: ' . (string) ($row['submitted'] ?? 0) .
                '; Pending: ' . (string) ($row['pending'] ?? 0) .
                '; Overdue: ' . (string) ($row['overdue'] ?? 0),
        ];
    }
}

if (in_array($reportType, ['department_summary', 'faculty_performance', 'complete_export', 'evaluation_status'], true)) {
    foreach ($analytics['programs'] ?? [] as $row) {
        $data[] = [
            'Program Analysis',
            (string) ($row['program_code'] ?? 'Unassigned Program'),
            'Program Performance',
            'Completion / Average',
            (string) ($row['completion_rate'] ?? 0) . '%',
            'Average: ' . (($row['average_score'] ?? null) === null ? 'N/A' : number_format((float) $row['average_score'], 2)) .
                '; Faculty: ' . (string) ($row['faculty_count'] ?? 0) .
                '; Submitted: ' . (string) ($row['submitted'] ?? 0) .
                '; Pending: ' . (string) ($row['pending'] ?? 0) .
                '; AI insights: ' . (string) ($row['insight_count'] ?? 0),
        ];
    }
}

if (in_array($reportType, ['faculty_performance', 'complete_export'], true)) {
    foreach ($analytics['facultyProfiles'] ?? [] as $row) {
        $data[] = [
            'Faculty Profile',
            (string) ($row['program_code'] ?? 'Unassigned Program'),
            (string) ($row['full_name'] ?? ''),
            'Average / Completion',
            ($row['average_score'] ?? null) === null ? 'N/A' : number_format((float) $row['average_score'], 2),
            'Completion: ' . (string) ($row['completion_rate'] ?? 0) . '%; Weakest: ' .
                ((string) ($row['weakest_category'] ?? '') ?: 'N/A') .
                '; AI weak area: ' . ((string) ($row['ai_weak_area'] ?? '') ?: 'N/A'),
        ];
    }
}

if (in_array($reportType, ['ai_training', 'faculty_performance', 'complete_export'], true)) {
    foreach (array_slice($analytics['weakResults'] ?? [], 0, 200) as $row) {
        $data[] = [
            'Weak Area Result',
            (string) ($row['program_code'] ?? 'Unassigned Program'),
            (string) ($row['faculty_name'] ?? ''),
            (string) ($row['category_title'] ?? ''),
            number_format((float) ($row['average_rating'] ?? 0), 2),
            'Period: ' . (string) ($row['cycle_name'] ?? $row['evaluation_period'] ?? '') .
                '; Form: ' . strtoupper((string) ($row['assignment_type'] ?? 'evaluation')) .
                '; Submitted: ' . (string) ($row['submitted_at'] ?? ''),
        ];
    }
}

if ($data === []) {
    $data[] = ['Summary', $periodLabel, 'No data', 'Records', '0', 'No matching analytics records found.'];
}

$generator = new ReportGenerator(
    'DIPASCAF Dean Analytics Report',
    'Dean Analytics Report',
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
