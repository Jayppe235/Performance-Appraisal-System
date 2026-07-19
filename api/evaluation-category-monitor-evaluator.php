<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';
require_once __DIR__ . '/../includes/self_evaluation_status.php';

header('Content-Type: application/json; charset=utf-8');

function evaluator_monitor_level(float $score): string
{
    if ($score >= 4.5) return 'Exceptional';
    if ($score >= 4.0) return 'Exceeds Expectations';
    if ($score >= 3.0) return 'Meets Expectations';
    if ($score > 0) return 'Needs Improvement';
    return 'Pending';
}

function evaluator_monitor_role_label(string $role, string $type = ''): string
{
    if ($type === 'self') return 'Self';
    return match ($role) {
        'dean' => 'Dean',
        'program_head' => 'Program Head',
        'vpaa' => 'VPAA',
        default => 'Teacher',
    };
}

function evaluator_monitor_json_array(mixed $value): array
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function evaluator_monitor_questions(string $form, int $categoryId): array
{
    $table = $form === 'form_a' ? 'pmas_form_a_questions' : 'pmas_form_b_questions';
    return admin_all(
        "SELECT id, question_text
         FROM {$table}
         WHERE category_id = :category_id AND COALESCE(is_active, 1) = 1
         ORDER BY sort_order, id",
        ['category_id' => $categoryId]
    );
}

function evaluator_monitor_categories(int $assignmentId): array
{
    $queries = [
        ['form_a', 'pmas_form_a_category_results', 'pmas_form_a_categories'],
        ['form_b', 'pmas_form_b_category_results', 'pmas_form_b_categories'],
    ];
    $rows = [];
    foreach ($queries as [$form, $table, $categoryTable]) {
        $resultRows = admin_all(
            "SELECT :form_type AS form_type, r.*, c.title AS category_title
             FROM {$table} r
             JOIN {$categoryTable} c ON c.id = r.category_id
             WHERE r.assignment_id = :assignment_id AND COALESCE(r.is_archived, 0) = 0
             ORDER BY c.sort_order, c.id",
            ['form_type' => $form, 'assignment_id' => $assignmentId]
        );
        foreach ($resultRows as $row) {
            $answers = evaluator_monitor_json_array($row['questionnaire_answers'] ?? []);
            $evidence = evaluator_monitor_json_array($row['questionnaire_evidence'] ?? []);
            $questions = [];
            foreach (evaluator_monitor_questions($form, (int) $row['category_id']) as $question) {
                $questionId = (string) $question['id'];
                $questions[] = [
                    'id' => (int) $question['id'],
                    'text' => (string) $question['question_text'],
                    'rating' => isset($answers[$questionId]) ? (float) $answers[$questionId] : null,
                    'evidence' => isset($evidence[$questionId]) ? secure_decrypt_value((string) $evidence[$questionId]) : '',
                ];
            }
            $rows[] = [
                'formType' => $form,
                'categoryId' => (int) $row['category_id'],
                'categoryTitle' => (string) $row['category_title'],
                'totalRate' => (float) $row['total_rate'],
                'questionCount' => (int) $row['question_count'],
                'averageRating' => (float) $row['average_rating'],
                'factorWeight' => (float) $row['factor_weight'],
                'weightedScore' => (float) $row['weighted_score'],
                'behavioralEvidence' => secure_decrypt_value($row['behavioral_evidence'] ?? ''),
                'reasonForRating' => secure_decrypt_value($row['reason_for_rating'] ?? ''),
                'recommendation' => secure_decrypt_value($row['recommendation'] ?? ''),
                'explanationComplete' => (int) ($row['explanation_complete'] ?? 0) === 1,
                'submittedAt' => (string) ($row['submitted_at'] ?? ''),
                'questions' => $questions,
            ];
        }
    }
    return $rows;
}

