<?php
/**
 * Server-sent events stream for live dashboard metrics.
 * Reuses api/dashboard.php query functions so streamed and fetched data match.
 */

define('DIPASCAF_DASHBOARD_LIBRARY', true);
require_once __DIR__ . '/dashboard.php';

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$role = (string) ($_GET['role'] ?? 'admin');
$lastSignature = '';
$startedAt = time();
$maxRuntimeSeconds = 55;
$intervalSeconds = 2;

echo "retry: 2000\n\n";

while (!connection_aborted() && (time() - $startedAt) < $maxRuntimeSeconds) {
    try {
        $data = dashboard_payload($role, $_GET);
        $payload = ['ok' => true, 'data' => $data, 'timestamp' => time()];
        $encoded = json_encode($payload);

        if ($encoded !== false) {
            $signature = sha1($encoded);
            if ($signature !== $lastSignature) {
                echo "event: metrics\n";
                echo 'data: ' . $encoded . "\n\n";
                $lastSignature = $signature;
            } else {
                echo ": heartbeat " . time() . "\n\n";
            }
        }
    } catch (Throwable $e) {
        $encoded = json_encode(['ok' => false, 'error' => $e->getMessage(), 'timestamp' => time()]);
        echo "event: error\n";
        echo 'data: ' . ($encoded ?: '{"ok":false,"error":"Dashboard stream failed."}') . "\n\n";
    }

    flush();
    sleep($intervalSeconds);
}
