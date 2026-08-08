<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/program_head_data.php';
require_once __DIR__ . '/../includes/report_generator.php';

require_role('program_head');

$user = current_user();
$periodId = (int)($_GET['period_id'] ?? 0);
$programs = program_head_programs((int) $user['id'], $periodId);
$departments = program_head_departments((int) $user['id'], $periodId);
$faculty = program_head_faculty($departments, $programs);
$insights = program_head_ai_insights($departments, $programs, (int) $user['id']);
$interventions = program_head_interventions($departments, $programs);

$format = $_GET['format'] ?? 'csv';
$headers = ['Department', 'Faculty', 'Position', 'Progress', 'AI Weak Area', 'Recommended Action', 'Status'];
$data = [];

foreach ($faculty as $row) {
    $weak = '';
    foreach ($insights as $insight) {
        if ((int) $insight['faculty_id'] === (int) $row['id']) {
            $weak = $insight['weak_area'];
            break;
        }
    }

    $recommendation = '';
    $status = '';
    foreach ($interventions as $plan) {
        if ((int) $plan['faculty_id'] === (int) $row['id']) {
            $recommendation = $plan['recommendation'];
            $status = $plan['status'];
            break;
        }
    }

    $data[] = [
        $row['department'],
        $row['full_name'],
        $row['position_title'],
        $row['progress_percent'] . '%',
        $weak,
        $recommendation,
        $status,
    ];
}

$generator = new ReportGenerator(
    'DIPASCAF Program Head Report',
    'Program Head Report',
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
