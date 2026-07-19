<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$tables = ['users', 'evaluations', 'peer_evaluation_assignments', 'appraisal_periods', 'notifications'];
$connection = db();
$result = [
    'environment' => PMAS_ENV,
    'host' => DB_HOST,
    'database' => DB_NAME,
    'counts' => [],
];

foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    try {
        $result['counts'][$table] = (int) $connection->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
    } catch (PDOException $exception) {
        $result['counts'][$table] = null;
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
