<?php
declare(strict_types=1);

function dashboard_admin_overview(PDO $db, ?array $period, array $query): array
{
    $periodId = (int) ($period['id'] ?? 0);
    $periodName = trim((string) ($period['period_name'] ?? ''));
    $department = trim((string) ($query['department'] ?? ''));
    $program = trim((string) ($query['program'] ?? ''));
    $scopeDepartments = array_values(array_filter(array_map('strval', is_array($query['_scope_departments'] ?? null) ? $query['_scope_departments'] : [])));
    $scopePrograms = array_values(array_filter(array_map('strval', is_array($query['_scope_programs'] ?? null) ? $query['_scope_programs'] : [])));
    $scopeEnforced = !empty($query['_scope_enforced']);
    $scopeDepartmentAliases = [];
    foreach ($scopeDepartments as $scopeDepartment) {
        $scopeDepartmentAliases = array_merge($scopeDepartmentAliases, admin_matching_department_aliases($scopeDepartment) ?: [$scopeDepartment]);
    }
    $scopeDepartmentAliases = array_values(array_unique(array_filter($scopeDepartmentAliases)));
    $departmentAliases = $department !== '' ? admin_matching_department_aliases($department) : [];
    if ($department !== '' && $departmentAliases === []) {
        $departmentAliases = [$department];
    }
    if ($scopeDepartmentAliases !== []) {
        $departmentAliases = $departmentAliases !== []
            ? array_values(array_intersect($departmentAliases, $scopeDepartmentAliases))
            : $scopeDepartmentAliases;
    }
    $effectivePrograms = $program !== '' ? [$program] : $scopePrograms;
    if ($scopePrograms !== []) {
        $effectivePrograms = array_values(array_intersect(array_map('strtoupper', $effectivePrograms), array_map('strtoupper', $scopePrograms)));
    }
    $scopeViolation = ($scopeEnforced && $scopeDepartmentAliases === [] && $scopePrograms === [])
        || ($scopeDepartmentAliases !== [] && $department !== '' && $departmentAliases === [])
        || ($scopePrograms !== [] && $program !== '' && $effectivePrograms === []);
    $allowedTrendDays = [1, 3, 7, 14, 30, 60, 90];
    $requestedTrendDays = (int) ($query['trend_days'] ?? 1);
    $trendDays = in_array($requestedTrendDays, $allowedTrendDays, true) ? $requestedTrendDays : 1;

    $where = ["COALESCE(pa.is_archived,0)=0", "pa.status <> 'not_required'", "COALESCE(f.is_archived,0)=0"];
    $where[] = "(pa.assignment_type<>'peer' OR EXISTS (
        SELECT 1 FROM peer_evaluation_assignments pea
        WHERE pea.peer_assignment_id=pa.id AND COALESCE(pea.is_archived,0)=0
    ))";
    if ($scopeViolation) $where[] = '1=0';
    $params = [];
    if ($periodName !== '') { $where[] = 'pa.cycle_name = ?'; $params[] = $periodName; }
    if ($periodId > 0) {
        $where[] = "EXISTS (SELECT 1 FROM evaluation_period_participation evaluator_epp
                            WHERE evaluator_epp.evaluation_period_id=?
                              AND evaluator_epp.user_id=pa.evaluator_user_id
                              AND evaluator_epp.participation_status='included'
                              AND evaluator_epp.work_status='active'
                              AND evaluator_epp.employment_status IN ('active','newly_added'))";
        $params[] = $periodId;
        $where[] = "EXISTS (SELECT 1 FROM evaluation_period_participation evaluatee_epp
                            WHERE evaluatee_epp.evaluation_period_id=?
                              AND evaluatee_epp.user_id=f.user_id
                              AND evaluatee_epp.participation_status='included'
                              AND evaluatee_epp.work_status='active'
                              AND evaluatee_epp.employment_status IN ('active','newly_added'))";
        $params[] = $periodId;
    }
    if ($departmentAliases !== []) {
        $where[] = 'f.department IN (' . implode(',', array_fill(0, count($departmentAliases), '?')) . ')';
        $params = array_merge($params, $departmentAliases);
    }
    if ($effectivePrograms !== []) {
        $where[] = "UPPER(COALESCE(f.program_code,'')) IN (" . implode(',', array_fill(0, count($effectivePrograms), '?')) . ')';
        $params = array_merge($params, array_map('strtoupper', $effectivePrograms));
    }
    $whereSql = implode(' AND ', $where);

    $rows = admin_all(
        "SELECT pa.id, pa.status, pa.deadline, pa.submitted_at, pa.assignment_type,
                f.id faculty_id, f.user_id evaluatee_user_id, f.full_name evaluatee_name, f.department,
                COALESCE(NULLIF(f.program_code,''),'Unassigned Program') program,
                u.full_name evaluator_name
         FROM peer_assignments pa
         JOIN faculty f ON f.id=pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id=pa.evaluator_user_id
         WHERE {$whereSql}", $params
    );
    $today = new DateTimeImmutable('today');
    $completed = $pending = $overdue = 0;
    $overdueItems = [];
    $pendingDates = [];
    $overdueDates = [];
    $departments = [];
    foreach ($rows as $row) {
        $dept = trim((string) ($row['department'] ?? '')) ?: 'Unassigned Department';
        $departments[$dept] ??= ['department' => $dept, 'completed' => 0, 'pending' => 0, 'overdue' => 0, 'total' => 0];
        $departments[$dept]['total']++;
        $deadline = trim((string) ($row['deadline'] ?? ''));
        $isOverdue = $row['status'] !== 'submitted' && $deadline !== '' && strtotime($deadline) < $today->getTimestamp();
        if ($row['status'] === 'submitted') {
            $completed++; $departments[$dept]['completed']++;
        } elseif ($isOverdue) {
            $pending++; $overdue++; $departments[$dept]['overdue']++; $overdueDates[] = $deadline;
            $due = new DateTimeImmutable($deadline);
            $overdueItems[] = [
                'id' => (int) $row['id'], 'faculty' => (string) $row['evaluatee_name'],
                'evaluator' => (string) ($row['evaluator_name'] ?: 'Unassigned evaluator'),
                'due_date' => $deadline, 'days_overdue' => (int) $due->diff($today)->format('%a'),
            ];
        } else {
            $pending++; $departments[$dept]['pending']++;
            if ($deadline !== '') $pendingDates[] = $deadline;
        }
    }
    usort($overdueItems, static fn($a,$b) => $b['days_overdue'] <=> $a['days_overdue']);
    sort($pendingDates); sort($overdueDates);

    $deptOptions = admin_all("SELECT department_code value, department_name label FROM departments WHERE is_active=1 ORDER BY department_name");
    if ($scopeEnforced && $scopeDepartmentAliases === []) $deptOptions = [];
    if ($scopeDepartmentAliases !== []) {
        $deptOptions = array_values(array_filter($deptOptions, static fn(array $option): bool => count(array_intersect(
            admin_matching_department_aliases((string) ($option['value'] ?? '')),
            $scopeDepartmentAliases
        )) > 0));
    }
    $programSql = "SELECT p.program_code value, p.program_name label, d.department_code department FROM programs p LEFT JOIN departments d ON d.id=p.department_id WHERE p.is_active=1";
    $programParams = [];
    if ($department !== '') { $programSql .= ' AND (d.department_code = ? OR d.department_name = ?)'; $programParams[] = $department; $programParams[] = admin_normalize_department_name($department); }
    $programOptions = admin_all($programSql . ' ORDER BY program_name', $programParams);
    if ($scopeEnforced && $scopeDepartmentAliases === [] && $scopePrograms === []) $programOptions = [];
    if ($scopePrograms !== []) {
        $allowedProgramCodes = array_map('strtoupper', $scopePrograms);
        $programOptions = array_values(array_filter($programOptions, static fn(array $option): bool => in_array(strtoupper((string) ($option['value'] ?? '')), $allowedProgramCodes, true)));
    } elseif ($scopeDepartmentAliases !== []) {
        $programOptions = array_values(array_filter($programOptions, static fn(array $option): bool => count(array_intersect(
            admin_matching_department_aliases((string) ($option['department'] ?? '')),
            $scopeDepartmentAliases
        )) > 0));
    }
    $missingDeans = admin_all("SELECT department_code code, department_name name FROM departments WHERE is_active=1 AND dean_user_id IS NULL" . ($department !== '' ? " AND (department_code=? OR department_name=?)" : '') . " ORDER BY department_name", $department !== '' ? [$department,$department] : []);
    if ($scopeDepartmentAliases !== []) {
        $missingDeans = array_values(array_filter($missingDeans, static fn(array $row): bool => count(array_intersect(
            admin_matching_department_aliases((string) ($row['code'] ?? '')),
            $scopeDepartmentAliases
        )) > 0));
    }
    $missingHeadSql = "SELECT p.program_code code, p.program_name name, d.department_code FROM programs p LEFT JOIN departments d ON d.id=p.department_id WHERE p.is_active=1 AND p.program_head_user_id IS NULL";
    $missingHeadParams = [];
    if ($department !== '') { $missingHeadSql .= " AND (d.department_code=? OR d.department_name=?)"; $missingHeadParams[]=$department; $missingHeadParams[]=admin_normalize_department_name($department); }
    if ($program !== '') { $missingHeadSql .= " AND p.program_code=?"; $missingHeadParams[]=$program; }
    $missingHeads = admin_all($missingHeadSql . " ORDER BY p.program_name", $missingHeadParams);
    if ($scopePrograms !== []) {
        $allowedProgramCodes = array_map('strtoupper', $scopePrograms);
        $missingHeads = array_values(array_filter($missingHeads, static fn(array $row): bool => in_array(strtoupper((string) ($row['code'] ?? '')), $allowedProgramCodes, true)));
    } elseif ($scopeDepartmentAliases !== []) {
        $missingHeads = array_values(array_filter($missingHeads, static fn(array $row): bool => count(array_intersect(
            admin_matching_department_aliases((string) ($row['department_code'] ?? '')),
            $scopeDepartmentAliases
        )) > 0));
    }

    $scoreWhere = ["s.submission_status='submitted'", "COALESCE(f.is_archived,0)=0"];
    if ($scopeViolation) $scoreWhere[]='1=0';
    $scoreParams = [];
    if ($periodName !== '') { $scoreWhere[]='s.evaluation_period=?'; $scoreParams[]=$periodName; }
    if ($departmentAliases !== []) {
        $scoreWhere[]='f.department IN (' . implode(',', array_fill(0, count($departmentAliases), '?')) . ')';
        $scoreParams=array_merge($scoreParams,$departmentAliases);
    }
    if ($effectivePrograms !== []) {
        $scoreWhere[]="UPPER(COALESCE(f.program_code,'')) IN (" . implode(',', array_fill(0, count($effectivePrograms), '?')) . ')';
        $scoreParams=array_merge($scoreParams,array_map('strtoupper',$effectivePrograms));
    }
    $scores = dashboard_table_exists($db, 'pmas_evaluator_results_summary') ? admin_all(
        "SELECT f.id, f.full_name, LEAST(100,GREATEST(0,AVG(COALESCE(s.overall_rating,s.average_category_score))*20)) score
         FROM pmas_evaluator_results_summary s JOIN faculty f ON f.id=s.faculty_id
         WHERE " . implode(' AND ', $scoreWhere) . " GROUP BY f.id,f.full_name", $scoreParams
    ) : [];
    $bands = ['below_50'=>0,'between_50_75'=>0,'above_75'=>0,'without_results'=>0];
    foreach ($scores as $score) { $v=(float)$score['score']; if ($v<50) $bands['below_50']++; elseif ($v<=75) $bands['between_50_75']++; else $bands['above_75']++; }
    $facultyScopeWhere = ['COALESCE(is_archived,0)=0']; $facultyScopeParams=[];
    if ($scopeViolation) $facultyScopeWhere[]='1=0';
    if ($departmentAliases !== []) {
        $facultyScopeWhere[]='department IN (' . implode(',', array_fill(0, count($departmentAliases), '?')) . ')';
        $facultyScopeParams=array_merge($facultyScopeParams,$departmentAliases);
    }
    if ($effectivePrograms !== []) {
        $facultyScopeWhere[]="UPPER(COALESCE(program_code,'')) IN (" . implode(',', array_fill(0, count($effectivePrograms), '?')) . ')';
        $facultyScopeParams=array_merge($facultyScopeParams,array_map('strtoupper',$effectivePrograms));
    }
    $facultyScope = dashboard_count($db, 'SELECT COUNT(*) FROM faculty WHERE '.implode(' AND ',$facultyScopeWhere), $facultyScopeParams);
    $bands['without_results'] = max(0, $facultyScope-count($scores));

    $activity = [];
    foreach ($rows as $row) if ($row['status']==='submitted' && !empty($row['submitted_at'])) $activity[] = [
        'id'=>'peer-'.$row['id'], 'actor'=>(string)($row['evaluator_name'] ?: 'An evaluator'),
        'subject'=>(string)$row['evaluatee_name'], 'action'=>'submitted an evaluation for', 'occurred_at'=>(string)$row['submitted_at']
    ];
    if (dashboard_table_exists($db, 'pmas_self_evaluations')) {
        $selfWhere = ["se.status IN ('submitted','approved')", "se.submitted_at IS NOT NULL", "COALESCE(f.is_archived,0)=0"];
        if ($scopeViolation) $selfWhere[]='1=0';
        $selfParams = [];
        if ($periodName !== '') { $selfWhere[]='se.evaluation_period=?'; $selfParams[]=$periodName; }
        if ($departmentAliases !== []) {
            $selfWhere[]='f.department IN (' . implode(',', array_fill(0, count($departmentAliases), '?')) . ')';
            $selfParams=array_merge($selfParams,$departmentAliases);
        }
        if ($effectivePrograms !== []) {
            $selfWhere[]="UPPER(COALESCE(f.program_code,'')) IN (" . implode(',', array_fill(0, count($effectivePrograms), '?')) . ')';
            $selfParams=array_merge($selfParams,array_map('strtoupper',$effectivePrograms));
        }
        foreach (admin_all("SELECT se.id,f.full_name,se.submitted_at FROM pmas_self_evaluations se JOIN faculty f ON f.user_id=se.user_id WHERE ".implode(' AND ',$selfWhere)." ORDER BY se.submitted_at DESC LIMIT 10",$selfParams) as $row) {
            $activity[]=['id'=>'self-'.$row['id'],'actor'=>(string)$row['full_name'],'subject'=>'their self-evaluation','action'=>'submitted','occurred_at'=>(string)$row['submitted_at']];
        }
    }
    usort($activity, static fn($a,$b)=>strcmp($b['occurred_at'],$a['occurred_at'])); $activity=array_slice($activity,0,10);

    $participantWhere = ["u.is_active=1", "u.role IN ('vpaa','dean','program_head','teacher')"];
    $participantParams = [];
    if ($periodId > 0) {
        $participantWhere[] = 'epp.evaluation_period_id=?';
        $participantParams[] = $periodId;
        $participantWhere[] = "epp.participation_status='included'";
        $participantWhere[] = "epp.work_status='active'";
        $participantWhere[] = "epp.employment_status IN ('active','newly_added')";
    }
    if ($scopeViolation) $participantWhere[] = '1=0';
    if ($departmentAliases !== []) {
        $participantWhere[] = "COALESCE(NULLIF(epp.department_snapshot,''),u.department) IN ("
            . implode(',', array_fill(0, count($departmentAliases), '?')) . ')';
        $participantParams = array_merge($participantParams, $departmentAliases);
    }
    if ($effectivePrograms !== []) {
        $participantWhere[] = "UPPER(COALESCE(NULLIF(epp.program_snapshot,''),u.program,'')) IN ("
            . implode(',', array_fill(0, count($effectivePrograms), '?')) . ')';
        $participantParams = array_merge($participantParams, array_map('strtoupper', $effectivePrograms));
    }
    $participantRows = admin_all(
        "SELECT DISTINCT u.id,u.full_name,epp.faculty_id,
                COALESCE(NULLIF(epp.role_snapshot,''),u.role) period_role,
                COALESCE(NULLIF(epp.department_snapshot,''),u.department,'Unassigned Department') department,
                COALESCE(NULLIF(epp.program_snapshot,''),u.program,'') program
         FROM users u "
        . ($periodId > 0
            ? 'JOIN evaluation_period_participation epp ON epp.user_id=u.id '
            : 'LEFT JOIN evaluation_period_participation epp ON 1=0 ')
        . 'WHERE ' . implode(' AND ', $participantWhere),
        $participantParams
    );
    $participantUserIds = array_map('intval', array_column($participantRows, 'id'));
    $periodPeople = count($participantUserIds);
    $assignedParticipantIds = array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['evaluatee_user_id'] ?? 0),
        array_filter($rows, static fn(array $row): bool =>
            in_array((int)($row['evaluatee_user_id'] ?? 0), $participantUserIds, true)
        )
    )));
    $peopleWithAssignments = count($assignedParticipantIds);

    // Assignment completion alone can report a false 100% when an included
    // person has no generated evaluatee assignment. Count every such roster
    // gap as one pending requirement in both overall and department progress.
    $missingAssignmentPeople = array_values(array_filter(
        $participantRows,
        static fn(array $participant): bool => !in_array((int)$participant['id'], $assignedParticipantIds, true)
    ));
    foreach ($missingAssignmentPeople as $participant) {
        $dept = trim((string)($participant['department'] ?? '')) ?: 'Unassigned Department';
        $departments[$dept] ??= ['department'=>$dept,'completed'=>0,'pending'=>0,'overdue'=>0,'total'=>0];
        $departments[$dept]['pending']++;
        $departments[$dept]['total']++;
        $pending++;
    }

    $peerEvaluateeIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (string)($row['assignment_type'] ?? '') === 'peer'
            ? (int)($row['evaluatee_user_id'] ?? 0) : 0,
        $rows
    ))));
    $missingPeerPeople = array_values(array_filter(
        $participantRows,
        static fn(array $participant): bool => (int)($participant['faculty_id'] ?? 0) > 0
            && !in_array((int)$participant['id'], $peerEvaluateeIds, true)
    ));
    foreach ($missingPeerPeople as &$participant) {
        $participant['requirement_type'] = 'peer_evaluation';
        $dept = trim((string)($participant['department'] ?? '')) ?: 'Unassigned Department';
        $departments[$dept] ??= ['department'=>$dept,'completed'=>0,'pending'=>0,'overdue'=>0,'total'=>0];
        $departments[$dept]['pending']++;
        $departments[$dept]['total']++;
        $pending++;
    }
    unset($participant);

    $selfEvaluationsByUser = [];
    if ($periodName !== '' && dashboard_table_exists($db, 'pmas_self_evaluations')) {
        foreach (admin_all('SELECT user_id,status FROM pmas_self_evaluations WHERE evaluation_period=? ORDER BY id', [$periodName]) as $selfEvaluation) {
            $selfEvaluationsByUser[(int)$selfEvaluation['user_id']] = (string)$selfEvaluation['status'];
        }
    }
    $missingSelfEvaluationPeople = [];
    foreach ($participantRows as $participant) {
        $selfEvaluationStatus = $selfEvaluationsByUser[(int)$participant['id']] ?? 'missing';
        $selfEvaluationComplete = $selfEvaluationStatus === 'submitted';
        $dept = trim((string)($participant['department'] ?? '')) ?: 'Unassigned Department';
        $departments[$dept] ??= ['department'=>$dept,'completed'=>0,'pending'=>0,'overdue'=>0,'total'=>0];
        $departments[$dept]['total']++;
        if ($selfEvaluationComplete) {
            $departments[$dept]['completed']++;
            $completed++;
        } else {
            $departments[$dept]['pending']++;
            $pending++;
            $participant['requirement_type'] = 'self_evaluation';
            $participant['requirement_status'] = $selfEvaluationStatus;
            $missingSelfEvaluationPeople[] = $participant;
        }
    }
    $effectiveTotal = count($rows) + count($missingAssignmentPeople)
        + count($missingPeerPeople) + count($participantRows);

    $current = [
        'overdue'=>$overdue,
        'below_50'=>$bands['below_50'],
        'period_people'=>$periodPeople,
        'people_with_assignments'=>$peopleWithAssignments,
        'departments_need_dean'=>count($missingDeans),
        'programs_need_head'=>count($missingHeads),
        'pending'=>$pending,
        'completed'=>$completed,
    ];
    $db->exec("CREATE TABLE IF NOT EXISTS admin_dashboard_snapshots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,snapshot_date DATE NOT NULL,period_id INT NOT NULL DEFAULT 0,department VARCHAR(160) NOT NULL DEFAULT '',program VARCHAR(160) NOT NULL DEFAULT '',metrics_json JSON NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_admin_dashboard_snapshot(snapshot_date,period_id,department,program)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $snapshotDepartment = $department !== '' ? $department : ($scopeDepartments !== [] ? '@scope:' . substr(sha1(implode('|',$scopeDepartments)),0,16) : '');
    $snapshotProgram = $program !== '' ? $program : ($scopePrograms !== [] ? '@scope:' . substr(sha1(implode('|',$scopePrograms)),0,16) : '');
    $snap = $db->prepare("INSERT INTO admin_dashboard_snapshots(snapshot_date,period_id,department,program,metrics_json) VALUES(CURDATE(),?,?,?,?) ON DUPLICATE KEY UPDATE metrics_json=VALUES(metrics_json),updated_at=CURRENT_TIMESTAMP");
    $snap->execute([$periodId,$snapshotDepartment,$snapshotProgram,json_encode($current)]);
    $pastStmt=$db->prepare("SELECT metrics_json FROM admin_dashboard_snapshots WHERE snapshot_date<=DATE_SUB(CURDATE(),INTERVAL ? DAY) AND period_id=? AND department=? AND program=? ORDER BY snapshot_date DESC LIMIT 1");
    $pastStmt->execute([$trendDays,$periodId,$snapshotDepartment,$snapshotProgram]); $past=json_decode((string)($pastStmt->fetchColumn() ?: ''),true);
    $trends=[]; foreach($current as $key=>$value) $trends[$key]=['delta'=>is_array($past)?$value-(int)($past[$key]??0):null,'available'=>is_array($past)];

    return [
        'filters'=>['departments'=>$deptOptions,'programs'=>$programOptions,'selected'=>['period_id'=>$periodId,'department'=>$department,'program'=>$program]],
        'progress'=>['total'=>$effectiveTotal,'completed'=>$completed,'pending'=>$pending-$overdue,'overdue'=>$overdue,'percentage'=>$effectiveTotal?round($completed/$effectiveTotal*100,1):0],
        'counts'=>$current,'trends'=>$trends,'trend_days'=>$trendDays,
        'deadlines'=>['pending'=>$pendingDates[0]??null,'overdue'=>$overdueDates[0]??null],
        'details'=>[
            'overdue'=>array_slice($overdueItems,0,50),
            'missing_assignments'=>array_merge($missingAssignmentPeople,$missingPeerPeople,$missingSelfEvaluationPeople),
        ],
        'department_breakdown'=>array_values($departments),'performance_distribution'=>$bands,'activity'=>$activity,
    ];
}
