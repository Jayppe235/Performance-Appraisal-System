<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_participation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function admin_analytics_result_rows(string $periodName = '', string $evaluationForm = ''): array
{
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();

    dipascaf_ensure_period_participation_schema();
    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $participationSql = " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods eap ON eap.id=epp.evaluation_period_id WHERE eap.period_name=pa.cycle_name AND epp.participation_status='excluded' AND (epp.user_id=pa.evaluator_user_id OR epp.user_id=rf.user_id))";
    $params = $periodName !== '' ? [$periodName, $periodName] : [];

    $sql = "
        SELECT 'a' AS form_type, r.evaluatee_faculty_id, r.assignment_id, r.category_id, c.title AS category_title,
               r.average_rating, r.weighted_score, r.questionnaire_answers, r.questionnaire_evidence,
               r.behavioral_evidence, r.reason_for_rating, r.recommendation, r.submitted_at
        FROM pmas_form_a_category_results r
        JOIN pmas_form_a_categories c ON c.id = r.category_id
        JOIN peer_assignments pa ON pa.id = r.assignment_id
        JOIN faculty rf ON rf.id = pa.evaluatee_faculty_id
        WHERE r.status = 'completed'
          AND COALESCE(r.is_archived, 0) = 0
          AND COALESCE(pa.is_archived, 0) = 0
          {$participationSql}
          {$periodSql}
        UNION ALL
        SELECT 'b' AS form_type, r.evaluatee_faculty_id, r.assignment_id, r.category_id, c.title AS category_title,
               r.average_rating, r.weighted_score, r.questionnaire_answers, r.questionnaire_evidence,
               r.behavioral_evidence, r.reason_for_rating, r.recommendation, r.submitted_at
        FROM pmas_form_b_category_results r
        JOIN pmas_form_b_categories c ON c.id = r.category_id
        JOIN peer_assignments pa ON pa.id = r.assignment_id
        JOIN faculty rf ON rf.id = pa.evaluatee_faculty_id
        WHERE r.status = 'completed'
          AND COALESCE(r.is_archived, 0) = 0
          AND COALESCE(pa.is_archived, 0) = 0
          {$participationSql}
          {$periodSql}
    ";

    $rows = admin_all($sql, $params);
    if ($evaluationForm === 'form_a') {
        return array_values(array_filter($rows, static fn (array $row): bool => ($row['form_type'] ?? '') === 'a'));
    }
    if ($evaluationForm === 'form_b') {
        return array_values(array_filter($rows, static fn (array $row): bool => ($row['form_type'] ?? '') === 'b'));
    }

    if ($evaluationForm === '' || $evaluationForm === 'self') {
        $selfPeriodSql = $periodName !== '' ? ' AND se.evaluation_period = ?' : '';
        $selfRows = admin_all(
            "SELECT 'self' AS form_type, f.id AS evaluatee_faculty_id, se.assignment_id, 0 AS category_id,
                    'Self Evaluation' AS category_title, se.overall_rating AS average_rating,
                    se.overall_rating AS weighted_score, '[]' AS questionnaire_answers,
                    '[]' AS questionnaire_evidence, '' AS behavioral_evidence,
                    '' AS reason_for_rating, '' AS recommendation, se.submitted_at
             FROM pmas_self_evaluations se
             JOIN faculty f ON f.user_id = se.user_id
             WHERE se.status IN ('submitted', 'approved')
               AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods eap ON eap.id=epp.evaluation_period_id WHERE eap.period_name=se.evaluation_period AND epp.user_id=se.user_id AND epp.participation_status='excluded')
               {$selfPeriodSql}",
            $periodName !== '' ? [$periodName] : []
        );
        if ($evaluationForm === 'self') {
            return $selfRows;
        }
        $rows = array_merge($rows, $selfRows);
    }

    return $rows;
}