function evaluator_monitor_assignment_summary(array $assignment, ?array $allScores = null): array
{
    $categories = evaluator_monitor_categories((int) $assignment['id']);
    $categoryCount = count($categories);
    $questionsAnswered = array_sum(array_map(static fn (array $row): int => (int) $row['questionCount'], $categories));
    $totalQuestions = max($questionsAnswered, $categoryCount > 0 ? $questionsAnswered : 0);
    $score = $categoryCount > 0
        ? array_sum(array_map(static fn (array $row): float => (float) $row['averageRating'], $categories)) / $categoryCount
        : 0.0;
    $highest = null;
    $lowest = null;
    foreach ($categories as $category) {
        if ($highest === null || $category['averageRating'] > $highest['averageRating']) $highest = $category;
        if ($lowest === null || $category['averageRating'] < $lowest['averageRating']) $lowest = $category;
    }
    $evidenceIncluded = count(array_filter($categories, static function (array $row): bool {
        return $row['explanationComplete']
            || trim((string) $row['behavioralEvidence']) !== ''
            || trim((string) $row['reasonForRating']) !== ''
            || trim((string) $row['recommendation']) !== '';
    })) > 0;

    $mean = 0.0;
    $stddev = 0.0;
    $zScore = null;
    if (is_array($allScores) && count($allScores) > 1 && $score > 0) {
        $mean = array_sum($allScores) / count($allScores);
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $allScores)) / count($allScores);
        $stddev = sqrt($variance);
        $zScore = $stddev > 0 ? ($score - $mean) / $stddev : 0.0;
    }

    $status = (string) ($assignment['status'] ?? 'pending');
    $isOverdue = $status !== 'submitted'
        && !empty($assignment['deadline'])
        && strtotime((string) $assignment['deadline']) < strtotime(date('Y-m-d'));

    return [
        'assignmentId' => (int) $assignment['id'],
        'facultyId' => (int) $assignment['evaluatee_faculty_id'],
        'facultyName' => (string) ($assignment['faculty_name'] ?? ''),
        'evaluatorUserId' => (int) $assignment['evaluator_user_id'],
        'evaluatorName' => (string) ($assignment['evaluator_name'] ?? 'Unknown evaluator'),
        'evaluatorEmail' => (string) ($assignment['evaluator_email'] ?? ''),
        'evaluatorRole' => (string) ($assignment['evaluator_role'] ?? ''),
        'roleLabel' => evaluator_monitor_role_label((string) ($assignment['evaluator_role'] ?? ''), (string) ($assignment['assignment_type'] ?? '')),
        'assignmentType' => (string) ($assignment['assignment_type'] ?? ''),
        'submissionStatus' => $isOverdue ? 'overdue' : $status,
        'submittedAt' => (string) ($assignment['submitted_at'] ?? ''),
        'deadline' => (string) ($assignment['deadline'] ?? ''),
        'overallRating' => round($score, 2),
        'performanceLevel' => evaluator_monitor_level($score),
        'completionPercentage' => $status === 'submitted' ? 100 : ($categoryCount > 0 ? 75 : 0),
        'categoryCount' => $categoryCount,
        'questionsAnswered' => $questionsAnswered,
        'totalQuestions' => $totalQuestions,
        'averageCategoryScore' => round($score, 2),
        'highestRatedCategory' => $highest['categoryTitle'] ?? '',
        'lowestRatedCategory' => $lowest['categoryTitle'] ?? '',
        'evidenceIncluded' => $evidenceIncluded,
        'isOutlier' => $zScore !== null && abs($zScore) >= 2,
        'zScore' => $zScore !== null ? round($zScore, 2) : null,
        'categories' => $categories,
    ];
}

