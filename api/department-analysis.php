<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/evaluation_consistency_sync.php';
require_once __DIR__ . '/../includes/subject_assignments.php';

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

function program_analysis_role_label(string $role): string
{
    return match ($role) {
        'dean' => 'Dean',
        'program_head' => 'Program Head',
        default => 'Faculty',
    };
}

function program_analysis_role_form(string $role): string
{
    return in_array($role, ['dean', 'program_head'], true) ? 'Form A' : 'Form B';
}

function program_analysis_score_interpretation(float $score): string
{
    if ($score >= 4.5) {
        return 'Excellent';
    }
    if ($score >= 3.75) {
        return 'Very Satisfactory';
    }
    if ($score >= 3.0) {
        return 'Satisfactory';
    }
    return 'Needs Improvement';
}

function program_analysis_priority(float $overallScore, float $categoryScore): string
{
    if ($overallScore < 3.0 || $categoryScore < 3.0) {
        return 'High';
    }
    if ($overallScore < 3.75 || $categoryScore < 3.5) {
        return 'Medium';
    }
    return 'Low';
}

function program_analysis_recommendation_type(float $overallScore, float $categoryScore, string $category): string
{
    $key = strtolower($category);
    if ($overallScore < 3.0 || $categoryScore < 3.0) {
        return str_contains($key, 'interpersonal') || str_contains($key, 'team') ? 'Mentoring' : 'Coaching';
    }
    if (str_contains($key, 'classroom') || str_contains($key, 'communication') || str_contains($key, 'innovation')) {
        return 'Workshop';
    }
    return 'Seminar';
}

function program_analysis_recommendation_catalog(): array
{
    return [
        'Form A' => [
            'leadership' => [
                'title' => 'Academic Leadership and Strategic Management Seminar',
                'outcome' => 'Improved planning, supervision, decision-making, and academic unit leadership.',
                'external' => 'CHED Executive Development Program for Academic Administrators',
            ],
            'management' => [
                'title' => 'Academic Leadership and Strategic Management Seminar',
                'outcome' => 'Improved planning, supervision, decision-making, and academic unit leadership.',
                'external' => 'CHED Executive Development Program for Academic Administrators',
            ],
            'job knowledge' => [
                'title' => 'Quality Assurance and Academic Operations Workshop',
                'outcome' => 'Stronger role mastery, process quality, and evidence-based performance delivery.',
                'external' => 'PAASCU Quality Assurance Training for Academic Leaders',
            ],
            'quality' => [
                'title' => 'Quality Assurance and Academic Operations Workshop',
                'outcome' => 'Stronger role mastery, process quality, and evidence-based performance delivery.',
                'external' => 'PAASCU Quality Assurance Training for Academic Leaders',
            ],
            'communication' => [
                'title' => 'Executive Communication and Constructive Feedback Workshop',
                'outcome' => 'Clearer communication, documentation, consultation, and feedback practices.',
                'external' => 'Executive Communication Program for Higher Education Leaders',
            ],
            'interpersonal' => [
                'title' => 'Collaborative Leadership and Team Relationship Mentoring',
                'outcome' => 'Improved teamwork, conflict handling, collegiality, and professional collaboration.',
                'external' => 'Team Leadership and Conflict Management Training',
            ],
            'teamwork' => [
                'title' => 'Collaborative Leadership and Team Relationship Mentoring',
                'outcome' => 'Improved teamwork, conflict handling, collegiality, and professional collaboration.',
                'external' => 'Team Leadership and Conflict Management Training',
            ],
            'initiative' => [
                'title' => 'Innovation, Initiative, and Institutional Improvement Workshop',
                'outcome' => 'Greater innovation, resourcefulness, and proactive improvement of academic services.',
                'external' => 'Design Thinking and Innovation in Higher Education Workshop',
            ],
            'innovation' => [
                'title' => 'Innovation, Initiative, and Institutional Improvement Workshop',
                'outcome' => 'Greater innovation, resourcefulness, and proactive improvement of academic services.',
                'external' => 'Design Thinking and Innovation in Higher Education Workshop',
            ],
            'institutional' => [
                'title' => 'Mission Alignment and Institutional Values Seminar',
                'outcome' => 'Stronger alignment with institutional mission, policies, and service expectations.',
                'external' => 'Institutional Mission and Governance Seminar',
            ],
            'decorum' => [
                'title' => 'Professional Ethics, Decorum, and Academic Leadership Seminar',
                'outcome' => 'Improved professionalism, ethical conduct, and role-modeling in leadership practice.',
                'external' => 'Professional Ethics for Academic Administrators Program',
            ],
            'attendance' => [
                'title' => 'Accountability, Time Management, and Administrative Reliability Coaching',
                'outcome' => 'Improved punctuality, reliability, deadline management, and administrative discipline.',
                'external' => 'Productivity and Time Management Training for Education Leaders',
            ],
            'punctuality' => [
                'title' => 'Accountability, Time Management, and Administrative Reliability Coaching',
                'outcome' => 'Improved punctuality, reliability, deadline management, and administrative discipline.',
                'external' => 'Productivity and Time Management Training for Education Leaders',
            ],
        ],
        'Form B' => [
            'teaching' => [
                'title' => 'Teaching Effectiveness and Outcomes-Based Education Seminar',
                'outcome' => 'Improved lesson design, learning facilitation, and measurable student outcomes.',
                'external' => 'CHED Outcomes-Based Education Faculty Development Training',
            ],
            'classroom' => [
                'title' => 'Classroom Management and Learner Engagement Workshop',
                'outcome' => 'Improved classroom routines, learner engagement, and positive learning climate.',
                'external' => 'Classroom Management and Student Engagement Training',
            ],
            'instruction' => [
                'title' => 'Instructional Delivery and Active Learning Workshop',
                'outcome' => 'More effective instructional strategies, facilitation, and learner-centered delivery.',
                'external' => 'Active Learning Strategies for Higher Education Workshop',
            ],
            'assessment' => [
                'title' => 'Assessment, Rubrics, and Constructive Feedback Seminar',
                'outcome' => 'Improved assessment design, feedback quality, and evidence of student learning.',
                'external' => 'Assessment of Student Learning and Rubrics Training',
            ],
            'feedback' => [
                'title' => 'Assessment, Rubrics, and Constructive Feedback Seminar',
                'outcome' => 'Improved assessment design, feedback quality, and evidence of student learning.',
                'external' => 'Assessment of Student Learning and Rubrics Training',
            ],
            'professional growth' => [
                'title' => 'Professional Growth and Reflective Teaching Development Program',
                'outcome' => 'Stronger reflective practice, professional updating, and continuous improvement habits.',
                'external' => 'Faculty Professional Development and Reflective Practice Program',
            ],
            'research' => [
                'title' => 'Research, Extension, and Scholarly Productivity Workshop',
                'outcome' => 'Increased research engagement, extension participation, and scholarly output.',
                'external' => 'Research Capability Building and Extension Services Training',
            ],
            'extension' => [
                'title' => 'Research, Extension, and Scholarly Productivity Workshop',
                'outcome' => 'Increased research engagement, extension participation, and scholarly output.',
                'external' => 'Research Capability Building and Extension Services Training',
            ],
            'student engagement' => [
                'title' => 'Student Engagement and Inclusive Teaching Seminar',
                'outcome' => 'Improved motivation, participation, inclusion, and student support strategies.',
                'external' => 'Inclusive Education and Student Engagement Training',
            ],
            'institutional' => [
                'title' => 'Institutional Commitment and Faculty Service Seminar',
                'outcome' => 'Stronger institutional participation, service orientation, and policy alignment.',
                'external' => 'Institutional Service and Professional Commitment Seminar',
            ],
            'commitment' => [
                'title' => 'Institutional Commitment and Faculty Service Seminar',
                'outcome' => 'Stronger institutional participation, service orientation, and policy alignment.',
                'external' => 'Institutional Service and Professional Commitment Seminar',
            ],
        ],
    ];
}

