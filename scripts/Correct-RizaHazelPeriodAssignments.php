<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';

$apply = in_array('--apply', $argv, true);
$backupArg = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--backup=')) $backupArg = substr($argument, 9);
}
if ($apply && ($backupArg === null || !is_file($backupArg) || filesize($backupArg) === 0)) {
    fwrite(STDERR, "Apply mode requires --backup=<existing non-empty SQL backup>.\n");
    exit(2);
}

$db = db();
dipascaf_ensure_period_participation_schema();
admin_ensure_archive_schema();
$actor = admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1");
$actorId = (int)($actor['id'] ?? 0);
if ($actorId <= 0) throw new RuntimeException('No active Admin HR account is available for audit attribution.');

$people = [
    'riza' => ['id'=>30, 'name'=>'Engr Riza Jean M. Acanto', 'program'=>'BSCpE'],
    'hazel' => ['id'=>34, 'name'=>'Hazel Joy M. Gadingan', 'program'=>'BSIT'],
];
foreach ($people as $person) {
    $row = admin_one('SELECT id,full_name FROM users WHERE id=:id', ['id'=>$person['id']]);
    if (!$row || strcasecmp(trim((string)$row['full_name']), $person['name']) !== 0) {
        throw new RuntimeException("User {$person['id']} does not match the expected person; correction stopped.");
    }
}

$affected = admin_all(
    "SELECT DISTINCT pa.id,pa.cycle_name,pa.evaluator_user_id,eu.full_name AS evaluator_name,
            pa.evaluatee_faculty_id,ef.full_name AS evaluatee_name,pa.evaluator_role,
            pa.assignment_type,pa.status,pa.submitted_at,COALESCE(pa.is_archived,0) AS is_archived
     FROM peer_assignments pa
     JOIN users eu ON eu.id=pa.evaluator_user_id
     JOIN faculty ef ON ef.id=pa.evaluatee_faculty_id
     LEFT JOIN users target_user ON target_user.id=ef.user_id OR (target_user.id IS NULL AND target_user.email=ef.email)
     WHERE COALESCE(pa.is_archived,0)=0 AND (
       (pa.evaluator_user_id=34 AND pa.evaluator_role='teacher')
       OR (target_user.id=34 AND pa.assignment_type='program_head' AND pa.evaluator_role='program_head')
       OR (pa.evaluator_user_id=30 AND pa.assignment_type='program_head' AND UPPER(COALESCE(ef.program_code,''))<>'BSCPE')
       OR (target_user.id=30 AND pa.assignment_type='program_head' AND pa.evaluator_role='program_head')
     )
     ORDER BY pa.cycle_name,pa.id"
);

$summary = [
    'mode'=>$apply ? 'apply' : 'dry-run',
    'master_assignments'=>[
        'Riza'=>'BSCpE Program Head',
        'Hazel'=>'BSIT Program Head',
    ],
    'assignments_to_archive'=>count($affected),
    'submitted_assignments_retained_but_hidden'=>count(array_filter(
        $affected,
        static fn(array $row): bool => (string)$row['status'] === 'submitted'
    )),
    'assignments'=>$affected,
];

