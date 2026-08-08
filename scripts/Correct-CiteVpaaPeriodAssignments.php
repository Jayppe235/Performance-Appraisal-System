<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';
require_once __DIR__ . '/../includes/peer_assignment_algorithm.php';

$apply = in_array('--apply', $argv, true);
$db = db();
dipascaf_ensure_period_participation_schema();
dipascaf_ensure_peer_evaluation_schema();
admin_ensure_archive_schema();

function correction_name_key(string $name): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
}

function correction_period(string $name, string $schoolYear): array
{
    $row = admin_one(
        'SELECT id,period_name,school_year,date_end,status FROM appraisal_periods WHERE period_name=:name AND school_year=:year LIMIT 1',
        ['name' => $name, 'year' => $schoolYear]
    );
    if ($row === null) throw new RuntimeException("Required period {$name} ({$schoolYear}) was not found.");
    return $row;
}

$periods = [
    2024 => correction_period('2024 APPRAISAL PERIOD', '2024-2025'),
    2025 => correction_period('2025 APPRAISAL PERIOD', '2025-2026'),
    2026 => correction_period('2026 ACADEMIC YEAR', '2026-2027'),
];
$department = admin_one(
    "SELECT id,department_code,department_name FROM departments
     WHERE department_code='CITE' OR department_name LIKE '%Information Technology%'
     ORDER BY id LIMIT 1"
);
if ($department === null) throw new RuntimeException('The CITE department was not found.');

$citeUsers = admin_all(
    "SELECT DISTINCT u.id,u.full_name,u.role,u.start_evaluation_period_id,f.id faculty_id
     FROM users u JOIN faculty f ON f.user_id=u.id
     WHERE u.is_active=1 AND COALESCE(f.is_archived,0)=0
       AND u.role IN ('dean','program_head','teacher')
       AND (u.department=:user_department OR u.department=:user_code
            OR f.department=:faculty_department OR f.department=:faculty_code)
     ORDER BY u.full_name",
    [
        'user_department' => $department['department_name'],
        'user_code' => $department['department_code'],
        'faculty_department' => $department['department_name'],
        'faculty_code' => $department['department_code'],
    ]
);

