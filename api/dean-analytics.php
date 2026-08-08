<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dean_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_string($origin) && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\]|192\.168\.\d{1,3}\.\d{1,3})(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $user = current_user();
    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $periodDean = $user !== null && $selectedPeriod !== null
        ? dipascaf_period_dean_scope((int)$selectedPeriod['id'], (int)$user['id']) !== []
        : false;
    if ($user === null || (($user['role'] ?? '') !== 'dean' && !$periodDean)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Dean access is required.']);
        exit;
    }

    $periodName = $selectedPeriod !== null ? trim((string) ($selectedPeriod['period_name'] ?? '')) : trim((string) ($_GET['period'] ?? ''));
    $departments = dean_departments((int) $user['id'], $selectedPeriod !== null ? (int)$selectedPeriod['id'] : null);
    $analytics = dean_analytics($departments, $periodName);
    $group = trim((string) ($_GET['group'] ?? ''));
    if ($group !== '' && isset($analytics[$group])) {
        $analytics = [
            'summary' => $analytics['summary'] ?? [],
            $group => $analytics[$group],
        ];
    }

    echo json_encode([
        'ok' => true,
        'data' => array_merge([
            'period' => [
                'id' => $selectedPeriod !== null ? (int) ($selectedPeriod['id'] ?? 0) : 0,
                'name' => $periodName,
            ],
            'departments' => $departments,
        ], $analytics),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
