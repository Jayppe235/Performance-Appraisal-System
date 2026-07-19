<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_data.php';
require_once __DIR__ . '/../includes/evaluation_cards.php';
require_once __DIR__ . '/../includes/evaluation_period.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function program_head_summary_api_seminar(string $weakArea): string
{
    $area = strtolower($weakArea);

    return match (true) {
        str_contains($area, 'communication') => 'Communication Skills and Professional Feedback Seminar',
        str_contains($area, 'teaching') || str_contains($area, 'instruction') => 'Teaching Strategies and Learning Outcomes Seminar',
        str_contains($area, 'classroom') || str_contains($area, 'learner') => 'Classroom Management and Learner Engagement Seminar',
        str_contains($area, 'job') || str_contains($area, 'knowledge') || str_contains($area, 'competence') => 'Subject Mastery and Professional Competence Seminar',
        str_contains($area, 'leadership') || str_contains($area, 'administrative') => 'Academic Leadership and Administrative Effectiveness Seminar',
        str_contains($area, 'technology') || str_contains($area, 'digital') => 'Educational Technology Integration Seminar',
        default => 'Targeted Faculty Development Seminar for ' . ($weakArea !== '' ? $weakArea : 'Professional Growth'),
    };
}

function program_head_summary_api_scope(int $programHeadUserId): array
{
    $programs = admin_all(
        'SELECT p.program_code, p.program_name
         FROM programs p
         WHERE p.program_head_user_id = :program_head_user_id AND p.is_active = 1
         ORDER BY p.program_code',
        ['program_head_user_id' => $programHeadUserId]
    );

    $codes = array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['program_code'] ?? '')),
        $programs
    )));

    $names = [];
    foreach ($programs as $program) {
        $code = trim((string) ($program['program_code'] ?? ''));
        if ($code !== '') {
            $names[$code] = trim((string) ($program['program_name'] ?? $code));
        }
    }

    $user = admin_one(
        'SELECT program FROM users WHERE id = :id AND role = "program_head" LIMIT 1',
        ['id' => $programHeadUserId]
    );
    $fallbackProgram = trim((string) ($user['program'] ?? ''));
    if ($fallbackProgram !== '' && !in_array($fallbackProgram, $codes, true)) {
        $codes[] = $fallbackProgram;
        $names[$fallbackProgram] = $fallbackProgram;
    }

    return ['codes' => array_values(array_unique($codes)), 'names' => $names];
}

