<?php
declare(strict_types=1);

// Single serverless entry point for the existing PHP API and report downloads.
$scope = (string)($_GET['_scope'] ?? 'api');
$requested = basename((string)($_GET['_endpoint'] ?? ''));
unset($_GET['_scope'], $_GET['_endpoint']);

$apiEndpoints = [
    'admin.php', 'admin-analytics.php', 'admin-evaluation-monitor.php',
    'ai-recommendation.php', 'archived-evaluations.php', 'assistant.php',
    'auth.php', 'dashboard.php', 'dean-analytics.php', 'dean-people.php',
    'dean-summary.php', 'department-analysis.php', 'department-people.php',
    'departments.php', 'evaluation-assignments.php',
    'evaluation-category-monitor-evaluator.php', 'evaluation-category-monitor.php',
    'evaluation-comparison.php', 'evaluation-period-participation.php',
    'evaluation-period.php', 'evaluation-stats.php', 'evaluations.php',
    'factor-scores.php', 'form_a_admin.php', 'form_b_admin.php',
    'goals-records.php', 'health.php', 'my-evaluation-results.php',
    'notifications.php', 'peer-evaluation-assignments.php', 'people.php',
    'performance-report.php', 'profile.php', 'program-head-people.php',
    'program-head-summary.php', 'program-people.php', 'programs.php',
    'report-meta.php', 'self-evaluations.php', 'subject-areas.php',
    'vpaa-evaluation-monitor.php', 'vpaa-summary.php',
];
$reportEndpoints = [
    'dean_download.php', 'download.php', 'performance_download.php',
    'program_head_download.php', 'user_accounts_download.php', 'vpaa_download.php',
];

$allowed = $scope === 'reports' ? $reportEndpoints : $apiEndpoints;
$directory = $scope === 'reports' ? dirname(__DIR__) . '/reports' : __DIR__;

if (!in_array($requested, $allowed, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Endpoint not found.']);
    exit;
}

require $directory . '/' . $requested;
