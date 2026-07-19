<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

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
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function evaluation_api_categories(int $assignmentId, int $userId, string $role): array
{
    admin_ensure_archive_schema();
    $assignment = admin_one(
        'SELECT * FROM peer_assignments WHERE id = :id AND evaluator_user_id = :euid AND COALESCE(is_archived, 0) = 0',
        ['id' => $assignmentId, 'euid' => $userId]
    );
    if ($assignment === null) {
        return ['ok' => false, 'message' => 'Assignment not found.'];
    }

    $formType = (string) ($assignment['questionnaire_type'] ?? '') === 'admin' ? 'form_a' : 'form_b';
    try {
        dipascaf_assert_assignment_allowed($assignment, $userId, $formType);
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => $exception->getMessage()];
    }

    $categories = dipascaf_categories($formType === 'form_a' ? 'a' : 'b');

    return [
        'ok' => true,
        'categories' => $categories,
        'form_type' => $formType,
        'role' => $role,
        'assignment' => [
            'id' => (int) $assignment['id'],
            'evaluatee_faculty_id' => (int) $assignment['evaluatee_faculty_id'],
            'questionnaire_type' => (string) ($assignment['questionnaire_type'] ?? ''),
        ],
        'csrf_token' => csrf_token(),
    ];
}

function evaluation_api_role(string $role): string
{
    return match ($role) {
        'programHead', 'programhead', 'program_head' => 'program_head',
        'faculty', 'teacher' => 'teacher',
        'dean' => 'dean',
        'vpaa' => 'vpaa',
        default => '',
    };
}

function evaluation_api_score(array $row, array $categoryResults = []): ?float
{
    if ($categoryResults !== []) {
        $weightedScore = array_reduce(
            $categoryResults,
            static fn (float $sum, array $result): float => $sum + (float) ($result['weightedScore'] ?? 0),
            0.0
        );

        if ($weightedScore > 0) {
            return round($weightedScore, 4);
        }
    }

    if (isset($row['evaluation_score']) && $row['evaluation_score'] !== null) {
        return (float) $row['evaluation_score'];
    }

    return null;
}

function evaluation_api_previous_score(int $assignmentId, int $evaluateeFacultyId, string $currentPeriod): ?array
{
    admin_ensure_archive_schema();
    if ($assignmentId <= 0 || $evaluateeFacultyId <= 0) {
        return null;
    }

    $rows = admin_all(
        "SELECT pa.id AS assignment_id, pa.cycle_name, MAX(x.submitted_at) AS submitted_at,
                ROUND(SUM(x.weighted_score), 4) AS score
         FROM (
             SELECT assignment_id, weighted_score, submitted_at
             FROM pmas_form_a_category_results
             WHERE evaluatee_faculty_id = :fac_a AND status = 'completed'
             UNION ALL
             SELECT assignment_id, weighted_score, submitted_at
             FROM pmas_form_b_category_results
             WHERE evaluatee_faculty_id = :fac_b AND status = 'completed'
         ) x
         JOIN peer_assignments pa ON pa.id = x.assignment_id
         WHERE pa.id <> :assignment_id
           AND COALESCE(pa.is_archived, 0) = 0
           AND (:current_period = '' OR pa.cycle_name <> :current_period_match)
         GROUP BY pa.id, pa.cycle_name
         ORDER BY submitted_at DESC
         LIMIT 1",
        [
            'fac_a' => $evaluateeFacultyId,
            'fac_b' => $evaluateeFacultyId,
            'assignment_id' => $assignmentId,
            'current_period' => $currentPeriod,
            'current_period_match' => $currentPeriod,
        ]
    );

    if ($rows === []) {
        return null;
    }

    $row = $rows[0];
    return [
        'assignmentId' => (int) ($row['assignment_id'] ?? 0),
        'period' => (string) ($row['cycle_name'] ?? ''),
        'score' => isset($row['score']) ? (float) $row['score'] : null,
        'submittedAt' => (string) ($row['submitted_at'] ?? ''),
    ];
}

