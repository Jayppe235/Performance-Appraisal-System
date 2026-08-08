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
        $period = admin_one('SELECT id,period_name,school_year,status FROM appraisal_periods WHERE id=:id', ['id'=>$periodId]);
        if (!$period) throw new DomainException('Evaluation period was not found.');
        echo json_encode([
            'ok'=>true,
            'period'=>array_merge($period, [
                'participants_finalized'=>dipascaf_period_participation_is_finalized($periodId),
                'validation'=>dipascaf_period_participation_validation($periodId),
            ]),
            'participants'=>dipascaf_period_participants($periodId),
            'options'=>dipascaf_period_assignment_options(),
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'message'=>'Method not allowed.']); exit; }
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = (string)($input['action'] ?? '');
    $periodId = (int)($input['evaluation_period_id'] ?? 0);
    if ($action === 'seed') {
        $created = dipascaf_seed_period_participants($periodId, (int)$user['id']);
        echo json_encode(['ok'=>true,'message'=>"{$created} participant snapshot(s) prepared.",'created'=>$created]);
        exit;
    }
    if ($action === 'finalize') {
        $validation = dipascaf_finalize_period_participants($periodId, (int)$user['id']);
        echo json_encode(['ok'=>true,'message'=>'Participants finalized. Proceed to Peer Assignments.','validation'=>$validation]);
        exit;
    }
    if ($action === 'reopen') {
        dipascaf_reopen_period_participants($periodId, (int)$user['id']);
        echo json_encode(['ok'=>true,'message'=>'Participants reopened. Peer assignment validation was cleared.']);
        exit;
    }
    if ($action === 'remove') {
        dipascaf_assert_period_participation_editable($periodId);
        $userId = (int)($input['user_id'] ?? 0);
        $activity = admin_one(
            "SELECT COUNT(*) total FROM peer_assignments pa JOIN appraisal_periods ap ON ap.period_name=pa.cycle_name
             WHERE ap.id=:period_id AND (pa.evaluator_user_id=:user_id OR pa.evaluatee_faculty_id IN
               (SELECT id FROM faculty WHERE user_id=:user_id))",
            ['period_id'=>$periodId,'user_id'=>$userId]
        );
        if ((int)($activity['total'] ?? 0) > 0) throw new DomainException('This participant has period activity and must be excluded instead of removed.');
        db()->prepare('DELETE FROM evaluation_period_participation WHERE evaluation_period_id=? AND user_id=?')->execute([$periodId,$userId]);
        echo json_encode(['ok'=>true,'message'=>'Candidate removed from this draft period.']);
        exit;
    }
    if ($action === 'employment_status') {
        dipascaf_assert_period_participation_editable($periodId);
        $employment = (string)($input['employment_status'] ?? '');
        if (!in_array($employment, ['active','newly_added','not_yet_employed','on_leave','inactive'], true)) {
            throw new DomainException('Invalid period employment status.');
        }
        $participation = in_array($employment, ['not_yet_employed','on_leave','inactive'], true) ? 'excluded' : 'included';
        $work = $participation === 'included' ? 'active' : 'no_assignments';
        db()->prepare(
            'UPDATE evaluation_period_participation SET employment_status=?,participation_status=?,work_status=?,
             exclusion_reason=?,notes=?,changed_by_user_id=? WHERE evaluation_period_id=? AND user_id=?'
        )->execute([
            $employment,$participation,$work,$employment === 'on_leave' ? 'leave' : null,
            trim((string)($input['notes'] ?? '')) ?: null,(int)$user['id'],$periodId,(int)($input['user_id'] ?? 0),
        ]);
        echo json_encode(['ok'=>true,'message'=>'Period employment status updated.']);
        exit;
    }
    if ($action === 'update_assignment') {
        $requestedRole = (string)($input['role'] ?? '');
        $result = $requestedRole === 'dean'
          ? dipascaf_set_period_dean_assignment(
            (int)($input['evaluation_period_id'] ?? 0),
            (int)($input['user_id'] ?? 0),
            (int)($input['department_id'] ?? 0),
            (string)($input['acting_dean_reason'] ?? ''),
            (string)($input['replaced_dean_action'] ?? ''),
            (int)$user['id'],
            filter_var($input['confirm_dean_replacement'] ?? false, FILTER_VALIDATE_BOOLEAN)
          )
          : dipascaf_set_period_assignment(
            (int)($input['evaluation_period_id'] ?? 0),
            (int)($input['user_id'] ?? 0),
            $requestedRole,
            (int)($input['department_id'] ?? 0),
            is_array($input['program_ids'] ?? null)
                ? $input['program_ids']
                : [(int)($input['program_id'] ?? 0)],
            (int)($input['primary_program_id'] ?? $input['program_id'] ?? 0),
            is_array($input['lead_program_ids'] ?? null)
                ? $input['lead_program_ids']
                : [(int)($input['program_id'] ?? 0)],
            filter_var($input['allow_co_head'] ?? false, FILTER_VALIDATE_BOOLEAN),
            (string)($input['co_head_reason'] ?? ''),
            (int)$user['id']
          );
        echo json_encode([
            'ok'=>true,
            'message'=>$requestedRole === 'dean' ? 'Acting Dean assignment saved for this evaluation period.' : 'Period-specific role and program assignment updated.',
            'data'=>$result,
        ]);
        exit;
    }
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
} catch (PeriodDeanConflictException $e) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'code'=>'dean_conflict','message'=>$e->getMessage(),'conflict'=>$e->conflict]);
} catch (PeriodProgramHeadConflictException $e) {
    http_response_code(409);
    echo json_encode([
        'ok'=>false,
        'code'=>'program_head_conflict',
        'message'=>$e->getMessage(),
        'conflicts'=>$e->conflicts,
    ]);
} catch (DomainException $e) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Period participation API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Unable to update period participation.']);
}
