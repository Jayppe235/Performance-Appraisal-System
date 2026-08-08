<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

header('Content-Type: application/json; charset=utf-8');

function faculty_results_performance_level(?float $score): string
{
    return match (true) {
        $score === null => 'Pending',
        $score >= 4.50 => 'Outstanding',
        $score >= 3.50 => 'Very Satisfactory',
        $score >= 2.50 => 'Satisfactory',
        $score >= 1.50 => 'Fair',
        default => 'Needs Improvement',
    };
}

function faculty_results_recommended_session(string $category): string
{
    $key = strtolower($category);
    if (str_contains($key, 'classroom')) return 'Classroom management and learner engagement seminar';
    if (str_contains($key, 'communication')) return 'Communication skills and constructive feedback workshop';
    if (str_contains($key, 'teamwork') || str_contains($key, 'interpersonal') || str_contains($key, 'collaboration')) return 'Team collaboration and interpersonal sensitivity seminar';
    if (str_contains($key, 'attendance') || str_contains($key, 'punctuality')) return 'Professional discipline, attendance, and time management seminar';
    if (str_contains($key, 'leadership') || str_contains($key, 'management')) return 'Leadership planning and people management coaching';
    if (str_contains($key, 'knowledge') || str_contains($key, 'quality') || str_contains($key, 'excellence')) return 'Instructional excellence and work quality enhancement seminar';
    if (str_contains($key, 'initiative') || str_contains($key, 'resourcefulness') || str_contains($key, 'creativity') || str_contains($key, 'innovation')) return 'Innovation, initiative, and resourcefulness workshop';
    if (str_contains($key, 'institutional') || str_contains($key, 'commitment') || str_contains($key, 'responsibility')) return 'Institutional commitment and professional responsibility seminar';
    if (str_contains($key, 'decorum') || str_contains($key, 'ethic')) return 'Professional ethics and decorum seminar';
    return 'Targeted professional development session for ' . ($category !== '' ? $category : 'the identified area');
}

function faculty_results_action_text(string $category, float $score): string
{
    $lower = strtolower($category);
    if (str_contains($lower, 'classroom')) return 'Use clearer routines, learner engagement checks, and consistent classroom management strategies.';
    if (str_contains($lower, 'communication')) return 'Practice clearer instructions, timely feedback, and active listening during academic interactions.';
    if (str_contains($lower, 'teamwork') || str_contains($lower, 'interpersonal')) return 'Strengthen collaboration through peer consultation, shared planning, and constructive feedback habits.';
    if (str_contains($lower, 'attendance') || str_contains($lower, 'punctuality')) return 'Set stricter schedule reminders and document attendance-related commitments consistently.';
    if (str_contains($lower, 'quality') || str_contains($lower, 'knowledge')) return 'Review instructional materials, assessment practices, and work outputs against department standards.';
    if (str_contains($lower, 'initiative') || str_contains($lower, 'resourcefulness')) return 'Try one improvement project or teaching innovation and document the impact for follow-up coaching.';
    if (str_contains($lower, 'institutional') || str_contains($lower, 'commitment')) return 'Align work priorities with institutional policies, deadlines, and program goals.';
    return $score < 3.5
        ? 'Coordinate with your dean or program head for coaching and a focused improvement plan.'
        : 'Maintain this area through continued practice, peer sharing, and periodic self-review.';
}