function evaluation_api_self_records(array $assignments): array
{
    $ids = array_values(array_unique(array_filter(array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $assignments
    ))));
    if ($ids === []) {
        return [];
    }

    if (admin_one("SHOW TABLES LIKE 'pmas_self_evaluations'") === null) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $assignmentId) {
        $key = 'assignment_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $assignmentId;
    }

    $rows = admin_all(
        'SELECT * FROM pmas_self_evaluations WHERE assignment_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    $records = [];
    foreach ($rows as $row) {
        $assignmentId = (int) ($row['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            continue;
        }
        $payload = json_decode((string) ($row['form_payload_json'] ?? ''), true);
        $answers = json_decode((string) ($row['answers_json'] ?? ''), true);
        $records[$assignmentId] = [
            ...$row,
            'form_payload' => is_array($payload) ? $payload : [],
            'answers' => is_array($answers) ? $answers : [],
        ];
    }

    return $records;
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }

    $requestedRole = evaluation_api_role((string) ($_GET['role'] ?? $user['role'] ?? ''));
    $actualRole = (string) ($user['role'] ?? '');

    if ($requestedRole === '') {
        $requestedRole = evaluation_api_role($actualRole);
    }

    if ($requestedRole === '') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'This account role cannot submit evaluations.']);
        exit;
    }

    if ($actualRole === 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Admin manages evaluation periods and assignments, but cannot evaluate.']);
        exit;
    }

    if ($actualRole !== 'admin_hr' && $requestedRole !== $actualRole) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You can only view your own evaluation assignments.']);
        exit;
    }

    admin_ensure_archive_schema();

    // ── GET actions ────────────────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $action = (string) ($_GET['action'] ?? '');

        if ($action === 'categories') {
            $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
            $result = evaluation_api_categories($assignmentId, (int) $user['id'], $requestedRole);
            echo json_encode($result);
            exit;
        }
    }

    // ── POST actions ────────────────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $bodyRole = evaluation_api_role((string) ($input['role'] ?? ''));
        if ($bodyRole !== '') {
            $requestedRole = $bodyRole;
        }
        if ($actualRole !== 'admin_hr' && $requestedRole !== $actualRole) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'You can only view your own evaluation assignments.']);
            exit;
        }

        $action = (string) ($input['action'] ?? '');

        if ($action === 'init_self_assignment') {
            try {
                $targetPeriod = dipascaf_selected_period_from_request($input, true);
                $targetCycleName = trim((string) ($targetPeriod['period_name'] ?? dipascaf_current_cycle_name()));
                dipascaf_ensure_self_assignment((int) $user['id'], $requestedRole, $targetPeriod);
                $assignment = admin_one(
                    "SELECT pa.*
                     FROM peer_assignments pa
                     WHERE pa.evaluator_user_id = :user_id
                       AND pa.evaluator_role = :role
                       AND pa.assignment_type = 'self'
                       AND pa.cycle_name = :cycle_name
                       AND COALESCE(pa.is_archived, 0) = 0
                     LIMIT 1",
                    ['user_id' => (int) $user['id'], 'role' => $requestedRole, 'cycle_name' => $targetCycleName]
                );
                if ($assignment === null) {
                    throw new RuntimeException('Self-evaluation assignment could not be created for this account.');
                }
                echo json_encode([
                    'ok' => true,
                    'message' => 'Self-evaluation assignment is ready.',
                    'assignment_id' => (int) $assignment['id'],
                ]);
            } catch (Throwable $exception) {
                error_log('[evaluations] Self assignment initialization failed: ' . $exception->getMessage() . ' user=' . (int) ($user['id'] ?? 0));
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
            }
            exit;
        }

        if ($action === 'submit') {
            $assignmentId = (int) ($input['assignment_id'] ?? 0);
            $assignment = admin_one(
                'SELECT * FROM peer_assignments WHERE id = :id AND evaluator_user_id = :euid AND COALESCE(is_archived, 0) = 0',
                ['id' => $assignmentId, 'euid' => (int) $user['id']]
            );
            if ($assignment === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Evaluation assignment was not found.']);
                exit;
            }

            $formType = (string) ($assignment['questionnaire_type'] ?? '') === 'admin' ? 'form_a' : 'form_b';

            try {
                dipascaf_assert_assignment_allowed($assignment, (int) $user['id'], $formType);

                if ($formType === 'form_a') {
                    $formAPayload = is_array($input['form_a_payload'] ?? null)
                        ? $input['form_a_payload']
                        : (is_array($input['form_payload'] ?? null) ? $input['form_payload'] : []);
                    $result = dipascaf_submit_category_results(
                        $assignment,
                        (int) $user['id'],
                        'a',
                        $formAPayload,
                        (string) ($assignment['cycle_name'] ?? 'Current Appraisal Cycle')
                    );
                } else {
                    $formBPayload = is_array($input['form_b_payload'] ?? null)
                        ? $input['form_b_payload']
                        : (is_array($input['form_payload'] ?? null) ? $input['form_payload'] : []);
                    $result = dipascaf_submit_category_results(
                        $assignment,
                        (int) $user['id'],
                        'b',
                        $formBPayload,
                        (string) ($assignment['cycle_name'] ?? 'Current Appraisal Cycle')
                    );
                }

                admin_activity('Submitted ' . ($formType === 'form_a' ? 'Form A' : 'Form B') . ' evaluation.');

                echo json_encode([
                    'ok' => true,
                    'success' => true,
                    'assignment_id' => (int) $assignment['id'],
                    'total_weighted_score' => $result['total_weighted_score'] ?? 0,
                    'message' => 'Evaluation submitted successfully.',
                ]);
            } catch (Throwable $e) {
                error_log('[evaluations] Evaluation submission failed: ' . $e->getMessage() . ' user=' . (int) ($user['id'] ?? 0) . ' assignment=' . $assignmentId);
                http_response_code($e instanceof RuntimeException ? 422 : 500);
                echo json_encode(['ok' => false, 'success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
        exit;
    }

    // ── Ensure assignments exist for the requested role ────────────────
    dipascaf_init_evaluation_assignments((int) $user['id'], $requestedRole);

    // ── Period filtering: use selected period or current ────────────────
    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = $selectedPeriod !== null ? (string) ($selectedPeriod['period_name'] ?? '') : '';

    $periodPayload = $selectedPeriod !== null ? dipascaf_period_payload($selectedPeriod) : dipascaf_period_payload();
    $peerLifecycle = $selectedPeriod !== null
        ? dipascaf_peer_lifecycle((int) ($selectedPeriod['id'] ?? 0))
        : ['status' => 'unlocked', 'isLocked' => false];
    $allAssignments = dipascaf_assignment_rows((int) $user['id'], $requestedRole);

    // Filter assignments by selected period name
    $assignments = $selectedPeriodName !== ''
        ? array_filter($allAssignments, static fn (array $row): bool =>
            strcasecmp((string) ($row['cycle_name'] ?? ''), $selectedPeriodName) === 0
          )
        : $allAssignments;
    $assignments = array_values($assignments);
    $formARecords = dipascaf_form_a_records($assignments);
    $formBRecords = dipascaf_form_b_records($assignments);
    $selfRecords = evaluation_api_self_records($assignments);

    $data = array_map(static function (array $row) use ($formARecords, $formBRecords, $selfRecords): array {
        $section = (string) ($row['section_key'] ?? 'faculty');
        $roleLabel = (string) ($row['role_label'] ?? ($section === 'program_head' ? 'Program Head' : ($section === 'dean' ? 'Dean' : 'Faculty')));
        $isSelf = (string) ($row['assignment_type'] ?? '') === 'self';
        if ($isSelf) {
            $section = 'self';
            $roleLabel = 'Self Evaluation';
        }
        $status = (string) ($row['status'] ?? 'pending');
        $deadline = (string) ($row['deadline'] ?? '');
        if ($status !== 'submitted' && $deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < strtotime(date('Y-m-d'))) {
            $status = 'overdue';
        }
        $assignmentId = (int) $row['id'];
        $selfRecord = $selfRecords[$assignmentId] ?? null;
        if ($isSelf && $selfRecord !== null) {
            $status = (string) ($selfRecord['status'] ?? $status);
        }
        $questionnaireType = (string) ($row['questionnaire_type'] ?? '');
        $categoryResults = [];
        if ($isSelf && $selfRecord !== null) {
            $selfCategories = is_array($selfRecord['form_payload']['categories'] ?? null) ? $selfRecord['form_payload']['categories'] : [];
            $categoryResults = array_map(static fn (array $category): array => [
                'categoryId' => (string) ($category['id'] ?? ''),
                'title' => (string) ($category['title'] ?? 'Self Evaluation'),
                'answers' => is_array($category['answers'] ?? null) ? $category['answers'] : [],
                'totalRating' => 0,
                'questionCount' => count(is_array($category['answers'] ?? null) ? $category['answers'] : []),
                'averageRating' => 0,
                'factorWeight' => (float) ($category['factor_weight'] ?? 0),
                'weightedScore' => 0,
                'behavioralEvidence' => (string) ($category['evidence'] ?? ''),
                'reasonForRating' => '',
                'recommendation' => '',
                'aiSuggestion' => '',
                'aiDecision' => 'none',
                'questionnaireEvidence' => [],
            ], $selfCategories);
        } elseif ($status === 'submitted') {
            if ($questionnaireType === 'admin') {
                $categoryResults = array_map(static fn (array $record): array => [
                    'categoryId' => (int) $record['category_id'],
                    'title' => (string) ($record['category_title'] ?? ''),
                    'answers' => $record['questionnaire_answers'] ?? [],
                    'totalRating' => (float) $record['total_rate'],
                    'questionCount' => (int) $record['question_count'],
                    'averageRating' => (float) $record['average_rating'],
                    'factorWeight' => (float) $record['factor_weight'],
                    'weightedScore' => (float) $record['weighted_score'],
                    'behavioralEvidence' => (string) ($record['behavioral_evidence'] ?? ''),
                    'reasonForRating' => (string) ($record['reason_for_rating'] ?? ''),
                    'recommendation' => (string) ($record['recommendation'] ?? ''),
                    'aiSuggestion' => (string) ($record['ai_suggestion'] ?? ''),
                    'aiDecision' => (string) ($record['ai_decision'] ?? 'none'),
                    'questionnaireEvidence' => $record['questionnaire_evidence'] ?? [],
                ], $formARecords[$assignmentId] ?? []);
            } else {
                $categoryResults = array_map(static fn (array $record): array => [
                    'categoryId' => (int) $record['category_id'],
                    'title' => (string) ($record['title'] ?? ''),
                    'answers' => $record['answers'] ?? [],
                    'totalRating' => (float) $record['total_rate'],
                    'questionCount' => (int) $record['question_count'],
                    'averageRating' => (float) $record['average_rating'],
                    'factorWeight' => (float) $record['factor_weight'],
                    'weightedScore' => (float) $record['weighted_score'],
                    'behavioralEvidence' => (string) ($record['behavioral_evidence'] ?? ''),
                    'reasonForRating' => (string) ($record['reason_for_rating'] ?? ''),
                    'recommendation' => (string) ($record['recommendation'] ?? ''),
                    'aiSuggestion' => (string) ($record['ai_suggestion'] ?? ''),
                    'aiDecision' => (string) ($record['ai_decision'] ?? 'none'),
                    'questionnaireEvidence' => $record['questionnaire_evidence'] ?? [],
                ], array_values($formBRecords[(string) $assignmentId] ?? []));
            }
        }

        $score = $isSelf && $selfRecord !== null && isset($selfRecord['overall_rating'])
            ? (float) $selfRecord['overall_rating']
            : evaluation_api_score($row, $categoryResults);
        $previous = evaluation_api_previous_score(
            $assignmentId,
            (int) ($row['evaluatee_faculty_id'] ?? 0),
            (string) ($row['cycle_name'] ?? '')
        );

        return [
            'id' => $assignmentId,
            'assignmentId' => $assignmentId,
            'evaluateeId' => (int) ($row['evaluatee_faculty_id'] ?? 0),
            'fullName' => (string) ($row['full_name'] ?? $row['evaluatee_name'] ?? 'Assigned Employee'),
            'evaluateeName' => (string) ($row['evaluatee_name'] ?? $row['full_name'] ?? 'Assigned Employee'),
            'role' => $roleLabel,
            'evaluateeRole' => $roleLabel,
            'assignmentType' => (string) ($row['assignment_type'] ?? ''),
            'assignmentTypeLabel' => $isSelf ? 'Self-Evaluation' : (string) ($row['assignment_type'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'program' => (string) ($row['program_code'] ?? $row['program_name'] ?? ''),
            'status' => $status === 'submitted' ? 'submitted' : ($status === 'overdue' ? 'overdue' : 'pending'),
            'avatar' => (string) ($row['profile_image'] ?? ''),
            'position' => (string) ($row['position_title'] ?? $roleLabel),
            'evaluateePosition' => (string) ($row['position_title'] ?? $roleLabel),
            'section' => $section,
            'questionnaireType' => $questionnaireType,
            'period' => (string) ($row['cycle_name'] ?? 'Current Appraisal Cycle'),
            'periodName' => (string) ($row['cycle_name'] ?? 'Current Appraisal Cycle'),
            'deadline' => $deadline,
            'dateEvaluated' => (string) ($row['date_evaluated_display'] ?? ''),
            'progressPercent' => (int) ($row['progress_percent'] ?? ($status === 'submitted' ? 100 : 0)),
            'relationshipTag' => (string) ($row['relationship_tag'] ?? ''),
            'score' => $score,
            'previousScore' => $previous['score'] ?? null,
            'previousPeriod' => $previous['period'] ?? '',
            'scoreChange' => ($score !== null && isset($previous['score']) && $previous['score'] !== null)
                ? round($score - (float) $previous['score'], 4)
                : null,
            'categoryResults' => $categoryResults,
            'selfEvaluationRecordId' => $selfRecord !== null ? (int) ($selfRecord['id'] ?? 0) : null,
            'selfEvaluationStatus' => $selfRecord !== null ? (string) ($selfRecord['status'] ?? '') : '',
        ];
    }, $assignments);

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'period' => $periodPayload,
        'peerLifecycle' => $peerLifecycle,
        'summary' => [
            'total' => count($data),
            'submitted' => count(array_filter($data, static fn (array $row): bool => $row['status'] === 'submitted')),
            'pending' => count(array_filter($data, static fn (array $row): bool => $row['status'] !== 'submitted')),
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