function evaluator_monitor_self_evaluation(int $facultyId, string $period): array
{
    $params = ['faculty_id' => $facultyId];
    $periodSql = '';
    if ($period !== '') {
        $periodSql = ' AND pa.cycle_name = :period';
        $params['period'] = $period;
    }
    $assignment = admin_one(
        "SELECT pa.* FROM peer_assignments pa
         WHERE pa.evaluatee_faculty_id = :faculty_id
           AND pa.assignment_type = 'self' AND COALESCE(pa.is_archived, 0) = 0
           {$periodSql}
         ORDER BY pa.assigned_at DESC, pa.id DESC LIMIT 1",
        $params
    );
    $record = null;
    if ($assignment !== null) {
        $record = admin_one(
            'SELECT id, assignment_id, status, submitted_at, reopened_at, updated_at
             FROM pmas_self_evaluations WHERE assignment_id = :assignment_id
             ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['assignment_id' => (int) $assignment['id']]
        );
    }
    return [
        'status' => evaluator_monitor_normalize_self_status($record, $assignment),
        'recordId' => (int) ($record['id'] ?? 0),
        'assignmentId' => (int) ($assignment['id'] ?? 0),
        'submittedAt' => (string) ($record['submitted_at'] ?? ''),
        'reopenedAt' => (string) ($record['reopened_at'] ?? ''),
        'deadline' => (string) ($assignment['deadline'] ?? ''),
        'canView' => $record !== null && (string) ($record['status'] ?? '') === 'submitted',
    ];
}

function evaluator_monitor_assignments(int $facultyId, string $period): array
{
    $params = ['faculty_id' => $facultyId];
    $periodSql = '';
    if ($period !== '') {
        $periodSql = ' AND pa.cycle_name = :period';
        $params['period'] = $period;
    }
    return admin_all(
        "SELECT pa.*, f.full_name AS faculty_name, f.department, f.program_code,
                u.full_name AS evaluator_name, u.email AS evaluator_email
         FROM peer_assignments pa
         JOIN faculty f ON f.id = pa.evaluatee_faculty_id
         LEFT JOIN users u ON u.id = pa.evaluator_user_id
         WHERE pa.evaluatee_faculty_id = :faculty_id
           AND pa.assignment_type <> 'self'
           AND COALESCE(pa.is_archived, 0) = 0
           AND COALESCE(f.is_archived, 0) = 0
           {$periodSql}
         ORDER BY FIELD(pa.status, 'pending', 'submitted'), pa.evaluator_role, u.full_name",
        $params
    );
}

function evaluator_monitor_payload(int $facultyId, string $period): array
{
    $assignments = evaluator_monitor_assignments($facultyId, $period);
    $firstPass = array_map(static fn (array $assignment): array => evaluator_monitor_assignment_summary($assignment), $assignments);
    $scores = array_values(array_filter(array_map(static fn (array $row): float => (float) $row['overallRating'], $firstPass), static fn (float $value): bool => $value > 0));
    $evaluators = array_map(static fn (array $assignment): array => evaluator_monitor_assignment_summary($assignment, $scores), $assignments);
    return ['evaluators' => $evaluators, 'statistics' => evaluator_monitor_statistics_from_rows($evaluators)];
}

function evaluator_monitor_statistics_from_rows(array $rows): array
{
    $scores = array_values(array_filter(array_map(static fn (array $row): float => (float) ($row['overallRating'] ?? 0), $rows), static fn (float $score): bool => $score > 0));
    sort($scores);
    $count = count($scores);
    $average = $count ? array_sum($scores) / $count : 0.0;
    $median = 0.0;
    if ($count > 0) {
        $middle = intdiv($count, 2);
        $median = $count % 2 ? $scores[$middle] : (($scores[$middle - 1] + $scores[$middle]) / 2);
    }
    $variance = $count ? array_sum(array_map(static fn (float $score): float => ($score - $average) ** 2, $scores)) / $count : 0.0;
    $histogram = ['0-1' => 0, '1-2' => 0, '2-3' => 0, '3-4' => 0, '4-5' => 0];
    foreach ($scores as $score) {
        if ($score < 1) $histogram['0-1']++;
        elseif ($score < 2) $histogram['1-2']++;
        elseif ($score < 3) $histogram['2-3']++;
        elseif ($score < 4) $histogram['3-4']++;
        else $histogram['4-5']++;
    }
    return [
        'averageScore' => round($average, 2),
        'medianScore' => round($median, 2),
        'standardDeviation' => round(sqrt($variance), 2),
        'scoreRange' => ['min' => $count ? min($scores) : 0, 'max' => $count ? max($scores) : 0],
        'evaluatorCount' => count($rows),
        'submittedCount' => count(array_filter($rows, static fn (array $row): bool => ($row['submissionStatus'] ?? '') === 'submitted')),
        'pendingCount' => count(array_filter($rows, static fn (array $row): bool => in_array(($row['submissionStatus'] ?? ''), ['pending', 'draft', 'overdue'], true))),
        'histogram' => $histogram,
    ];
}

