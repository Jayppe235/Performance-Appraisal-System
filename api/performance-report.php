<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/performance_report.php';
header('Content-Type: application/json; charset=utf-8');

function performance_report_json(array $payload, int $status = 200): never
{
    if (ob_get_level() > 0) ob_clean();
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"message":"Unable to serialize the report response."}';
    }
    echo $json;
    exit;
}

$user = current_user();
if (!$user || !in_array($user['role'] ?? '', ['admin_hr', 'vpaa', 'dean', 'program_head'], true)) {
    performance_report_json(['ok' => false, 'message' => 'You are not authorized to generate this report.'], 403);
}
try {
    $metadata = performance_report_metadata();
    $filters = [
        'report_type' => $_GET['report_type'] ?? 'department', 'department_id' => $_GET['department_id'] ?? 0,
        'role' => $_GET['role'] ?? 'teacher', 'program' => $_GET['program'] ?? '',
        'period_id' => $_GET['period_id'] ?? 0, 'sort' => $_GET['sort'] ?? 'name',
    ];
    [$filters, $metadata] = performance_report_user_scope($user, $filters, $metadata);
    $data = performance_report_build($filters);
    unset($data['assets']);
    performance_report_json(['ok' => true, 'metadata' => $metadata, 'data' => $data]);
} catch (PerformanceReportScopeException $e) {
    performance_report_json(['ok' => false, 'message' => $e->getMessage()], 403);
} catch (Throwable $e) {
    error_log('APPRAISIA performance report API failed: ' . $e->getMessage());
    performance_report_json(['ok' => false, 'message' => 'Unable to generate the performance report.'], 500);
}
