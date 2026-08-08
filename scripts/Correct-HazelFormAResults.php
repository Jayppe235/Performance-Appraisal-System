<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);
$db = db();

$hazel = $db->query(
    "SELECT u.id AS user_id, f.id AS faculty_id, u.full_name, u.role, f.position_title
     FROM users u JOIN faculty f ON f.user_id=u.id
     WHERE u.id=34 AND u.full_name='Hazel Joy M. Gadingan' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$hazel || $hazel['role'] !== 'program_head') {
    throw new RuntimeException('Hazel was not found as the expected Program Head; no correction was made.');
}

$stmt = $db->prepare(
    "SELECT DISTINCT r.assignment_id, r.evaluation_period, pa.status, pa.questionnaire_type
     FROM pmas_form_b_category_results r
     JOIN peer_assignments pa ON pa.id=r.assignment_id
     WHERE r.evaluatee_faculty_id=:faculty_id
       AND COALESCE(r.is_archived,0)=0
       AND pa.questionnaire_type='admin'"
);
$stmt->execute(['faculty_id'=>(int)$hazel['faculty_id']]);
$invalidAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'mode'=>$apply ? 'apply' : 'dry-run',
    'user'=>$hazel['full_name'],
    'role'=>$hazel['role'],
    'position_title'=>$hazel['position_title'],
    'invalid_form_b_assignments'=>$invalidAssignments,
];

if (!$apply || $invalidAssignments === []) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    $archiveResults = $db->prepare(
        "UPDATE pmas_form_b_category_results
         SET is_archived=1, archived_at=NOW()
         WHERE assignment_id=:assignment_id AND COALESCE(is_archived,0)=0"
    );
    $reopenAssignment = $db->prepare(
        "UPDATE peer_assignments
         SET questionnaire_type='admin', status='pending', submitted_at=NULL,
             is_archived=0, archived_at=NULL, archived_by=NULL
         WHERE id=:assignment_id"
    );
    foreach ($invalidAssignments as $assignment) {
        $id = (int)$assignment['assignment_id'];
        $archiveResults->execute(['assignment_id'=>$id]);
        $reopenAssignment->execute(['assignment_id'=>$id]);
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}

$summary['archived_form_b_rows'] = count($invalidAssignments);
$summary['reopened_for_form_a'] = array_map('intval', array_column($invalidAssignments, 'assignment_id'));
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
