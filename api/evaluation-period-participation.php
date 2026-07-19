<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Authentication required.']); exit; }
if ((string)($user['role'] ?? '') !== 'admin_hr') { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Only Admin HR can manage period participation.']); exit; }

try {
    dipascaf_ensure_period_participation_schema();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $periodId = (int)($_GET['evaluation_period_id'] ?? 0);
        if ($periodId <= 0) throw new DomainException('Select an evaluation period.');
        $period = admin_one('SELECT id,period_name,school_year,semester,status FROM appraisal_periods WHERE id=:id', ['id'=>$periodId]);
        if (!$period) throw new DomainException('Evaluation period was not found.');
        echo json_encode(['ok'=>true,'period'=>$period,'participants'=>dipascaf_period_participants($periodId)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'message'=>'Method not allowed.']); exit; }
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = (string)($input['action'] ?? '');
    $status = $action === 'exclude' ? 'excluded' : ($action === 'include' ? 'included' : '');
    if ($status === '') throw new DomainException('Invalid participation action.');
    $result = dipascaf_set_period_participation(
        (int)($input['evaluation_period_id'] ?? 0),
        (int)($input['user_id'] ?? 0),
        $status,
        isset($input['reason']) ? (string)$input['reason'] : null,
        (string)($input['notes'] ?? ''),
        (int)$user['id']
    );
    echo json_encode(['ok'=>true,'message'=>$status === 'excluded' ? 'Faculty member excluded from this evaluation period.' : 'Faculty member re-included in this evaluation period.','data'=>$result]);
} catch (DomainException $e) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Period participation API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Unable to update period participation.']);
}
