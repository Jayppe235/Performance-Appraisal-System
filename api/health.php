<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/http.php';

allow_local_dev_cors(['GET', 'OPTIONS']);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    db()->query('SELECT 1');
    echo json_encode([
        'ok' => true,
        'message' => 'Application is available.',
    ]);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'message' => 'Application is temporarily unavailable.',
    ]);
}
