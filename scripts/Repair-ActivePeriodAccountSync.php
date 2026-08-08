<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';

dipascaf_ensure_period_participation_schema();
dipascaf_ensure_peer_evaluation_schema();

try {
    // Every synchronization operation is idempotent. Avoid one long global
    // transaction so this maintenance command can coexist with dashboard polls.
    $summary = dipascaf_repair_actionable_period_account_sync();
    fwrite(STDOUT, json_encode(['ok' => true, 'summary' => $summary], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