try {
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'program_head') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Program Head access is required.']);
        exit;
    }

    admin_ensure_faculty_program_schema();
    dipascaf_ensure_form_a_schema();
    dipascaf_ensure_form_b_schema();
    admin_ensure_archive_schema();

    $programHeadUserId = (int) $user['id'];
    $scope = program_head_summary_api_scope($programHeadUserId);
    $selectedPeriod = dipascaf_selected_period_from_request($_GET, true);
    $selectedPeriodName = $selectedPeriod !== null ? (string) ($selectedPeriod['period_name'] ?? '') : '';

    $programCodes = $scope['codes'];

    if ($programCodes === []) {
        echo json_encode([
            'ok' => true,
            'data' => [
                'programs' => [],
                'facultyResults' => [],
                'trainingPlans' => [],
                'weakAreas' => [],
                'summary' => ['faculty' => 0, 'reviewed' => 0, 'plans' => 0],
            ],
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
    $facultyRows = admin_all(
        "SELECT f.id, f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), NULLIF(u.program, ''), '') AS program_code
         FROM faculty f
         LEFT JOIN users u ON u.id = f.user_id OR (f.user_id IS NULL AND u.email = f.email)
         WHERE f.is_active = 1
           AND f.is_archived = 0
           AND (f.program_code IN ($placeholders) OR u.program IN ($placeholders))
           AND COALESCE(LOWER(u.role), 'teacher') = 'teacher'
         ORDER BY program_code, f.full_name",
        array_merge($programCodes, $programCodes)
    );

    $facultyById = [];
    foreach ($facultyRows as $faculty) {
        $facultyById[(int) $faculty['id']] = $faculty;
    }

    $facultyIds = array_keys($facultyById);
    $resultRows = [];
    $planRows = [];
    $insightRows = [];

    // ── Check if ALL faculty evaluations are complete ──
    $allFacultyComplete = false;
    if ($facultyIds !== []) {
        $fPlaceholdersCheck = implode(',', array_fill(0, count($facultyIds), '?'));
        $completionRows = admin_all(
            "SELECT pa.evaluatee_faculty_id,
                    COUNT(DISTINCT pa.id) AS total_assignments,
                    SUM(CASE WHEN pa.status = 'submitted' THEN 1 ELSE 0 END) AS completed_assignments
             FROM peer_assignments pa
             WHERE pa.evaluatee_faculty_id IN ($fPlaceholdersCheck)
               AND pa.assignment_type IN ('peer', 'dean', 'program_head')
               AND COALESCE(pa.is_archived, 0) = 0
             GROUP BY pa.evaluatee_faculty_id",
            $facultyIds
        );
        $allComplete = true;
        foreach ($completionRows as $row) {
            $total = (int) ($row['total_assignments'] ?? 0);
            $completed = (int) ($row['completed_assignments'] ?? 0);
            if ($total > 0 && $completed < $total) {
                $allComplete = false;
                break;
            }
        }
        $allFacultyComplete = $allComplete;
    }

    if ($facultyIds !== []) {
        $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));
        $periodCondition = '';
        $periodParams = [];
        if ($selectedPeriodName !== '') {
            $periodCondition = 'AND pa.cycle_name = ?';
            $periodParams[] = $selectedPeriodName;
        }
        $resultRows = array_merge(
            admin_all(
                "SELECT r.evaluatee_faculty_id, r.assignment_id, c.title AS category_title,
                        r.average_rating, r.weighted_score, r.recommendation, r.submitted_at
                 FROM pmas_form_a_category_results r
                 JOIN pmas_form_a_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
                   AND r.status = 'completed'
                   AND COALESCE(r.is_archived, 0) = 0
                   AND COALESCE(pa.is_archived, 0) = 0
                   $periodCondition
                 ORDER BY r.submitted_at DESC, r.average_rating ASC",
                $selectedPeriodName !== '' ? array_merge($facultyIds, $periodParams) : $facultyIds
            ),
            admin_all(
                "SELECT r.evaluatee_faculty_id, r.assignment_id, c.title AS category_title,
                        r.average_rating, r.weighted_score, r.recommendation, r.submitted_at
                 FROM pmas_form_b_category_results r
                 JOIN pmas_form_b_categories c ON c.id = r.category_id
                 JOIN peer_assignments pa ON pa.id = r.assignment_id
                 WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
                   AND r.status = 'completed'
                   AND COALESCE(r.is_archived, 0) = 0
                   AND COALESCE(pa.is_archived, 0) = 0
                   $periodCondition
                 ORDER BY r.submitted_at DESC, r.average_rating ASC",
                $selectedPeriodName !== '' ? array_merge($facultyIds, $periodParams) : $facultyIds
            )
        );

        $planRows = admin_all(
            "SELECT p.*, f.full_name AS faculty_name, COALESCE(NULLIF(f.program_code, ''), '') AS program_code
             FROM intervention_plans p
             JOIN faculty f ON f.id = p.faculty_id
             WHERE p.faculty_id IN ($facultyPlaceholders)
             ORDER BY FIELD(p.status, 'assigned', 'planned', 'completed'), p.target_date",
            $facultyIds
        );

        $insightRows = admin_all(
            "SELECT i.*, f.full_name AS faculty_name, COALESCE(NULLIF(f.program_code, ''), '') AS program_code
             FROM ai_insights i
             JOIN faculty f ON f.id = i.faculty_id
             WHERE i.faculty_id IN ($facultyPlaceholders)
             ORDER BY i.created_at DESC",
            $facultyIds
        );
    }

    $resultsByFaculty = [];
    foreach ($resultRows as $row) {
        $facultyId = (int) $row['evaluatee_faculty_id'];
        $resultsByFaculty[$facultyId] ??= [
            'totalScore' => 0.0,
            'weightedTotal' => 0.0,
            'categoryCount' => 0,
            'weakArea' => '',
            'weakRating' => 99.0,
            'strongArea' => '',
            'strongRating' => 0.0,
            'recommendation' => '',
            'submittedAt' => (string) ($row['submitted_at'] ?? ''),
            'categoryScores' => [],
        ];

        $rating = (float) ($row['average_rating'] ?? 0);
        $weighted = (float) ($row['weighted_score'] ?? 0);
        $categoryTitle = (string) ($row['category_title'] ?? 'Professional Growth');
        $resultsByFaculty[$facultyId]['totalScore'] += $rating;
        $resultsByFaculty[$facultyId]['weightedTotal'] += $weighted;
        $resultsByFaculty[$facultyId]['categoryCount']++;
        $resultsByFaculty[$facultyId]['categoryScores'][$categoryTitle] ??= ['total' => 0.0, 'count' => 0];
        $resultsByFaculty[$facultyId]['categoryScores'][$categoryTitle]['total'] += $rating;
        $resultsByFaculty[$facultyId]['categoryScores'][$categoryTitle]['count']++;

        if ($rating < $resultsByFaculty[$facultyId]['weakRating']) {
            $resultsByFaculty[$facultyId]['weakRating'] = $rating;
            $resultsByFaculty[$facultyId]['weakArea'] = $categoryTitle;
            $resultsByFaculty[$facultyId]['recommendation'] = secure_decrypt_value($row['recommendation'] ?? '');
        }
        if ($rating > $resultsByFaculty[$facultyId]['strongRating']) {
            $resultsByFaculty[$facultyId]['strongRating'] = $rating;
            $resultsByFaculty[$facultyId]['strongArea'] = $categoryTitle;
        }
    }

    $insightsByFaculty = [];
    foreach ($insightRows as $row) {
        $facultyId = (int) $row['faculty_id'];
        $insightsByFaculty[$facultyId] ??= $row;
    }

    $facultyResults = [];
    foreach ($facultyById as $facultyId => $faculty) {
        $result = $resultsByFaculty[$facultyId] ?? null;
        $insight = $insightsByFaculty[$facultyId] ?? null;
        $average = $result !== null && $result['categoryCount'] > 0
            ? round($result['totalScore'] / $result['categoryCount'], 2)
            : null;
        $weakArea = $result['weakArea'] ?? (string) ($insight['weak_area'] ?? 'No submitted result yet');

        $facultyResults[] = [
            'id' => $facultyId,
            'faculty' => (string) $faculty['full_name'],
            'program' => (string) ($faculty['program_code'] ?: 'Unassigned Program'),
            'averageRating' => $average === null ? 'Pending' : number_format($average, 2),
            'weightedRating' => $result !== null && $result['categoryCount'] > 0 ? number_format((float) $result['weightedTotal'] / (int) $result['categoryCount'], 4) : 'Pending',
            'weakArea' => $weakArea,
            'strongArea' => $result['strongArea'] ?? '',
            'categoryScores' => $result !== null ? array_map(
                static fn (string $category, array $bucket): array => [
                    'category' => $category,
                    'score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
                ],
                array_keys($result['categoryScores']),
                array_values($result['categoryScores'])
            ) : [],
            'result' => $average === null ? 'Awaiting submitted evaluation' : ($average >= 4.51 ? 'Excellent' : ($average >= 3.01 ? 'Satisfactory' : 'Needs Support')),
            'seminar' => $average === null ? 'Pending evaluation result' : program_head_summary_api_seminar($weakArea),
        ];
    }

    $categoryBuckets = [];
    $ratingDistribution = ['excellent' => 0, 'very_satisfactory' => 0, 'satisfactory' => 0, 'needs_improvement' => 0];
    $weightedValues = [];
    foreach ($resultRows as $row) {
        $category = (string) ($row['category_title'] ?? 'Professional Growth');
        $rating = (float) ($row['average_rating'] ?? 0);
        $categoryBuckets[$category] ??= ['category' => $category, 'total' => 0.0, 'count' => 0];
        $categoryBuckets[$category]['total'] += $rating;
        $categoryBuckets[$category]['count']++;
        if (isset($row['weighted_score'])) {
            $weightedValues[] = (float) $row['weighted_score'];
        }
        if ($rating >= 4.5) {
            $ratingDistribution['excellent']++;
        } elseif ($rating >= 3.75) {
            $ratingDistribution['very_satisfactory']++;
        } elseif ($rating >= 3.0) {
            $ratingDistribution['satisfactory']++;
        } else {
            $ratingDistribution['needs_improvement']++;
        }
    }
    $categoryScores = array_values(array_map(static fn (array $bucket): array => [
        'category' => $bucket['category'],
        'average_score' => $bucket['count'] > 0 ? round($bucket['total'] / $bucket['count'], 2) : null,
        'result_count' => $bucket['count'],
    ], $categoryBuckets));
    usort($categoryScores, static fn (array $a, array $b): int => ((float) ($b['average_score'] ?? 0)) <=> ((float) ($a['average_score'] ?? 0)));
    $lowestCategoryScores = $categoryScores;
    usort($lowestCategoryScores, static fn (array $a, array $b): int => ((float) ($a['average_score'] ?? 99)) <=> ((float) ($b['average_score'] ?? 99)));

    $trainingPlans = [];
    foreach ($planRows as $plan) {
        $trainingPlans[] = [
            'id' => (int) $plan['id'],
            'program' => (string) ($plan['program_code'] ?: 'Unassigned Program'),
            'weakArea' => (string) ($plan['weak_area'] ?? ''),
            'seminar' => program_head_summary_api_seminar((string) ($plan['weak_area'] ?? '')),
            'recommendation' => (string) ($plan['recommendation'] ?? ''),
            'status' => admin_status_label((string) ($plan['status'] ?? 'planned')),
        ];
    }

    // Auto-generate training plans only when ALL faculty evaluations are complete
    if ($allFacultyComplete && $trainingPlans === []) {
        // Group faculty results by program
        $resultsByProgram = [];
        foreach ($facultyResults as $row) {
            if ($row['weakArea'] === 'No submitted result yet') {
                continue;
            }
            $prog = $row['program'];
            $resultsByProgram[$prog][] = $row;
        }

        foreach ($resultsByProgram as $programCode => $rows) {
            // Count most common weak area across faculty in this program
            $weakCounts = [];
            foreach ($rows as $row) {
                $weakCounts[$row['weakArea']] = ($weakCounts[$row['weakArea']] ?? 0) + 1;
            }
            arsort($weakCounts);
            $topWeakArea = array_key_first($weakCounts);

            // Pick the corresponding seminar for the top weak area
            $seminar = '';
            foreach ($rows as $row) {
                if ($row['weakArea'] === $topWeakArea) {
                    $seminar = $row['seminar'];
                    break;
                }
            }
            if ($seminar === '') {
                $seminar = program_head_summary_api_seminar($topWeakArea);
            }

            $trainingPlans[] = [
                'id' => 0,
                'program' => $programCode,
                'weakArea' => $topWeakArea,
                'facultyCount' => count($rows),
                'seminar' => $seminar,
                'recommendation' => 'Recommend attendance in ' . $seminar . ' for all ' . count($rows) . ' faculty in ' . $programCode . '.',
                'status' => 'Planned',
            ];
        }
    }

    // --- Weak Area Register: per-evaluation-result rows from completed category results ---
    // Only shown when ALL faculty evaluations are complete
    $weakAreas = [];
    if ($allFacultyComplete && $facultyIds !== []) {
        $facultyPlaceholders = implode(',', array_fill(0, count($facultyIds), '?'));

        $periodCondition = '';
        $periodParams = [];
        if ($selectedPeriodName !== '') {
            $periodCondition = ' AND pa.cycle_name = ?';
            $periodParams[] = $selectedPeriodName;
        }

        // Form B weak areas (program head evaluates via Form B)
        $formBWeak = admin_all(
            "SELECT r.evaluatee_faculty_id, r.average_rating, r.submitted_at, r.status,
                    c.title AS category_title, 'Form B' AS form_title,
                    f.full_name, f.department, COALESCE(NULLIF(f.program_code, ''), 'Unassigned Program') AS program_code
             FROM pmas_form_b_category_results r
             JOIN pmas_form_b_categories c ON c.id = r.category_id
             JOIN peer_assignments pa ON pa.id = r.assignment_id
             JOIN faculty f ON f.id = r.evaluatee_faculty_id
             WHERE r.evaluatee_faculty_id IN ($facultyPlaceholders)
               AND r.status = 'completed'
               AND COALESCE(r.is_archived, 0) = 0
               AND COALESCE(pa.is_archived, 0) = 0
               AND r.average_rating <= 3.50
               $periodCondition
             ORDER BY r.average_rating ASC, r.submitted_at DESC",
            $selectedPeriodName !== '' ? array_merge($facultyIds, $periodParams) : $facultyIds
        );

        $seen = [];
        foreach ($formBWeak as $row) {
            $fid = (int) $row['evaluatee_faculty_id'];
            $cat = (string) ($row['category_title'] ?? '');
            $key = "{$fid}|{$cat}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $weakAreas[] = [
                'facultyName' => (string) ($row['full_name'] ?? ''),
                'department' => (string) ($row['department'] ?? ''),
                'program' => (string) ($row['program_code'] ?? 'Unassigned Program'),
                'formTitle' => (string) ($row['form_title'] ?? 'Form B'),
                'weakCategory' => $cat,
                'averageScore' => number_format((float) ($row['average_rating'] ?? 0), 2),
                'dateSubmitted' => (string) ($row['submitted_at'] ?? ''),
                'status' => admin_status_label((string) ($row['status'] ?? 'completed')),
                'seminar' => program_head_summary_api_seminar($cat),
            ];
        }
    }

    // If no weak areas from category results, derive from faculty results
    if ($allFacultyComplete && $weakAreas === []) {
        foreach ($facultyResults as $row) {
            if ($row['weakArea'] === 'No submitted result yet') {
                continue;
            }
            $weakAreas[] = [
                'facultyName' => $row['faculty'],
                'department' => $row['program'],
                'program' => $row['program'],
                'formTitle' => 'Form B',
                'weakCategory' => $row['weakArea'],
                'averageScore' => $row['averageRating'],
                'dateSubmitted' => '—',
                'status' => 'Identified',
                'seminar' => program_head_summary_api_seminar($row['weakArea']),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'programs' => array_map(static fn (string $code): array => [
                'code' => $code,
                'name' => $scope['names'][$code] ?? $code,
            ], $programCodes),
            'facultyResults' => $facultyResults,
            'trainingPlans' => $trainingPlans,
            'weakAreas' => $weakAreas,
            'summary' => [
                'faculty' => count($facultyResults),
                'reviewed' => count(array_filter($facultyResults, static fn (array $row): bool => $row['averageRating'] !== 'Pending')),
                'plans' => count($trainingPlans),
                'pending' => count(array_filter($facultyResults, static fn (array $row): bool => $row['averageRating'] === 'Pending')),
                'average_score' => count(array_filter($facultyResults, static fn (array $row): bool => $row['averageRating'] !== 'Pending')) > 0
                    ? round(array_sum(array_map(static fn (array $row): float => $row['averageRating'] === 'Pending' ? 0.0 : (float) $row['averageRating'], $facultyResults)) / max(1, count(array_filter($facultyResults, static fn (array $row): bool => $row['averageRating'] !== 'Pending'))), 2)
                    : null,
                'overall_weighted_average' => $weightedValues !== [] ? round(array_sum($weightedValues) / count($weightedValues), 4) : null,
            ],
            'categoryScores' => $categoryScores,
            'highestRatedAreas' => array_slice($categoryScores, 0, 3),
            'lowestRatedAreas' => array_slice($lowestCategoryScores, 0, 3),
            'ratingDistribution' => $ratingDistribution,
            'generatedSummary' => $categoryScores === []
                ? 'No evaluation data available for the selected appraisal period.'
                : 'The assigned program has submitted evaluation results that identify strengths in the highest-rated categories and development priorities in the lowest-rated categories for the selected appraisal period.',
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
