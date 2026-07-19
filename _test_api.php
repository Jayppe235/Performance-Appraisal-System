<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['scope' => 'departments'];
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:5173';

// Simulate a logged-in admin_hr user
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'full_name' => 'Admin HR User',
    'email' => 'admin@dipascaf.edu',
    'role' => 'admin_hr',
];

ob_start();
require __DIR__ . '/api/admin-evaluation-monitor.php';
$output = ob_get_clean();

$data = json_decode($output, true);
if ($data && $data['ok']) {
    echo "API RESPONSE: OK\n\n";
    echo "SUMMARY:\n";
    echo json_encode($data['summary'], JSON_PRETTY_PRINT) . "\n\n";
    echo "DEPARTMENTS (" . count($data['data']) . "):\n";
    foreach ($data['data'] as $d) {
        echo "  - {$d['department_name']} ({$d['department_code']}): {$d['total_faculty']} faculty, {$d['completed']} completed, {$d['pending']} pending, {$d['completion_pct']}%\n";
    }
    echo "\nWEAK AREAS:\n";
    echo json_encode($data['weakAreas'], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "API RESPONSE: ERROR\n";
    echo $output;
}
