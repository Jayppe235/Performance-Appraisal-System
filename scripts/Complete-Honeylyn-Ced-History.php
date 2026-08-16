<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_assignment_generator.php';

$apply = in_array('--apply', $argv, true);
$userCode = '2025111';
$periodIds = [1, 5, 6];
$db = db();

$account = admin_one(
    'SELECT u.id user_id,u.full_name,u.role,f.id faculty_id
       FROM users u JOIN faculty f ON f.user_id=u.id
      WHERE u.user_code=? AND u.is_active=1 LIMIT 1',
    [$userCode]
);
if ($account === null || (string)$account['role'] !== 'dean') {
    throw new RuntimeException('Active Dean account 2025111 was not found.');
}
$userId = (int)$account['user_id'];
$facultyId = (int)$account['faculty_id'];
$periods = admin_all(
    'SELECT id,period_name,date_end FROM appraisal_periods WHERE id IN (1,5,6) ORDER BY id'
);
if (count($periods) !== 3) throw new RuntimeException('The 2024, 2025, and 2026 periods are required.');

$historicalRequirements = static function (int $periodId) use ($userId, $facultyId): array {
    $rows = admin_all(
        "SELECT f.id evaluatee_faculty_id,epp.role_snapshot
           FROM evaluation_period_participation epp
           JOIN users u ON u.id=epp.user_id
           JOIN faculty f ON f.user_id=u.id
          WHERE epp.evaluation_period_id=? AND epp.user_id<>?
            AND epp.department_snapshot='College of Education'
            AND epp.participation_status='included' AND epp.work_status='active'
            AND epp.role_snapshot IN ('teacher','program_head')
          ORDER BY u.full_name",
        [$periodId,$userId]
    );
    $requirements = array_map(static fn(array $row): array => [
        'evaluator_user_id'=>$userId,
        'evaluatee_faculty_id'=>(int)$row['evaluatee_faculty_id'],
        'evaluator_role'=>'dean',
        'assignment_type'=>'dean',
        'questionnaire_type'=>(string)$row['role_snapshot'] === 'teacher' ? 'faculty' : 'admin',
    ], $rows);
    $requirements[] = [
        'evaluator_user_id'=>$userId,'evaluatee_faculty_id'=>$facultyId,
        'evaluator_role'=>'dean','assignment_type'=>'self','questionnaire_type'=>'admin',
    ];
    return $requirements;
};

$plan = [];
foreach ($periods as $period) {
    $requirements = $historicalRequirements((int)$period['id']);
    $plan[(string)$period['period_name']] = count($requirements) + 1; // one peer task
}
if (!$apply) {
    echo json_encode(['mode'=>'dry-run','account'=>$account['full_name'],'periods'=>$plan,'touches_2027'=>false], JSON_PRETTY_PRINT), PHP_EOL;
    exit;
}

$backupDir = __DIR__ . '/../private-backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Cannot create backup directory.');
}
$backupPath = $backupDir . '/honeylyn-ced-history-' . date('Ymd-His') . '.json';
$before = admin_all(
    "SELECT * FROM peer_assignments WHERE evaluator_user_id=? AND cycle_name IN
     ('2024 APPRAISAL PERIOD','2025 APPRAISAL PERIOD','2026 ACADEMIC YEAR') ORDER BY cycle_name,id",
    [$userId]
);
file_put_contents($backupPath, json_encode(['created_at'=>date(DATE_ATOM),'assignments'=>$before], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));

$insert = $db->prepare(
    "INSERT INTO peer_assignments
      (cycle_name,evaluator_user_id,evaluatee_faculty_id,evaluator_role,assignment_type,
       questionnaire_type,status,assigned_at,deadline,is_current,effective_from,
       evaluator_name_snapshot,evaluator_role_snapshot)
     VALUES (?,?,?,?,? ,?,'pending',NOW(),?,1,NOW(),?,'dean')
     ON DUPLICATE KEY UPDATE evaluator_role=VALUES(evaluator_role),
       questionnaire_type=VALUES(questionnaire_type),deadline=VALUES(deadline),
       status=IF(status='submitted',status,'pending'),is_current=1,is_archived=0,
       archived_at=NULL,archived_by=NULL"
);

$evidence = [
    'Provides clear academic direction and follows through on agreed department actions.',
    'Uses classroom and program evidence to guide practical instructional improvements.',
    'Communicates expectations respectfully and supports timely resolution of learner concerns.',
    'Collaborates consistently on curriculum, assessment, and faculty development activities.',
    'Demonstrates dependable professional practice while identifying realistic next steps.',
    'Contributes useful ideas during planning and supports colleagues in completing shared work.',
];
$recommendations = [
    'Expand the use of differentiated strategies and document their effect on learner outcomes.',
    'Strengthen evidence-based monitoring through concise, regularly updated progress records.',
    'Share effective classroom practices more frequently during department learning sessions.',
    'Increase participation in research, extension, and cross-program professional activities.',
    'Continue refining assessment feedback so learners can act on it more quickly.',
    'Build on current strengths through targeted mentoring and professional development.',
];