function program_analysis_catalog_entry(string $formType, string $category): array
{
    $key = strtolower($category);
    foreach (program_analysis_recommendation_catalog()[$formType] ?? [] as $needle => $entry) {
        if (str_contains($key, $needle)) {
            return $entry;
        }
    }

    return $formType === 'Form A'
        ? [
            'title' => 'Academic Leadership Professional Excellence Seminar',
            'outcome' => 'Improved leadership effectiveness, administrative quality, and institutional contribution.',
            'external' => 'Higher Education Leadership Development Program',
        ]
        : [
            'title' => 'Targeted Faculty Development Seminar for ' . ($category !== '' ? $category : 'Professional Growth'),
            'outcome' => 'Improved instructional practice, professional growth, and student learning support.',
            'external' => 'Faculty Development Program for Teaching Excellence',
        ];
}

function program_analysis_seminar(string $category, string $formType = 'Form B'): string
{
    return program_analysis_catalog_entry($formType, $category)['title'];
}

function program_analysis_recommendation(array $input): array
{
    $category = (string) ($input['category'] ?? 'Professional Growth');
    $formType = (string) ($input['form_type'] ?? 'Form B');
    $role = (string) ($input['role'] ?? 'teacher');
    $overallScore = (float) ($input['overall_score'] ?? 0);
    $categoryScore = (float) ($input['category_score'] ?? $overallScore);
    $entry = program_analysis_catalog_entry($formType, $category);
    $interpretation = program_analysis_score_interpretation($overallScore);
    $priority = program_analysis_priority($overallScore, $categoryScore);
    $type = program_analysis_recommendation_type($overallScore, $categoryScore, $category);
    $roleLabel = program_analysis_role_label($role);

    if (in_array($interpretation, ['Excellent', 'Very Satisfactory'], true)) {
        $reason = "{$roleLabel} performance is {$interpretation}; enrichment is recommended to sustain strengths while improving {$category}.";
    } elseif ($interpretation === 'Satisfactory') {
        $reason = "{$roleLabel} performance is Satisfactory; continuous improvement should prioritize the lower-rated category {$category}.";
    } else {
        $reason = "{$roleLabel} performance needs targeted support; {$category} is among the lowest-rated categories and requires structured intervention.";
    }

    return [
        'id' => (string) ($input['id'] ?? uniqid('rec_', true)),
        'evaluation_period_id' => (int) ($input['evaluation_period_id'] ?? 0),
        'department_id' => (string) ($input['department_id'] ?? ''),
        'user_id' => (int) ($input['user_id'] ?? 0),
        'user_role' => $role,
        'role_label' => $roleLabel,
        'form_type' => $formType,
        'overall_score' => round($overallScore, 2),
        'overall_interpretation' => $interpretation,
        'weak_category' => $category,
        'category_score' => round($categoryScore, 2),
        'recommendation_title' => $entry['title'],
        'recommendation_reason' => $reason,
        'recommendation_type' => $type,
        'priority_level' => $priority,
        'expected_outcome' => $entry['outcome'],
        'source_type' => 'Internal',
        'external_title' => $entry['external'],
        'created_at' => date('c'),
    ];
}

function program_analysis_external_recommendation(array $recommendation): array
{
    return [
        'id' => $recommendation['id'] . '_external',
        'evaluation_period_id' => $recommendation['evaluation_period_id'],
        'department_id' => $recommendation['department_id'],
        'user_id' => $recommendation['user_id'],
        'user_role' => $recommendation['user_role'],
        'role_label' => $recommendation['role_label'],
        'form_type' => $recommendation['form_type'],
        'overall_score' => $recommendation['overall_score'],
        'weak_category' => $recommendation['weak_category'],
        'recommendation_title' => $recommendation['external_title'],
        'recommendation_reason' => 'External provider option aligned with the same PMAS category and development priority.',
        'recommendation_type' => 'Seminar',
        'priority_level' => $recommendation['priority_level'],
        'expected_outcome' => $recommendation['expected_outcome'],
        'source_type' => 'External',
        'created_at' => $recommendation['created_at'],
    ];
}

function department_analysis_role_from_faculty(array $faculty): string
{
    $role = strtolower(trim((string) ($faculty['user_role'] ?? '')));
    $position = strtolower(trim((string) ($faculty['position_title'] ?? '')));

    if ($role === '' || $role === 'teacher') {
        if (str_contains($position, 'dean')) {
            return 'dean';
        }
        if (str_contains($position, 'program head')) {
            return 'program_head';
        }
        return 'teacher';
    }

    return $role;
}

function department_analysis_program_codes(array $programs): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn (array $program): string => strtoupper(trim((string) ($program['program_code'] ?? ''))),
        $programs
    ))));
}

function department_analysis_program_key(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value))) ?? '');
}

function department_analysis_canonical_program(string $value, array $programAliasMap): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    return $programAliasMap[department_analysis_program_key($trimmed)] ?? $trimmed;
}

function department_analysis_department_aliases(array $departments): array
{
    $aliases = [];
    foreach ($departments as $department) {
        $value = trim((string) $department);
        if ($value === '') {
            continue;
        }
        $matched = admin_matching_department_aliases($value);
        $aliases = array_merge($aliases, $matched !== [] ? $matched : [$value]);
    }

    return array_values(array_unique(array_filter($aliases)));
}

function department_analysis_user_scope(array $user, int $evaluationPeriodId = 0): array
{
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $scope = [
        'role' => $role,
        'departments' => [],
        'program_codes' => [],
        'include_roles' => ['teacher', 'program_head'],
        'label' => 'All departments',
        'is_limited' => false,
    ];

    if ($role === 'dean') {
        require_once __DIR__ . '/../includes/dean_data.php';
        $departments = dean_departments($userId);
        $scope['departments'] = department_analysis_department_aliases($departments);
        $scope['include_roles'] = ['teacher', 'program_head'];
        $scope['label'] = implode(', ', $departments) ?: 'Assigned Department';
        $scope['is_limited'] = true;
        return $scope;
    }

    if ($role === 'program_head') {
        require_once __DIR__ . '/../includes/program_head_data.php';
        $programs = program_head_programs($userId, $evaluationPeriodId);
        $scope['program_codes'] = department_analysis_program_codes($programs);
        $programDepartments = [];
        foreach ($programs as $program) {
            $programDepartments[] = (string) ($program['department_code'] ?? '');
            $programDepartments[] = (string) ($program['department_name'] ?? '');
        }

        $fallbackProgram = strtoupper(trim((string) ($user['program'] ?? '')));
        if ($fallbackProgram !== '') {
            $scope['program_codes'][] = $fallbackProgram;
        }
        $fallbackDepartment = trim((string) ($user['department'] ?? ''));
        if ($fallbackDepartment !== '') {
            $programDepartments[] = $fallbackDepartment;
        }
        $scope['program_codes'] = array_values(array_unique(array_filter($scope['program_codes'])));
        $scope['departments'] = department_analysis_department_aliases($programDepartments);
        $scope['include_roles'] = ['teacher'];
        $scope['label'] = implode(', ', $scope['program_codes']) ?: 'Assigned Program';
        $scope['is_limited'] = true;
        return $scope;
    }

    if ($role === 'vpaa') {
        $scope['include_roles'] = ['dean', 'program_head', 'teacher'];
        $scope['label'] = 'All Departments';
        $scope['is_limited'] = false;
        return $scope;
    }

    return $scope;
}