function evaluator_monitor_comparison(array $evaluators): array
{
    $categoryMap = [];
    foreach ($evaluators as $evaluator) {
        foreach (($evaluator['categories'] ?? []) as $category) {
            $title = (string) $category['categoryTitle'];
            if (!isset($categoryMap[$title])) {
                $categoryMap[$title] = ['categoryTitle' => $title, 'scores' => []];
            }
            $categoryMap[$title]['scores'][] = [
                'assignmentId' => (int) $evaluator['assignmentId'],
                'evaluatorName' => (string) $evaluator['evaluatorName'],
                'roleLabel' => (string) $evaluator['roleLabel'],
                'score' => (float) $category['averageRating'],
            ];
        }
    }
    foreach ($categoryMap as &$row) {
        $values = array_map(static fn (array $score): float => (float) $score['score'], $row['scores']);
        $row['average'] = count($values) ? round(array_sum($values) / count($values), 2) : 0;
        $row['spread'] = count($values) ? round(max($values) - min($values), 2) : 0;
    }
    return array_values($categoryMap);
}

try {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Unauthenticated.']);
        exit;
    }
    if (!in_array(($user['role'] ?? ''), ['admin_hr', 'admin', 'dean'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You do not have access to evaluator results.']);
        exit;
    }

    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();
    admin_ensure_faculty_program_schema();

    $action = (string) ($_GET['action'] ?? 'overview');
    $facultyId = (int) ($_GET['faculty_id'] ?? 0);
    $period = trim((string) ($_GET['period'] ?? ''));

    if ($action === 'options') {
        $periods = admin_all(
            "SELECT DISTINCT cycle_name AS period
             FROM peer_assignments
             WHERE COALESCE(is_archived, 0) = 0 AND cycle_name IS NOT NULL AND cycle_name != ''
             ORDER BY cycle_name DESC"
        );
        $faculty = admin_all(
            "SELECT DISTINCT f.id, f.full_name, f.department, f.program_code
             FROM faculty f
             JOIN peer_assignments pa ON pa.evaluatee_faculty_id = f.id
             WHERE COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0
             ORDER BY f.department, f.full_name"
        );
        echo json_encode(['ok' => true, 'faculty' => $faculty, 'periods' => $periods]);
        exit;
    }

    if ($facultyId <= 0) {
        $first = admin_one(
            "SELECT f.id
             FROM faculty f
             JOIN peer_assignments pa ON pa.evaluatee_faculty_id = f.id
             WHERE COALESCE(f.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0
             ORDER BY pa.assigned_at DESC, f.full_name
             LIMIT 1"
        );
        $facultyId = (int) ($first['id'] ?? 0);
    }

    $payload = evaluator_monitor_payload($facultyId, $period);
    $selfEvaluation = evaluator_monitor_self_evaluation($facultyId, $period);

    if ($action === 'evaluator_detail') {
        $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
        $detail = null;
        foreach ($payload['evaluators'] as $row) {
            if ((int) $row['assignmentId'] === $assignmentId) {
                $detail = $row;
                break;
            }
        }
        echo json_encode(['ok' => true, 'detail' => $detail]);
        exit;
    }

    if ($action === 'comparison') {
        echo json_encode(['ok' => true, 'comparison' => evaluator_monitor_comparison($payload['evaluators'])]);
        exit;
    }

    if ($action === 'statistics') {
        echo json_encode(['ok' => true, 'statistics' => $payload['statistics']]);
        exit;
    }

    $faculty = admin_one('SELECT id, full_name, department, program_code FROM faculty WHERE id = :id', ['id' => $facultyId]);
    echo json_encode([
        'ok' => true,
        'faculty' => $faculty,
        'period' => $period,
        'evaluators' => $payload['evaluators'],
        'statistics' => $payload['statistics'],
        'comparison' => evaluator_monitor_comparison($payload['evaluators']),
        'selfEvaluation' => $selfEvaluation,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
