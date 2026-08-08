<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';

$db = db();
dipascaf_ensure_period_participation_schema();
dipascaf_ensure_peer_evaluation_schema();
$period = admin_one("SELECT id,period_name,date_end FROM appraisal_periods WHERE period_name='2024 APPRAISAL PERIOD' AND school_year='2024-2025' LIMIT 1");
$department = admin_one("SELECT id,department_name FROM departments WHERE department_code='CITE' LIMIT 1");
$actor = admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1");
$lyndel = admin_one("SELECT u.id,f.id faculty_id FROM users u JOIN faculty f ON f.user_id=u.id WHERE u.full_name LIKE '%Lyndel Jean%Portolazo%' AND u.role='program_head' LIMIT 1");
if (!$period || !$department || !$actor || !$lyndel) throw new RuntimeException('Required 2024 CITE period, administrator, or Lyndel account was not found.');

$db->beginTransaction();
try {
    // Restore only CITE peer rows whose evaluator and evaluatee are valid,
    // included participants. Submitted/completed rows remain unchanged.
    $db->prepare(
        "UPDATE peer_evaluation_assignments pea
         JOIN users evaluator_user ON evaluator_user.id=pea.evaluator_id
         JOIN users evaluatee_user ON evaluatee_user.id=pea.evaluatee_id
         JOIN evaluation_period_participation evaluator
           ON evaluator.evaluation_period_id=pea.evaluation_period_id AND evaluator.user_id=pea.evaluator_id
         JOIN evaluation_period_participation evaluatee
           ON evaluatee.evaluation_period_id=pea.evaluation_period_id AND evaluatee.user_id=pea.evaluatee_id
         JOIN peer_assignments pa ON pa.id=pea.peer_assignment_id
         SET pea.status=IF(pea.status='completed','completed','pending'),pea.is_archived=0,pea.archived_at=NULL,pea.archived_by=NULL,
             pa.status=IF(pa.status='submitted','submitted','pending'),pa.is_archived=0,pa.archived_at=NULL,pa.archived_by=NULL
         WHERE pea.evaluation_period_id=?
           AND evaluator_user.department=? AND evaluatee_user.department=?
           AND evaluator.participation_status='included' AND evaluator.work_status='active'
           AND evaluator.employment_status IN ('active','newly_added')
           AND evaluatee.participation_status='included' AND evaluatee.work_status='active'
           AND evaluatee.employment_status IN ('active','newly_added')"
    )->execute([(int)$period['id'], $department['department_name'], $department['department_name']]);

    $generated = dipascaf_generate_peer_evaluation_assignments(
        (int)$period['id'], (string)$period['period_name'], (string)$period['date_end'], true, false,
        ['department_ids'=>[(int)$department['id']]], 'department'
    );

    $invalid = admin_all(
        "SELECT pea.id FROM peer_evaluation_assignments pea
         JOIN users eu ON eu.id=pea.evaluator_id
         JOIN users vu ON vu.id=pea.evaluatee_id
         LEFT JOIN evaluation_period_participation evaluator ON evaluator.evaluation_period_id=pea.evaluation_period_id AND evaluator.user_id=pea.evaluator_id
         LEFT JOIN evaluation_period_participation evaluatee ON evaluatee.evaluation_period_id=pea.evaluation_period_id AND evaluatee.user_id=pea.evaluatee_id
         WHERE pea.evaluation_period_id=:period AND COALESCE(pea.is_archived,0)=0
           AND eu.department=:evaluator_department AND vu.department=:evaluatee_department
           AND (pea.evaluator_id=pea.evaluatee_id OR evaluator.participation_status<>'included' OR evaluator.work_status<>'active'
             OR evaluator.employment_status NOT IN ('active','newly_added') OR evaluatee.participation_status<>'included'
             OR evaluatee.work_status<>'active' OR evaluatee.employment_status NOT IN ('active','newly_added'))",
        ['period'=>(int)$period['id'],'evaluator_department'=>$department['department_name'],'evaluatee_department'=>$department['department_name']]
    );
    if ($invalid !== []) throw new RuntimeException('CITE peer assignments still contain invalid participants.');

    $lyndelAssignment = admin_one(
        "SELECT pea.id,pea.peer_assignment_id FROM peer_evaluation_assignments pea
         JOIN peer_assignments pa ON pa.id=pea.peer_assignment_id
         WHERE pea.evaluation_period_id=:period AND pea.evaluator_id=:user
           AND COALESCE(pea.is_archived,0)=0 AND COALESCE(pa.is_archived,0)=0
           AND pea.status IN ('pending','completed') AND pa.status IN ('pending','submitted') LIMIT 1",
        ['period'=>(int)$period['id'],'user'=>(int)$lyndel['id']]
    );
    if (!$lyndelAssignment) throw new RuntimeException('Ma’am Lyndel still has no active 2024 peer assignment.');

    $db->prepare(
        "UPDATE peer_evaluation_assignments pea JOIN users eu ON eu.id=pea.evaluator_id
         SET pea.locked_at=COALESCE(pea.locked_at,NOW())
         WHERE pea.evaluation_period_id=? AND eu.department=? AND COALESCE(pea.is_archived,0)=0"
    )->execute([(int)$period['id'],$department['department_name']]);
    $db->prepare(
        'UPDATE appraisal_periods SET participants_finalized_at=COALESCE(participants_finalized_at,NOW()),
         participants_finalized_by=COALESCE(participants_finalized_by,?),peer_assignments_validated_at=NOW(),peer_assignments_validated_by=? WHERE id=?'
    )->execute([(int)$actor['id'],(int)$actor['id'],(int)$period['id']]);
    dipascaf_set_peer_lifecycle((int)$period['id'], 'locked', (int)$actor['id']);
    $db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([
        (int)$actor['id'], 'Finalized and locked valid CITE peer-to-peer assignments for the 2024 appraisal period, including Lyndel Portolazo.'
    ]);
    if ($db->inTransaction()) $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$rows = admin_all(
    "SELECT eu.full_name evaluator,vu.full_name evaluatee,pea.status,pa.status assignment_status,pea.locked_at
     FROM peer_evaluation_assignments pea JOIN users eu ON eu.id=pea.evaluator_id JOIN users vu ON vu.id=pea.evaluatee_id
     JOIN peer_assignments pa ON pa.id=pea.peer_assignment_id
     WHERE pea.evaluation_period_id=:period AND eu.department=:department AND vu.department=:department2
       AND COALESCE(pea.is_archived,0)=0 AND COALESCE(pa.is_archived,0)=0 ORDER BY eu.role,eu.full_name",
    ['period'=>(int)$period['id'],'department'=>$department['department_name'],'department2'=>$department['department_name']]
);
echo json_encode(['ok'=>true,'generated'=>$generated,'cite_peer_count'=>count($rows),'lyndel_user_id'=>(int)$lyndel['id'],'assignments'=>$rows], JSON_PRETTY_PRINT), PHP_EOL;