function department_analysis_program_in_scope(array $programInfo, array $scope): bool
{
    if (!$scope['is_limited']) {
        return true;
    }

    if (($scope['role'] ?? '') === 'program_head') {
        $programCode = strtoupper(trim((string) ($programInfo['program_code'] ?? '')));
        if ($programCode === '' || !in_array($programCode, $scope['program_codes'], true)) {
            return false;
        }

        if (($scope['departments'] ?? []) === []) {
            return true;
        }

        $departmentValues = [
            (string) ($programInfo['department_name'] ?? ''),
            (string) ($programInfo['department_code'] ?? ''),
        ];
        foreach ($departmentValues as $department) {
            foreach ($scope['departments'] as $alias) {
                if ($department !== '' && strcasecmp($department, (string) $alias) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    if (($scope['role'] ?? '') === 'dean') {
        $departmentValues = [
            (string) ($programInfo['department_name'] ?? ''),
            (string) ($programInfo['department_code'] ?? ''),
        ];
        foreach ($departmentValues as $department) {
            if (in_array($department, $scope['departments'], true)) {
                return true;
            }
            foreach ($scope['departments'] as $alias) {
                if (strcasecmp($department, (string) $alias) === 0) {
                    return true;
                }
            }
        }
    }

    return false;
}

function department_analysis_faculty_in_scope(array $faculty, array $programInfo, array $scope): bool
{
    $resolvedRole = department_analysis_role_from_faculty($faculty);
    if (!in_array($resolvedRole, $scope['include_roles'], true)) {
        return false;
    }

    if (!$scope['is_limited']) {
        return true;
    }

    $programCode = strtoupper(trim((string) ($faculty['program_code'] ?? '')));
    if (($scope['role'] ?? '') === 'program_head') {
        if ($programCode === '' || !in_array($programCode, $scope['program_codes'], true)) {
            return false;
        }

        if (($scope['departments'] ?? []) === []) {
            return true;
        }

        $departmentValues = [
            (string) ($faculty['department'] ?? ''),
            (string) ($faculty['user_department'] ?? ''),
            (string) ($programInfo['department_name'] ?? ''),
            (string) ($programInfo['department_code'] ?? ''),
        ];
        foreach ($departmentValues as $department) {
            foreach ($scope['departments'] as $alias) {
                if ($department !== '' && strcasecmp($department, (string) $alias) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    if (($scope['role'] ?? '') === 'dean') {
        $departmentValues = [
            (string) ($faculty['department'] ?? ''),
            (string) ($faculty['user_department'] ?? ''),
            (string) ($programInfo['department_name'] ?? ''),
            (string) ($programInfo['department_code'] ?? ''),
        ];
        foreach ($departmentValues as $department) {
            if ($department === '') {
                continue;
            }
            foreach ($scope['departments'] as $alias) {
                if (strcasecmp($department, (string) $alias) === 0) {
                    return true;
                }
            }
        }
    }

    return false;
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();
    subject_assignments_ensure_schema();

    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = trim((string) ($selectedPeriod['period_name'] ?? ''));
    dipascaf_sync_evaluation_consistency($selectedPeriodName);
    $selectedYear = trim((string) ($_GET['year'] ?? ''));
    if ($selectedYear === '' && $selectedPeriod !== null) {
        $selectedYear = dipascaf_period_year($selectedPeriod);
    }

    // ── Load all programs and map them by program_code ────────────────
    $allPrograms = admin_all(
        'SELECT p.id, p.program_code, p.program_name, d.department_code, d.department_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         WHERE p.is_active = 1
         ORDER BY d.department_name, p.program_code'
    );

    $programMap = []; // program_code => [program_code, program_name, department_code, department_name]
    $programAliasMap = []; // normalized program code/name => canonical program_code
    foreach ($allPrograms as $prog) {
        $code = trim((string) ($prog['program_code'] ?? ''));
        $name = trim((string) ($prog['program_name'] ?? $code));
        if ($code !== '') {
            $programMap[$code] = [
                'program_code' => $code,
                'program_name' => $name,
                'department_code' => trim((string) ($prog['department_code'] ?? '')),
                'department_name' => trim((string) ($prog['department_name'] ?? '')),
            ];
            $programAliasMap[department_analysis_program_key($code)] = $code;
            if ($name !== '') {
                $programAliasMap[department_analysis_program_key($name)] = $code;
            }
        }
    }

    // ── Resolve scope from the authenticated user, not request input ───
    $userScope = department_analysis_user_scope($user, (int)($selectedPeriod['id'] ?? 0));
    $roleFilter = strtolower(trim((string) ($_GET['role'] ?? '')));
    $allowedRoleFilters = ['dean', 'program_head', 'teacher'];
    if (($userScope['role'] ?? '') === 'vpaa' && in_array($roleFilter, $allowedRoleFilters, true)) {
        $userScope['include_roles'] = [$roleFilter];
    }
    if (($userScope['role'] ?? '') === 'program_head') {
        $userScope['program_codes'] = array_values(array_unique(array_filter(array_map(
            static fn (string $code): string => strtoupper(department_analysis_canonical_program($code, $programAliasMap)),
            $userScope['program_codes']
        ))));
    }

    // ── Load faculty grouped by program_code ─────────────────────────
    $facultyRows = admin_all(
        "SELECT f.id, f.full_name,
                COALESCE(NULLIF(epp.department_snapshot, ''), f.department) AS department,
                COALESCE(NULLIF(epp.program_snapshot, ''), NULLIF(f.program_code, ''), 'Unassigned') AS program_code,
                f.position_title,
                COALESCE(NULLIF(epp.department_snapshot, ''), NULLIF(u.department, ''), f.department) AS user_department,
                COALESCE(NULLIF(epp.role_snapshot, ''), NULLIF(u.role, ''), '') AS user_role
         FROM faculty f
         JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         JOIN evaluation_period_participation epp
           ON epp.evaluation_period_id = ? AND epp.user_id = u.id
         WHERE COALESCE(f.is_archived, 0) = 0
           AND f.is_active = 1 AND u.is_active = 1
           AND epp.participation_status = 'included'
           AND epp.work_status = 'active'
           AND epp.employment_status IN ('active','newly_added')",
        [(int)($selectedPeriod['id'] ?? 0)]
    );

    $groups = []; // program_code => [data]
    $facultyProgramById = [];
    $facultyRoleById = [];

    foreach ($facultyRows as $faculty) {
        $programCode = department_analysis_canonical_program((string) ($faculty['program_code'] ?? ''), $programAliasMap);
        $faculty['program_code'] = $programCode;
        $displayProgramCode = $programCode;
        if ($programCode === '' || $programCode === 'Unassigned') {
            $departmentKey = trim((string) ($faculty['department'] ?? $faculty['user_department'] ?? ''));
            $programCode = 'Unassigned::' . ($departmentKey !== '' ? $departmentKey : 'Institution');
            $displayProgramCode = 'Unassigned';
        }

        $facultyId = (int) $faculty['id'];
        $resolvedRole = department_analysis_role_from_faculty($faculty);
        $progInfo = $programMap[$programCode] ?? [
            'program_code' => $displayProgramCode !== '' ? $displayProgramCode : $programCode,
            'program_name' => $displayProgramCode === 'Unassigned' ? 'Unassigned Program' : $programCode,
            'department_code' => '',
            'department_name' => trim((string) ($faculty['department'] ?? $faculty['user_department'] ?? '')),
        ];

        if (!department_analysis_faculty_in_scope($faculty, $progInfo, $userScope)) {
            continue;
        }

        $facultyProgramById[$facultyId] = $programCode;
        $facultyRoleById[$facultyId] = $resolvedRole;

        $label = $progInfo['program_name'];
        $groups[$programCode] ??= [
            'id' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $programCode)),
            'program_code' => $progInfo['program_code'],
            'program' => $label,
            'department_code' => $progInfo['department_code'],
            'department_name' => $progInfo['department_name'],
            'facultyCount' => 0,
            'faculty' => [],
            'evaluatedFaculty' => [],
            'completedFaculty' => [],
            'facultyResults' => [],
            'fieldBuckets' => [],
            'recommendations' => [],
            'weakAreaCount' => 0,
            'interventionCount' => 0,
            'trend' => 'No completed results yet',
        ];
        $groups[$programCode]['facultyCount']++;
        $groups[$programCode]['faculty'][] = [
            'id' => $facultyId,
            'name' => trim((string) ($faculty['full_name'] ?? 'Faculty Member')),
            'role' => department_analysis_role_from_faculty($faculty),
            'department' => (string) ($faculty['department'] ?? $faculty['user_department'] ?? ''),
            'program_code' => (string) ($progInfo['program_code'] ?? $displayProgramCode),
            'program_name' => (string) ($progInfo['program_name'] ?? $label),
        ];
    }

    // ── Add all remaining programs from the programs table (even with 0 faculty) ─
    foreach ($programMap as $progCode => $progInfo) {
        if (isset($groups[$progCode])) {
            continue; // Already added from faculty data
        }

        if (!department_analysis_program_in_scope($progInfo, $userScope)) {
            continue;
        }

        $groups[$progCode] = [
            'id' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $progCode)),
            'program_code' => $progCode,
            'program' => $progInfo['program_name'],
            'department_code' => $progInfo['department_code'],
            'department_name' => $progInfo['department_name'],
            'facultyCount' => 0,
            'faculty' => [],
            'evaluatedFaculty' => [],
            'completedFaculty' => [],
            'facultyResults' => [],
            'fieldBuckets' => [],
            'recommendations' => [],
            'weakAreaCount' => 0,
            'interventionCount' => 0,
            'trend' => 'No completed results yet',
        ];
    }

    // ── Completion gate: a faculty member is complete only when every assigned
    // evaluation for that evaluatee in the selected period has been submitted.
    $facultyEvaluationProgress = [];
    $facultyEvaluationAssignments = [];
    if ($facultyProgramById !== []) {
        $facultyIdsForProgress = array_keys($facultyProgramById);
        $progressPlaceholders = implode(',', array_fill(0, count($facultyIdsForProgress), '?'));
        $assignmentPeriodWhere = '';
        $assignmentPeriodParams = [];
        if ($selectedPeriodName !== '') {
            $assignmentPeriodWhere = ' AND pa.cycle_name = ?';
            $assignmentPeriodParams[] = $selectedPeriodName;
        } elseif ($selectedYear !== '') {
            $assignmentPeriodWhere = ' AND (pa.cycle_name LIKE ? OR YEAR(pa.assigned_at) = ? OR YEAR(pa.deadline) = ?)';
            $assignmentPeriodParams[] = '%' . $selectedYear . '%';
            $assignmentPeriodParams[] = $selectedYear;
            $assignmentPeriodParams[] = $selectedYear;
        }

        $progressRows = admin_all(
            "SELECT
                    pa.evaluatee_faculty_id,
                    COUNT(DISTINCT pa.id) AS total_assignments,
                    COUNT(DISTINCT CASE WHEN pa.status = 'submitted' THEN pa.id END) AS completed_assignments
             FROM peer_assignments pa
             LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
             WHERE pa.evaluatee_faculty_id IN ($progressPlaceholders)
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status NOT IN ('not_required', 'reassigned')
               AND (pa.assignment_type <> 'peer' OR (pea.id IS NOT NULL AND COALESCE(pea.is_archived, 0) = 0))
               $assignmentPeriodWhere
             GROUP BY pa.evaluatee_faculty_id",
            array_merge($facultyIdsForProgress, $assignmentPeriodParams)
        );

        foreach ($progressRows as $row) {
            $facultyId = (int) ($row['evaluatee_faculty_id'] ?? 0);
            $totalAssignments = (int) ($row['total_assignments'] ?? 0);
            $completedAssignments = (int) ($row['completed_assignments'] ?? 0);
            $facultyEvaluationProgress[$facultyId] = [
                'total' => $totalAssignments,
                'completed' => $completedAssignments,
                'isComplete' => $totalAssignments > 0 && $completedAssignments >= $totalAssignments,
            ];

            $programCode = $facultyProgramById[$facultyId] ?? null;
            if ($programCode !== null && isset($groups[$programCode])) {
                if ($completedAssignments > 0) {
                    $groups[$programCode]['evaluatedFaculty'][$facultyId] = true;
                }
                if ($facultyEvaluationProgress[$facultyId]['isComplete']) {
                    $groups[$programCode]['completedFaculty'][$facultyId] = true;
                }
            }
        }

        $assignmentRows = admin_all(
            "SELECT pa.id, pa.evaluatee_faculty_id, pa.evaluator_user_id, pa.evaluator_role,
                    pa.assignment_type, pa.status, pa.deadline, pa.submitted_at,
                    COALESCE(NULLIF(pa.evaluator_name_snapshot, ''), u.full_name, 'Unassigned evaluator') AS evaluator_name
             FROM peer_assignments pa
             LEFT JOIN users u ON u.id = pa.evaluator_user_id
             LEFT JOIN peer_evaluation_assignments pea ON pea.peer_assignment_id = pa.id
             WHERE pa.evaluatee_faculty_id IN ($progressPlaceholders)
               AND COALESCE(pa.is_archived, 0) = 0
               AND pa.status NOT IN ('not_required', 'reassigned')
               AND (pa.assignment_type <> 'peer' OR (pea.id IS NOT NULL AND COALESCE(pea.is_archived, 0) = 0))
               $assignmentPeriodWhere
             ORDER BY FIELD(pa.status, 'pending', 'in_progress', 'reopened', 'submitted'), pa.deadline ASC, pa.id ASC",
            array_merge($facultyIdsForProgress, $assignmentPeriodParams)
        );

        foreach ($assignmentRows as $row) {
            $facultyId = (int) ($row['evaluatee_faculty_id'] ?? 0);
            $rawStatus = strtolower((string) ($row['status'] ?? 'pending'));
            $status = $rawStatus === 'submitted' ? 'completed' : 'pending';
            $facultyEvaluationAssignments[$facultyId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'assignmentType' => (string) ($row['assignment_type'] ?? ''),
                'evaluatorRole' => (string) ($row['evaluator_role'] ?? ''),
                'evaluatorName' => (string) ($row['evaluator_name'] ?? 'Unassigned evaluator'),
                'status' => $status,
                'workflowStatus' => $rawStatus,
                'deadline' => (string) ($row['deadline'] ?? ''),
                'submittedAt' => (string) ($row['submitted_at'] ?? ''),
                'requiredForCurrentDean' => (string) ($user['role'] ?? '') === 'dean'
                    && (int) ($row['evaluator_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
                    && (string) ($row['evaluator_role'] ?? '') === 'dean',
                'canEvaluate' => (string) ($user['role'] ?? '') === 'dean'
                    && (int) ($row['evaluator_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
                    && (string) ($row['evaluator_role'] ?? '') === 'dean'
                    && $rawStatus !== 'submitted'
                    && (string) ($selectedPeriod['status'] ?? '') === 'open',
            ];
        }

        $evaluationRules = admin_all(
            "SELECT rule_name, evaluator_role, evaluatee_role, assignment_type, GREATEST(1, peer_count) AS required_count
             FROM evaluation_rules
             WHERE is_active = 1 AND assignment_type <> 'self'"
        );
        foreach ($facultyProgramById as $facultyId => $_programCode) {
            $evaluateeRole = (string) ($facultyRoleById[$facultyId] ?? 'teacher');
            $assignments = $facultyEvaluationAssignments[$facultyId] ?? [];
            foreach ($evaluationRules as $rule) {
                if ((string) ($rule['evaluatee_role'] ?? '') !== $evaluateeRole) continue;
                $matchingCount = count(array_filter(
                    $assignments,
                    static fn (array $assignment): bool =>
                        (string) ($assignment['assignmentType'] ?? '') === (string) ($rule['assignment_type'] ?? '')
                        && (string) ($assignment['evaluatorRole'] ?? '') === (string) ($rule['evaluator_role'] ?? '')
                ));
                $requiredCount = (int) ($rule['required_count'] ?? 1);
                for ($missingIndex = $matchingCount; $missingIndex < $requiredCount; $missingIndex++) {
                    $facultyEvaluationAssignments[$facultyId][] = [
                        'id' => null,
                        'assignmentType' => (string) ($rule['assignment_type'] ?? ''),
                        'evaluatorRole' => (string) ($rule['evaluator_role'] ?? ''),
                        'evaluatorName' => 'Evaluator not assigned',
                        'status' => 'missing',
                        'workflowStatus' => 'missing',
                        'deadline' => '',
                        'submittedAt' => '',
                        'requiredForCurrentDean' => (string) ($rule['evaluator_role'] ?? '') === 'dean'
                            && (string) ($user['role'] ?? '') === 'dean',
                        'canEvaluate' => false,
                        'ruleName' => (string) ($rule['rule_name'] ?? 'Required evaluation'),
                    ];
                }
            }
        }
    }

    // ── Load evaluation results and group by program ──────────────────
    $periodWhere = '';
    $periodParams = [];
    if ($selectedPeriodName !== '') {
        $periodWhere = ' AND (r.evaluation_period = ? OR pa.cycle_name = ?)';
        $periodParams[] = $selectedPeriodName;
        $periodParams[] = $selectedPeriodName;
    } elseif ($selectedYear !== '') {
        $periodWhere = ' AND (r.evaluation_period LIKE ? OR pa.cycle_name LIKE ? OR YEAR(r.submitted_at) = ?)';
        $periodParams[] = '%' . $selectedYear . '%';
        $periodParams[] = '%' . $selectedYear . '%';
        $periodParams[] = $selectedYear;
    }

    $resultRows = array_merge(
        admin_all(
            "SELECT 'Form A' AS form_label, r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.submitted_at
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(pa.assignment_type, '') <> 'self'{$periodWhere}",
            $periodParams
        ),
        admin_all(
            "SELECT 'Form B' AS form_label, r.evaluatee_faculty_id, c.title AS category_title,
                    r.average_rating, r.submitted_at
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND COALESCE(pa.assignment_type, '') <> 'self'{$periodWhere}",
            $periodParams
        )
    );

    foreach ($resultRows as $row) {
        $facultyId = (int) ($row['evaluatee_faculty_id'] ?? 0);
        $programCode = $facultyProgramById[$facultyId] ?? null;
        if ($programCode === null) {
            continue;
        }
        $resolvedRole = $facultyRoleById[$facultyId] ?? 'teacher';
        $expectedFormType = program_analysis_role_form($resolvedRole);
        $rowFormType = (string) ($row['form_label'] ?? '');
        if ($rowFormType !== $expectedFormType) {
            continue;
        }
        $groups[$programCode]['evaluatedFaculty'][$facultyId] = true;

        $category = trim((string) ($row['category_title'] ?? 'Uncategorized'));
        if ($category === '') {
            $category = 'Uncategorized';
        }

        $groups[$programCode]['fieldBuckets'][$category] ??= [
            'name' => $category,
            'formType' => $expectedFormType,
            'scoreTotal' => 0.0,
            'resultCount' => 0,
            'latestSubmittedAt' => '',
        ];
        $groups[$programCode]['fieldBuckets'][$category]['scoreTotal'] += (float) ($row['average_rating'] ?? 0);
        $groups[$programCode]['fieldBuckets'][$category]['resultCount']++;
        if ((string) ($row['submitted_at'] ?? '') > $groups[$programCode]['fieldBuckets'][$category]['latestSubmittedAt']) {
            $groups[$programCode]['fieldBuckets'][$category]['latestSubmittedAt'] = (string) ($row['submitted_at'] ?? '');
        }

        $rating = (float) ($row['average_rating'] ?? 0);
        $groups[$programCode]['facultyResults'][$facultyId] ??= [
            'scoreTotal' => 0.0,
            'resultCount' => 0,
            'formType' => $expectedFormType,
            'role' => $resolvedRole,
            'weakArea' => '',
            'weakScore' => 99.0,
            'strongArea' => '',
            'strongScore' => 0.0,
            'fieldBuckets' => [],
            'latestSubmittedAt' => '',
        ];
        $groups[$programCode]['facultyResults'][$facultyId]['scoreTotal'] += $rating;
        $groups[$programCode]['facultyResults'][$facultyId]['resultCount']++;
        $groups[$programCode]['facultyResults'][$facultyId]['fieldBuckets'][$category] ??= [
            'name' => $category,
            'formType' => $expectedFormType,
            'scoreTotal' => 0.0,
            'resultCount' => 0,
            'latestSubmittedAt' => '',
        ];
        $groups[$programCode]['facultyResults'][$facultyId]['fieldBuckets'][$category]['scoreTotal'] += $rating;
        $groups[$programCode]['facultyResults'][$facultyId]['fieldBuckets'][$category]['resultCount']++;
        if ((string) ($row['submitted_at'] ?? '') > $groups[$programCode]['facultyResults'][$facultyId]['fieldBuckets'][$category]['latestSubmittedAt']) {
            $groups[$programCode]['facultyResults'][$facultyId]['fieldBuckets'][$category]['latestSubmittedAt'] = (string) ($row['submitted_at'] ?? '');
        }
        if ($rating < (float) $groups[$programCode]['facultyResults'][$facultyId]['weakScore']) {
            $groups[$programCode]['facultyResults'][$facultyId]['weakScore'] = $rating;
            $groups[$programCode]['facultyResults'][$facultyId]['weakArea'] = $category;
        }
        if ($rating > (float) $groups[$programCode]['facultyResults'][$facultyId]['strongScore']) {
            $groups[$programCode]['facultyResults'][$facultyId]['strongScore'] = $rating;
            $groups[$programCode]['facultyResults'][$facultyId]['strongArea'] = $category;
        }
        if ((string) ($row['submitted_at'] ?? '') > $groups[$programCode]['facultyResults'][$facultyId]['latestSubmittedAt']) {
            $groups[$programCode]['facultyResults'][$facultyId]['latestSubmittedAt'] = (string) ($row['submitted_at'] ?? '');
        }
    }

    // ── Add weak-area and intervention counts for VPAA/department summaries ─
    if ($facultyProgramById !== []) {
        $visibleFacultyIds = array_keys($facultyProgramById);
        $visiblePlaceholders = implode(',', array_fill(0, count($visibleFacultyIds), '?'));

        $weakRows = admin_all(
            "SELECT faculty_id, COUNT(*) AS weak_count
             FROM ai_insights
             WHERE faculty_id IN ($visiblePlaceholders)
             GROUP BY faculty_id",
            $visibleFacultyIds
        );
        foreach ($weakRows as $row) {
            $facultyId = (int) ($row['faculty_id'] ?? 0);
            $programCode = $facultyProgramById[$facultyId] ?? null;
            if ($programCode !== null && isset($groups[$programCode])) {
                $groups[$programCode]['weakAreaCount'] += (int) ($row['weak_count'] ?? 0);
            }
        }

        $interventionRows = admin_all(
            "SELECT faculty_id, COUNT(*) AS intervention_count
             FROM intervention_plans
             WHERE faculty_id IN ($visiblePlaceholders)
             GROUP BY faculty_id",
            $visibleFacultyIds
        );
        foreach ($interventionRows as $row) {
            $facultyId = (int) ($row['faculty_id'] ?? 0);
            $programCode = $facultyProgramById[$facultyId] ?? null;
            if ($programCode !== null && isset($groups[$programCode])) {
                $groups[$programCode]['interventionCount'] += (int) ($row['intervention_count'] ?? 0);
            }
        }
    }

    // ── Build response data ───────────────────────────────────────────
    $data = [];
    foreach ($groups as $programCode => $group) {
        $fields = [];
        foreach ($group['fieldBuckets'] as $bucket) {
            if ((int) $bucket['resultCount'] <= 0) {
                continue;
            }

            $score = (float) $bucket['scoreTotal'] / (int) $bucket['resultCount'];
            $fields[] = [
                'name' => (string) $bucket['name'],
                'score' => round($score, 2),
                'formType' => (string) ($bucket['formType'] ?? 'Form B'),
                'resultCount' => (int) $bucket['resultCount'],
                'seminar' => program_analysis_seminar((string) $bucket['name'], (string) ($bucket['formType'] ?? 'Form B')),
                'latestSubmittedAt' => (string) $bucket['latestSubmittedAt'],
            ];
        }

        usort($fields, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        $trend = 'No completed results yet';
        if ($fields !== []) {
            $avgScore = array_sum(array_map(static fn (array $field): float => (float) $field['score'], $fields)) / count($fields);
            $trend = $avgScore >= 4.25
                ? 'Strong performance trend'
                : ($avgScore >= 3.50 ? 'Stable performance trend' : 'Needs intervention trend');
        }

        $facultyList = array_map(
            static function (array $faculty) use ($group, $facultyEvaluationProgress, $facultyEvaluationAssignments, $selectedPeriod): array {
                $facultyId = (int) ($faculty['id'] ?? 0);
                $result = $group['facultyResults'][$facultyId] ?? null;
                $average = $result && (int) ($result['resultCount'] ?? 0) > 0
                    ? round((float) $result['scoreTotal'] / (int) $result['resultCount'], 2)
                    : null;
                $progress = $facultyEvaluationProgress[$facultyId] ?? ['total' => 0, 'completed' => 0, 'isComplete' => false];
                $fields = [];
                foreach (($result['fieldBuckets'] ?? []) as $bucket) {
                    $count = (int) ($bucket['resultCount'] ?? 0);
                    if ($count <= 0) {
                        continue;
                    }
                    $fieldScore = round((float) ($bucket['scoreTotal'] ?? 0) / $count, 2);
                    $fields[] = [
                        'name' => (string) ($bucket['name'] ?? ''),
                        'score' => $fieldScore,
                        'formType' => (string) ($bucket['formType'] ?? ($result['formType'] ?? 'Form B')),
                        'resultCount' => $count,
                        'seminar' => program_analysis_seminar((string) ($bucket['name'] ?? ''), (string) ($bucket['formType'] ?? ($result['formType'] ?? 'Form B'))),
                        'latestSubmittedAt' => (string) ($bucket['latestSubmittedAt'] ?? ''),
                    ];
                }
                usort($fields, static fn (array $a, array $b): int => (float) $a['score'] <=> (float) $b['score']);

                $weakAreas = array_values(array_filter(
                    $fields,
                    static fn (array $field): bool => (float) ($field['score'] ?? 0) < 3.5
                ));
                if ($weakAreas === [] && $fields !== []) {
                    $weakAreas = [reset($fields)];
                }
                $strengths = array_reverse(array_values(array_filter(
                    $fields,
                    static fn (array $field): bool => (float) ($field['score'] ?? 0) >= 3.75
                )));
                if ($strengths === [] && $fields !== []) {
                    $strengths = [end($fields)];
                }
                $role = (string) ($faculty['role'] ?? ($result['role'] ?? 'teacher'));
                $formType = program_analysis_role_form($role);
                $recommendations = [];
                if ($average !== null && $fields !== []) {
                    foreach (array_slice($fields, 0, 3) as $index => $field) {
                        $recommendations[] = program_analysis_recommendation([
                            'id' => $facultyId . '_' . strtolower(str_replace(' ', '_', $formType)) . '_' . ($index + 1),
                            'evaluation_period_id' => (int) ($selectedPeriod['id'] ?? 0),
                            'department_id' => (string) ($group['department_code'] ?? $group['department_name'] ?? ''),
                            'user_id' => $facultyId,
                            'role' => $role,
                            'form_type' => $formType,
                            'overall_score' => $average,
                            'category' => (string) ($field['name'] ?? ''),
                            'category_score' => (float) ($field['score'] ?? $average),
                        ]);
                    }
                }
                $primaryRecommendation = $recommendations[0] ?? null;

                return [
                    'id' => $facultyId,
                    'name' => (string) ($faculty['name'] ?? 'Faculty Member'),
                    'role' => $role,
                    'formType' => $formType,
                    'department' => (string) ($faculty['department'] ?? ''),
                    'program_code' => (string) ($faculty['program_code'] ?? ''),
                    'program_name' => (string) ($faculty['program_name'] ?? ''),
                    'evaluated' => $result !== null,
                    'complete' => (bool) ($progress['isComplete'] ?? false),
                    'assignmentTotal' => (int) ($progress['total'] ?? 0),
                    'assignmentCompleted' => (int) ($progress['completed'] ?? 0),
                    'evaluationAssignments' => array_values($facultyEvaluationAssignments[$facultyId] ?? []),
                    'averageScore' => $average,
                    'scores' => $fields,
                    'weakAreas' => array_slice($weakAreas, 0, 4),
                    'weakArea' => (string) ($result['weakArea'] ?? ''),
                    'weakScore' => isset($result['weakScore']) && (float) $result['weakScore'] < 99 ? round((float) $result['weakScore'], 2) : null,
                    'strengths' => array_slice($strengths, 0, 4),
                    'strongArea' => (string) ($result['strongArea'] ?? ''),
                    'strongScore' => isset($result['strongScore']) && (float) $result['strongScore'] > 0 ? round((float) $result['strongScore'], 2) : null,
                    'recommendation' => $primaryRecommendation['recommendation_title'] ?? (!empty($result['weakArea']) ? program_analysis_seminar((string) $result['weakArea'], $formType) : ''),
                    'recommendations' => $recommendations,
                    'latestSubmittedAt' => (string) ($result['latestSubmittedAt'] ?? ''),
                ];
            },
            $group['faculty'] ?? []
        );

        $programRecommendations = [];
        foreach ($facultyList as $facultyRecommendationRow) {
            foreach (($facultyRecommendationRow['recommendations'] ?? []) as $recommendation) {
                $programRecommendations[] = $recommendation + [
                    'program_code' => (string) $group['program_code'],
                    'program_name' => (string) $group['program'],
                    'faculty_name' => (string) ($facultyRecommendationRow['name'] ?? ''),
                    'department_name' => (string) $group['department_name'],
                ];
            }
        }

        $data[] = [
            'id' => (string) $group['id'],
            'group_type' => 'program',
            'program_code' => (string) $group['program_code'],
            'program' => $group['program'],
            'department_code' => $group['department_code'],
            'department_name' => $group['department_name'],
            'facultyCount' => (int) $group['facultyCount'],
            'evaluatedCount' => count($group['evaluatedFaculty']),
            'completeCount' => count($group['completedFaculty']),
            'faculty' => $facultyList,
            'fields' => $fields,
            'recommendations' => $programRecommendations,
            'weakAreaCount' => (int) $group['weakAreaCount'],
            'interventionCount' => (int) $group['interventionCount'],
            'trend' => $trend,
        ];
    }

    // Build parallel subject-performance groups. Evaluation results remain
    // person-based and are attributed only to the period-snapshotted primary
    // subject (or the current primary subject when no period is selected).
    if (($userScope['role'] ?? '') !== 'program_head') {
        $facultyAnalysisById = [];
        foreach ($data as $groupRow) {
            foreach (($groupRow['faculty'] ?? []) as $facultyRow) {
                $facultyAnalysisById[(int)$facultyRow['id']] = $facultyRow;
            }
        }
        $periodId = (int)($selectedPeriod['id'] ?? 0);
        if ($periodId > 0) {
            $subjectRows = admin_all(
                "SELECT epfs.faculty_id,epfs.subject_area_id,epfs.subject_code_snapshot subject_code,
                        epfs.subject_name_snapshot subject_name,epfs.department_id,epfs.is_primary,epfs.is_coordinator,
                        d.department_code,d.department_name,cf.full_name coordinator_name
                 FROM evaluation_period_faculty_subjects epfs
                 JOIN departments d ON d.id=epfs.department_id
                 LEFT JOIN evaluation_period_faculty_subjects coordinator
                   ON coordinator.evaluation_period_id=epfs.evaluation_period_id
                  AND coordinator.subject_area_id=epfs.subject_area_id
                  AND coordinator.is_coordinator=1
                 LEFT JOIN faculty cf ON cf.id=coordinator.faculty_id
                 WHERE epfs.evaluation_period_id=?",
                [$periodId]
            );
        } else {
            $subjectRows = admin_all(
                "SELECT fsa.faculty_id,sa.id subject_area_id,sa.subject_code,sa.subject_name,
                        sa.department_id,fsa.is_primary,(sa.coordinator_faculty_id=fsa.faculty_id) is_coordinator,
                        d.department_code,d.department_name,cf.full_name coordinator_name
                 FROM faculty_subject_assignments fsa
                 JOIN subject_areas sa ON sa.id=fsa.subject_area_id
                 JOIN departments d ON d.id=sa.department_id
                 LEFT JOIN faculty cf ON cf.id=sa.coordinator_faculty_id
                 WHERE sa.is_active=1"
            );
        }
        $subjectGroups = [];
        $subjectFacultyIds = [];
        foreach ($subjectRows as $subjectRow) {
            $facultyId = (int)$subjectRow['faculty_id'];
            if (!isset($facultyAnalysisById[$facultyId])) continue;
            if ((int)$subjectRow['is_primary'] === 1) $subjectFacultyIds[$facultyId] = true;
            $key = 'subject:' . (int)$subjectRow['subject_area_id'];
            $subjectGroups[$key] ??= [
                'id' => $key,
                'group_type' => 'subject',
                'subject_id' => (int)$subjectRow['subject_area_id'],
                'subject_code' => (string)$subjectRow['subject_code'],
                'subject_name' => (string)$subjectRow['subject_name'],
                'program_code' => (string)$subjectRow['subject_code'],
                'program' => (string)$subjectRow['subject_name'],
                'department_code' => (string)$subjectRow['department_code'],
                'department_name' => (string)$subjectRow['department_name'],
                'coordinator_name' => (string)($subjectRow['coordinator_name'] ?? ''),
                'faculty' => [],
            ];
            $member = $facultyAnalysisById[$facultyId];
            $member['subject_id'] = (int)$subjectRow['subject_area_id'];
            $member['subject_code'] = (string)$subjectRow['subject_code'];
            $member['subject_name'] = (string)$subjectRow['subject_name'];
            $member['is_subject_coordinator'] = (bool)$subjectRow['is_coordinator'];
            $member['performance_attributed'] = (bool)$subjectRow['is_primary'];
            $member['program_code'] = '';
            $member['program_name'] = '';
            if (!(bool)$subjectRow['is_primary']) {
                $member['evaluated'] = false;
                $member['complete'] = false;
                $member['averageScore'] = null;
                $member['assignmentTotal'] = 0;
                $member['assignmentCompleted'] = 0;
                $member['evaluationAssignments'] = [];
                $member['scores'] = [];
                $member['weakAreas'] = [];
                $member['strengths'] = [];
                $member['weakArea'] = '';
                $member['strongArea'] = '';
                $member['recommendation'] = 'Performance is reported under the primary subject assignment.';
                $member['recommendations'] = [];
            }
            $subjectGroups[$key]['faculty'][] = $member;
        }
        foreach ($subjectGroups as $subjectGroup) {
            $fieldBuckets = [];
            $recommendationRows = [];
            foreach ($subjectGroup['faculty'] as $member) {
                if (empty($member['performance_attributed'])) continue;
                foreach (($member['scores'] ?? []) as $field) {
                    $name = (string)($field['name'] ?? '');
                    if ($name === '') continue;
                    $count = max(1, (int)($field['resultCount'] ?? 1));
                    $fieldBuckets[$name] ??= ['name'=>$name,'total'=>0.0,'count'=>0,'seminar'=>$field['seminar'] ?? ''];
                    $fieldBuckets[$name]['total'] += (float)$field['score'] * $count;
                    $fieldBuckets[$name]['count'] += $count;
                }
                foreach (($member['recommendations'] ?? []) as $recommendation) {
                    $recommendationRows[] = $recommendation + [
                        'subject_code'=>$subjectGroup['subject_code'],
                        'subject_name'=>$subjectGroup['subject_name'],
                        'faculty_name'=>$member['name'] ?? '',
                        'department_name'=>$subjectGroup['department_name'],
                    ];
                }
            }
            $fields = array_map(static fn(array $bucket): array => [
                'name'=>$bucket['name'],
                'score'=>round($bucket['total'] / max(1, $bucket['count']), 2),
                'resultCount'=>$bucket['count'],
                'seminar'=>$bucket['seminar'],
            ], array_values($fieldBuckets));
            usort($fields, static fn(array $a,array $b): int => $a['score'] <=> $b['score']);
            $subjectGroup['facultyCount'] = count($subjectGroup['faculty']);
            $subjectGroup['analysisFacultyCount'] = count(array_filter($subjectGroup['faculty'], static fn(array $row): bool => !empty($row['performance_attributed'])));
            $subjectGroup['evaluatedCount'] = count(array_filter($subjectGroup['faculty'], static fn(array $row): bool => !empty($row['evaluated'])));
            $subjectGroup['completeCount'] = count(array_filter($subjectGroup['faculty'], static fn(array $row): bool => !empty($row['complete'])));
            $subjectGroup['fields'] = $fields;
            $subjectGroup['recommendations'] = $recommendationRows;
            $subjectGroup['weakAreaCount'] = count(array_filter($fields, static fn(array $field): bool => (float)$field['score'] < 3.5));
            $subjectGroup['interventionCount'] = 0;
            $subjectGroup['trend'] = $fields === [] ? 'No completed results yet' : 'Subject performance trend';
            $data[] = $subjectGroup;
        }
        foreach ($data as &$group) {
            if (($group['group_type'] ?? 'program') !== 'program' || ($group['program_code'] ?? '') !== 'Unassigned') continue;
            $group['faculty'] = array_values(array_filter(
                $group['faculty'] ?? [],
                static fn(array $faculty): bool => !isset($subjectFacultyIds[(int)($faculty['id'] ?? 0)])
            ));
            $group['facultyCount'] = count($group['faculty']);
            $group['evaluatedCount'] = count(array_filter($group['faculty'], static fn(array $faculty): bool => !empty($faculty['evaluated'])));
            $group['completeCount'] = count(array_filter($group['faculty'], static fn(array $faculty): bool => !empty($faculty['complete'])));
        }
        unset($group);
        $data = array_values(array_filter($data, static fn(array $group): bool => ($group['group_type'] ?? 'program') !== 'program' || ($group['program_code'] ?? '') !== 'Unassigned' || (int)$group['facultyCount'] > 0));
    }

    usort($data, static fn (array $a, array $b): int => (($a['group_type'] ?? 'program') <=> ($b['group_type'] ?? 'program')) ?: strcmp((string) $a['program'], (string) $b['program']));
    $recommendations = [];
    foreach ($data as $program) {
        foreach (($program['recommendations'] ?? []) as $recommendation) {
            $recommendations[] = $recommendation;
        }
    }
    usort($recommendations, static function (array $a, array $b): int {
        $priorityOrder = ['High' => 0, 'Medium' => 1, 'Low' => 2];
        $priorityCompare = ($priorityOrder[$a['priority_level'] ?? 'Low'] ?? 3) <=> ($priorityOrder[$b['priority_level'] ?? 'Low'] ?? 3);
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }
        return ((float) ($a['category_score'] ?? 99)) <=> ((float) ($b['category_score'] ?? 99));
    });

    $externalRecommendations = [];
    foreach (array_slice($recommendations, 0, 8) as $recommendation) {
        if (in_array(($recommendation['priority_level'] ?? ''), ['High', 'Medium'], true) || (float) ($recommendation['overall_score'] ?? 0) >= 3.75) {
            $externalRecommendations[] = program_analysis_external_recommendation($recommendation);
        }
    }

    $overallRecommendation = null;
    if ($recommendations !== []) {
        $overallAverage = array_sum(array_map(static fn (array $row): float => (float) ($row['overall_score'] ?? 0), $recommendations)) / max(1, count($recommendations));
        $topRecommendation = $recommendations[0];
        $topCategories = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['weak_category'] ?? ''),
            array_slice($recommendations, 0, 6)
        ))));
        $interpretation = program_analysis_score_interpretation($overallAverage);
        $overallRecommendation = [
            'title' => $interpretation === 'Needs Improvement'
                ? 'Overall Department Development Recommendation'
                : 'Overall Department Development Recommendation',
            'overall_score' => round($overallAverage, 2),
            'interpretation' => $interpretation,
            'priority_level' => $topRecommendation['priority_level'] ?? 'Medium',
            'summary' => $interpretation === 'Needs Improvement'
                ? 'Prioritize targeted intervention, coaching, mentoring, and follow-up development planning for the lowest-rated PMAS categories.'
                : ($interpretation === 'Satisfactory'
                    ? 'Prioritize continuous improvement seminars based on the three lowest-rated PMAS categories while monitoring progress in the next appraisal period.'
                    : 'Prioritize enrichment seminars, mentoring, innovation, research, leadership, and professional excellence programs to sustain high performance.'),
            'focus_categories' => array_slice($topCategories, 0, 3),
            'recommended_action' => $topRecommendation['recommendation_title'] ?? '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'summary' => [
            'programs' => count($data),
            'programGroups' => count(array_filter($data, static fn(array $row): bool => ($row['group_type'] ?? 'program') === 'program')),
            'subjectGroups' => count(array_filter($data, static fn(array $row): bool => ($row['group_type'] ?? '') === 'subject')),
            'withResults' => count(array_filter($data, static fn (array $row): bool => count($row['fields']) > 0)),
            'recommendations' => $recommendations,
            'externalRecommendations' => $externalRecommendations,
            'overallRecommendation' => $overallRecommendation,
            'period' => $selectedPeriod !== null ? dipascaf_period_payload($selectedPeriod) + ['year' => dipascaf_period_year($selectedPeriod)] : null,
            'year' => $selectedYear,
            'scope' => [
                'role' => (string) ($userScope['role'] ?? ''),
                'label' => (string) ($userScope['label'] ?? ''),
                'limited' => (bool) ($userScope['is_limited'] ?? false),
            ],
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