function admin_analytics_json_array(mixed $value): array
{
    $decoded = json_decode((string) ($value ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function admin_analytics_question_catalog(): array
{
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();

    $catalog = ['a' => [], 'b' => []];
    foreach (['a' => 'pmas_form_a_questions', 'b' => 'pmas_form_b_questions'] as $form => $table) {
        $rows = admin_all(
            "SELECT id, question_text
             FROM {$table}
             WHERE is_active = 1
             ORDER BY sort_order, id"
        );
        foreach ($rows as $row) {
            $catalog[$form][(string) ((int) ($row['id'] ?? 0))] = (string) ($row['question_text'] ?? '');
        }
    }

    return $catalog;
}

function admin_analytics_record_questions(array $row, array $questionCatalog): array
{
    $formType = (string) ($row['form_type'] ?? '');
    $answers = admin_analytics_json_array($row['questionnaire_answers'] ?? '');
    $evidence = admin_analytics_json_array($row['questionnaire_evidence'] ?? '');
    $questions = [];

    foreach ($answers as $questionId => $rating) {
        $questionKey = (string) $questionId;
        $score = is_numeric($rating) ? (float) $rating : null;
        if ($score === null) {
            continue;
        }
        $text = trim((string) ($questionCatalog[$formType][$questionKey] ?? ''));
        $questions[] = [
            'question_id' => $questionKey,
            'question' => $text !== '' ? $text : 'Question #' . $questionKey,
            'score' => round($score, 2),
            'evidence' => trim((string) ($evidence[$questionKey] ?? '')),
        ];
    }

    return $questions;
}

function admin_analytics_add_question_details(array &$bucket, array $questions): void
{
    $bucket['questions'] ??= [];
    foreach ($questions as $question) {
        $questionText = (string) ($question['question'] ?? '');
        if ($questionText === '') {
            continue;
        }
        $bucket['questions'][$questionText] ??= [
            'question' => $questionText,
            'total' => 0.0,
            'count' => 0,
            'evidence' => [],
        ];
        $bucket['questions'][$questionText]['total'] += (float) ($question['score'] ?? 0);
        $bucket['questions'][$questionText]['count']++;
        $evidence = trim((string) ($question['evidence'] ?? ''));
        if ($evidence !== '') {
            $bucket['questions'][$questionText]['evidence'][] = $evidence;
        }
    }
}

function admin_analytics_format_questions(array $questions): array
{
    return array_values(array_map(
        static fn (array $bucket): array => [
            'question' => (string) ($bucket['question'] ?? ''),
            'average_score' => (int) ($bucket['count'] ?? 0) > 0
                ? round(((float) ($bucket['total'] ?? 0)) / (int) $bucket['count'], 2)
                : null,
            'answer_count' => (int) ($bucket['count'] ?? 0),
            'evidence' => array_values(array_unique(array_filter(
                array_map('strval', $bucket['evidence'] ?? []),
                static fn (string $text): bool => trim($text) !== ''
            ))),
        ],
        $questions
    ));
}

function admin_analytics_payload(string $periodName = '', string $evaluationForm = ''): array
{
    admin_ensure_faculty_program_schema();
    admin_ensure_archive_schema();

    $periodSql = $periodName !== '' ? ' AND pa.cycle_name = ?' : '';
    $assignmentFormSql = match ($evaluationForm) {
        'form_a' => " AND pa.questionnaire_type = 'admin' AND pa.assignment_type <> 'self'",
        'form_b' => " AND pa.questionnaire_type = 'faculty' AND pa.assignment_type <> 'self'",
        'self' => " AND pa.assignment_type = 'self'",
        default => '',
    };

    dipascaf_ensure_period_participation_schema();
    $facultyParticipationSql = $periodName !== '' ? " AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods eap ON eap.id=epp.evaluation_period_id WHERE eap.period_name=? AND epp.user_id=u.id AND epp.participation_status='excluded')" : '';
    $facultyRows = admin_all(
        "SELECT f.id, f.full_name, f.email, f.department,
                COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code,
                f.position_title, f.progress_percent
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE COALESCE(f.is_archived, 0) = 0
           AND (u.role IS NULL OR u.role IN ('teacher', 'program_head', 'dean', 'vpaa'))
           {$facultyParticipationSql}
         ORDER BY f.department, f.full_name"
    , $periodName !== '' ? [$periodName] : []);

    $profiles = [];
    foreach ($facultyRows as $row) {
        $profiles[(int) $row['id']] = [
            'faculty_id' => (int) $row['id'],
            'full_name' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department'] ?? 'Unassigned Department'),
            'program_code' => (string) ($row['program_code'] ?? 'Unassigned Program'),
            'position_title' => (string) ($row['position_title'] ?? ''),
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
            'total_assignments' => 0,
            'submitted' => 0,
            'pending' => 0,
            'completion_rate' => 0,
            'average_score' => null,
            'result_count' => 0,
            'weakest_category' => '',
            'weakest_score' => null,
            'strongest_category' => '',
            'strongest_score' => null,
            'category_scores' => [],
            'ai_weak_area' => '',
            'ai_strength_area' => '',
            'ai_summary' => '',
        ];
    }

    if ($profiles === []) {
        return [
            'summary' => ['faculty_count' => 0, 'submitted' => 0, 'pending' => 0, 'average_score' => null],
            'departments' => [],
            'departmentPerformance' => [],
            'facultyProfiles' => [],
            'categoryScores' => [],
            'highestRatedAreas' => [],
            'lowestRatedAreas' => [],
            'ratingDistribution' => ['excellent' => 0, 'very_satisfactory' => 0, 'satisfactory' => 0, 'needs_improvement' => 0],
            'generatedSummary' => 'No evaluation data available for the selected appraisal period.',
        ];
    }

    $ids = array_keys($profiles);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $assignmentRows = admin_all(
        "SELECT pa.evaluatee_faculty_id,
                COUNT(*) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN pa.status = 'pending' THEN 1 ELSE 0 END) AS pending
         FROM peer_assignments pa
         WHERE pa.evaluatee_faculty_id IN ({$placeholders})
           AND COALESCE(pa.is_archived, 0) = 0 AND pa.status <> 'not_required'
           AND NOT EXISTS (SELECT 1 FROM evaluation_period_participation epp JOIN appraisal_periods eap ON eap.id=epp.evaluation_period_id WHERE eap.period_name=pa.cycle_name AND epp.user_id=pa.evaluator_user_id AND epp.participation_status='excluded'){$assignmentFormSql}{$periodSql}
         GROUP BY pa.evaluatee_faculty_id",
        $periodName !== '' ? array_merge($ids, [$periodName]) : $ids
    );

    foreach ($assignmentRows as $row) {
        $id = (int) ($row['evaluatee_faculty_id'] ?? 0);
        if (!isset($profiles[$id])) {
            continue;
        }
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted'] ?? 0);
        $profiles[$id]['total_assignments'] = $total;
        $profiles[$id]['submitted'] = $submitted;
        $profiles[$id]['pending'] = (int) ($row['pending'] ?? 0);
        $profiles[$id]['completion_rate'] = $total > 0 ? round(($submitted / $total) * 100, 1) : 0;
    }

    $resultRows = admin_analytics_result_rows($periodName, $evaluationForm);
    $questionCatalog = admin_analytics_question_catalog();
    $scoreBuckets = [];
    $categoryBuckets = [];
    $weightedValues = [];
    $ratingDistribution = ['excellent' => 0, 'very_satisfactory' => 0, 'satisfactory' => 0, 'needs_improvement' => 0];

    foreach ($resultRows as $row) {
        $id = (int) ($row['evaluatee_faculty_id'] ?? 0);
        $score = (float) ($row['average_rating'] ?? 0);
        $weighted = isset($row['weighted_score']) ? (float) $row['weighted_score'] : null;
        $formType = (string) ($row['form_type'] ?? '');
        $categoryTitle = (string) ($row['category_title'] ?? 'Uncategorized');
        $categoryKey = $formType . ':' . $categoryTitle;
        $questionDetails = admin_analytics_record_questions($row, $questionCatalog);

        if (isset($profiles[$id])) {
            $scoreBuckets[$id] ??= ['total' => 0.0, 'count' => 0];
            $scoreBuckets[$id]['total'] += $score;
            $scoreBuckets[$id]['count']++;

            if ($profiles[$id]['weakest_score'] === null || $score < (float) $profiles[$id]['weakest_score']) {
                $profiles[$id]['weakest_score'] = round($score, 2);
                $profiles[$id]['weakest_category'] = $categoryTitle;
            }
            if ($profiles[$id]['strongest_score'] === null || $score > (float) $profiles[$id]['strongest_score']) {
                $profiles[$id]['strongest_score'] = round($score, 2);
                $profiles[$id]['strongest_category'] = $categoryTitle;
            }
            $profiles[$id]['category_scores'][$categoryKey] ??= [
                'category' => $categoryTitle,
                'form_type' => $formType,
                'total' => 0.0,
                'count' => 0,
                'questions' => [],
            ];
            $profiles[$id]['category_scores'][$categoryKey]['total'] += $score;
            $profiles[$id]['category_scores'][$categoryKey]['count']++;
            admin_analytics_add_question_details($profiles[$id]['category_scores'][$categoryKey], $questionDetails);
        }

        $categoryBuckets[$categoryKey] ??= [
            'category' => $categoryTitle,
            'form_type' => $formType,
            'total' => 0.0,
            'count' => 0,
            'questions' => [],
        ];
        $categoryBuckets[$categoryKey]['total'] += $score;
        $categoryBuckets[$categoryKey]['count']++;
        admin_analytics_add_question_details($categoryBuckets[$categoryKey], $questionDetails);
        if ($weighted !== null) {
            $weightedValues[] = $weighted;
        }
        if ($score >= 4.5) {
            $ratingDistribution['excellent']++;
        } elseif ($score >= 3.75) {
            $ratingDistribution['very_satisfactory']++;
        } elseif ($score >= 3.0) {
            $ratingDistribution['satisfactory']++;
        } else {
            $ratingDistribution['needs_improvement']++;
        }
    }

    foreach ($scoreBuckets as $id => $bucket) {
        $profiles[$id]['average_score'] = $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null;
        $profiles[$id]['result_count'] = $bucket['count'];
    }
    foreach ($profiles as &$profile) {
        $profile['category_scores'] = array_map(
            static fn (string $categoryKey, array $bucket): array => [
                'category' => (string) ($bucket['category'] ?? $categoryKey),
                'form_type' => (string) ($bucket['form_type'] ?? ''),
                'score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
                'result_count' => (int) $bucket['count'],
                'questions' => admin_analytics_format_questions($bucket['questions'] ?? []),
            ],
            array_keys($profile['category_scores']),
            array_values($profile['category_scores'])
        );
    }
    unset($profile);

    $insightRows = admin_all(
        "SELECT i.faculty_id, i.weak_area, i.strength_area, i.analysis_summary, i.created_at
         FROM ai_insights i
         WHERE i.faculty_id IN ({$placeholders})
         ORDER BY i.created_at DESC",
        $ids
    );
    foreach ($insightRows as $row) {
        $id = (int) ($row['faculty_id'] ?? 0);
        if (!isset($profiles[$id]) || $profiles[$id]['ai_summary'] !== '') {
            continue;
        }
        $profiles[$id]['ai_weak_area'] = (string) ($row['weak_area'] ?? '');
        $profiles[$id]['ai_strength_area'] = (string) ($row['strength_area'] ?? '');
        $profiles[$id]['ai_summary'] = (string) ($row['analysis_summary'] ?? '');
    }

    $departmentBuckets = [];
    foreach ($profiles as $profile) {
        $department = trim((string) ($profile['department'] ?? '')) ?: 'Unassigned Department';
        $departmentBuckets[$department] ??= [
            'program_code' => $department,
            'program_name' => $department,
            'faculty_count' => 0,
            'total_assignments' => 0,
            'submitted' => 0,
            'pending' => 0,
            'overdue' => 0,
            'score_total' => 0.0,
            'score_count' => 0,
        ];
        $departmentBuckets[$department]['faculty_count']++;
        $departmentBuckets[$department]['total_assignments'] += (int) $profile['total_assignments'];
        $departmentBuckets[$department]['submitted'] += (int) $profile['submitted'];
        $departmentBuckets[$department]['pending'] += (int) $profile['pending'];
        if ($profile['average_score'] !== null) {
            $departmentBuckets[$department]['score_total'] += (float) $profile['average_score'];
            $departmentBuckets[$department]['score_count']++;
        }
    }
    $departmentPerformance = array_values(array_map(static function (array $bucket): array {
        $total = (int) $bucket['total_assignments'];
        $submitted = (int) $bucket['submitted'];
        $scoreCount = (int) $bucket['score_count'];
        return [
            'program_code' => $bucket['program_code'],
            'program_name' => $bucket['program_name'],
            'faculty_count' => (int) $bucket['faculty_count'],
            'total_assignments' => $total,
            'submitted' => $submitted,
            'pending' => (int) $bucket['pending'],
            'overdue' => (int) $bucket['overdue'],
            'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
            'average_score' => $scoreCount > 0 ? round(((float) $bucket['score_total']) / $scoreCount, 2) : null,
        ];
    }, $departmentBuckets));
    usort($departmentPerformance, static fn (array $a, array $b): int => strcmp((string) $a['program_code'], (string) $b['program_code']));

    $categoryScores = array_values(array_map(static fn (array $bucket): array => [
        'category' => $bucket['category'],
        'form_type' => (string) ($bucket['form_type'] ?? ''),
        'average_score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
        'result_count' => $bucket['count'],
        'questions' => admin_analytics_format_questions($bucket['questions'] ?? []),
    ], $categoryBuckets));
    usort($categoryScores, static fn (array $a, array $b): int => ((float) ($b['average_score'] ?? 0)) <=> ((float) ($a['average_score'] ?? 0)));
    $lowestCategoryScores = $categoryScores;
    usort($lowestCategoryScores, static fn (array $a, array $b): int => ((float) ($a['average_score'] ?? 99)) <=> ((float) ($b['average_score'] ?? 99)));

    $scoreValues = array_values(array_filter(array_map(static fn (array $profile): ?float => $profile['average_score'] !== null ? (float) $profile['average_score'] : null, $profiles), static fn ($score): bool => $score !== null));
    $totalAssignments = array_sum(array_column($departmentPerformance, 'total_assignments'));
    $submitted = array_sum(array_column($departmentPerformance, 'submitted'));
    $overallAverage = $scoreValues !== [] ? round(array_sum($scoreValues) / count($scoreValues), 2) : null;
    $overallWeightedAverage = $weightedValues !== [] ? round(array_sum($weightedValues) / count($weightedValues), 4) : null;
    $interpretation = $overallAverage === null
        ? 'No evaluation data available'
        : ($overallAverage >= 4.5 ? 'Excellent' : ($overallAverage >= 3.75 ? 'Very Satisfactory' : ($overallAverage >= 3.0 ? 'Satisfactory' : 'Needs Improvement')));

    return [
        'summary' => [
            'department_count' => count($departmentPerformance),
            'faculty_count' => count($profiles),
            'total_assignments' => $totalAssignments,
            'submitted' => $submitted,
            'pending' => array_sum(array_column($departmentPerformance, 'pending')),
            'overdue' => array_sum(array_column($departmentPerformance, 'overdue')),
            'completion_rate' => $totalAssignments > 0 ? round(($submitted / $totalAssignments) * 100, 1) : 0,
            'average_score' => $overallAverage,
            'overall_weighted_average' => $overallWeightedAverage,
            'interpretation' => $interpretation,
        ],
        'departments' => array_column($departmentPerformance, 'program_code'),
        'departmentPerformance' => $departmentPerformance,
        'programs' => $departmentPerformance,
        'facultyProfiles' => array_values($profiles),
        'categoryScores' => $categoryScores,
        'highestRatedAreas' => array_slice($categoryScores, 0, 3),
        'lowestRatedAreas' => array_slice($lowestCategoryScores, 0, 3),
        'ratingDistribution' => $ratingDistribution,
        'generatedSummary' => $overallAverage === null
            ? 'No evaluation data available for the selected appraisal period.'
            : 'The institution recorded an average performance rating of ' . number_format((float) $overallAverage, 2) . ' with ' . $interpretation . ' interpretation. Department comparisons and improvement priorities are based on completed evaluations for the selected appraisal period.',
    ];
}

try {
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'admin_hr') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Admin access is required.']);
        exit;
    }

    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $periodName = $selectedPeriod !== null ? trim((string) ($selectedPeriod['period_name'] ?? '')) : trim((string) ($_GET['period'] ?? ''));
    $evaluationForm = trim((string) ($_GET['evaluation_form'] ?? ''));
    if (!in_array($evaluationForm, ['', 'form_a', 'form_b', 'self'], true)) {
        $evaluationForm = '';
    }
    echo json_encode([
        'ok' => true,
        'data' => array_merge([
            'period' => [
                'id' => $selectedPeriod !== null ? (int) ($selectedPeriod['id'] ?? 0) : 0,
                'name' => $periodName,
            ],
        ], admin_analytics_payload($periodName, $evaluationForm)),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
