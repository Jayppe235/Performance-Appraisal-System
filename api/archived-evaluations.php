<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
];

if (in_array($origin, $allowedDevOrigins, true)) {
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

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin_hr') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

try {
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();

    $stmt = db()->query(
        "SELECT
            pa.id AS assignment_id,
            pa.cycle_name,
            pa.assignment_type,
            pa.evaluator_role,
            pa.status,
            pa.assigned_at,
            pa.submitted_at,
            COALESCE(pa.archived_at, fa.archived_at, fb.archived_at) AS archived_at,
            eu.full_name AS evaluator_name,
            f.full_name AS evaluatee_name,
            f.department,
            COALESCE(NULLIF(f.program_code, ''), '') AS program,
            COALESCE(fa.result_count, 0) AS form_a_archived_results,
            COALESCE(fb.result_count, 0) AS form_b_archived_results,
            CASE
                WHEN COALESCE(pa.is_archived, 0) = 1 THEN 'assignment'
                WHEN COALESCE(fa.result_count, 0) > 0 OR COALESCE(fb.result_count, 0) > 0 THEN 'result'
                ELSE 'record'
            END AS archive_scope
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users eu ON eu.id = pa.evaluator_user_id
         LEFT JOIN (
            SELECT assignment_id, COUNT(*) AS result_count, MAX(archived_at) AS archived_at
            FROM pmas_form_a_category_results
            WHERE COALESCE(is_archived, 0) = 1
            GROUP BY assignment_id
         ) fa ON fa.assignment_id = pa.id
         LEFT JOIN (
            SELECT assignment_id, COUNT(*) AS result_count, MAX(archived_at) AS archived_at
            FROM pmas_form_b_category_results
            WHERE COALESCE(is_archived, 0) = 1
            GROUP BY assignment_id
         ) fb ON fb.assignment_id = pa.id
         WHERE COALESCE(pa.is_archived, 0) = 1
            OR COALESCE(fa.result_count, 0) > 0
            OR COALESCE(fb.result_count, 0) > 0
         ORDER BY COALESCE(pa.archived_at, fa.archived_at, fb.archived_at, pa.submitted_at, pa.assigned_at) DESC
         LIMIT 200"
    );

    echo json_encode([
        'ok' => true,
        'data' => array_map(static function (array $row): array {
            return [
                'assignment_id' => (int) $row['assignment_id'],
                'cycle_name' => (string) ($row['cycle_name'] ?? ''),
                'assignment_type' => (string) ($row['assignment_type'] ?? ''),
                'evaluator_role' => (string) ($row['evaluator_role'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'assigned_at' => (string) ($row['assigned_at'] ?? ''),
                'submitted_at' => (string) ($row['submitted_at'] ?? ''),
                'archived_at' => (string) ($row['archived_at'] ?? ''),
                'evaluator_name' => (string) ($row['evaluator_name'] ?? ''),
                'evaluatee_name' => (string) ($row['evaluatee_name'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'program' => (string) ($row['program'] ?? ''),
                'form_a_archived_results' => (int) $row['form_a_archived_results'],
                'form_b_archived_results' => (int) $row['form_b_archived_results'],
                'archive_scope' => (string) ($row['archive_scope'] ?? 'record'),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