function faculty_results_evaluation_type_label(string $assignmentType, string $evaluatorRole): string
{
    if ($assignmentType === 'self') return 'Self-Assessment';
    if ($assignmentType === 'peer') return 'Peer Evaluation';
    if ($assignmentType === 'program_head') return 'Program Head Evaluation';
    if ($assignmentType === 'dean' && $evaluatorRole === 'vpaa') return 'VPAA Evaluation';
    if ($assignmentType === 'dean') return 'Dean Evaluation';
    return ucwords(str_replace('_', ' ', $assignmentType));
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

    $faculty = admin_one(
        'SELECT f.id, f.full_name, f.department, f.program_code
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE u.id = :user_id OR f.user_id = :user_id_match
         LIMIT 1',
        ['user_id' => (int) $user['id'], 'user_id_match' => (int) $user['id']]
    );

    if ($faculty === null) {
        echo json_encode(['ok' => true, 'data' => [], 'summary' => ['total' => 0, 'latestScore' => null, 'canRevealResults' => false]]);
        exit;
    }

    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = $selectedPeriod !== null ? (string) ($selectedPeriod['period_name'] ?? '') : '';

    $periodCondition = '';
    $periodParams = [];
    if ($selectedPeriodName !== '') {
        $periodCondition = 'AND pa.cycle_name = :cycle_name';
        $periodParams['cycle_name'] = $selectedPeriodName;
    }

    $facultyId = (int) $faculty['id'];

    $progressRows = admin_all(
        "SELECT pa.cycle_name,
                COUNT(*) AS total_assignments,
                SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS submitted_assignments,
                MAX(pa.submitted_at) AS latest_submitted_at
         FROM peer_assignments pa
         WHERE pa.evaluatee_faculty_id = :fac_id AND COALESCE(pa.is_archived, 0) = 0
         $periodCondition
         GROUP BY pa.cycle_name
         ORDER BY MAX(COALESCE(pa.submitted_at, pa.assigned_at)) DESC, pa.cycle_name DESC",
        ['fac_id' => $facultyId] + $periodParams
    );

    $periodProgress = [];
    foreach ($progressRows as $row) {
        $period = (string) ($row['cycle_name'] ?? '');
        if ($period === '') continue;
        $total = (int) ($row['total_assignments'] ?? 0);
        $submitted = (int) ($row['submitted_assignments'] ?? 0);
        $periodProgress[$period] = [
            'period' => $period,
            'total' => $total,
            'submitted' => $submitted,
            'pending' => max(0, $total - $submitted),
            'complete' => $total > 0 && $submitted >= $total,
            'latestSubmittedAt' => (string) ($row['latest_submitted_at'] ?? ''),
        ];
    }

    $completedPeriods = array_values(array_filter(
        array_keys($periodProgress),
        static fn (string $period): bool => (bool) ($periodProgress[$period]['complete'] ?? false)
    ));

    $lockedProgress = $progressRows[0] ?? null;
    if ($completedPeriods === []) {
        echo json_encode([
            'ok' => true,
            'data' => [],
            'insights' => ['strengths' => [], 'weaknesses' => [], 'recommendations' => []],
            'summary' => [
                'total' => 0,
                'latestScore' => null,
                'latestPeriod' => $lockedProgress['cycle_name'] ?? $selectedPeriodName,
                'canRevealResults' => false,
                'completion' => [
                    'total' => (int) ($lockedProgress['total_assignments'] ?? 0),
                    'submitted' => (int) ($lockedProgress['submitted_assignments'] ?? 0),
                    'pending' => max(0, (int) ($lockedProgress['total_assignments'] ?? 0) - (int) ($lockedProgress['submitted_assignments'] ?? 0)),
                ],
                'message' => 'Overall results, strengths, weaknesses, and recommendations will appear after all assigned evaluators submit their evaluations for this period.',
            ],
        ]);
        exit;
    }

    $periodPlaceholders = [];
    $periodSqlParams = ['fac_a' => $facultyId, 'fac_b' => $facultyId];
    foreach ($completedPeriods as $index => $period) {
        $key = 'period_' . $index;
        $periodPlaceholders[] = ':' . $key;
        $periodSqlParams[$key] = $period;
    }
    $periodIn = implode(',', $periodPlaceholders);

    $assignmentRows = admin_all(
        "SELECT assignment_scores.cycle_name,
                ROUND(AVG(assignment_scores.assignment_score), 4) AS overall_score,
                COUNT(*) AS completed_assignments,
                MAX(assignment_scores.submitted_at) AS latest_submitted_at
         FROM (
             SELECT pa.id, pa.cycle_name, pa.submitted_at, ROUND(SUM(x.weighted_score), 4) AS assignment_score
             FROM (
                 SELECT assignment_id, weighted_score
                 FROM pmas_form_a_category_results
                 WHERE evaluatee_faculty_id = :fac_a AND status = 'completed' AND COALESCE(is_archived, 0) = 0
                 UNION ALL
                 SELECT assignment_id, weighted_score
                 FROM pmas_form_b_category_results
                 WHERE evaluatee_faculty_id = :fac_b AND status = 'completed' AND COALESCE(is_archived, 0) = 0
             ) x
             JOIN peer_assignments pa ON pa.id = x.assignment_id
             WHERE COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted' AND pa.cycle_name IN ($periodIn)
             GROUP BY pa.id, pa.cycle_name, pa.submitted_at
         ) assignment_scores
         GROUP BY assignment_scores.cycle_name
         ORDER BY latest_submitted_at DESC, assignment_scores.cycle_name DESC",
        $periodSqlParams
    );

    $data = array_map(static function (array $row) use ($periodProgress): array {
        $periodName = (string) ($row['cycle_name'] ?? '');
        preg_match('/\b(20\d{2})\b/', $periodName, $matches);
        $score = isset($row['overall_score']) ? (float) $row['overall_score'] : null;

        return [
            'periodKey' => $periodName,
            'period' => $periodName,
            'year' => $matches[1] ?? '',
            'overallScore' => $score,
            'performanceLevel' => faculty_results_performance_level($score),
            'status' => 'Completed',
            'completedAssignments' => (int) ($row['completed_assignments'] ?? 0),
            'totalAssignments' => (int) ($periodProgress[$periodName]['total'] ?? 0),
            'submittedAt' => (string) ($row['latest_submitted_at'] ?? ''),
        ];
    }, $assignmentRows);

    $latestPeriod = (string) ($data[0]['period'] ?? $completedPeriods[0] ?? '');
    $categoryRows = $latestPeriod !== '' ? admin_all(
        "SELECT form_label, category_title, average_rating, recommendation
         FROM (
             SELECT 'Form A' AS form_label, c.title AS category_title, r.average_rating, r.recommendation, pa.cycle_name
             FROM pmas_form_a_category_results r
             JOIN pmas_form_a_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_a AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'
             UNION ALL
             SELECT 'Form B' AS form_label, c.title AS category_title, r.average_rating, r.recommendation, pa.cycle_name
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             WHERE r.evaluatee_faculty_id = :fac_b AND r.status = 'completed' AND COALESCE(r.is_archived, 0) = 0 AND COALESCE(pa.is_archived, 0) = 0 AND pa.status = 'submitted'
         ) category_data
         WHERE cycle_name = :latest_period",
        ['fac_a' => $facultyId, 'fac_b' => $facultyId, 'latest_period' => $latestPeriod]
    ) : [];

    $categoryMap = [];
    foreach ($categoryRows as $row) {
        $category = trim((string) ($row['category_title'] ?? ''));
        if ($category === '') continue;
        $key = strtolower($category);
        if (!isset($categoryMap[$key])) {
            $categoryMap[$key] = [
                'category' => $category,
                'form' => (string) ($row['form_label'] ?? ''),
                'scores' => [],
                'recommendations' => [],
            ];
        }
        $categoryMap[$key]['scores'][] = (float) ($row['average_rating'] ?? 0);
        $recommendation = secure_decrypt_value($row['recommendation'] ?? '');
        if ($recommendation !== '') $categoryMap[$key]['recommendations'][] = $recommendation;
    }

    $categories = array_map(static function (array $item): array {
        $scores = array_values(array_filter($item['scores'], static fn ($score): bool => (float) $score > 0));
        $average = $scores !== [] ? array_sum($scores) / count($scores) : 0.0;
        $recommendation = (string) ($item['recommendations'][0] ?? '');
        return [
            'category' => $item['category'],
            'form' => $item['form'],
            'score' => round($average, 2),
            'recommendation' => $recommendation,
        ];
    }, array_values($categoryMap));

    usort($categories, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
    $weaknesses = array_slice($categories, 0, 3);
    $strengths = array_slice(array_reverse($categories), 0, 3);

    $recommendations = array_map(static function (array $weakness): array {
        $category = (string) ($weakness['category'] ?? '');
        $score = (float) ($weakness['score'] ?? 0);
        $existing = trim((string) ($weakness['recommendation'] ?? ''));
        return [
            'category' => $category,
            'score' => $score,
            'seminar' => faculty_results_recommended_session($category),
            'action' => $existing !== '' ? $existing : faculty_results_action_text($category, $score),
        ];
    }, $weaknesses);

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'insights' => [
            'period' => $latestPeriod,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'recommendations' => $recommendations,
        ],
        'summary' => [
            'total' => count($data),
            'latestScore' => $data[0]['overallScore'] ?? null,
            'latestPeriod' => $latestPeriod,
            'canRevealResults' => true,
            'completion' => [
                'total' => (int) ($periodProgress[$latestPeriod]['total'] ?? 0),
                'submitted' => (int) ($periodProgress[$latestPeriod]['submitted'] ?? 0),
                'pending' => (int) ($periodProgress[$latestPeriod]['pending'] ?? 0),
            ],
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