$completed = [];
$scores = [];
$sequence = 0;
foreach ($periods as $periodIndex => $period) {
    $periodId = (int)$period['id'];
    $periodName = (string)$period['period_name'];
    $deadline = (string)$period['date_end'];
    $requirements = $historicalRequirements($periodId);

    // One Dean-to-Dean peer task per historical period. Prefer the same valid
    // counterpart used by the current roster when that Dean participated.
    $peer = admin_one(
        "SELECT u.id user_id,f.id faculty_id
           FROM evaluation_period_participation epp
           JOIN users u ON u.id=epp.user_id
           JOIN faculty f ON f.user_id=u.id
          WHERE epp.evaluation_period_id=? AND epp.user_id<>?
            AND epp.role_snapshot='dean' AND epp.participation_status='included'
            AND epp.work_status='active' AND u.is_active=1
          ORDER BY (u.full_name='Dr. Melvie F. Bayog RCrim, CST') DESC,u.id LIMIT 1",
        [$periodId,$userId]
    );
    if ($peer === null) throw new RuntimeException("No eligible historical Dean peer for {$periodName}.");
    $requirements[] = [
        'evaluator_user_id'=>$userId,
        'evaluatee_faculty_id'=>(int)$peer['faculty_id'],
        'evaluator_role'=>'dean',
        'assignment_type'=>'peer',
        'questionnaire_type'=>'admin',
    ];

    foreach ($requirements as $requirement) {
        $insert->execute([
            $periodName,$userId,(int)$requirement['evaluatee_faculty_id'],'dean',
            (string)$requirement['assignment_type'],(string)$requirement['questionnaire_type'],
            $deadline,(string)$account['full_name'],
        ]);
        $assignment = admin_one(
            'SELECT * FROM peer_assignments WHERE cycle_name=? AND evaluator_user_id=? AND evaluatee_faculty_id=? AND assignment_type=? AND COALESCE(is_archived,0)=0 LIMIT 1',
            [$periodName,$userId,(int)$requirement['evaluatee_faculty_id'],(string)$requirement['assignment_type']]
        );
        if ($assignment === null) throw new RuntimeException('Historical assignment could not be created.');
        if ((string)$assignment['status'] === 'submitted') continue;

        $form = (string)$assignment['questionnaire_type'] === 'admin' ? 'a' : 'b';
        $categories = $form === 'a' ? dipascaf_form_a_categories() : dipascaf_form_b_categories();
        $items = [];
        $seed = ($periodIndex + 1) * 101 + (++$sequence) * 17 + (int)$assignment['evaluatee_faculty_id'];
        $target = 3.20 + (($seed % 161) / 100); // 3.20–4.80, intentionally varied
        foreach ($categories as $categoryIndex => $category) {
            $answers = [];
            foreach ($category['questions'] as $questionIndex => $question) {
                $low = (int)floor($target);
                $fraction = $target - $low;
                $threshold = (int)round($fraction * 100);
                $mix = ($seed * 13 + $categoryIndex * 29 + $questionIndex * 37) % 100;
                $answers[(string)$question['id']] = min(5, $low + ($mix < $threshold ? 1 : 0));
            }
            $item = [
                'category_id'=>(int)$category['id'],
                'answers'=>$answers,
                'evidence'=>[],
                'behavioral_evidence'=>'SAMPLE DATA: '.$evidence[($seed+$categoryIndex)%count($evidence)],
                'reason_for_rating'=>'SAMPLE DATA: Ratings reflect varied historical performance evidence.',
                'recommendation'=>'SAMPLE DATA: '.$recommendations[($seed+$categoryIndex*2)%count($recommendations)],
            ];
            if ($form === 'a') $items[(string)$category['id']] = $item;
            else $items[] = $item;
        }
        $result = dipascaf_submit_category_results(
            $assignment,$userId,$form,$form === 'a' ? $items : ['sample_data'=>true,'categories'=>$items],$periodName
        );
        $completed[] = (int)$assignment['id'];
        $scores[] = (float)$result['total_weighted_score'];
    }
}

$actor = (int)(admin_one("SELECT id FROM users WHERE role='admin_hr' AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);
$db->prepare('INSERT INTO activity_logs(user_id,description) VALUES(?,?)')->execute([
    $actor,
    'Added varied, labeled sample evaluation history for CED Dean Honeylyn Mahinay in 2024, 2025, and 2026 only.',
]);

echo json_encode([
    'mode'=>'apply','account'=>$account['full_name'],'backup'=>$backupPath,
    'completed_count'=>count($completed),'completed_assignment_ids'=>$completed,
    'distinct_scores'=>count(array_unique(array_map(static fn(float $v): string => number_format($v,4,'.',''),$scores))),
    'score_range'=>$scores ? [min($scores),max($scores)] : [],'touches_2027'=>false,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