if (!$apply) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $department = admin_one(
        "SELECT id,department_name FROM departments WHERE department_code='CITE' OR department_name LIKE '%Information Technology%' ORDER BY id LIMIT 1"
    );
    if (!$department) throw new RuntimeException('CITE department was not found.');

    $programStmt = $db->prepare(
        'SELECT id,program_code FROM programs WHERE department_id=? AND UPPER(program_code)=? AND is_active=1 LIMIT 1 FOR UPDATE'
    );
    $programs = [];
    foreach (['riza','hazel'] as $key) {
        $programStmt->execute([(int)$department['id'], strtoupper($people[$key]['program'])]);
        $programs[$key] = $programStmt->fetch(PDO::FETCH_ASSOC);
        if (!$programs[$key]) throw new RuntimeException($people[$key]['program'] . ' program was not found.');
    }

    foreach (['riza','hazel'] as $key) {
        $person = $people[$key];
        $program = $programs[$key];
        $db->prepare("UPDATE users SET role='program_head',department=?,program=? WHERE id=?")
            ->execute([$department['department_name'],$program['program_code'],$person['id']]);
        $db->prepare(
            "UPDATE faculty SET department=?,program_code=?,position_title='Program Head'
             WHERE user_id=? OR (user_id IS NULL AND email=(SELECT email FROM users WHERE id=?))"
        )->execute([$department['department_name'],$program['program_code'],$person['id'],$person['id']]);
        $db->prepare('UPDATE programs SET program_head_user_id=NULL WHERE program_head_user_id=? AND id<>?')
            ->execute([$person['id'],$program['id']]);
        $db->prepare('UPDATE programs SET program_head_user_id=? WHERE id=?')
            ->execute([$person['id'],$program['id']]);
    }

    if ($affected !== []) {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $affected);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reason = '[ROLE_CORRECTION:RIZA_HAZEL] Archived incorrect former-role evaluation assignment.';
        $db->prepare(
            "UPDATE peer_assignments SET is_archived=1,archived_at=NOW(),archived_by=?,
             replacement_reason=CONCAT(COALESCE(replacement_reason,''),IF(COALESCE(replacement_reason,'')='','', ' '),?)
             WHERE id IN ({$placeholders})"
        )->execute(array_merge([$actorId,$reason], $ids));
        $db->prepare(
            "UPDATE peer_evaluation_assignments SET is_archived=1,archived_at=NOW(),archived_by=?
             WHERE peer_assignment_id IN ({$placeholders})"
        )->execute(array_merge([$actorId],$ids));
    }

    $periods = admin_all('SELECT id,period_name,date_end,status FROM appraisal_periods ORDER BY id');
    foreach ($periods as $period) {
        foreach (['riza','hazel'] as $key) {
            $person = $people[$key];
            $program = $programs[$key];
            $evidence = admin_all(
                "SELECT DISTINCT evaluator_role FROM peer_assignments
                 WHERE cycle_name=:cycle AND evaluator_user_id=:user_id AND status='submitted'",
                ['cycle'=>$period['period_name'],'user_id'=>$person['id']]
            );
            $roles = array_values(array_unique(array_map(
                static fn(array $row): string => (string)$row['evaluator_role'],
                $evidence
            )));
            $role = count($roles) === 1 && in_array($roles[0], ['teacher','program_head'], true)
                ? $roles[0]
                : 'program_head';
            $isCurrentCorrection = (string)$period['period_name'] === '2026 ACADEMIC YEAR';
            if ($isCurrentCorrection) $role = 'program_head';
            $needsReview = !$isCurrentCorrection && count($roles) !== 1 ? 1 : 0;
            $faculty = admin_one('SELECT id FROM faculty WHERE user_id=:id LIMIT 1', ['id'=>$person['id']]);
            $db->prepare(
                "INSERT INTO evaluation_period_participation
                 (evaluation_period_id,user_id,faculty_id,role_snapshot,department_id,program_id,
                  department_snapshot,program_snapshot,assignment_source,needs_review,program_head_slot,
                  participation_status,changed_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'included',?)
                 ON DUPLICATE KEY UPDATE role_snapshot=VALUES(role_snapshot),department_id=VALUES(department_id),
                  program_id=VALUES(program_id),department_snapshot=VALUES(department_snapshot),
                  program_snapshot=VALUES(program_snapshot),assignment_source=VALUES(assignment_source),
                  needs_review=VALUES(needs_review),program_head_slot=VALUES(program_head_slot),changed_by_user_id=4"
            )->execute([
                $period['id'],$person['id'],(int)($faculty['id'] ?? 0),$role,$department['id'],$program['id'],
                $department['department_name'],$program['program_code'],$isCurrentCorrection ? 'admin' : 'inferred',
                $needsReview,$role === 'program_head' ? $program['id'] : null,$actorId
            ]);
        }
    }

    $db->prepare('INSERT INTO activity_logs (user_id,description) VALUES (?,?)')->execute([
        $actorId,
        'Archived incorrect former-role evaluations for Riza/Hazel and established period-scoped BSCpE/BSIT Program Head assignments.'
    ]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

$current = admin_one("SELECT id,period_name,date_end FROM appraisal_periods WHERE period_name='2026 ACADEMIC YEAR' LIMIT 1");
$generated = $current
    ? dipascaf_upsert_required_assignments_for_period((string)$current['period_name'], (string)$current['date_end'])
    : null;
$summary['applied'] = true;
$summary['generated'] = $generated;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