$exceptionKeys = array_fill_keys(array_map('correction_name_key', [
    'Sunset Faye Cindo',
    'Engr. Jay Jorolan',
    'Shareyne T. Bura-ay',
]), true);
$exceptions = [];
$legacy = [];
foreach ($citeUsers as $user) {
    if (isset($exceptionKeys[correction_name_key((string)$user['full_name'])])) $exceptions[] = $user;
    else $legacy[] = $user;
}
if (count($exceptions) !== 3) {
    throw new RuntimeException('Expected exactly three 2026-only CITE accounts; found ' . count($exceptions) . '.');
}
$vpaa = admin_one("SELECT id,full_name FROM users WHERE role='vpaa' AND is_active=1 ORDER BY id LIMIT 1");
if ($vpaa === null) throw new RuntimeException('No active VPAA account was found.');
$actor = admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1");
$actorId = (int)($actor['id'] ?? 0);
if ($actorId <= 0) throw new RuntimeException('No active Admin/HR account was found for audit attribution.');

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'periods' => array_map(static fn(array $period): array => [
        'id' => (int)$period['id'], 'name' => $period['period_name'], 'status' => $period['status'],
    ], $periods),
    'exceptions_2026_only' => array_map(static fn(array $user): array => ['id'=>(int)$user['id'],'name'=>$user['full_name']], $exceptions),
    'cite_accounts_starting_2024' => array_map(static fn(array $user): array => ['id'=>(int)$user['id'],'name'=>$user['full_name'],'role'=>$user['role']], $legacy),
    'vpaa' => ['id'=>(int)$vpaa['id'],'name'=>$vpaa['full_name']],
];
if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $updateStart = $db->prepare('UPDATE users SET start_evaluation_period_id=? WHERE id=?');
    foreach ($exceptions as $user) $updateStart->execute([(int)$periods[2026]['id'], (int)$user['id']]);
    foreach ($legacy as $user) $updateStart->execute([(int)$periods[2024]['id'], (int)$user['id']]);

    foreach (array_merge($exceptions, $legacy) as $user) {
        dipascaf_sync_user_start_period((int)$user['id'], $actorId);
    }

    $exceptionUserIds = array_map(static fn(array $row): int => (int)$row['id'], $exceptions);
    $exceptionFacultyIds = array_map(static fn(array $row): int => (int)$row['faculty_id'], $exceptions);
    $oldPeriodNames = [(string)$periods[2024]['period_name'], (string)$periods[2025]['period_name']];
    $userMarks = implode(',', array_fill(0, count($exceptionUserIds), '?'));
    $facultyMarks = implode(',', array_fill(0, count($exceptionFacultyIds), '?'));
    $periodMarks = implode(',', array_fill(0, count($oldPeriodNames), '?'));
    $assignmentParams = array_merge($exceptionUserIds, $exceptionFacultyIds, $oldPeriodNames);
    $affected = admin_all(
        "SELECT id,status FROM peer_assignments
         WHERE (evaluator_user_id IN ({$userMarks}) OR evaluatee_faculty_id IN ({$facultyMarks}))
           AND cycle_name IN ({$periodMarks})",
        $assignmentParams
    );
    $assignmentIds = array_map(static fn(array $row): int => (int)$row['id'], $affected);
    if ($assignmentIds !== []) {
        $idMarks = implode(',', array_fill(0, count($assignmentIds), '?'));
        $db->prepare(
            "UPDATE peer_assignments SET
               status=IF(status='submitted',status,'not_required'),
               is_archived=1,archived_at=COALESCE(archived_at,NOW()),archived_by=COALESCE(archived_by,?)
             WHERE id IN ({$idMarks})"
        )->execute(array_merge([$actorId], $assignmentIds));
        $db->prepare(
            "UPDATE peer_evaluation_assignments SET is_archived=1,archived_at=COALESCE(archived_at,NOW()),
             archived_by=COALESCE(archived_by,?) WHERE peer_assignment_id IN ({$idMarks})"
        )->execute(array_merge([$actorId], $assignmentIds));
    }

    $generated = [];
    $peerGenerated = [];
    foreach ($periods as $year => $period) {
        $deadline = (string)($period['date_end'] ?: date('Y-m-d', strtotime('+30 days')));
        // Lock the period row before assignment rows to match the application's
        // normal lock order and avoid period/assignment deadlocks.
        $db->prepare('UPDATE appraisal_periods SET peer_assignments_validated_at=NULL,peer_assignments_validated_by=NULL WHERE id=?')
            ->execute([(int)$period['id']]);
        $generated[$year] = dipascaf_upsert_required_assignments_for_period((string)$period['period_name'], $deadline);
        $vpaaDean = $db->prepare(
            "INSERT INTO peer_assignments
             (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,
              questionnaire_type,status,assigned_at,deadline)
             VALUES (?, ?, ?, 'vpaa', 'dean', 'admin', 'pending', NOW(), ?)
             ON DUPLICATE KEY UPDATE deadline=VALUES(deadline),questionnaire_type='admin',
              status=IF(status='submitted',status,'pending'),is_archived=0,archived_at=NULL,archived_by=NULL"
        );
        $eligibleDeans = admin_all(
            "SELECT DISTINCT f.id faculty_id
             FROM evaluation_period_participation epp
             JOIN users u ON u.id=epp.user_id AND u.is_active=1
             JOIN faculty f ON f.user_id=u.id AND f.is_active=1 AND COALESCE(f.is_archived,0)=0
             WHERE epp.evaluation_period_id=:period_id AND epp.role_snapshot='dean'
               AND epp.participation_status='included' AND COALESCE(epp.work_status,'active')='active'
               AND epp.employment_status IN ('active','newly_added')",
            ['period_id'=>(int)$period['id']]
        );
        foreach ($eligibleDeans as $dean) {
            $vpaaDean->execute([(string)$period['period_name'], (int)$vpaa['id'], (int)$dean['faculty_id'], $deadline]);
        }
        foreach (['department','dean'] as $peerGroup) {
            try {
                $peerGenerated[$year][$peerGroup] = dipascaf_generate_peer_evaluation_assignments(
                    (int)$period['id'], (string)$period['period_name'], $deadline, true, false,
                    $peerGroup === 'department' ? ['department_ids'=>[(int)$department['id']]] : [], $peerGroup
                );
            } catch (RuntimeException $error) {
                $peerGenerated[$year][$peerGroup] = ['created'=>0,'warning'=>$error->getMessage()];
            }
        }
    }

    $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([
        $actorId,
        'Corrected CITE evaluation eligibility: Sunset Cindo, Jay Jorolan, and Shareyne Bura-ay begin in 2026; all other active CITE evaluation accounts and VPAA records begin in 2024.',
    ]);
    if ($db->inTransaction()) $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$validation = [];
foreach (array_merge($exceptions, $legacy) as $user) {
    $validation[] = admin_one(
        "SELECT u.id,u.full_name,ap.period_name start_period,
          SUM(epp.participation_status='included' AND epp.work_status='active') included_periods,
          SUM(epp.participation_status='excluded') excluded_periods
         FROM users u LEFT JOIN appraisal_periods ap ON ap.id=u.start_evaluation_period_id
         LEFT JOIN evaluation_period_participation epp ON epp.user_id=u.id
         WHERE u.id=:id GROUP BY u.id,ap.id",
        ['id'=>(int)$user['id']]
    );
}
$vpaaAssignments = admin_all(
    "SELECT cycle_name,status,COUNT(*) count FROM peer_assignments
     WHERE evaluator_user_id=:vpaa AND evaluator_role='vpaa' AND assignment_type='dean' AND COALESCE(is_archived,0)=0
       AND cycle_name IN (:p2024,:p2025,:p2026)
     GROUP BY cycle_name,status ORDER BY cycle_name,status",
    ['vpaa'=>(int)$vpaa['id'],'p2024'=>$periods[2024]['period_name'],'p2025'=>$periods[2025]['period_name'],'p2026'=>$periods[2026]['period_name']]
);
$summary['applied'] = true;
$summary['archived_pre_2026_assignments'] = count($assignmentIds);
$summary['generated'] = $generated;
$summary['peer_generated'] = $peerGenerated;
$summary['validation'] = $validation;
$summary['vpaa_assignments'] = $vpaaAssignments;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
