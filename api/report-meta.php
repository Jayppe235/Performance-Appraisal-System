<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';

@set_time_limit(20);
ini_set('default_socket_timeout', '5');
ini_set('mysql.connect_timeout', '5');

ob_start();
$reportMetaResponded = false;

function report_meta_response(int $status, bool $ok, string $message = '', array $extra = []): void
{
    $GLOBALS['reportMetaResponded'] = true;
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $message !== '' ? ['message' => $message] : [], $extra));
    exit;
}

register_shutdown_function(static function (): void {
    if (($GLOBALS['reportMetaResponded'] ?? false) === true) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($error !== null && in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'message' => 'Report metadata is temporarily unavailable. Please check that MySQL is running and try again.',
        ]);
    }
});

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedDevOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5175',
    'http://127.0.0.1:5175',
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

try {
    $user = current_user();
    if ($user === null) {
        report_meta_response(403, false, 'Authentication required.');
    }

    $role = $_GET['role'] ?? $user['role'] ?? '';
    $role = match ($role) {
        'admin' => 'admin_hr',
        'vpaa' => 'vpaa',
        'programHead', 'program-head' => 'program_head',
        'faculty' => 'teacher',
        default => $role,
    };
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    $data = [];

    if ($role === 'vpaa') {
        require_once __DIR__ . '/../includes/vpaa_data.php';

        $departments = vpaa_departments((int) $user['id']);
        $assignments = vpaa_assignments($departments);
        $summary = vpaa_summary($departments);
        $interventions = vpaa_interventions($departments);
        $completionRate = (int) ($summary['completionRate'] ?? 0);

        $data = [
            'completion' => $completionRate,
            'departments' => $departments,
            'reports' => [
                'evaluation_status' => [
                    'badge' => ($summary['pendingEvaluations'] ?? 0) . ' pending',
                    'progress' => $completionRate,
                ],
                'department_summary' => [
                    'badge' => count($departments) . ' departments',
                    'progress' => $completionRate,
                ],
                'faculty_performance' => [
                    'badge' => ($summary['averageFacultyRating'] ?? null) === null ? 'No rating' : number_format((float) $summary['averageFacultyRating'], 2) . '/5',
                    'progress' => $completionRate,
                ],
                'peer_assignments' => [
                    'badge' => count($assignments) . ' assignments',
                    'progress' => min(100, count($assignments) * 10),
                ],
                'ai_training' => [
                    'badge' => count($interventions) . ' plans',
                    'progress' => min(100, max(12, count($interventions) * 18)),
                ],
                'complete_export' => [
                    'badge' => count($assignments) . ' records',
                    'progress' => 100,
                ],
            ],
        ];
    } elseif ($role === 'dean') {
        require_once __DIR__ . '/../includes/dean_data.php';
        require_once __DIR__ . '/../includes/evaluation_cards.php';

        $deanId = (int) $user['id'];
        $departments = dean_departments($deanId);
        $faculty = dean_faculty($departments);
        $assignments = dean_assignments($deanId);
        $summary = dean_summary($departments);
        $interventions = dean_interventions($departments);
        $completionRate = count($assignments) > 0 ? round(($summary['submitted'] ?? 0) / count($assignments) * 100) : 0;

        $data = [
            'completion' => $completionRate,
            'reports' => [
                'evaluation_status' => [
                    'badge' => ($summary['pending'] ?? 0) . ' pending',
                    'progress' => $completionRate,
                ],
                'department_summary' => [
                    'badge' => count($departments) . ' departments',
                    'progress' => min(100, count($departments) * 24),
                ],
                'faculty_performance' => [
                    'badge' => count($faculty) . ' faculty',
                    'progress' => $completionRate,
                ],
                'peer_assignments' => [
                    'badge' => count($assignments) . ' assignments',
                    'progress' => min(100, count($assignments) * 10),
                ],
                'ai_training' => [
                    'badge' => count($interventions) . ' plans',
                    'progress' => min(100, max(12, count($interventions) * 18)),
                ],
                'complete_export' => [
                    'badge' => count($assignments) . ' records',
                    'progress' => 100,
                ],
            ],
        ];
    } elseif ($role === 'program_head') {
        require_once __DIR__ . '/../includes/program_head_data.php';
        require_once __DIR__ . '/../includes/evaluation_cards.php';

        $programHeadId = (int) $user['id'];
        $programs = program_head_programs($programHeadId);
        $departments = program_head_departments($programHeadId);
        $assignments = dipascaf_assignment_rows($programHeadId, 'program_head');
        $faculty = program_head_faculty($departments, $programs);
        $summary = program_head_summary($programHeadId, $departments, $programs);
        $interventions = program_head_interventions($departments, $programs);
        $completionRate = count($assignments) > 0 ? round(($summary['submitted'] ?? 0) / count($assignments) * 100) : 0;

        $data = [
            'completion' => $completionRate,
            'reports' => [
                'evaluation_status' => [
                    'badge' => ($summary['pending'] ?? 0) . ' pending',
                    'progress' => $completionRate,
                ],
                'department_summary' => [
                    'badge' => count($faculty) . ' faculty',
                    'progress' => min(100, count($faculty) * 10),
                ],
                'faculty_performance' => [
                    'badge' => count($faculty) . ' faculty',
                    'progress' => $completionRate,
                ],
                'peer_assignments' => [
                    'badge' => count($assignments) . ' assignments',
                    'progress' => min(100, count($assignments) * 10),
                ],
                'ai_training' => [
                    'badge' => count($interventions) . ' plans',
                    'progress' => min(100, max(12, count($interventions) * 18)),
                ],
                'complete_export' => [
                    'badge' => count($assignments) . ' records',
                    'progress' => 100,
                ],
            ],
        ];
    } elseif ($role === 'admin_hr') {
        $facultyCount = (int) admin_count('SELECT COUNT(*) FROM faculty WHERE COALESCE(is_archived, 0) = 0');
        $pendingCount = (int) admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'pending'");
        $completedCount = (int) admin_count("SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0 AND status = 'submitted'");
        $interventionCount = (int) admin_count('SELECT COUNT(*) FROM intervention_plans');
        $departmentCount = (int) admin_count('SELECT COUNT(*) FROM departments WHERE is_active = 1');
        $peerCount = (int) admin_count('SELECT COUNT(*) FROM peer_assignments WHERE COALESCE(is_archived, 0) = 0');
        $totalEvals = $pendingCount + $completedCount;
        $completionRate = $totalEvals > 0 ? round($completedCount / $totalEvals * 100) : 0;

        $data = [
            'completion' => $completionRate,
            'reports' => [
                'evaluation_status' => [
                    'badge' => $pendingCount . ' pending',
                    'progress' => $completionRate,
                ],
                'department_summary' => [
                    'badge' => $departmentCount . ' departments',
                    'progress' => $completionRate,
                ],
                'faculty_performance' => [
                    'badge' => $facultyCount . ' faculty',
                    'progress' => $completionRate,
                ],
                'peer_assignments' => [
                    'badge' => $peerCount . ' records',
                    'progress' => min(100, $peerCount > 0 ? round($completedCount / $peerCount * 100) : 0),
                ],
                'ai_training' => [
                    'badge' => $interventionCount . ' plans',
                    'progress' => min(100, max(12, $interventionCount * 18)),
                ],
                'complete_export' => [
                    'badge' => 'Ready',
                    'progress' => 100,
                ],
            ],
        ];
    } elseif ($role === 'teacher') {
        $faculty = admin_one(
            'SELECT f.id FROM faculty f JOIN users u ON u.id = f.user_id WHERE u.id = :user_id LIMIT 1',
            ['user_id' => (int) $user['id']]
        );
        $evalCount = 0;
        $submittedCount = 0;
        if ($faculty) {
            $evalCount = (int) admin_count(
                'SELECT COUNT(*) FROM peer_assignments WHERE evaluatee_faculty_id = :faculty_id AND COALESCE(is_archived, 0) = 0',
                ['faculty_id' => (int) $faculty['id']]
            );
            $submittedCount = (int) admin_count(
                'SELECT COUNT(*) FROM evaluation_submissions WHERE evaluatee_faculty_id = :faculty_id',
                ['faculty_id' => (int) $faculty['id']]
            );
        }
        $completionRate = $evalCount > 0 ? round($submittedCount / $evalCount * 100) : 0;

        $data = [
            'completion' => $completionRate,
            'reports' => [
                'evaluation_status' => [
                    'badge' => ($evalCount - $submittedCount) . ' pending',
                    'progress' => $completionRate,
                ],
                'faculty_performance' => [
                    'badge' => $submittedCount . ' results',
                    'progress' => $completionRate,
                ],
                'complete_export' => [
                    'badge' => 'Ready',
                    'progress' => 100,
                ],
            ],
        ];
    } else {
        report_meta_response(400, false, 'Invalid role specified.');
    }

    report_meta_response(200, true, '', ['data' => $data]);
} catch (Throwable $e) {
    report_meta_response(500, false, $e->getMessage());
}
